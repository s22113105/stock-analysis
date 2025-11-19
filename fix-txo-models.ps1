# TXO 預測模型自動修正腳本
# 此腳本會自動備份並替換 Python 模型檔案

$ErrorActionPreference = "Stop"

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "TXO 預測模型自動修正工具" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

# 檢查是否在正確的目錄
if (-not (Test-Path "python\models")) {
    Write-Host "❌ 錯誤: 找不到 python\models 目錄" -ForegroundColor Red
    Write-Host "   請確認您在專案根目錄執行此腳本" -ForegroundColor Yellow
    Write-Host "   正確路徑應該是: C:\Users\user\Documents\GitHub\stock-analysis`n" -ForegroundColor Yellow
    Read-Host "按 Enter 鍵退出"
    exit 1
}

Write-Host "✓ 已確認在專案根目錄`n" -ForegroundColor Green

# 定義檔案
$models = @(
    @{
        Name = "LSTM"
        Original = "python\models\lstm_model.py"
        Backup = "python\models\lstm_model.py.backup"
        Fixed = "lstm_model_fixed.py"
    },
    @{
        Name = "ARIMA"
        Original = "python\models\arima_model.py"
        Backup = "python\models\arima_model.py.backup"
        Fixed = "arima_model_fixed.py"
    },
    @{
        Name = "GARCH"
        Original = "python\models\garch_model.py"
        Backup = "python\models\garch_model.py.backup"
        Fixed = "garch_model_fixed.py"
    }
)

# 步驟 1: 備份現有檔案
Write-Host "步驟 1/4: 備份現有模型檔案" -ForegroundColor Yellow
Write-Host "----------------------------------------`n" -ForegroundColor Yellow

foreach ($model in $models) {
    if (Test-Path $model.Original) {
        if (-not (Test-Path $model.Backup)) {
            Copy-Item $model.Original $model.Backup
            Write-Host "  ✓ 已備份: $($model.Name)" -ForegroundColor Green
        } else {
            Write-Host "  ⚠️  備份已存在: $($model.Name) (跳過)" -ForegroundColor Yellow
        }
    } else {
        Write-Host "  ❌ 找不到原始檔案: $($model.Original)" -ForegroundColor Red
    }
}

Write-Host ""

# 步驟 2: 檢查修正檔案
Write-Host "步驟 2/4: 檢查修正後的檔案" -ForegroundColor Yellow
Write-Host "----------------------------------------`n" -ForegroundColor Yellow

$allFixed = $true
foreach ($model in $models) {
    if (Test-Path $model.Fixed) {
        Write-Host "  ✓ 找到修正檔案: $($model.Fixed)" -ForegroundColor Green
    } else {
        Write-Host "  ❌ 找不到修正檔案: $($model.Fixed)" -ForegroundColor Red
        $allFixed = $false
    }
}

if (-not $allFixed) {
    Write-Host "`n❌ 錯誤: 缺少修正後的檔案" -ForegroundColor Red
    Write-Host "   請確認 Claude 提供的修正檔案都已下載到專案根目錄`n" -ForegroundColor Yellow
    Read-Host "按 Enter 鍵退出"
    exit 1
}

Write-Host ""

# 步驟 3: 替換檔案
Write-Host "步驟 3/4: 替換模型檔案" -ForegroundColor Yellow
Write-Host "----------------------------------------`n" -ForegroundColor Yellow

foreach ($model in $models) {
    try {
        Copy-Item $model.Fixed $model.Original -Force
        Write-Host "  ✓ 已替換: $($model.Name)" -ForegroundColor Green
    } catch {
        Write-Host "  ❌ 替換失敗: $($model.Name)" -ForegroundColor Red
        Write-Host "     錯誤: $($_.Exception.Message)" -ForegroundColor Red
    }
}

Write-Host ""

# 步驟 4: 驗證修正
Write-Host "步驟 4/4: 驗證修正結果" -ForegroundColor Yellow
Write-Host "----------------------------------------`n" -ForegroundColor Yellow

$verified = $true
foreach ($model in $models) {
    $content = Get-Content $model.Original -Raw -Encoding UTF8

    if ($content -match 'with open\(input_file') {
        Write-Host "  ✓ $($model.Name) 檔案讀取邏輯正確" -ForegroundColor Green
    } else {
        Write-Host "  ❌ $($model.Name) 檔案讀取邏輯未更新" -ForegroundColor Red
        $verified = $false
    }
}

Write-Host ""

# 總結
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "修正完成!" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

if ($verified) {
    Write-Host "✅ 所有模型檔案已成功修正" -ForegroundColor Green
    Write-Host ""
    Write-Host "下一步操作:" -ForegroundColor Yellow
    Write-Host "  1. 執行測試腳本: .\test-txo-models-simple.ps1" -ForegroundColor White
    Write-Host "  2. 檢查所有三個模型是否都成功執行" -ForegroundColor White
    Write-Host "  3. 如果仍有問題,請查看 storage\logs\laravel.log`n" -ForegroundColor White
} else {
    Write-Host "⚠️  部分檔案可能未正確更新" -ForegroundColor Yellow
    Write-Host "   請手動檢查 python\models\ 目錄中的檔案`n" -ForegroundColor Yellow
}

Write-Host "備份檔案位置:" -ForegroundColor Cyan
foreach ($model in $models) {
    Write-Host "  - $($model.Backup)" -ForegroundColor Gray
}

Write-Host "`n如需還原,請執行:" -ForegroundColor Cyan
Write-Host "  Copy-Item python\models\*.backup python\models\*.py`n" -ForegroundColor Gray

Read-Host "按 Enter 鍵結束"
