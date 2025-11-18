# ==========================================
# Check TXO Data Dates
# ==========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "TXO Data Date Checker" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$baseUrl = "http://localhost:8000/api"

# Login
$loginBody = @{
    email = "test@stock.com"
    password = "test1234"
} | ConvertTo-Json

try {
    $loginResponse = Invoke-RestMethod -Uri "$baseUrl/auth/login" -Method POST -ContentType "application/json" -Body $loginBody
    $token = $loginResponse.data.token
    $headers = @{
        "Authorization" = "Bearer $token"
        "Content-Type" = "application/json"
    }
    Write-Host "[OK] Login successful" -ForegroundColor Green
    Write-Host ""
} catch {
    Write-Host "[ERROR] Login failed" -ForegroundColor Red
    exit
}

# Test prediction to see current date
Write-Host "Testing LSTM prediction to check current date..." -ForegroundColor Yellow
Write-Host ""

$body = @{
    underlying = "TXO"
    model_type = "lstm"
    prediction_days = 1
    parameters = @{
        epochs = 10
        units = 32
        historical_days = 100
    }
} | ConvertTo-Json

try {
    $response = Invoke-RestMethod -Uri "$baseUrl/predictions/run" -Method POST -Headers $headers -Body $body

    if ($response.success) {
        Write-Host "Prediction Result:" -ForegroundColor Cyan
        Write-Host "  Current Date: $($response.data.current_date)" -ForegroundColor Yellow
        Write-Host "  Current Price: `$$($response.data.current_price)" -ForegroundColor Gray
        Write-Host "  Data Source: $($response.data.data_source)" -ForegroundColor Gray
        Write-Host ""

        # Check historical data dates
        if ($response.data.historical_prices) {
            $historicalPrices = $response.data.historical_prices
            $count = $historicalPrices.Count

            Write-Host "Historical Data Info:" -ForegroundColor Cyan
            Write-Host "  Total Records: $count" -ForegroundColor Gray

            if ($count -gt 0) {
                $firstDate = $historicalPrices[0].date
                $lastDate = $historicalPrices[$count - 1].date

                Write-Host "  First Date: $firstDate" -ForegroundColor Gray
                Write-Host "  Last Date: $lastDate" -ForegroundColor Yellow
                Write-Host ""

                Write-Host "Recent 10 Records:" -ForegroundColor Cyan
                $recent = $historicalPrices | Select-Object -Last 10
                foreach ($record in $recent) {
                    Write-Host "  $($record.date): `$$($record.close)" -ForegroundColor Gray
                }
            }
        }

        Write-Host ""
        Write-Host "========================================" -ForegroundColor Green

        # Check if date is correct
        $currentDate = $response.data.current_date
        $today = Get-Date -Format "yyyy-MM-dd"

        Write-Host "Date Analysis:" -ForegroundColor Cyan
        Write-Host "  System shows: $currentDate" -ForegroundColor Yellow
        Write-Host "  Today is: $today" -ForegroundColor Gray
        Write-Host ""

        if ($currentDate -eq "2025-09-26") {
            Write-Host "[WARNING] Data is outdated!" -ForegroundColor Red
            Write-Host "  Expected: 2025-11-14 (from your database)" -ForegroundColor Yellow
            Write-Host "  Got: $currentDate" -ForegroundColor Yellow
            Write-Host ""
            Write-Host "Possible causes:" -ForegroundColor Yellow
            Write-Host "  1. Query is not getting latest data" -ForegroundColor Gray
            Write-Host "  2. Data sorting issue" -ForegroundColor Gray
            Write-Host "  3. Cache problem" -ForegroundColor Gray
            Write-Host ""
            Write-Host "Solutions:" -ForegroundColor Cyan
            Write-Host "  1. Clear cache: php artisan cache:clear" -ForegroundColor Gray
            Write-Host "  2. Check TxoMarketIndexService.php" -ForegroundColor Gray
            Write-Host "  3. Verify database query" -ForegroundColor Gray
        } else {
            Write-Host "[OK] Data date looks reasonable" -ForegroundColor Green
        }

    } else {
        Write-Host "[ERROR] Prediction failed: $($response.message)" -ForegroundColor Red
    }

} catch {
    Write-Host "[ERROR] Request failed: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Check Complete" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
