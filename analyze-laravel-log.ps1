# ==========================================
# Analyze Laravel Log for Python Errors
# ==========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Laravel Log Analysis" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$logFile = "storage\logs\laravel.log"

if (!(Test-Path $logFile)) {
    Write-Host "[ERROR] Log file not found: $logFile" -ForegroundColor Red
    exit
}

Write-Host "Analyzing recent Python errors..." -ForegroundColor Yellow
Write-Host ""

# Get last 100 lines
$lines = Get-Content $logFile -Tail 200

# Find Python-related errors
$pythonErrors = @()
$currentError = @()
$inError = $false

foreach ($line in $lines) {
    if ($line -match "Python 腳本執行失敗" -or $line -match "Python.*執行失敗") {
        $inError = $true
        $currentError = @($line)
    } elseif ($inError) {
        $currentError += $line
        if ($line -match "^\[20") {  # New log entry starts
            $pythonErrors += ,($currentError -join "`n")
            $currentError = @()
            $inError = $false
        }
    }
}

if ($currentError.Count -gt 0) {
    $pythonErrors += ,($currentError -join "`n")
}

if ($pythonErrors.Count -eq 0) {
    Write-Host "[INFO] No recent Python errors found in log" -ForegroundColor Green
    Write-Host ""
    Write-Host "Last 20 log entries:" -ForegroundColor Yellow
    $lines | Select-Object -Last 20 | ForEach-Object {
        Write-Host $_ -ForegroundColor Gray
    }
} else {
    Write-Host "Found $($pythonErrors.Count) Python error(s):" -ForegroundColor Red
    Write-Host ""

    $errorNum = 1
    foreach ($error in $pythonErrors | Select-Object -Last 3) {
        Write-Host "Error #$errorNum" -ForegroundColor Yellow
        Write-Host "----------------------------------------" -ForegroundColor Gray
        Write-Host $error -ForegroundColor Red
        Write-Host ""
        $errorNum++
    }
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Test Python Script Directly" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Create a test JSON file
Write-Host "Creating test data file..." -ForegroundColor Yellow

$testData = @{
    prices = @(100..150 | ForEach-Object { $_ + (Get-Random -Minimum -5 -Maximum 5) })
    dates = @(1..51 | ForEach-Object { (Get-Date "2025-09-01").AddDays($_).ToString("yyyy-MM-dd") })
    base_date = "2025-11-18"
    prediction_days = 1
    stock_symbol = "TEST"
    auto_select = $true
} | ConvertTo-Json

$tempFile = [System.IO.Path]::GetTempFileName()
$testData | Out-File -FilePath $tempFile -Encoding UTF8 -NoNewline

Write-Host "Test file created: $tempFile" -ForegroundColor Gray
Write-Host ""

# Test ARIMA directly
Write-Host "Testing ARIMA model directly..." -ForegroundColor Yellow
$arimaResult = & C:\Python313\python.exe python\models\arima_model.py $tempFile 2>&1
$arimaExitCode = $LASTEXITCODE

Write-Host "ARIMA Exit Code: $arimaExitCode" -ForegroundColor $(if ($arimaExitCode -eq 0) { "Green" } else { "Red" })

if ($arimaExitCode -eq 0) {
    Write-Host "[SUCCESS] ARIMA works!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Output (first 500 chars):" -ForegroundColor Cyan
    $output = $arimaResult | Out-String
    Write-Host $output.Substring(0, [Math]::Min(500, $output.Length)) -ForegroundColor Gray
} else {
    Write-Host "[ERROR] ARIMA failed!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Error Output:" -ForegroundColor Yellow
    Write-Host $arimaResult -ForegroundColor Red
}

Write-Host ""

# Test GARCH directly
Write-Host "Testing GARCH model directly..." -ForegroundColor Yellow

# GARCH needs more data
$garchTestData = @{
    prices = @(100..200 | ForEach-Object { $_ + (Get-Random -Minimum -5 -Maximum 5) })
    dates = @(1..101 | ForEach-Object { (Get-Date "2025-06-01").AddDays($_).ToString("yyyy-MM-dd") })
    base_date = "2025-11-18"
    prediction_days = 1
    stock_symbol = "TEST"
    p = 1
    q = 1
} | ConvertTo-Json

$garchTempFile = [System.IO.Path]::GetTempFileName()
$garchTestData | Out-File -FilePath $garchTempFile -Encoding UTF8 -NoNewline

$garchResult = & C:\Python313\python.exe python\models\garch_model.py $garchTempFile 2>&1
$garchExitCode = $LASTEXITCODE

Write-Host "GARCH Exit Code: $garchExitCode" -ForegroundColor $(if ($garchExitCode -eq 0) { "Green" } else { "Red" })

if ($garchExitCode -eq 0) {
    Write-Host "[SUCCESS] GARCH works!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Output (first 500 chars):" -ForegroundColor Cyan
    $output = $garchResult | Out-String
    Write-Host $output.Substring(0, [Math]::Min(500, $output.Length)) -ForegroundColor Gray
} else {
    Write-Host "[ERROR] GARCH failed!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Error Output:" -ForegroundColor Yellow
    Write-Host $garchResult -ForegroundColor Red
}

# Cleanup
Remove-Item $tempFile -ErrorAction SilentlyContinue
Remove-Item $garchTempFile -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Analysis Complete" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""

if ($arimaExitCode -eq 0 -and $garchExitCode -eq 0) {
    Write-Host "Both Python models work correctly!" -ForegroundColor Green
    Write-Host ""
    Write-Host "If Laravel test still fails, the issue might be:" -ForegroundColor Yellow
    Write-Host "  1. Laravel cache - Run: php artisan cache:clear" -ForegroundColor Gray
    Write-Host "  2. Process timeout" -ForegroundColor Gray
    Write-Host "  3. Permission issues" -ForegroundColor Gray
    Write-Host "  4. Path issues" -ForegroundColor Gray
} else {
    Write-Host "Python models have issues that need to be fixed." -ForegroundColor Red
    Write-Host "Check the error output above." -ForegroundColor Yellow
}
