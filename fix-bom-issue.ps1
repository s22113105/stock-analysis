# ==========================================
# Fix UTF-8 BOM Issue
# ==========================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Fix UTF-8 BOM Issue" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Problem: Python can't read files with UTF-8 BOM" -ForegroundColor Yellow
Write-Host "Solution: Change encoding from 'utf-8' to 'utf-8-sig'" -ForegroundColor Yellow
Write-Host ""

$pythonModelsDir = "python\models"

# Fix ARIMA Model
Write-Host "Fixing ARIMA Model..." -ForegroundColor Cyan

$arimaFile = Join-Path $pythonModelsDir "arima_model.py"

if (Test-Path $arimaFile) {
    # Backup
    $backupFile = "$arimaFile.bom_backup"
    Copy-Item $arimaFile $backupFile -Force
    Write-Host "[OK] Backup created: $backupFile" -ForegroundColor Green

    # Read and replace
    $content = Get-Content $arimaFile -Raw

    # Replace utf-8 with utf-8-sig
    $content = $content -replace "encoding='utf-8'", "encoding='utf-8-sig'"
    $content = $content -replace 'encoding="utf-8"', 'encoding="utf-8-sig"'

    # Save without BOM
    [System.IO.File]::WriteAllText($arimaFile, $content, [System.Text.UTF8Encoding]::new($false))

    Write-Host "[SUCCESS] ARIMA model fixed!" -ForegroundColor Green
    Write-Host "  Changed: encoding='utf-8' -> encoding='utf-8-sig'" -ForegroundColor Gray
} else {
    Write-Host "[ERROR] File not found: $arimaFile" -ForegroundColor Red
}

Write-Host ""

# Fix GARCH Model
Write-Host "Fixing GARCH Model..." -ForegroundColor Cyan

$garchFile = Join-Path $pythonModelsDir "garch_model.py"

if (Test-Path $garchFile) {
    # Backup
    $backupFile = "$garchFile.bom_backup"
    Copy-Item $garchFile $backupFile -Force
    Write-Host "[OK] Backup created: $backupFile" -ForegroundColor Green

    # Read and replace
    $content = Get-Content $garchFile -Raw

    # Replace utf-8 with utf-8-sig
    $content = $content -replace "encoding='utf-8'", "encoding='utf-8-sig'"
    $content = $content -replace 'encoding="utf-8"', 'encoding="utf-8-sig"'

    # Save without BOM
    [System.IO.File]::WriteAllText($garchFile, $content, [System.Text.UTF8Encoding]::new($false))

    Write-Host "[SUCCESS] GARCH model fixed!" -ForegroundColor Green
    Write-Host "  Changed: encoding='utf-8' -> encoding='utf-8-sig'" -ForegroundColor Gray
} else {
    Write-Host "[ERROR] File not found: $garchFile" -ForegroundColor Red
}

Write-Host ""

# Fix LSTM too (just in case)
Write-Host "Fixing LSTM Model (preventive)..." -ForegroundColor Cyan

$lstmFile = Join-Path $pythonModelsDir "lstm_model.py"

if (Test-Path $lstmFile) {
    $content = Get-Content $lstmFile -Raw

    if ($content -match "encoding='utf-8'" -and $content -notmatch "encoding='utf-8-sig'") {
        # Backup
        $backupFile = "$lstmFile.bom_backup"
        Copy-Item $lstmFile $backupFile -Force

        # Replace
        $content = $content -replace "encoding='utf-8'", "encoding='utf-8-sig'"
        $content = $content -replace 'encoding="utf-8"', 'encoding="utf-8-sig"'

        # Save without BOM
        [System.IO.File]::WriteAllText($lstmFile, $content, [System.Text.UTF8Encoding]::new($false))

        Write-Host "[SUCCESS] LSTM model fixed!" -ForegroundColor Green
    } else {
        Write-Host "[INFO] LSTM model already correct or doesn't need fixing" -ForegroundColor Gray
    }
} else {
    Write-Host "[ERROR] File not found: $lstmFile" -ForegroundColor Red
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Fix Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""

Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "  1. Test Python models directly: .\analyze-laravel-log.ps1" -ForegroundColor White
Write-Host "  2. Test via Laravel: .\test-txo-models-simple.ps1" -ForegroundColor White
Write-Host ""
