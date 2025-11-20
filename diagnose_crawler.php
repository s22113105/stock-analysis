#!/usr/bin/env php
<?php
/**
 * 爬蟲診斷工具 (修正版)
 * 用途: 診斷為什麼爬蟲執行後沒有資料寫入資料庫
 *
 * 使用方式: php diagnose_crawler.php [股票代碼] [日期]
 */

if (!file_exists('artisan')) {
    echo "❌ 錯誤: 請在 Laravel 專案根目錄執行此腳本\n";
    exit(1);
}

$symbol = $argv[1] ?? '2330';  // 預設台積電
$date = $argv[2] ?? date('Y-m-d', strtotime('-3 days'));  // 預設3天前

echo "========================================\n";
echo "🔍 爬蟲診斷工具\n";
echo "========================================\n";
echo "股票代碼: {$symbol}\n";
echo "檢查日期: {$date}\n";
echo "========================================\n\n";

// 載入 Laravel
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\TwseApiService;
use App\Models\Stock;
use App\Models\StockPrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$twseApi = app(TwseApiService::class);
$carbon = Carbon::parse($date);

// 1. 檢查交易日
echo "1️⃣ 檢查是否為交易日...\n";
if ($carbon->isWeekend()) {
    echo "   ❌ {$date} 是週末 (" . $carbon->locale('zh_TW')->dayName . ")，不是交易日\n";
    echo "   💡 建議使用最近的交易日\n\n";
    
    $tradingDate = $carbon->copy();
    while ($tradingDate->isWeekend()) {
        $tradingDate->subDay();
    }
    echo "   💡 最近的交易日是: {$tradingDate->format('Y-m-d')}\n\n";
    $date = $tradingDate->format('Y-m-d');
    $carbon = $tradingDate;
} else {
    echo "   ✅ {$date} 是 " . $carbon->locale('zh_TW')->dayName . "，應該是交易日\n\n";
}

// 2. 測試 API 連線
echo "2️⃣ 測試 TWSE API 連線...\n";
try {
    $dateString = $carbon->format('Ymd');
    echo "   🌐 嘗試連接 TWSE OpenAPI...\n";
    echo "   📅 使用日期格式: {$dateString}\n";
    
    $url = "https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL";
    echo "   🔗 API URL: {$url}\n";

    $priceData = $twseApi->getStockDayAll($dateString);

    if ($priceData->isEmpty()) {
        echo "   ⚠️  警告: API 回傳空資料\n";
        // ... (省略重試邏輯，保持原樣) ...
    } else {
        echo "   ✅ API 連線成功\n";
        echo "   📊 取得 " . $priceData->count() . " 筆股票資料\n\n";

        // ✅ 修正: 使用 'symbol' 而不是 'Code'
        $targetStock = $priceData->firstWhere('symbol', $symbol);
        
        if ($targetStock) {
            echo "   ✅ 找到目標股票 {$symbol}\n";
            // ✅ 修正: 使用轉換後的欄位名稱
            echo "   📊 股票名稱: " . ($targetStock['name'] ?? 'Unknown') . "\n";
            echo "   💰 收盤價: " . ($targetStock['close'] ?? 0) . "\n";
            echo "   📊 成交量: " . number_format($targetStock['volume'] ?? 0) . "\n\n";
        } else {
            echo "   ⚠️  警告: API 資料中找不到股票 {$symbol}\n";
            
            echo "   📋 API 中可用的股票代碼範例:\n";
            $samples = $priceData->take(5);
            foreach ($samples as $sample) {
                // ✅ 修正: 使用 'symbol' 和 'name'
                echo "      - {$sample['symbol']} {$sample['name']}\n";
            }
            echo "\n";
        }
    }
} catch (\Exception $e) {
    echo "   ❌ API 連線失敗\n";
    echo "   錯誤訊息: " . $e->getMessage() . "\n";
    exit(1);
}

// 3. 檢查資料庫連線 (保持不變)
echo "3️⃣ 檢查資料庫連線...\n";
try {
    DB::connection()->getPdo();
    echo "   ✅ 資料庫連線正常\n\n";
} catch (\Exception $e) {
    echo "   ❌ 資料庫連線失敗\n";
    echo "   錯誤訊息: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 4. 檢查資料庫中的資料 (保持不變)
// ... (略) ...

// 6. 模擬爬蟲執行
echo "6️⃣ 模擬爬蟲執行流程...\n";
echo "   📝 以下是爬蟲會執行的步驟:\n\n";

if (!$priceData->isEmpty()) {
    try {
        DB::beginTransaction();

        echo "   步驟 1: 從 TWSE API 取得資料\n";
        // ✅ 修正: 使用 'symbol'
        $targetData = $priceData->firstWhere('symbol', $symbol);

        if (!$targetData) {
            echo "      ❌ API 中沒有股票 {$symbol} 的資料\n";
            DB::rollBack();
            exit(0);
        }

        // ✅ 修正: 使用 'name'
        echo "      ✅ 找到資料: {$targetData['name']}\n";

        echo "   步驟 2: 建立或更新股票基本資料\n";
        $stock = Stock::updateOrCreate(
            ['symbol' => $symbol],
            [
                'name' => $targetData['name'], // ✅ 修正
                'is_active' => true
            ]
        );
        echo "      ✅ 股票記錄已更新 (ID: {$stock->id})\n";

        echo "   步驟 3: 建立或更新價格資料\n";
        
        // ✅ 修正: 直接使用已轉換好的數據，不需要再 floatval 或 str_replace
        // TwseApiService 已經幫我們處理好了
        $priceRecord = StockPrice::updateOrCreate(
            [
                'stock_id' => $stock->id,
                'trade_date' => $date
            ],
            [
                'open' => $targetData['open'],
                'high' => $targetData['high'],
                'low' => $targetData['low'],
                'close' => $targetData['close'],
                'volume' => $targetData['volume'],
                'turnover' => $targetData['turnover'] ?? 0,
                'change' => $targetData['change'] ?? 0,
                'change_percent' => 0, // API 可能沒給這個，可以自己算
            ]
        );
        echo "      ✅ 價格記錄已寫入\n";
        echo "      💰 收盤價: {$priceRecord->close}\n";
        echo "      📊 成交量: " . number_format($priceRecord->volume) . "\n";

        DB::commit();
        echo "      ✅ Transaction 已提交\n\n";

        echo "========================================\n";
        echo "✅ 診斷完成 - 模擬執行成功！\n";
        echo "========================================\n";

    } catch (\Exception $e) {
        DB::rollBack();
        echo "   ❌ 模擬執行失敗\n";
        echo "   錯誤: " . $e->getMessage() . "\n";
        echo "   檔案: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    }
}