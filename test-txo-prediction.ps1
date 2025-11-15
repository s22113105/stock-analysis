# ========================================
# TXO Prediction Test (Auto-detect URL)
# ========================================

Write-Host ""
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host "TXO Price Prediction Test" -ForegroundColor Cyan
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Auto-detect Laravel URL
Write-Host "Step 1: Detecting Laravel server..." -ForegroundColor Yellow

$possibleUrls = @(
    "http://localhost:8000",
    "http://localhost",
    "http://localhost:80",
    "http://127.0.0.1:8000",
    "http://127.0.0.1"
)

$baseUrl = $null

foreach ($url in $possibleUrls) {
    Write-Host "  Testing: $url" -ForegroundColor Gray -NoNewline
    try {
        $testUrl = "$url/api/options?per_page=1"
        $response = Invoke-RestMethod -Uri $testUrl -Method GET -TimeoutSec 2 -ErrorAction Stop
        if ($response) {
            Write-Host " [OK]" -ForegroundColor Green
            $baseUrl = $url
            break
        }
    } catch {
        Write-Host " [X]" -ForegroundColor Red
    }
}

if (-not $baseUrl) {
    Write-Host ""
    Write-Host "[ERROR] Cannot find Laravel server" -ForegroundColor Red
    Write-Host ""
    Write-Host "Please start Laravel:" -ForegroundColor Yellow
    Write-Host "  php artisan serve" -ForegroundColor Cyan
    Write-Host ""
    exit
}

Write-Host ""
Write-Host "[INFO] Laravel found at: $baseUrl" -ForegroundColor Green
Write-Host ""

# Step 2: Check TXO options
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host "Step 2: Loading TXO contracts..." -ForegroundColor Yellow
Write-Host ""

try {
    $url = "$baseUrl/api/options?underlying=TXO&per_page=5"
    $response = Invoke-RestMethod -Uri $url -Method GET

    if ($response.success) {
        $total = $response.data.total
        $count = $response.data.data.Count

        if ($total -eq 0) {
            Write-Host "[WARNING] No TXO options in database!" -ForegroundColor Red
            Write-Host ""
            Write-Host "Please run crawler:" -ForegroundColor Yellow
            Write-Host "  php artisan crawler:options" -ForegroundColor Cyan
            Write-Host ""
            exit
        }

        Write-Host "[OK] Found $total TXO contracts" -ForegroundColor Green
        Write-Host ""

        Write-Host "Sample contracts:" -ForegroundColor White
        foreach ($opt in $response.data.data) {
            $typeText = if ($opt.option_type -eq 'call') { "[Call]" } else { "[Put ]" }
            $typeColor = if ($opt.option_type -eq 'call') { "Green" } else { "Red" }
            Write-Host "  " -NoNewline
            Write-Host $typeText -ForegroundColor $typeColor -NoNewline
            Write-Host " $($opt.option_code) | Strike $($opt.strike_price) | Exp: $($opt.expiry_date)" -ForegroundColor Gray
        }

        $global:testOptionId = $response.data.data[0].id
        Write-Host ""
        Write-Host "[INFO] Will use option ID: $testOptionId for prediction" -ForegroundColor Cyan
    }
} catch {
    Write-Host "[ERROR] Failed to load TXO options" -ForegroundColor Red
    Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Yellow
    exit
}

Write-Host ""
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host "Step 3: Running prediction..." -ForegroundColor Yellow
Write-Host ""
Write-Host "This may take 30-60 seconds, please wait..." -ForegroundColor Gray
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
        -Body $body `
        -TimeoutSec 120

    if ($response.success) {
        $data = $response.data
        $pred = $data.predictions[0]

        # Calculate change
        $change = (($pred.predicted_price - $data.current_price) / $data.current_price * 100)

        Write-Host "=====================================" -ForegroundColor Cyan
        Write-Host "PREDICTION RESULT" -ForegroundColor Cyan
        Write-Host "=====================================" -ForegroundColor Cyan
        Write-Host ""

        # Contract info
        Write-Host "Contract: " -NoNewline -ForegroundColor Gray
        Write-Host $data.target_info.option_code -ForegroundColor White

        $typeText = if ($data.target_info.option_type -eq 'call') { "Call" } else { "Put" }
        $typeColor = if ($data.target_info.option_type -eq 'call') { "Green" } else { "Red" }
        Write-Host "Type: " -NoNewline -ForegroundColor Gray
        Write-Host $typeText -ForegroundColor $typeColor -NoNewline
        Write-Host " | Strike $($data.target_info.strike_price) | Exp: $($data.target_info.expiry_date)" -ForegroundColor White

        Write-Host ""
        Write-Host "=====================================" -ForegroundColor Cyan
        Write-Host ""

        # Price prediction
        Write-Host "  Today Close           Tomorrow Predict" -ForegroundColor Gray
        Write-Host ""
        Write-Host "     `$$($data.current_price)      --->      `$$($pred.predicted_price)" -ForegroundColor White
        Write-Host ""

        # Change
        $changeText = if ($change -ge 0) { "+$($change.ToString('F2'))%" } else { "$($change.ToString('F2'))%" }
        $changeColor = if ($change -ge 0) { "Green" } else { "Red" }
        $arrow = if ($change -ge 0) { "UP" } else { "DOWN" }

        Write-Host "     Change: " -NoNewline -ForegroundColor Gray
        Write-Host "$changeText $arrow" -ForegroundColor $changeColor
        Write-Host ""

        Write-Host "=====================================" -ForegroundColor Cyan
        Write-Host ""

        # Confidence interval
        Write-Host "95% Confidence Interval:" -ForegroundColor White
        Write-Host "  `$$($pred.confidence_lower) ~ `$$($pred.confidence_upper)" -ForegroundColor Gray
        Write-Host ""
        Write-Host "  Predicted price has 95% probability" -ForegroundColor Gray
        Write-Host "  to fall within this range" -ForegroundColor Gray
        Write-Host ""

        Write-Host "=====================================" -ForegroundColor Cyan
        Write-Host ""

    } else {
        Write-Host "[ERROR] Prediction failed" -ForegroundColor Red
        Write-Host "Message: $($response.message)" -ForegroundColor Yellow
    }

} catch {
    Write-Host "[ERROR] Prediction failed" -ForegroundColor Red
    Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Yellow

    if ($_.ErrorDetails.Message) {
        try {
            $errorDetail = $_.ErrorDetails.Message | ConvertFrom-Json
            Write-Host "Details: $($errorDetail.message)" -ForegroundColor Yellow
        } catch {
            # Ignore JSON parse error
        }
    }
}

Write-Host ""
Write-Host "[SUCCESS] Test completed!" -ForegroundColor Green
Write-Host ""
Write-Host "Next step:" -ForegroundColor Cyan
Write-Host "  Open your browser at: http://localhost:5173/predictions" -ForegroundColor White
Write-Host "  (or your frontend URL)" -ForegroundColor Gray
Write-Host ""
Write-Host "Your Laravel API is at: $baseUrl" -ForegroundColor Gray
Write-Host ""
