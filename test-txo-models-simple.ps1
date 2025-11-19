# ==========================================
# TXO Model Testing Script (ASCII Only)
# ==========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "TXO Prediction Models Test" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$baseUrl = "http://localhost:8000/api"

# Login
Write-Host "Step 1: Login..." -ForegroundColor Yellow

$loginBody = @{
    email = "test@stock.com"
    password = "test1234"
} | ConvertTo-Json

try {
    $loginResponse = Invoke-RestMethod -Uri "$baseUrl/auth/login" -Method POST -ContentType "application/json" -Body $loginBody
    $token = $loginResponse.data.token
    Write-Host "[OK] Login successful" -ForegroundColor Green
    Write-Host ""
} catch {
    Write-Host "[ERROR] Login failed: $($_.Exception.Message)" -ForegroundColor Red
    exit
}

$headers = @{
    "Authorization" = "Bearer $token"
    "Content-Type" = "application/json"
}

# Test Function
function Test-Model {
    param(
        [string]$ModelName,
        [hashtable]$Parameters = @{}
    )

    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host "Testing $ModelName Model" -ForegroundColor Cyan
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host ""

    $body = @{
        underlying = "TXO"
        model_type = $ModelName.ToLower()
        prediction_days = 1
        parameters = $Parameters
    } | ConvertTo-Json

    Write-Host "Request parameters:" -ForegroundColor Yellow
    Write-Host $body -ForegroundColor Gray
    Write-Host ""

    try {
        $startTime = Get-Date

        $response = Invoke-RestMethod -Uri "$baseUrl/predictions/run" -Method POST -Headers $headers -Body $body

        $endTime = Get-Date
        $duration = ($endTime - $startTime).TotalSeconds

        if ($response.success) {
            Write-Host "[SUCCESS] $ModelName prediction completed!" -ForegroundColor Green
            Write-Host "Duration: $([math]::Round($duration, 2))s" -ForegroundColor Gray
            Write-Host ""

            Write-Host "Results Summary:" -ForegroundColor Cyan
            Write-Host "  Data Source: $($response.data.data_source)" -ForegroundColor Gray
            Write-Host "  Current Price: `$$($response.data.current_price)" -ForegroundColor Gray
            Write-Host "  Current Date: $($response.data.current_date)" -ForegroundColor Gray
            Write-Host "  Predicted Price: `$$($response.data.predictions[0].predicted_price)" -ForegroundColor Gray

            if ($response.data.predictions[0].confidence_lower) {
                Write-Host "  95% CI: `$$($response.data.predictions[0].confidence_lower) ~ `$$($response.data.predictions[0].confidence_upper)" -ForegroundColor Gray
            }

            $current = $response.data.current_price
            $predicted = $response.data.predictions[0].predicted_price
            $change = (($predicted - $current) / $current) * 100
            $changeColor = if ($change -ge 0) { "Green" } else { "Red" }

            Write-Host "  Change: $([math]::Round($change, 2))%" -ForegroundColor $changeColor
            Write-Host ""

            if ($response.data.model_info) {
                Write-Host "Model Info:" -ForegroundColor Cyan
                $modelInfo = $response.data.model_info
                foreach ($key in $modelInfo.PSObject.Properties.Name) {
                    Write-Host "  $key = $($modelInfo.$key)" -ForegroundColor Gray
                }
                Write-Host ""
            }

            if ($response.data.metrics) {
                Write-Host "Model Metrics:" -ForegroundColor Cyan
                $metrics = $response.data.metrics
                foreach ($key in $metrics.PSObject.Properties.Name) {
                    Write-Host "  $key = $($metrics.$key)" -ForegroundColor Gray
                }
                Write-Host ""
            }

            return @{
                success = $true
                data = $response.data
                duration = $duration
                change = $change
            }

        } else {
            Write-Host "[FAILED] $ModelName prediction failed" -ForegroundColor Red
            Write-Host "Error: $($response.message)" -ForegroundColor Yellow
            Write-Host ""

            return @{
                success = $false
                error = $response.message
            }
        }

    } catch {
        Write-Host "[ERROR] $ModelName prediction error" -ForegroundColor Red
        Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Yellow

        if ($_.Exception.Response) {
            $statusCode = $_.Exception.Response.StatusCode.value__
            Write-Host "Status Code: $statusCode" -ForegroundColor Yellow

            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            $responseBody = $reader.ReadToEnd()
            Write-Host "Response: $responseBody" -ForegroundColor Yellow
        }
        Write-Host ""

        return @{
            success = $false
            error = $_.Exception.Message
        }
    }
}

# Test LSTM Model
$lstmResult = Test-Model -ModelName "LSTM" -Parameters @{
    epochs = 20
    units = 64
    historical_days = 180
}

Start-Sleep -Seconds 2

# Test ARIMA Model
$arimaResult = Test-Model -ModelName "ARIMA" -Parameters @{
    auto_select = $true
    historical_days = 100
}

Start-Sleep -Seconds 2

# Test GARCH Model
$garchResult = Test-Model -ModelName "GARCH" -Parameters @{
    p = 1
    q = 1
    historical_days = 200
}

# Summary Report
Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Test Summary" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""

$results = @(
    @{ Name = "LSTM"; Result = $lstmResult },
    @{ Name = "ARIMA"; Result = $arimaResult },
    @{ Name = "GARCH"; Result = $garchResult }
)

$successCount = ($results | Where-Object { $_.Result.success }).Count
$failCount = 3 - $successCount

Write-Host "Statistics:" -ForegroundColor Cyan
Write-Host "  Total Tests: 3" -ForegroundColor Gray
Write-Host "  Success: $successCount" -ForegroundColor Green
Write-Host "  Failed: $failCount" -ForegroundColor $(if ($failCount -eq 0) { "Green" } else { "Red" })
Write-Host ""

Write-Host "Model Results:" -ForegroundColor Cyan
foreach ($result in $results) {
    $status = if ($result.Result.success) { "[OK]" } else { "[FAIL]" }
    $color = if ($result.Result.success) { "Green" } else { "Red" }

    Write-Host "  $status $($result.Name)" -ForegroundColor $color

    if ($result.Result.success) {
        Write-Host "    Duration: $([math]::Round($result.Result.duration, 2))s" -ForegroundColor Gray
        Write-Host "    Change: $([math]::Round($result.Result.change, 2))%" -ForegroundColor Gray
    } else {
        Write-Host "    Error: $($result.Result.error)" -ForegroundColor Yellow
    }
}

Write-Host ""

if ($successCount -eq 3) {
    Write-Host "All models tested successfully!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Model Comparison:" -ForegroundColor Cyan

    foreach ($result in $results) {
        if ($result.Result.success -and $result.Result.data.predictions) {
            $pred = $result.Result.data.predictions[0].predicted_price
            $current = $result.Result.data.current_price
            $change = (($pred - $current) / $current) * 100

            Write-Host "  $($result.Name): `$$pred ($([math]::Round($change, 2))%)" -ForegroundColor Gray
        }
    }

    Write-Host ""
    Write-Host "Recommendations:" -ForegroundColor Yellow
    Write-Host "  - LSTM: Best for long-term, complex patterns" -ForegroundColor Gray
    Write-Host "  - ARIMA: Best for short-term, fast prediction" -ForegroundColor Gray
    Write-Host "  - GARCH: Best for volatility, risk analysis" -ForegroundColor Gray

} else {
    Write-Host "Some models failed. Please check errors above." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Test Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
