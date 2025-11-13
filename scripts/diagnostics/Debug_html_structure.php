#!/usr/bin/env php
<?php
/**
 * 診斷 TAIFEX HTML 結構
 * 用於找出正確的表格索引
 */

if (!file_exists('artisan')) {
    echo "❌ 錯誤: 請在 Laravel 專案根目錄執行此腳本\n";
    exit(1);
}

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Carbon\Carbon;

$date = $argv[1] ?? '2025-11-05';
$queryDate = Carbon::parse($date)->format('Y/m/d');

echo "========================================\n";
echo "🔍 TAIFEX HTML 結構診斷\n";
echo "========================================\n";
echo "日期: {$date}\n";
echo "========================================\n\n";

// 發送請求
$url = 'https://www.taifex.com.tw/cht/3/optDailyMarketReport';
$payload = [
    'queryDate' => $queryDate,
    'commodity_id' => 'TXO',
    'MarketCode' => '0',
];

echo "1️⃣ 發送請求...\n";
$response = Http::timeout(30)->asForm()->post($url, $payload);

if (!$response->successful()) {
    echo "❌ 請求失敗: " . $response->status() . "\n";
    exit(1);
}

$html = $response->body();
echo "✅ 收到回應: " . number_format(strlen($html)) . " bytes\n\n";

// 儲存 HTML
$htmlPath = storage_path('app/debug_taifex.html');
file_put_contents($htmlPath, $html);
echo "📄 已儲存至: {$htmlPath}\n\n";

// 解析 HTML
echo "2️⃣ 解析表格結構...\n";
$crawler = new Crawler($html);
$tables = $crawler->filter('table');

echo "找到表格總數: " . $tables->count() . "\n\n";

// 檢查每個表格
$tables->each(function (Crawler $table, $index) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📊 表格 #{$index}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    $rows = $table->filter('tr');
    $rowCount = $rows->count();
    echo "總行數: {$rowCount}\n";

    if ($rowCount == 0) {
        echo "⚠️  空表格\n\n";
        return;
    }

    // 顯示前 10 行
    echo "\n前 10 行內容:\n";
    $rows->each(function (Crawler $row, $rowIndex) use ($rowCount) {
        if ($rowIndex >= 10) return;

        $cells = $row->filter('th, td');
        $cellCount = $cells->count();

        echo "  行 {$rowIndex} ({$cellCount} 個欄位): ";

        // 顯示前 5 個 cell
        $preview = [];
        $cells->each(function (Crawler $cell, $cellIndex) use (&$preview) {
            if ($cellIndex < 5) {
                $text = trim($cell->text());
                $text = mb_substr($text, 0, 15); // 限制長度
                $preview[] = $text;
            }
        });

        echo implode(' | ', $preview);

        // 檢查關鍵字
        $fullText = trim($row->text());
        $keywords = ['契約', '履約價', '買賣權', 'TXO', '到期月份'];
        $found = [];
        foreach ($keywords as $keyword) {
            if (mb_strpos($fullText, $keyword) !== false) {
                $found[] = $keyword;
            }
        }

        if (!empty($found)) {
            echo " ✨ [包含: " . implode(', ', $found) . "]";
        }

        echo "\n";
    });

    // 檢查是否包含資料
    $hasData = false;
    $hasTXO = false;
    $rows->each(function (Crawler $row) use (&$hasData, &$hasTXO) {
        $text = $row->text();
        if (mb_strpos($text, '契約') !== false || mb_strpos($text, '履約價') !== false) {
            $hasData = true;
        }
        if (mb_strpos($text, 'TXO') !== false) {
            $hasTXO = true;
        }
    });

    echo "\n判斷結果:\n";
    echo "  包含標題關鍵字: " . ($hasData ? "✅ 是" : "❌ 否") . "\n";
    echo "  包含 TXO 資料: " . ($hasTXO ? "✅ 是" : "❌ 否") . "\n";

    if ($hasData && $hasTXO && $rowCount > 100) {
        echo "  ⭐ 這個表格看起來是正確的資料表格！\n";
    }

    echo "\n";
});

echo "========================================\n";
echo "3️⃣ 建議\n";
echo "========================================\n";
echo "\n";

// 找出最可能的表格
$bestTableIndex = -1;
$maxRows = 0;

$tables->each(function (Crawler $table, $index) use (&$bestTableIndex, &$maxRows) {
    $rows = $table->filter('tr');
    $rowCount = $rows->count();

    $hasKeywords = false;
    $rows->each(function (Crawler $row) use (&$hasKeywords) {
        $text = $row->text();
        if (mb_strpos($text, '契約') !== false && mb_strpos($text, '履約價') !== false) {
            $hasKeywords = true;
        }
    });

    if ($hasKeywords && $rowCount > $maxRows) {
        $maxRows = $rowCount;
        $bestTableIndex = $index;
    }
});

if ($bestTableIndex >= 0) {
    echo "✅ 建議使用表格索引: {$bestTableIndex}\n";
    echo "   (該表格有 {$maxRows} 行，且包含關鍵字)\n\n";

    echo "修改 TaifexApiService.php 第 109 行:\n";
    echo "   // 原本\n";
    echo "   \$dataTable = \$tables->eq(2);\n\n";
    echo "   // 改為\n";
    echo "   \$dataTable = \$tables->eq({$bestTableIndex});\n\n";
} else {
    echo "⚠️  找不到明確的資料表格\n";
    echo "   請手動查看 {$htmlPath}\n\n";
}

echo "4️⃣ 下一步\n";
echo "========================================\n";
echo "1. 查看儲存的 HTML: {$htmlPath}\n";
echo "2. 確認正確的表格索引\n";
echo "3. 修改 app/Services/TaifexApiService.php\n";
echo "4. 重新執行爬蟲\n";
echo "\n";
