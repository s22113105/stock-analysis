<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "==========================================\n";
echo "   TXO 資料診斷\n";
echo "==========================================\n\n";

// 檢查 1: 總交易日數
$stats = DB::selectOne("
    SELECT
        COUNT(DISTINCT trade_date) as trading_days,
        MIN(trade_date) as first_date,
        MAX(trade_date) as last_date,
        COUNT(*) as total_records
    FROM option_prices
    WHERE option_id IN (
        SELECT id FROM options WHERE underlying = 'TXO'
    )
    AND close IS NOT NULL AND close > 0
");

echo "📊 資料庫統計:\n";
echo "   交易日數: {$stats->trading_days}\n";
echo "   日期範圍: {$stats->first_date} ~ {$stats->last_date}\n";
echo "   總記錄數: {$stats->total_records}\n\n";

if ($stats->trading_days < 100) {
    echo "❌ 警告: 交易日數不足 100 天!\n";
    echo "   建議執行歷史資料回補\n\n";
}

// 檢查 2: is_active 影響
$activeCount = DB::selectOne("
    SELECT COUNT(DISTINCT trade_date) as days
    FROM option_prices
    WHERE option_id IN (
        SELECT id FROM options WHERE underlying = 'TXO' AND is_active = 1
    )
    AND close IS NOT NULL AND close > 0
");

$allCount = DB::selectOne("
    SELECT COUNT(DISTINCT trade_date) as days
    FROM option_prices
    WHERE option_id IN (
        SELECT id FROM options WHERE underlying = 'TXO'
    )
    AND close IS NOT NULL AND close > 0
");

echo "🔍 is_active 影響:\n";
echo "   is_active=1: {$activeCount->days} 天\n";
echo "   全部契約:    {$allCount->days} 天\n";

if ($activeCount->days < $allCount->days) {
    echo "   ⚠️  is_active 限制減少了 " . ($allCount->days - $activeCount->days) . " 天資料\n";
    echo "   建議: 移除 is_active 條件\n";
}

echo "\n";

// 檢查 3: 測試市場指數計算
echo "🧪 測試市場指數計算:\n";

$indexData = DB::select("
    SELECT
        trade_date as date,
        COUNT(*) as contract_count,
        SUM(close * volume) / NULLIF(SUM(volume), 0) as index_value
    FROM option_prices
    WHERE option_id IN (
        SELECT id FROM options WHERE underlying = 'TXO'
    )
    AND close IS NOT NULL AND close > 0
    AND volume IS NOT NULL AND volume > 0
    GROUP BY trade_date
    ORDER BY trade_date DESC
    LIMIT 10
");

echo "   最近 10 天的指數:\n";
foreach ($indexData as $row) {
    echo sprintf(
        "   %s | 契約數: %3d | 指數: %8.2f\n",
        $row->date,
        $row->contract_count,
        $row->index_value
    );
}

echo "\n==========================================\n";
