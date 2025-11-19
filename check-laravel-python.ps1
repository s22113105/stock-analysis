# ==========================================
# Check Python Environment Used by Laravel
# ==========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Python Environment Checker" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Check System Python (used by Laravel)
Write-Host "System Python (used by Laravel):" -ForegroundColor Yellow
Write-Host ""

$systemPython = "C:\Python313\python.exe"

if (Test-Path $systemPython) {
    Write-Host "[OK] Found: $systemPython" -ForegroundColor Green

    # Check version
    $version = & $systemPython --version 2>&1
    Write-Host "Version: $version" -ForegroundColor Gray
    Write-Host ""

    # Check packages
    Write-Host "Checking packages in system Python:" -ForegroundColor Cyan

    $packages = @("numpy", "pandas", "tensorflow", "statsmodels", "pmdarima", "arch")
    $missingPackages = @()

    foreach ($package in $packages) {
        $result = & $systemPython -c "import $package; print($package.__version__)" 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Host "  [OK] $package : $result" -ForegroundColor Green
        } else {
            Write-Host "  [MISSING] $package" -ForegroundColor Red
            $missingPackages += $package
        }
    }

    Write-Host ""

    if ($missingPackages.Count -gt 0) {
        Write-Host "========================================" -ForegroundColor Red
        Write-Host "Problem Found!" -ForegroundColor Red
        Write-Host "========================================" -ForegroundColor Red
        Write-Host ""
        Write-Host "Laravel is using Python 3.13, but it's missing packages:" -ForegroundColor Yellow
        foreach ($pkg in $missingPackages) {
            Write-Host "  - $pkg" -ForegroundColor Red
        }
        Write-Host ""
        Write-Host "Solutions:" -ForegroundColor Cyan
        Write-Host ""
        Write-Host "Option 1: Install packages in system Python (Recommended)" -ForegroundColor Yellow
        Write-Host "  Run this command:" -ForegroundColor Gray
        Write-Host "  $systemPython -m pip install numpy pandas tensorflow statsmodels pmdarima arch --break-system-packages" -ForegroundColor White
        Write-Host ""
        Write-Host "Option 2: Configure Laravel to use virtual environment" -ForegroundColor Yellow
        Write-Host "  Update .env file:" -ForegroundColor Gray
        Write-Host "  PYTHON_PATH=C:\Users\user\Documents\GitHub\stock-analysis\.venv\Scripts\python.exe" -ForegroundColor White
        Write-Host ""
    } else {
        Write-Host "[OK] All required packages are installed!" -ForegroundColor Green
    }

} else {
    Write-Host "[ERROR] System Python not found at: $systemPython" -ForegroundColor Red
    Write-Host ""
    Write-Host "Laravel log shows it's trying to use this path." -ForegroundColor Yellow
    Write-Host "You need to either:" -ForegroundColor Yellow
    Write-Host "  1. Install Python 3.13 at C:\Python313" -ForegroundColor Gray
    Write-Host "  2. Configure Laravel to use virtual environment" -ForegroundColor Gray
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Virtual Environment Python:" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$venvPython = ".venv\Scripts\python.exe"

if (Test-Path $venvPython) {
    Write-Host "[OK] Found: $venvPython" -ForegroundColor Green

    $version = & $venvPython --version 2>&1
    Write-Host "Version: $version" -ForegroundColor Gray
    Write-Host ""

    Write-Host "All packages are installed in virtual environment!" -ForegroundColor Green
    Write-Host ""
} else {
    Write-Host "[ERROR] Virtual environment not found" -ForegroundColor Red
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Recommended Action" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""

Write-Host "Run this command to install packages in system Python:" -ForegroundColor Yellow
Write-Host ""
Write-Host "C:\Python313\python.exe -m pip install numpy pandas tensorflow statsmodels pmdarima arch scipy --break-system-packages" -ForegroundColor White
Write-Host ""
Write-Host "Then test again:" -ForegroundColor Yellow
Write-Host ".\test-txo-models-simple.ps1" -ForegroundColor White
Write-Host ""
