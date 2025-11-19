# ==========================================
# Stock Analysis - 系統診斷腳本
# ==========================================
# 檢查系統配置和可能的問題

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "系統診斷工具" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$baseUrl = "http://localhost:8000"
$apiUrl = "$baseUrl/api"

# ==========================================
# 檢查 1: Laravel 服務狀態
# ==========================================
Write-Host "檢查 1: Laravel 服務狀態" -ForegroundColor Yellow

try {
    $healthResponse = Invoke-RestMethod -Uri "$apiUrl/health" -Method GET -TimeoutSec 5
    Write-Host "✓ Laravel 服務正常運行" -ForegroundColor Green
    Write-Host "  時間: $($healthResponse.timestamp)" -ForegroundColor Gray
} catch {
    Write-Host "✗ Laravel 服務無法連接" -ForegroundColor Red
    Write-Host "  請確認: php artisan serve 是否正在運行" -ForegroundColor Yellow
    Write-Host "  或檢查 Docker 容器狀態" -ForegroundColor Yellow
}

Write-Host ""

# ==========================================
# 檢查 2: 資料庫連線
# ==========================================
Write-Host "檢查 2: 資料庫連線" -ForegroundColor Yellow

try {
    # 檢查股票資料是否存在
    $stocksResponse = Invoke-RestMethod -Uri "$apiUrl/stocks?per_page=1" -Method GET -TimeoutSec 5
    Write-Host "✓ 資料庫連線正常" -ForegroundColor Green
    Write-Host "  股票資料數量: $($stocksResponse.total)" -ForegroundColor Gray
} catch {
    Write-Host "✗ 資料庫連線失敗" -ForegroundColor Red
    Write-Host "  請檢查 .env 中的資料庫設定" -ForegroundColor Yellow
}

Write-Host ""

# ==========================================
# 檢查 3: 認證 API 狀態
# ==========================================
Write-Host "檢查 3: 認證 API 狀態" -ForegroundColor Yellow

# 嘗試訪問需要認證的 API(不帶 Token)
try {
    $predictionResponse = Invoke-RestMethod `
        -Uri "$apiUrl/predictions" `
        -Method GET `
        -ErrorAction Stop

    Write-Host "⚠ 警告: 預測 API 未受保護!" -ForegroundColor Yellow
    Write-Host "  建議: 啟用 auth:sanctum 中介軟體" -ForegroundColor Yellow
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__

    if ($statusCode -eq 401) {
        Write-Host "✓ 認證保護正常運作" -ForegroundColor Green
        Write-Host "  狀態碼: 401 Unauthorized" -ForegroundColor Gray
    } elseif ($statusCode -eq 500) {
        Write-Host "⚠ 發現問題: Route [login] not defined" -ForegroundColor Yellow
        Write-Host "  需要修正: app/Exceptions/Handler.php" -ForegroundColor Yellow
        Write-Host "  參考: README-FIX-GUIDE.md" -ForegroundColor Cyan
    } else {
        Write-Host "? 未預期的狀態碼: $statusCode" -ForegroundColor Yellow
    }
}

Write-Host ""

# ==========================================
# 檢查 4: Sanctum 設定
# ==========================================
Write-Host "檢查 4: Sanctum 設定" -ForegroundColor Yellow

try {
    # 檢查註冊 API 是否正常
    $testRegisterBody = @{
        name = "Diagnostic Test"
        email = "diagnostic-$(Get-Random)@test.com"
        password = "test1234"
        password_confirmation = "test1234"
    } | ConvertTo-Json

    $registerResponse = Invoke-RestMethod `
        -Uri "$apiUrl/auth/register" `
        -Method POST `
        -ContentType "application/json" `
        -Body $testRegisterBody `
        -ErrorAction Stop

    Write-Host "✓ Sanctum 認證系統正常" -ForegroundColor Green
    Write-Host "  Token 長度: $($registerResponse.data.token.Length) 字元" -ForegroundColor Gray

    # 清理測試帳號(可選)
    # 需要有刪除用戶的功能才能執行

} catch {
    Write-Host "✗ Sanctum 設定可能有問題" -ForegroundColor Red
    Write-Host "  錯誤: $($_.Exception.Message)" -ForegroundColor Yellow
    Write-Host "  請執行: php artisan migrate" -ForegroundColor Yellow
}

Write-Host ""

# ==========================================
# 檢查 5: 路由配置
# ==========================================
Write-Host "檢查 5: 路由配置" -ForegroundColor Yellow

$routes = @(
    @{Name="認證-註冊"; Method="POST"; Path="/api/auth/register"; RequireAuth=$false},
    @{Name="認證-登入"; Method="POST"; Path="/api/auth/login"; RequireAuth=$false},
    @{Name="股票列表"; Method="GET"; Path="/api/stocks"; RequireAuth=$false},
    @{Name="選擇權列表"; Method="GET"; Path="/api/options"; RequireAuth=$false},
    @{Name="預測執行"; Method="POST"; Path="/api/predictions/run"; RequireAuth=$true}
)

foreach ($route in $routes) {
    $routeUrl = "$baseUrl$($route.Path)"
    $authStatus = if ($route.RequireAuth) { "[需認證]" } else { "[公開]" }

    try {
        if ($route.Method -eq "GET") {
            $testResponse = Invoke-RestMethod -Uri $routeUrl -Method GET -TimeoutSec 3 -ErrorAction SilentlyContinue
            Write-Host "  ✓ $($route.Name) $authStatus - 可用" -ForegroundColor Green
        } else {
            # POST 路由不實際執行,僅檢查是否存在
            Write-Host "  ? $($route.Name) $authStatus - 需實際測試" -ForegroundColor Gray
        }
    } catch {
        $statusCode = $_.Exception.Response.StatusCode.value__
        if ($statusCode -eq 401 -and $route.RequireAuth) {
            Write-Host "  ✓ $($route.Name) $authStatus - 需要認證(正常)" -ForegroundColor Green
        } elseif ($statusCode -eq 405) {
            Write-Host "  ? $($route.Name) $authStatus - 方法不支援(需檢查)" -ForegroundColor Yellow
        } else {
            Write-Host "  ✗ $($route.Name) $authStatus - 錯誤($statusCode)" -ForegroundColor Red
        }
    }
}

Write-Host ""

# ==========================================
# 檢查 6: Python 環境(預測模型)
# ==========================================
Write-Host "檢查 6: Python 環境" -ForegroundColor Yellow

try {
    $pythonVersion = python --version 2>&1
    Write-Host "✓ Python 已安裝: $pythonVersion" -ForegroundColor Green

    # 檢查必要的套件
    $packages = @("pandas", "numpy", "tensorflow", "scikit-learn")
    foreach ($package in $packages) {
        try {
            $checkPackage = python -c "import $package; print('OK')" 2>&1
            if ($checkPackage -eq "OK") {
                Write-Host "  ✓ $package 已安裝" -ForegroundColor Green
            } else {
                Write-Host "  ✗ $package 未安裝" -ForegroundColor Red
            }
        } catch {
            Write-Host "  ✗ $package 未安裝" -ForegroundColor Red
        }
    }
} catch {
    Write-Host "✗ Python 未安裝或不在 PATH 中" -ForegroundColor Red
    Write-Host "  預測功能需要 Python 環境" -ForegroundColor Yellow
}

Write-Host ""

# ==========================================
# 總結與建議
# ==========================================
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "診斷總結" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "📋 常見問題解決方案:" -ForegroundColor Yellow
Write-Host ""
Write-Host "1. Route [login] not defined 錯誤:" -ForegroundColor Cyan
Write-Host "   → 修改 app/Exceptions/Handler.php" -ForegroundColor Gray
Write-Host "   → 參考: README-FIX-GUIDE.md" -ForegroundColor Gray
Write-Host ""
Write-Host "2. 預測 API 401 錯誤:" -ForegroundColor Cyan
Write-Host "   → 使用 test-prediction-api.ps1 測試完整流程" -ForegroundColor Gray
Write-Host "   → 確保請求包含 Authorization: Bearer {token}" -ForegroundColor Gray
Write-Host ""
Write-Host "3. 資料庫連線失敗:" -ForegroundColor Cyan
Write-Host "   → 檢查 .env 設定" -ForegroundColor Gray
Write-Host "   → 執行: php artisan migrate" -ForegroundColor Gray
Write-Host ""
Write-Host "4. Python 套件缺失:" -ForegroundColor Cyan
Write-Host "   → 執行: pip install pandas numpy tensorflow scikit-learn" -ForegroundColor Gray
Write-Host ""

Write-Host "📚 相關文件:" -ForegroundColor Yellow
Write-Host "   - README-FIX-GUIDE.md (錯誤修正指南)" -ForegroundColor Gray
Write-Host "   - test-prediction-api.ps1 (完整測試腳本)" -ForegroundColor Gray
Write-Host "   - fix-handler.php (Handler 修正代碼)" -ForegroundColor Gray
Write-Host ""

Write-Host "========================================" -ForegroundColor Green
Write-Host "診斷完成" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
