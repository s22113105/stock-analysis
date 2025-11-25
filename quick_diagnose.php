<?php

/**
 * 快速診斷腳本 - 檢查選擇權資料問題
 * 在專案根目錄執行: php quick_diagnose.php
 */

// 載入 Laravel
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\n=====================================\n";
echo "     選擇權資料快速診斷\n";
echo "=====================================\n\n";

// 1. 檢查基本資料表
echo "【步驟1】檢查資料表是否存在\n";
echo "-----------------------------------\n";

try {
    // 檢查 options 表
    $hasOptionsTable = DB::getSchemaBuilder()->hasTable('options');
    echo "✓ options 表: " . ($hasOptionsTable ? "存在" : "不存在") . "\n";

    // 檢查 option_prices 表
    $hasOptionPricesTable = DB::getSchemaBuilder()->hasTable('option_prices');
    echo "✓ option_prices 表: " . ($hasOptionPricesTable ? "存在" : "不存在") . "\n\n";
} catch (\Exception $e) {
    echo "✗ 資料庫連線失敗: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 2. 檢查資料數量
echo "【步驟2】檢查資料數量\n";
echo "-----------------------------------\n";

$optionCount = DB::table('options')->count();
echo "✓ options 總筆數: {$optionCount}\n";

$txoCount = DB::table('options')->where('underlying', 'TXO')->count();
echo "✓ TXO 選擇權數量: {$txoCount}\n";

$priceCount = DB::table('option_prices')->count();
echo "✓ option_prices 總筆數: {$priceCount}\n\n";

// 3. 檢查具體的 TXO 資料
echo "【步驟3】檢查 TXO 選擇權詳細資料\n";
echo "-----------------------------------\n";

if ($txoCount > 0) {
    // 列出前5筆 TXO 資料
    $samples = DB::table('options')
        ->where('underlying', 'TXO')
        ->limit(5)
        ->get();

    echo "前 5 筆 TXO 資料範例:\n";
    foreach ($samples as $option) {
        echo "  - ID: {$option->id}, ";
        echo "代碼: {$option->option_code}, ";
        echo "履約價: {$option->strike_price}, ";
        echo "類型: {$option->option_type}, ";
        echo "到期日: {$option->expiry_date}\n";
    }
    echo "\n";

    // 檢查有價格的 TXO
    $txoWithPrices = DB::table('options as o')
        ->join('option_prices as p', 'o.id', '=', 'p.option_id')
        ->where('o.underlying', 'TXO')
        ->count();
    echo "✓ 有價格資料的 TXO 選擇權: {$txoWithPrices}\n";

    // 取得最新交易日
    $latestDate = DB::table('option_prices')->max('trade_date');
    echo "✓ 最新交易日期: " . ($latestDate ?? "無") . "\n\n";
} else {
    echo "⚠ 沒有找到 TXO 選擇權資料\n\n";
}

// 4. 測試 API 查詢
echo "【步驟4】測試 OptionChainService\n";
echo "-----------------------------------\n";

try {
    $service = app(\App\Services\OptionChainService::class);
    $result = $service->getOptionChain();

    if (isset($result['success']) && $result['success']) {
        echo "✓ Service 執行成功\n";
        echo "  - 鏈資料筆數: " . count($result['chain'] ?? []) . "\n";
        echo "  - 可用到期日: " . implode(', ', $result['available_expiries'] ?? []) . "\n";
    } else {
        echo "✗ Service 執行失敗\n";
        echo "  錯誤訊息: " . ($result['message'] ?? '未知錯誤') . "\n";
    }
} catch (\Exception $e) {
    echo "✗ Service 發生例外: " . $e->getMessage() . "\n";
}

echo "\n";

// 5. 建議解決方案
echo "【步驟5】問題診斷與建議\n";
echo "-----------------------------------\n";

if ($optionCount == 0) {
    echo "❌ 問題: options 表是空的\n";
    echo "💡 解決方案:\n";
    echo "   1. 執行選擇權爬蟲: php artisan crawler:options\n";
    echo "   2. 或手動匯入測試資料\n";
} elseif ($txoCount == 0) {
    echo "❌ 問題: 沒有 TXO 選擇權資料\n";
    echo "💡 解決方案:\n";
    echo "   1. 檢查爬蟲設定是否正確設定為爬取 TXO\n";
    echo "   2. 手動插入 TXO 測試資料\n";
} elseif ($priceCount == 0) {
    echo "❌ 問題: option_prices 表是空的\n";
    echo "💡 解決方案:\n";
    echo "   1. 執行價格爬蟲: php artisan crawler:option-prices\n";
} else {
    echo "✓ 資料庫有基本資料\n";
    echo "💡 可能是查詢邏輯或 API 路由問題\n";
}

echo "\n=====================================\n";
echo "     診斷完成\n";
echo "=====================================\n\n";
