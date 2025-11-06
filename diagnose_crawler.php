#!/usr/bin/env php
<?php
/**
 * 爬蟲診斷工具
 * 用途: 診斷為什麼爬蟲執行後沒有資料寫入資料庫
 *
 * 使用方式: php diagnose_crawler.php 2317
 */

if (!file_exists('artisan')) {
    echo "❌ 錯誤: 請在 Laravel 專案根目錄執行此腳本\n";
    exit(1);
}

$symbol = $argv[1] ?? '2317';
$date = $argv[2] ?? date('Y-m-d');

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

$twseApi = app(TwseApiService::class);
$carbon = Carbon::parse($date);

// 1. 檢查交易日
echo "1️⃣ 檢查是否為交易日...\n";
if ($carbon->isWeekend()) {
    echo "   ❌ {$date} 是週末 (" . $carbon->dayName . ")，不是交易日\n";
    echo "   💡 建議使用最近的交易日\n\n";
    exit(1);
} else {
    echo "   ✅ {$date} 是 " . $carbon->dayName . "，應該是交易日\n\n";
}

// 2. 測試 API 連線
echo "2️⃣ 測試 TWSE API 連線...\n";
try {
    $dateString = $carbon->format('Ymd');
    echo "   🌐 嘗試連接 TWSE API...\n";
    echo "   📅 使用日期格式: {$dateString}\n";

    $priceData = $twseApi->getStockDayAll($dateString);

    if ($priceData->isEmpty()) {
        echo "   ⚠️  警告: API 回傳空資料\n";
        echo "   可能原因:\n";
        echo "      - 該日期沒有交易資料（假日或尚未開盤）\n";
        echo "      - TWSE API 尚未更新該日期資料\n";
        echo "      - API 回應格式改變\n\n";

        // 嘗試取得最近一個有資料的日期
        echo "   🔍 嘗試取得最近有資料的日期...\n";
        for ($i = 1; $i <= 7; $i++) {
            $testDate = $carbon->copy()->subDays($i);
            if ($testDate->isWeekend()) continue;

            $testDateString = $testDate->format('Ymd');
            $testData = $twseApi->getStockDayAll($testDateString);

            if (!$testData->isEmpty()) {
                echo "   ✅ 找到最近有資料的日期: {$testDate->format('Y-m-d')}\n";
                echo "   💡 建議使用這個日期重新爬取\n\n";
                break;
            }
        }
    } else {
        echo "   ✅ API 連線成功\n";
        echo "   📊 取得 " . $priceData->count() . " 筆股票資料\n\n";

        // 檢查是否包含目標股票
        $targetStock = $priceData->firstWhere('symbol', $symbol);
        if ($targetStock) {
            echo "   ✅ 找到目標股票 {$symbol}\n";
            echo "   📊 股票名稱: " . $targetStock['name'] . "\n";
            echo "   💰 收盤價: " . $targetStock['close'] . "\n";
            echo "   📊 成交量: " . number_format($targetStock['volume']) . "\n\n";
        } else {
            echo "   ⚠️  警告: API 資料中找不到股票 {$symbol}\n";
            echo "   可能原因:\n";
            echo "      - 股票代碼錯誤\n";
            echo "      - 該股票當天沒有交易\n";
            echo "      - 該股票已下市\n\n";
        }
    }
} catch (\Exception $e) {
    echo "   ❌ API 連線失敗\n";
    echo "   錯誤訊息: " . $e->getMessage() . "\n";
    echo "   💡 請檢查網路連線和 API 設定\n\n";
    exit(1);
}

// 3. 檢查資料庫連線
echo "3️⃣ 檢查資料庫連線...\n";
try {
    \DB::connection()->getPdo();
    echo "   ✅ 資料庫連線正常\n\n";
} catch (\Exception $e) {
    echo "   ❌ 資料庫連線失敗\n";
    echo "   錯誤訊息: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 4. 檢查資料庫中的資料
echo "4️⃣ 檢查資料庫現有資料...\n";
$stock = Stock::where('symbol', $symbol)->first();

if (!$stock) {
    echo "   ⚠️  資料庫中不存在股票 {$symbol}\n";
    echo "   這是正常的，首次爬取會自動建立\n\n";
} else {
    echo "   ✅ 找到股票記錄\n";
    echo "   📌 ID: {$stock->id}\n";
    echo "   📌 名稱: {$stock->name}\n";

    $priceCount = $stock->prices()->count();
    echo "   📊 歷史價格筆數: {$priceCount}\n";

    if ($priceCount > 0) {
        $latestPrice = $stock->prices()->latest('trade_date')->first();
        echo "   📅 最新資料日期: {$latestPrice->trade_date}\n";
    }

    $todayPrice = $stock->prices()->where('trade_date', $date)->first();
    if ($todayPrice) {
        echo "   ✅ {$date} 的資料已存在\n";
        echo "   💰 收盤價: {$todayPrice->close}\n";
    } else {
        echo "   ⚠️  {$date} 的資料不存在\n";
    }
    echo "\n";
}

// 5. 檢查 Log 檔案
echo "5️⃣ 檢查最近的 Log 記錄...\n";
$logFile = storage_path('logs/laravel.log');
if (file_exists($logFile)) {
    $lines = file($logFile);
    $recentLogs = array_slice($lines, -50);

    $relevantLogs = array_filter($recentLogs, function ($line) use ($symbol) {
        return strpos($line, '爬蟲') !== false ||
            strpos($line, 'crawler') !== false ||
            strpos($line, $symbol) !== false;
    });

    if (empty($relevantLogs)) {
        echo "   ⚠️  最近 50 行 log 中沒有爬蟲相關記錄\n\n";
    } else {
        echo "   📋 找到相關 log:\n";
        foreach (array_slice($relevantLogs, -5) as $log) {
            echo "   " . trim($log) . "\n";
        }
        echo "\n";
    }
} else {
    echo "   ⚠️  找不到 log 檔案\n\n";
}

// 6. 模擬爬蟲執行
echo "6️⃣ 模擬爬蟲執行流程...\n";
echo "   📝 以下是爬蟲會執行的步驟:\n\n";

try {
    \DB::beginTransaction();

    // 步驟 1: 取得 API 資料
    echo "   步驟 1: 從 TWSE API 取得資料\n";
    $priceData = $twseApi->getStockDayAll($dateString);
    $targetData = $priceData->firstWhere('symbol', $symbol);

    if (!$targetData) {
        echo "      ❌ API 中沒有股票 {$symbol} 的資料\n";
        echo "      這就是為什麼沒有資料寫入！\n\n";
        \DB::rollBack();

        echo "========================================\n";
        echo "🎯 問題診斷結果\n";
        echo "========================================\n";
        echo "問題: TWSE API 在 {$date} 沒有股票 {$symbol} 的資料\n\n";
        echo "可能原因:\n";
        echo "1. 該日期不是交易日（已排除）\n";
        echo "2. 股票代碼錯誤\n";
        echo "3. 該股票當天停止交易\n";
        echo "4. TWSE API 尚未更新該日期資料\n";
        echo "5. API 回應格式改變\n\n";
        echo "解決方案:\n";
        echo "1. 確認股票代碼是否正確\n";
        echo "2. 使用較早的日期重試: --date=2025-11-04\n";
        echo "3. 直接測試 API: curl 'https://openapi.twse.com.tw/exchangeReport/STOCK_DAY_ALL?response=json&date={$dateString}'\n";
        echo "4. 查看完整 log: tail -f storage/logs/laravel.log\n\n";
        exit(0);
    }

    echo "      ✅ 找到資料: {$targetData['name']}\n";

    // 步驟 2: 建立或更新 Stock
    echo "   步驟 2: 建立或更新股票基本資料\n";
    $stock = Stock::updateOrCreate(
        ['symbol' => $symbol],
        [
            'name' => $targetData['name'],
            'is_active' => true
        ]
    );
    echo "      ✅ 股票記錄已更新 (ID: {$stock->id})\n";

    // 步驟 3: 建立或更新 StockPrice
    echo "   步驟 3: 建立或更新價格資料\n";
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
            'turnover' => $targetData['turnover'],
            'change' => $targetData['change'],
            'change_percent' => 0,
        ]
    );
    echo "      ✅ 價格記錄已寫入\n";
    echo "      💰 收盤價: {$priceRecord->close}\n";
    echo "      📊 成交量: " . number_format($priceRecord->volume) . "\n";

    \DB::commit();
    echo "      ✅ Transaction 已提交\n\n";

    echo "========================================\n";
    echo "✅ 診斷完成 - 模擬執行成功！\n";
    echo "========================================\n";
    echo "資料已成功寫入資料庫\n";
    echo "Stock ID: {$stock->id}\n";
    echo "Price ID: {$priceRecord->id}\n\n";
    echo "現在執行實際爬蟲應該會成功\n";
    echo "執行: php artisan crawler:stocks --symbol={$symbol} --date={$date} --sync\n\n";
} catch (\Exception $e) {
    \DB::rollBack();
    echo "   ❌ 模擬執行失敗\n";
    echo "   錯誤: " . $e->getMessage() . "\n";
    echo "   檔案: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
}
