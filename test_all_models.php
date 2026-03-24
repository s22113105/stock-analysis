<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\PredictionService;
use App\Models\Stock;

echo "=== 測試所有預測模型 ===\n\n";

$service = app(PredictionService::class);

// 測試參數
$testParams = [
    'epochs' => 10,
    'units' => 32,
    'historical_days' => 200
];

// 1. 測試股票 LSTM
echo "1. 測試股票 LSTM 預測...\n";
$stock = Stock::first();
if ($stock) {
    $result = $service->runLSTMPrediction($stock, 1, $testParams);
    echo $result['success'] ? "✅ 成功\n" : "❌ 失敗: " . ($result['message'] ?? 'Unknown') . "\n";
} else {
    echo "⚠️ 沒有股票資料\n";
}

// 2. 測試 TXO LSTM
echo "\n2. 測試 TXO 市場 LSTM 預測...\n";
try {
    $result = $service->runTxoMarketLSTMPrediction('TXO', 1, $testParams);
    echo $result['success'] ? "✅ 成功\n" : "❌ 失敗: " . ($result['message'] ?? 'Unknown') . "\n";
} catch (\Exception $e) {
    echo "❌ 錯誤: " . $e->getMessage() . "\n";
}

// 3. 測試股票 ARIMA（如果有資料）
if ($stock) {
    echo "\n3. 測試股票 ARIMA 預測...\n";
    try {
        $result = $service->runARIMAPrediction($stock, 1, ['historical_days' => 100]);
        echo $result['success'] ? "✅ 成功\n" : "❌ 失敗: " . ($result['message'] ?? 'Unknown') . "\n";
    } catch (\Exception $e) {
        echo "❌ 錯誤: " . $e->getMessage() . "\n";
    }
}

// 4. 測試股票 GARCH（如果有資料）
if ($stock) {
    echo "\n4. 測試股票 GARCH 預測...\n";
    try {
        $result = $service->runGARCHPrediction($stock, 1, $testParams);
        echo $result['success'] ? "✅ 成功\n" : "❌ 失敗: " . ($result['message'] ?? 'Unknown') . "\n";
    } catch (\Exception $e) {
        echo "❌ 錯誤: " . $e->getMessage() . "\n";
    }
}

echo "\n=== 測試完成 ===\n";
