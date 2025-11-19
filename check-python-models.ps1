# ==========================================
# Check Python Models
# ==========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Python Models Diagnostic" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$pythonDir = "python\models"

Write-Host "Checking Python models directory..." -ForegroundColor Yellow
Write-Host "Directory: $pythonDir" -ForegroundColor Gray
Write-Host ""

# Check if directory exists
if (Test-Path $pythonDir) {
    Write-Host "[OK] Directory exists" -ForegroundColor Green
    Write-Host ""

    # List all files
    Write-Host "Files in directory:" -ForegroundColor Cyan
    Get-ChildItem $pythonDir | ForEach-Object {
        $size = [math]::Round($_.Length / 1KB, 2)
        Write-Host "  $($_.Name) ($size KB)" -ForegroundColor Gray
    }
    Write-Host ""

    # Check required model files
    $requiredModels = @(
        "lstm_model.py",
        "arima_model.py",
        "garch_model.py"
    )

    Write-Host "Checking required models:" -ForegroundColor Cyan
    $missingModels = @()

    foreach ($model in $requiredModels) {
        $path = Join-Path $pythonDir $model
        if (Test-Path $path) {
            Write-Host "  [OK] $model" -ForegroundColor Green
        } else {
            Write-Host "  [MISSING] $model" -ForegroundColor Red
            $missingModels += $model
        }
    }
    Write-Host ""

    if ($missingModels.Count -gt 0) {
        Write-Host "Missing Models:" -ForegroundColor Red
        foreach ($model in $missingModels) {
            Write-Host "  - $model" -ForegroundColor Yellow
        }
        Write-Host ""
        Write-Host "Action Required:" -ForegroundColor Yellow
        Write-Host "  1. Check if files were deleted" -ForegroundColor Gray
        Write-Host "  2. Restore from Git: git checkout python/models/" -ForegroundColor Gray
        Write-Host "  3. Or create new model files" -ForegroundColor Gray
    }

} else {
    Write-Host "[ERROR] Directory not found!" -ForegroundColor Red
    Write-Host "Expected path: $pythonDir" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Action Required:" -ForegroundColor Yellow
    Write-Host "  1. Create directory: mkdir python\models" -ForegroundColor Gray
    Write-Host "  2. Add model files to the directory" -ForegroundColor Gray
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Python Environment Check" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Check Python executable
Write-Host "Checking Python..." -ForegroundColor Yellow

try {
    $pythonVersion = & python --version 2>&1
    Write-Host "[OK] Python found: $pythonVersion" -ForegroundColor Green
} catch {
    Write-Host "[ERROR] Python not found" -ForegroundColor Red
}

Write-Host ""

# Check required packages
Write-Host "Checking required packages..." -ForegroundColor Yellow
Write-Host ""

$packages = @(
    "numpy",
    "pandas",
    "tensorflow",
    "statsmodels",
    "pmdarima",
    "arch"
)

foreach ($package in $packages) {
    try {
        $result = & python -c "import $package; print($package.__version__)" 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Host "  [OK] $package : $result" -ForegroundColor Green
        } else {
            Write-Host "  [MISSING] $package" -ForegroundColor Red
        }
    } catch {
        Write-Host "  [MISSING] $package" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Test Python Models Directly" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Test ARIMA model if it exists
$arimaPath = "python\models\arima_model.py"
if (Test-Path $arimaPath) {
    Write-Host "Testing ARIMA model..." -ForegroundColor Yellow

    $testData = @{
        prices = @(100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113, 114, 115, 116, 117, 118, 119, 120)
        dates = @("2025-01-01", "2025-01-02", "2025-01-03", "2025-01-04", "2025-01-05", "2025-01-06", "2025-01-07", "2025-01-08", "2025-01-09", "2025-01-10", "2025-01-11", "2025-01-12", "2025-01-13", "2025-01-14", "2025-01-15", "2025-01-16", "2025-01-17", "2025-01-18", "2025-01-19", "2025-01-20", "2025-01-21")
        base_date = "2025-11-18"
        prediction_days = 1
        stock_symbol = "TEST"
        auto_select = $true
    } | ConvertTo-Json -Compress

    try {
        $result = & python $arimaPath $testData 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Host "  [OK] ARIMA model executed successfully" -ForegroundColor Green
            Write-Host "  Output: $result" -ForegroundColor Gray
        } else {
            Write-Host "  [ERROR] ARIMA model failed" -ForegroundColor Red
            Write-Host "  Error: $result" -ForegroundColor Yellow
        }
    } catch {
        Write-Host "  [ERROR] Failed to execute ARIMA model" -ForegroundColor Red
        Write-Host "  Error: $($_.Exception.Message)" -ForegroundColor Yellow
    }
} else {
    Write-Host "[SKIP] ARIMA model not found" -ForegroundColor Yellow
}

Write-Host ""

# Test GARCH model if it exists
$garchPath = "python\models\garch_model.py"
if (Test-Path $garchPath) {
    Write-Host "Testing GARCH model..." -ForegroundColor Yellow

    $testData = @{
        prices = @(100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113, 114, 115, 116, 117, 118, 119, 120)
        dates = @("2025-01-01", "2025-01-02", "2025-01-03", "2025-01-04", "2025-01-05", "2025-01-06", "2025-01-07", "2025-01-08", "2025-01-09", "2025-01-10", "2025-01-11", "2025-01-12", "2025-01-13", "2025-01-14", "2025-01-15", "2025-01-16", "2025-01-17", "2025-01-18", "2025-01-19", "2025-01-20", "2025-01-21")
        base_date = "2025-11-18"
        prediction_days = 1
        stock_symbol = "TEST"
        p = 1
        q = 1
    } | ConvertTo-Json -Compress

    try {
        $result = & python $garchPath $testData 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Host "  [OK] GARCH model executed successfully" -ForegroundColor Green
            Write-Host "  Output: $result" -ForegroundColor Gray
        } else {
            Write-Host "  [ERROR] GARCH model failed" -ForegroundColor Red
            Write-Host "  Error: $result" -ForegroundColor Yellow
        }
    } catch {
        Write-Host "  [ERROR] Failed to execute GARCH model" -ForegroundColor Red
        Write-Host "  Error: $($_.Exception.Message)" -ForegroundColor Yellow
    }
} else {
    Write-Host "[SKIP] GARCH model not found" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Diagnostic Complete" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
