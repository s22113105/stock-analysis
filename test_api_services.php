#!/usr/bin/env php
<?php
/**
 * API Service 測試工具
 * 用於驗證 TWSE 和 TAIFEX API 服務是否正常運作
 *
 * 使用方式: php test_api_services.php
 */

if (!file_exists('artisan')) {
    echo "❌ 錯誤: 請在 Laravel 專案根目錄執行此腳本\n";
    exit(1);
}

// 載入 Laravel
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\TwseApiService;
use App\Services\TaifexOpenApiService;
use Carbon\Carbon;

echo "========================================\n";
echo "🧪 API Service 測試工具\n";
echo "========================================\n";
echo "執行時間: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

$hasError = false;

// ===========================
// 測試 1: TWSE API Service
// ===========================
echo "📈 測試 TWSE API Service\n";
echo "========================================\n";

try {
    $twseApi = app(TwseApiService::class);
    echo "✅ TwseApiService 實例化成功\n\n";
    
    // 測試 1.1: 檢查最近有資料的日期
    echo "📅 測試 1.1: 尋找最近有資料的日期...\n";
    $latestDate = $twseApi->getLatestAvailableDate();
    
    if ($latestDate) {
        echo "   ✅ 找到有資料的日期: {$latestDate}\n";
    } else {
        echo "   ❌ 無法找到有資料的日期\n";
        $hasError = true;
    }
    echo "\n";
    
    // 測試 1.2: 取得所有股票當日行情
    if ($latestDate) {
        echo "📊 測試 1.2: 取得所有股票資料 ({$latestDate})...\n";
        $dateString = Carbon::parse($latestDate)->format('Ymd');
        $allStocks = $twseApi->getStockDayAll($dateString);
        
        if (!$allStocks->isEmpty()) {
            echo "   ✅ 成功取得 {$allStocks->count()} 筆股票資料\n";
            
            // 顯示前 3 筆資料
            echo "   📋 資料範例:\n";
            $samples = $allStocks->take(3);
            foreach ($samples as $stock) {
                $symbol = $stock['symbol'] ?? $stock['Code'] ?? 'N/A';
                $name = $stock['name'] ?? $stock['Name'] ?? 'N/A';
                $close = $stock['close'] ?? $stock['ClosingPrice'] ?? 'N/A';
                echo "      - {$symbol} {$name}: 收盤價 {$close}\n";
            }
        } else {
            echo "   ❌ 無法取得股票資料\n";
            $hasError = true;
        }
        echo "\n";
    }
    
    // 測試 1.3: 取得特定股票資料
    echo "🔍 測試 1.3: 取得特定股票資料 (2330 台積電)...\n";
    if ($latestDate) {
        $dateString = Carbon::parse($latestDate)->format('Ymd');
        $allData = $twseApi->getStockDayAll($dateString);
        
        // 尋找台積電
        $tsmc = $allData->firstWhere('symbol', '2330') ?? 
                $allData->firstWhere('Code', '2330');
        
        if ($tsmc) {
            echo "   ✅ 找到台積電資料\n";
            echo "   股票代碼: 2330\n";
            echo "   股票名稱: " . ($tsmc['name'] ?? $tsmc['Name'] ?? 'N/A') . "\n";
            echo "   開盤價: " . ($tsmc['open'] ?? $tsmc['OpeningPrice'] ?? 'N/A') . "\n";
            echo "   最高價: " . ($tsmc['high'] ?? $tsmc['HighestPrice'] ?? 'N/A') . "\n";
            echo "   最低價: " . ($tsmc['low'] ?? $tsmc['LowestPrice'] ?? 'N/A') . "\n";
            echo "   收盤價: " . ($tsmc['close'] ?? $tsmc['ClosingPrice'] ?? 'N/A') . "\n";
            echo "   成交量: " . number_format($tsmc['volume'] ?? $tsmc['TradeVolume'] ?? 0) . "\n";
        } else {
            echo "   ⚠️  找不到台積電資料\n";
        }
    }
    echo "\n";
    
    // 測試 1.4: 批次取得多檔股票
    echo "📦 測試 1.4: 批次取得多檔股票資料...\n";
    $testSymbols = ['2330', '2317', '2454'];
    $batchData = $twseApi->getBatchStockData($testSymbols);
    
    if (!$batchData->isEmpty()) {
        echo "   ✅ 成功取得 {$batchData->count()} 筆資料\n";
        foreach ($batchData as $stock) {
            $symbol = $stock['symbol'] ?? $stock['Code'] ?? 'N/A';
            $name = $stock['name'] ?? $stock['Name'] ?? 'N/A';
            echo "      - {$symbol} {$name}\n";
        }
    } else {
        echo "   ⚠️  無法批次取得股票資料\n";
    }
    
} catch (\Exception $e) {
    echo "❌ TWSE API 測試失敗\n";
    echo "錯誤訊息: " . $e->getMessage() . "\n";
    echo "錯誤位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
    $hasError = true;
}

echo "\n";
echo "========================================\n";

// ===========================
// 測試 2: TAIFEX API Service
// ===========================
echo "📈 測試 TAIFEX API Service\n";
echo "========================================\n";

try {
    $taifexApi = app(TaifexOpenApiService::class);
    echo "✅ TaifexOpenApiService 實例化成功\n\n";
    
    // 測試 2.1: 檢查資料可用性
    echo "🔍 測試 2.1: 檢查選擇權資料可用性...\n";
    $isAvailable = $taifexApi->checkDataAvailable();
    
    if ($isAvailable) {
        echo "   ✅ 選擇權資料可用\n";
    } else {
        echo "   ⚠️  選擇權資料目前不可用\n";
        echo "   可能原因:\n";
        echo "   - 非交易日\n";
        echo "   - 資料尚未更新（收盤後30-60分鐘）\n";
    }
    echo "\n";
    
    // 測試 2.2: 取得選擇權資料
    echo "📊 測試 2.2: 取得 TXO 選擇權資料...\n";
    $optionsData = $taifexApi->getDailyOptionsData();
    
    if (!$optionsData->isEmpty()) {
        echo "   ✅ 成功取得 {$optionsData->count()} 筆 TXO 資料\n";
        
        // 取得資料日期
        $dataDate = $taifexApi->getLatestDataDate();
        if ($dataDate) {
            echo "   📅 資料日期: {$dataDate}\n";
        }
        
        // 統計各類型選擇權
        $callCount = $optionsData->where('option_type', 'CALL')->count();
        $putCount = $optionsData->where('option_type', 'PUT')->count();
        echo "   📊 Call 選擇權: {$callCount} 筆\n";
        echo "   📊 Put 選擇權: {$putCount} 筆\n";
        
        // 顯示前 3 筆資料
        echo "   📋 資料範例:\n";
        $samples = $optionsData->take(3);
        foreach ($samples as $option) {
            echo "      - {$option['option_code']}\n";
            echo "        履約價: {$option['strike_price']}\n";
            echo "        類型: {$option['option_type']}\n";
            echo "        收盤價: {$option['close_price']}\n";
            echo "        成交量: {$option['volume_total']}\n";
        }
    } else {
        echo "   ⚠️  無法取得選擇權資料\n";
        echo "   可能需要等待資料更新\n";
    }
    echo "\n";
    
    // 測試 2.3: 取得特定履約價資料
    if (!$optionsData->isEmpty()) {
        echo "🔍 測試 2.3: 取得特定履約價資料...\n";
        
        // 找出中間的履約價
        $strikes = $optionsData->pluck('strike_price')->unique()->sort()->values();
        if ($strikes->count() > 0) {
            $middleStrike = $strikes->get(intval($strikes->count() / 2));
            
            $strikeOptions = $taifexApi->getOptionsByStrike($middleStrike);
            echo "   履約價 {$middleStrike} 的選擇權:\n";
            foreach ($strikeOptions as $option) {
                echo "      - {$option['option_type']}: 收盤價 {$option['close_price']}\n";
            }
        }
    }
    
} catch (\Exception $e) {
    echo "❌ TAIFEX API 測試失敗\n";
    echo "錯誤訊息: " . $e->getMessage() . "\n";
    echo "錯誤位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
    $hasError = true;
}

echo "\n";
echo "========================================\n";

// ===========================
// 測試總結
// ===========================
echo "📊 測試總結\n";
echo "========================================\n";

if ($hasError) {
    echo "❌ 測試發現問題\n\n";
    echo "建議修復步驟:\n";
    echo "1. 檢查網路連線是否正常\n";
    echo "2. 確認 .env 檔案中的 API 設定\n";
    echo "3. 檢查是否為交易日\n";
    echo "4. 等待 30-60 分鐘後重試（資料更新時間）\n";
    echo "5. 查看 storage/logs/laravel.log 取得詳細錯誤\n";
} else {
    echo "✅ 所有測試通過！\n\n";
    echo "API Service 運作正常，可以執行:\n";
    echo "1. php artisan crawler:stocks --date=" . ($latestDate ?? date('Y-m-d', strtotime('-3 days'))) . " --sync\n";
    echo "2. php artisan crawler:options-api\n";
    echo "3. ./fetch_real_data.sh (批次匯入)\n";
}

echo "\n";