@echo off
REM ============================================================
REM 清除測試資料並批次抓取股票資料 (Windows 批次檔版本)
REM 
REM 使用方式: 直接雙擊此檔案,或在命令提示字元執行
REM ============================================================

chcp 65001 > nul
setlocal EnableDelayedExpansion

echo ========================================
echo 清除測試資料並抓取真實資料
echo ========================================
echo.

REM ============ 設定區 ============

REM 股票代碼列表(可自行修改)
set STOCKS=2330 2317 2454 2308 2303 2882 2881 2891 2892 2886

REM 日期列表(最近的交易日,可自行修改)
set DATES=2025-11-15 2025-11-14 2025-11-13 2025-11-12 2025-11-11 2025-11-08 2025-11-07

REM ================================

echo 設定:
echo   股票代碼: %STOCKS%
echo   日期數量: 7 天
echo.

set /p CONFIRM="確定要繼續嗎? (y/n): "
if /i not "%CONFIRM%"=="y" (
    echo 已取消操作
    pause
    exit /b 0
)

echo.

REM ============ 步驟 1: 清除測試資料 ============

echo ========================================
echo 步驟 1/3: 清除測試資料
echo ========================================
echo.

php artisan data:reset-and-fetch --keep-users --skip-confirm

if errorlevel 1 (
    echo.
    echo [錯誤] 清除資料失敗
    pause
    exit /b 1
)

echo.
echo [成功] 資料清除完成
echo.

REM ============ 步驟 2: 抓取股票資料 ============

echo ========================================
echo 步驟 2/3: 抓取股票資料
echo ========================================
echo.

set TOTAL=0
set SUCCESS=0
set FAIL=0

for %%s in (%STOCKS%) do (
    echo 抓取股票: %%s
    
    for %%d in (%DATES%) do (
        set /a TOTAL+=1
        echo   [!TOTAL!] %%s - %%d ... 
        
        php artisan crawler:stocks --symbol=%%s --date=%%d --sync > nul 2>&1
        
        if errorlevel 0 (
            set /a SUCCESS+=1
            echo       ^=^> 成功
        ) else (
            set /a FAIL+=1
            echo       ^=^> 失敗
        )
        
        REM 避免 API 請求過於頻繁
        timeout /t 1 /nobreak > nul
    )
    
    echo.
)

echo.
echo [完成] 股票資料抓取完成
echo   成功: !SUCCESS! 個任務
if !FAIL! gtr 0 (
    echo   失敗: !FAIL! 個任務
)
echo.

REM ============ 步驟 3: 驗證資料 ============

echo ========================================
echo 步驟 3/3: 驗證資料完整性
echo ========================================
echo.

php artisan data:validate --report

echo.

REM ============ 完成 ============

echo ========================================
echo 所有操作完成!
echo ========================================
echo.

echo 資料統計:
echo.

REM 簡單統計(使用 tinker)
echo 正在查詢資料庫...
php artisan tinker --execute="echo 'Stock count: ' . App\Models\Stock::count() . PHP_EOL; echo 'Price count: ' . App\Models\StockPrice::count() . PHP_EOL;"

echo.
echo 建議的下一步:
echo   1. 檢查資料: php artisan tinker
echo   2. 測試 API: http://localhost:8000/api/stocks
echo   3. 開啟前端: npm run dev
echo.
echo 系統已準備就緒!
echo.

pause