# ========================================
# Option Prediction API Test (Fixed)
# ========================================

Write-Host ""
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host "Option Price Prediction System" -ForegroundColor Cyan
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host ""

$baseUrl = "http://localhost"

# Test 1: Load Options
Write-Host "Step 1: Loading option contracts..." -ForegroundColor Yellow
Write-Host ""

try {
    # Fix: Use quotes for URL with & symbol
    $url = "$baseUrl/api/options?underlying=TXO&per_page=5"
    $response = Invoke-RestMethod -Uri $url -Method GET

    if ($response.success -and $response.data.data.Count -gt 0) {
        Write-Host "[OK] Found $($response.data.total) contracts" -ForegroundColor Green
        Write-Host ""

        # Display available contracts
        Write-Host "Available contracts:" -ForegroundColor White
        for ($i = 0; $i -lt [Math]::Min(5, $response.data.data.Count); $i++) {
            $opt = $response.data.data[$i]
            $typeText = if ($opt.option_type -eq 'call') { "[Call]" } else { "[Put ]" }
            $typeColor = if ($opt.option_type -eq 'call') { "Green" } else { "Red" }
            Write-Host "  $($i+1). " -NoNewline
            Write-Host $typeText -ForegroundColor $typeColor -NoNewline
            Write-Host " $($opt.option_code) | Strike $($opt.strike_price)" -ForegroundColor Gray
        }

        $global:testOptionId = $response.data.data[0].id
        Write-Host ""
        Write-Host "-> Using contract ID: $testOptionId for testing" -ForegroundColor Cyan
    } else {
        Write-Host "[ERROR] No option data found" -ForegroundColor Red
        Write-Host "Please run: php artisan crawler:options" -ForegroundColor Yellow
        exit
    }
} catch {
    Write-Host "[ERROR] Cannot connect to API: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host ""
    Write-Host "Please check:" -ForegroundColor Yellow
    Write-Host "1. Is Laravel running? (docker-compose ps)" -ForegroundColor White
    Write-Host "2. Is the URL correct? ($baseUrl)" -ForegroundColor White
    exit
}

Write-Host ""
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host ""
Start-Sleep -Seconds 1

# Test 2: Run Prediction
Write-Host "Step 2: Running LSTM prediction (30-60 seconds)" -ForegroundColor Yellow
Write-Host ""

$body = @{
    option_id = $testOptionId
    model_type = "lstm"
    prediction_days = 1
    parameters = @{
        historical_days = 60
        epochs = 50
    }
} | ConvertTo-Json

try {
    $response = Invoke-RestMethod -Uri "$baseUrl/api/predictions/run" `
        -Method POST `
        -ContentType "application/json" `
        -Body $body

    if ($response.success) {
        $data = $response.data
        $pred = $data.predictions[0]

        # Calculate change percentage
        $change = (($pred.predicted_price - $data.current_price) / $data.current_price * 100)

        # Determine recommendation
        $recommendation = "HOLD"
        $reason = "Expected small movement"
        $recColor = "Yellow"

        if ($change -ge 2) {
            $recommendation = "BUY"
            $reason = "Expected to rise over 2%"
            $recColor = "Green"
        } elseif ($change -le -2) {
            $recommendation = "SELL"
            $reason = "Expected to fall over 2%"
            $recColor = "Red"
        }

        # Display results
        Write-Host "=====================================" -ForegroundColor Cyan
        Write-Host ""
        Write-Host "PREDICTION RESULT" -ForegroundColor Cyan
        Write-Host ""

        # Contract info
        Write-Host "Contract: " -NoNewline -ForegroundColor Gray
        Write-Host $data.target_info.option_code -ForegroundColor White

        $typeText = if ($data.target_info.option_type -eq 'call') { "Call" } else { "Put" }
        $typeColor = if ($data.target_info.option_type -eq 'call') { "Green" } else { "Red" }
        Write-Host "Type: " -NoNewline -ForegroundColor Gray
        Write-Host $typeText -ForegroundColor $typeColor -NoNewline
        Write-Host " | Strike $($data.target_info.strike_price)" -ForegroundColor White

        Write-Host ""
        Write-Host "=====================================" -ForegroundColor Cyan
        Write-Host ""

        # Price info
        Write-Host "   Today Close     ->     Tomorrow Predict" -ForegroundColor Gray
        Write-Host ""
        Write-Host "      `$$($data.current_price)      ->      `$$($pred.predicted_price)" -ForegroundColor White
        Write-Host ""

        # Change percentage
        $changeText = if ($change -ge 0) { "+$($change.ToString('F2'))%" } else { "$($change.ToString('F2'))%" }
        $changeColor = if ($change -ge 0) { "Green" } else { "Red" }
        $arrow = if ($change -ge 0) { "UP" } else { "DOWN" }

        Write-Host "         Change: " -NoNewline -ForegroundColor Gray
        Write-Host "$changeText $arrow" -ForegroundColor $changeColor
        Write-Host ""

        Write-Host "=====================================" -ForegroundColor Cyan
        Write-Host ""

        # Trading recommendation
        Write-Host "RECOMMENDATION: " -NoNewline -ForegroundColor Yellow
        Write-Host $recommendation -ForegroundColor $recColor
        Write-Host "   $reason" -ForegroundColor Gray
        Write-Host ""

        # Confidence interval
        Write-Host "95% Confidence: `$$($pred.confidence_lower) ~ `$$($pred.confidence_upper)" -ForegroundColor Gray
        Write-Host ""

        Write-Host "=====================================" -ForegroundColor Cyan
        Write-Host ""

    } else {
        Write-Host "[ERROR] Prediction failed" -ForegroundColor Red
    }

} catch {
    Write-Host "[ERROR] Prediction failed: $($_.Exception.Message)" -ForegroundColor Red

    if ($_.ErrorDetails.Message) {
        try {
            $errorDetail = $_.ErrorDetails.Message | ConvertFrom-Json
            Write-Host "   Reason: $($errorDetail.message)" -ForegroundColor Yellow
        } catch {
            Write-Host "   $($_.ErrorDetails.Message)" -ForegroundColor Yellow
        }
    }
}

Write-Host ""
Write-Host "Test completed!" -ForegroundColor Green
Write-Host ""
Write-Host "Next step: Open browser at http://localhost:5173/predictions" -ForegroundColor Cyan
Write-Host ""
