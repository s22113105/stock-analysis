# ==========================================
# Verify Python Model Files
# ==========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Verify Python Model Files" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$pythonModelsDir = "python\models"

# Check ARIMA model
Write-Host "Checking ARIMA Model..." -ForegroundColor Yellow
Write-Host ""

$arimaFile = Join-Path $pythonModelsDir "arima_model.py"

if (Test-Path $arimaFile) {
    Write-Host "[OK] File exists: $arimaFile" -ForegroundColor Green

    # Check if it uses correct file reading
    $content = Get-Content $arimaFile -Raw

    if ($content -match "with open\(.*?encoding") {
        Write-Host "[OK] Uses file reading (correct)" -ForegroundColor Green

        # Show the specific line
        $lines = Get-Content $arimaFile
        $lineNum = 0
        foreach ($line in $lines) {
            $lineNum++
            if ($line -match "with open" -or $line -match "json\.load\(f\)") {
                Write-Host "  Line $lineNum`: $line" -ForegroundColor Gray
            }
        }
    } elseif ($content -match "json\.loads\(sys\.argv\[1\]\)") {
        Write-Host "[ERROR] Still uses json.loads() (incorrect)" -ForegroundColor Red
        Write-Host "  The file needs to be replaced!" -ForegroundColor Yellow

        # Show the problematic line
        $lines = Get-Content $arimaFile
        $lineNum = 0
        foreach ($line in $lines) {
            $lineNum++
            if ($line -match "json\.loads") {
                Write-Host "  Line $lineNum`: $line" -ForegroundColor Red
            }
        }
    } else {
        Write-Host "[WARNING] Could not determine reading method" -ForegroundColor Yellow
    }
} else {
    Write-Host "[ERROR] File not found: $arimaFile" -ForegroundColor Red
}

Write-Host ""

# Check GARCH model
Write-Host "Checking GARCH Model..." -ForegroundColor Yellow
Write-Host ""

$garchFile = Join-Path $pythonModelsDir "garch_model.py"

if (Test-Path $garchFile) {
    Write-Host "[OK] File exists: $garchFile" -ForegroundColor Green

    # Check if it uses correct file reading
    $content = Get-Content $garchFile -Raw

    if ($content -match "with open\(.*?encoding") {
        Write-Host "[OK] Uses file reading (correct)" -ForegroundColor Green

        # Show the specific line
        $lines = Get-Content $garchFile
        $lineNum = 0
        foreach ($line in $lines) {
            $lineNum++
            if ($line -match "with open" -or $line -match "json\.load\(f\)") {
                Write-Host "  Line $lineNum`: $line" -ForegroundColor Gray
            }
        }
    } elseif ($content -match "json\.loads\(sys\.argv\[1\]\)") {
        Write-Host "[ERROR] Still uses json.loads() (incorrect)" -ForegroundColor Red
        Write-Host "  The file needs to be replaced!" -ForegroundColor Yellow

        # Show the problematic line
        $lines = Get-Content $garchFile
        $lineNum = 0
        foreach ($line in $lines) {
            $lineNum++
            if ($line -match "json\.loads") {
                Write-Host "  Line $lineNum`: $line" -ForegroundColor Red
            }
        }
    } else {
        Write-Host "[WARNING] Could not determine reading method" -ForegroundColor Yellow
    }
} else {
    Write-Host "[ERROR] File not found: $garchFile" -ForegroundColor Red
}

Write-Host ""

# Check LSTM for comparison (should work)
Write-Host "Checking LSTM Model (for comparison)..." -ForegroundColor Yellow
Write-Host ""

$lstmFile = Join-Path $pythonModelsDir "lstm_model.py"

if (Test-Path $lstmFile) {
    Write-Host "[OK] File exists: $lstmFile" -ForegroundColor Green

    $content = Get-Content $lstmFile -Raw

    if ($content -match "with open\(.*?encoding") {
        Write-Host "[OK] LSTM uses file reading" -ForegroundColor Green
    } elseif ($content -match "json\.loads\(sys\.argv\[1\]\)") {
        Write-Host "[INFO] LSTM uses json.loads()" -ForegroundColor Gray
    }
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Summary" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""

Write-Host "If ARIMA or GARCH show [ERROR], you need to:" -ForegroundColor Yellow
Write-Host "  1. Download the fixed files from outputs folder" -ForegroundColor Gray
Write-Host "  2. Replace python\models\arima_model.py" -ForegroundColor Gray
Write-Host "  3. Replace python\models\garch_model.py" -ForegroundColor Gray
Write-Host ""
Write-Host "Commands:" -ForegroundColor Cyan
Write-Host "  copy arima_model.py python\models\arima_model.py -Force" -ForegroundColor White
Write-Host "  copy garch_model.py python\models\garch_model.py -Force" -ForegroundColor White
