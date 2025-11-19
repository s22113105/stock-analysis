# ==========================================
# Install Packages in System Python
# ==========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Install Packages for Laravel Python" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$systemPython = "C:\Python313\python.exe"

if (!(Test-Path $systemPython)) {
    Write-Host "[ERROR] Python 3.13 not found at: $systemPython" -ForegroundColor Red
    Write-Host ""
    Write-Host "Please check the actual Python path used by Laravel" -ForegroundColor Yellow
    exit
}

Write-Host "Installing packages in system Python..." -ForegroundColor Yellow
Write-Host "This may take a few minutes..." -ForegroundColor Gray
Write-Host ""

# List of packages to install
$packages = @(
    "numpy",
    "pandas",
    "tensorflow",
    "statsmodels",
    "pmdarima",
    "arch",
    "scipy"
)

Write-Host "Packages to install:" -ForegroundColor Cyan
foreach ($pkg in $packages) {
    Write-Host "  - $pkg" -ForegroundColor Gray
}
Write-Host ""

# Install packages
Write-Host "Running pip install..." -ForegroundColor Yellow
Write-Host ""

try {
    & $systemPython -m pip install $packages --break-system-packages

    if ($LASTEXITCODE -eq 0) {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Green
        Write-Host "[SUCCESS] Packages installed!" -ForegroundColor Green
        Write-Host "========================================" -ForegroundColor Green
        Write-Host ""

        # Verify installation
        Write-Host "Verifying installation..." -ForegroundColor Yellow
        Write-Host ""

        $allInstalled = $true
        foreach ($pkg in $packages) {
            $result = & $systemPython -c "import $pkg; print($pkg.__version__)" 2>&1
            if ($LASTEXITCODE -eq 0) {
                Write-Host "  [OK] $pkg : $result" -ForegroundColor Green
            } else {
                Write-Host "  [FAIL] $pkg" -ForegroundColor Red
                $allInstalled = $false
            }
        }

        Write-Host ""

        if ($allInstalled) {
            Write-Host "All packages installed successfully!" -ForegroundColor Green
            Write-Host ""
            Write-Host "Next step: Test the models" -ForegroundColor Yellow
            Write-Host ".\test-txo-models-simple.ps1" -ForegroundColor White
        } else {
            Write-Host "Some packages failed to install" -ForegroundColor Red
            Write-Host "Please check the error messages above" -ForegroundColor Yellow
        }

    } else {
        Write-Host ""
        Write-Host "[ERROR] Installation failed" -ForegroundColor Red
        Write-Host "Exit code: $LASTEXITCODE" -ForegroundColor Yellow
    }

} catch {
    Write-Host ""
    Write-Host "[ERROR] Installation error" -ForegroundColor Red
    Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Complete" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
