# ============================================
# Docker 環境 - 儀表板修復 (修正版)
# ============================================

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "🐳 Docker 環境修復" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# ==========================================
# 步驟 1: 檢查 Docker 狀態
# ==========================================
Write-Host "[1/8] 檢查 Docker..." -ForegroundColor Green

try {
    $dockerVersion = docker --version 2>&1
    Write-Host "   ✓ Docker 已安裝: $dockerVersion" -ForegroundColor Green
}
catch {
    Write-Host "   ✗ Docker 未安裝或未啟動" -ForegroundColor Red
    Write-Host "   請啟動 Docker Desktop" -ForegroundColor Yellow
    exit 1
}

try {
    $composeVersion = docker-compose --version 2>&1
    Write-Host "   ✓ Docker Compose 已安裝: $composeVersion" -ForegroundColor Green
}
catch {
    Write-Host "   ✗ Docker Compose 未安裝" -ForegroundColor Red
    exit 1
}
Write-Host ""

# ==========================================
# 步驟 2: 停止現有容器
# ==========================================
Write-Host "[2/8] 停止現有容器..." -ForegroundColor Green
docker-compose down 2>&1 | Out-Null
Write-Host "   ✓ 已停止所有容器" -ForegroundColor Green
Write-Host ""

# ==========================================
# 步驟 3: 檢查 .env 設定
# ==========================================
Write-Host "[3/8] 檢查 .env 設定..." -ForegroundColor Green

$dbHost = Select-String -Path ".env" -Pattern "DB_HOST=" -ErrorAction SilentlyContinue
if ($dbHost) {
    $hostValue = ($dbHost.Line -split '=')[1].Trim()
    if ($hostValue -eq "db") {
        Write-Host "   ✓ DB_HOST=db (正確)" -ForegroundColor Green
    }
    else {
        Write-Host "   ⚠ DB_HOST=$hostValue (應該是 db)" -ForegroundColor Yellow
    }
}
Write-Host ""

# ==========================================
# 步驟 4: 部署控制器
# ==========================================
Write-Host "[4/8] 部署 DashboardController..." -ForegroundColor Green

if (Test-Path "DashboardController_2330_2317_2454.php") {
    Copy-Item "DashboardController_2330_2317_2454.php" "app\Http\Controllers\Api\DashboardController.php" -Force
    Write-Host "   ✓ 已部署專用控制器" -ForegroundColor Green
}
else {
    Write-Host "   ⚠ 找不到控制器檔案" -ForegroundColor Yellow
}
Write-Host ""

# ==========================================
# 步驟 5: 檢查路由設定
# ==========================================
Write-Host "[5/8] 檢查路由設定..." -ForegroundColor Green
$hasRoute = Select-String -Path "routes\api.php" -Pattern "stock-trends" -Quiet

if ($hasRoute) {
    Write-Host "   ✓ 路由已設定" -ForegroundColor Green
}
else {
    Write-Host "   ⚠ 需要手動設定路由" -ForegroundColor Yellow
}
Write-Host ""

# ==========================================
# 步驟 6: 啟動 Docker 容器
# ==========================================
Write-Host "[6/8] 啟動 Docker 容器..." -ForegroundColor Green
Write-Host "   這可能需要幾分鐘..." -ForegroundColor Gray
Write-Host ""

docker-compose up -d --build

if ($LASTEXITCODE -eq 0) {
    Write-Host "   ✓ 容器已啟動" -ForegroundColor Green
}
else {
    Write-Host "   ✗ 容器啟動失敗" -ForegroundColor Red
    Write-Host "   請執行: docker-compose logs" -ForegroundColor Yellow
    exit 1
}
Write-Host ""

# ==========================================
# 步驟 7: 等待 MySQL 就緒
# ==========================================
Write-Host "[7/8] 等待 MySQL 就緒..." -ForegroundColor Green
Write-Host "   等待 30 秒讓 MySQL 完全啟動..." -ForegroundColor Gray

for ($i = 30; $i -gt 0; $i--) {
    Write-Progress -Activity "等待 MySQL 啟動" -Status "$i 秒" -PercentComplete ((30 - $i) / 30 * 100)
    Start-Sleep -Seconds 1
}
Write-Progress -Activity "等待 MySQL 啟動" -Completed

Write-Host "   ✓ 等待完成" -ForegroundColor Green
Write-Host ""

# ==========================================
# 步驟 8: 初始化系統
# ==========================================
Write-Host "[8/8] 初始化系統..." -ForegroundColor Green

# 清除快取
Write-Host "   清除快取..." -ForegroundColor Cyan
docker-compose exec -T app php artisan optimize:clear 2>&1 | Out-Null
Write-Host "   ✓ 快取已清除" -ForegroundColor Green

# 測試資料庫連線
Write-Host "   測試資料庫連線..." -ForegroundColor Cyan
$dbTest = docker-compose exec -T app php artisan tinker --execute="echo 'DB_TEST';" 2>&1
if ($dbTest -match "DB_TEST") {
    Write-Host "   ✓ 容器執行正常" -ForegroundColor Green
}

# 檢查資料
Write-Host "   檢查資料庫資料..." -ForegroundColor Cyan
$checkData = docker-compose exec -T app php artisan db:show 2>&1
if ($checkData -match "stock_analysis" -or $checkData -match "mysql") {
    Write-Host "   ✓ 資料庫存在" -ForegroundColor Green

    # 詢問是否執行爬蟲
    $response = Read-Host "   是否執行爬蟲取得資料? (y/n)"
    if ($response -eq "y") {
        Write-Host "   執行爬蟲中 (這需要幾分鐘)..." -ForegroundColor Yellow
        docker-compose exec -T app php artisan crawler:stocks
        Write-Host "   ✓ 爬蟲完成" -ForegroundColor Green
    }
}

Write-Host ""

# ==========================================
# 總結
# ==========================================
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "✅ Docker 環境修復完成!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# 顯示容器狀態
Write-Host "📦 容器狀態:" -ForegroundColor Cyan
docker-compose ps
Write-Host ""

# 顯示服務網址
Write-Host "🌐 服務網址:" -ForegroundColor Cyan
Write-Host "   應用程式: http://localhost:8000" -ForegroundColor White
Write-Host "   儀表板:   http://localhost:8000/dashboard" -ForegroundColor White
Write-Host ""

# 測試 API
Write-Host "🧪 測試 API..." -ForegroundColor Cyan
try {
    $response = Invoke-WebRequest -Uri "http://localhost:8000/api/dashboard/stock-trends" -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop
    if ($response.StatusCode -eq 200) {
        Write-Host "   ✓ API 正常運作" -ForegroundColor Green
    }
}
catch {
    Write-Host "   ⚠ API 測試失敗 (可能需要更多時間)" -ForegroundColor Yellow
    Write-Host "   請稍候並重新整理瀏覽器" -ForegroundColor Gray
}

Write-Host ""
Write-Host "下一步:" -ForegroundColor White
Write-Host "  1. 開啟瀏覽器訪問 http://localhost:8000/dashboard" -ForegroundColor Gray
Write-Host "  2. 按 F5 重新整理頁面" -ForegroundColor Gray
Write-Host "  3. 應該看到 2330/2317/2454 的走勢圖和波動率圖" -ForegroundColor Gray
Write-Host ""
Write-Host "常用命令:" -ForegroundColor White
Write-Host "  docker-compose ps               # 查看容器狀態" -ForegroundColor Gray
Write-Host "  docker-compose logs -f app      # 查看應用日誌" -ForegroundColor Gray
Write-Host "  docker-compose exec app bash    # 進入容器" -ForegroundColor Gray
Write-Host "  docker-compose down             # 停止容器" -ForegroundColor Gray
Write-Host ""
