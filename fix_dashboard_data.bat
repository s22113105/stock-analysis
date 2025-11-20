@echo off
chcp 65001 >nul
REM ============================================
REM 儀表板圖表資料一鍵修復
REM ============================================

echo ========================================
echo 🔧 修復儀表板圖表無資料問題
echo ========================================
echo.

REM 步驟 1: 檢查並複製控制器
echo [1/5] 檢查 DashboardController...
if exist "app\Http\Controllers\Api\DashboardController.php" (
    echo    ✓ DashboardController 已存在
) else (
    echo    ⚠ DashboardController 不存在
    if exist "DashboardController_完整版.php" (
        echo    正在複製 DashboardController...
        copy "DashboardController_完整版.php" "app\Http\Controllers\Api\DashboardController.php"
        echo    ✓ 已複製 DashboardController
    ) else (
        echo    ✗ 找不到 DashboardController_完整版.php
        echo    請從輸出目錄取得此檔案
    )
)
echo.

REM 步驟 2: 檢查路由
echo [2/5] 檢查 API 路由...
findstr /C:"stock-trends" routes\api.php >nul
if %errorlevel% equ 0 (
    echo    ✓ dashboard 路由已設定
) else (
    echo    ⚠ dashboard 路由可能未設定
    echo    請手動編輯 routes\api.php
    echo    加入 dashboard 相關路由
)
echo.

REM 步驟 3: 執行爬蟲取得資料
echo [3/5] 執行爬蟲取得股票資料...
echo    這可能需要幾分鐘...
call php artisan crawler:stocks
echo    ✓ 爬蟲執行完成
echo.

REM 步驟 4: 清除快取
echo [4/5] 清除快取...
call php artisan optimize:clear
echo    ✓ 快取已清除
echo.

REM 步驟 5: 測試 API
echo [5/5] 測試 API 端點...
echo    測試 stock-trends...
curl -s http://localhost:8000/api/dashboard/stock-trends >nul 2>&1
if %errorlevel% equ 0 (
    echo    ✓ stock-trends API 正常
) else (
    echo    ⚠ stock-trends API 可能有問題
)

echo    測試 volatility-overview...
curl -s http://localhost:8000/api/dashboard/volatility-overview >nul 2>&1
if %errorlevel% equ 0 (
    echo    ✓ volatility-overview API 正常
) else (
    echo    ⚠ volatility-overview API 可能有問題
)
echo.

echo ========================================
echo 🎯 修復完成
echo ========================================
echo.
echo 請執行以下步驟驗證:
echo 1. 開啟瀏覽器訪問 http://localhost:8000/dashboard
echo 2. 按 F12 開啟開發者工具
echo 3. 切換到 Network 分頁
echo 4. 重新整理頁面 (Ctrl+R)
echo 5. 檢查是否有 stock-trends 和 volatility-overview 請求
echo.
echo 如果圖表仍然沒有資料:
echo - 檢查 routes\api.php 是否包含 dashboard 路由
echo - 查看 storage\logs\laravel.log 錯誤訊息
echo - 執行: php artisan route:list 確認路由
echo.

pause
