# ==========================================
# Direct Python Model Test
# ==========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Direct Python Model Test" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$pythonExe = "C:\Python313\python.exe"

# Test data (similar to what Laravel sends)
$testData = @{
    prices = @(
        140.00, 145.00, 142.00, 138.00, 135.00, 133.00, 130.00, 128.00, 125.00, 122.00,
        120.00, 118.00, 115.00, 113.00, 110.00, 108.00, 105.00, 103.00, 100.00, 98.00,
        95.00, 93.00, 90.00, 88.00, 85.00, 83.00, 80.00, 78.00, 75.00, 73.00,
        70.00, 72.00, 74.00, 76.00, 78.00, 80.00, 82.00, 84.00, 86.00, 88.00,
        90.00, 92.00, 94.00, 96.00, 98.00, 100.00, 102.00, 104.00, 106.00, 108.00,
        110.00, 112.00, 114.00, 116.00, 118.00, 120.00, 122.00, 124.00, 126.00, 128.00,
        130.00, 132.00, 134.00, 136.00, 138.00, 140.00, 142.00, 144.00, 146.00, 148.00,
        150.00, 152.00, 154.00, 156.00, 158.00, 160.00, 162.00, 164.00, 166.00, 168.00,
        170.00, 172.00, 174.00, 176.00, 178.00, 180.00, 182.00, 184.00, 186.00, 188.00,
        190.00, 192.00, 194.00, 196.00, 198.00, 200.00, 202.00, 204.00, 206.00, 208.00
    )
    dates = @(1..100 | ForEach-Object {
        (Get-Date "2025-08-01").AddDays($_).ToString("yyyy-MM-dd")
    })
    base_date = "2025-11-18"
    prediction_days = 1
    stock_symbol = "TXO"
    auto_select = $true
}

# Convert to JSON
$jsonData = $testData | ConvertTo-Json -Compress

Write-Host "Test Data Summary:" -ForegroundColor Yellow
Write-Host "  Prices count: $($testData.prices.Count)" -ForegroundColor Gray
Write-Host "  Date range: $($testData.dates[0]) to $($testData.dates[-1])" -ForegroundColor Gray
Write-Host ""

# Test ARIMA
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Testing ARIMA Model" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$arimaScript = "python\models\arima_model.py"

if (Test-Path $arimaScript) {
    Write-Host "Running: $pythonExe $arimaScript" -ForegroundColor Gray
    Write-Host ""

    try {
        # Run Python script and capture all output
        $output = & $pythonExe $arimaScript $jsonData 2>&1
        $exitCode = $LASTEXITCODE

        Write-Host "Exit Code: $exitCode" -ForegroundColor $(if ($exitCode -eq 0) { "Green" } else { "Red" })
        Write-Host ""

        if ($exitCode -eq 0) {
            Write-Host "[SUCCESS] ARIMA model executed" -ForegroundColor Green
            Write-Host ""
            Write-Host "Output:" -ForegroundColor Cyan
            Write-Host $output -ForegroundColor Gray

            # Try to parse as JSON
            try {
                $result = $output | ConvertFrom-Json
                Write-Host ""
                Write-Host "Parsed Result:" -ForegroundColor Green
                Write-Host "  Success: $($result.success)" -ForegroundColor Gray
                if ($result.predictions) {
                    Write-Host "  Predictions: $($result.predictions.Count)" -ForegroundColor Gray
                    Write-Host "  Predicted Price: $($result.predictions[0].predicted_price)" -ForegroundColor Gray
                }
            } catch {
                Write-Host ""
                Write-Host "[WARNING] Could not parse output as JSON" -ForegroundColor Yellow
            }
        } else {
            Write-Host "[ERROR] ARIMA model failed" -ForegroundColor Red
            Write-Host ""
            Write-Host "Error Output:" -ForegroundColor Yellow
            Write-Host $output -ForegroundColor Red
        }
    } catch {
        Write-Host "[ERROR] Failed to execute" -ForegroundColor Red
        Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Yellow
    }
} else {
    Write-Host "[ERROR] Script not found: $arimaScript" -ForegroundColor Red
}

Write-Host ""

# Test GARCH
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Testing GARCH Model" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$garchScript = "python\models\garch_model.py"
$garchData = $testData.Clone()
$garchData.p = 1
$garchData.q = 1
$garchData.Remove('auto_select')
$garchJsonData = $garchData | ConvertTo-Json -Compress

if (Test-Path $garchScript) {
    Write-Host "Running: $pythonExe $garchScript" -ForegroundColor Gray
    Write-Host ""

    try {
        # Run Python script and capture all output
        $output = & $pythonExe $garchScript $garchJsonData 2>&1
        $exitCode = $LASTEXITCODE

        Write-Host "Exit Code: $exitCode" -ForegroundColor $(if ($exitCode -eq 0) { "Green" } else { "Red" })
        Write-Host ""

        if ($exitCode -eq 0) {
            Write-Host "[SUCCESS] GARCH model executed" -ForegroundColor Green
            Write-Host ""
            Write-Host "Output:" -ForegroundColor Cyan
            Write-Host $output -ForegroundColor Gray

            # Try to parse as JSON
            try {
                $result = $output | ConvertFrom-Json
                Write-Host ""
                Write-Host "Parsed Result:" -ForegroundColor Green
                Write-Host "  Success: $($result.success)" -ForegroundColor Gray
                if ($result.predictions) {
                    Write-Host "  Predictions: $($result.predictions.Count)" -ForegroundColor Gray
                    Write-Host "  Predicted Volatility: $($result.predictions[0].predicted_volatility)" -ForegroundColor Gray
                }
            } catch {
                Write-Host ""
                Write-Host "[WARNING] Could not parse output as JSON" -ForegroundColor Yellow
            }
        } else {
            Write-Host "[ERROR] GARCH model failed" -ForegroundColor Red
            Write-Host ""
            Write-Host "Error Output:" -ForegroundColor Yellow
            Write-Host $output -ForegroundColor Red
        }
    } catch {
        Write-Host "[ERROR] Failed to execute" -ForegroundColor Red
        Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Yellow
    }
} else {
    Write-Host "[ERROR] Script not found: $garchScript" -ForegroundColor Red
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Test Complete" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""

Write-Host "If models failed above, the error messages will help identify the problem." -ForegroundColor Yellow
Write-Host "Common issues:" -ForegroundColor Yellow
Write-Host "  1. JSON parsing error - Check data format" -ForegroundColor Gray
Write-Host "  2. Import error - Package not properly installed" -ForegroundColor Gray
Write-Host "  3. Data validation error - Not enough data points" -ForegroundColor Gray
Write-Host "  4. Model convergence error - Data unsuitable for model" -ForegroundColor Gray
