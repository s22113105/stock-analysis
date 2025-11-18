<?php

// 測試資料
$testData = [
    'prices' => array_map(fn($i) => 100 + $i * 0.5 + rand(-5, 5), range(0, 199)),
    'dates' => array_map(fn($i) => date('Y-m-d', strtotime("-$i days")), range(0, 199)),
    'volumes' => array_fill(0, 200, 1000000),
    'base_date' => date('Y-m-d'),
    'prediction_days' => 1,
    'stock_symbol' => 'TEST',
    'epochs' => 10,  // 減少訓練輪數以加快測試
    'units' => 32,
    'lookback' => 30,
    'dropout' => 0.2
];

$tempFile = tempnam(sys_get_temp_dir(), 'test_prediction_');
file_put_contents($tempFile, json_encode($testData));

$pythonScript = __DIR__ . '/python/models/lstm_model.py';
$pythonCommand = 'C:\\Python313\\python.exe';

echo "執行測試...\n";
echo "測試資料點數: " . count($testData['prices']) . "\n";
echo "預測天數: {$testData['prediction_days']}\n";
echo "訓練輪數: {$testData['epochs']}\n\n";

// 設定環境變數
$systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
$systemPath = getenv('PATH');

// 建立環境變數字串
$envVars = [
    'PYTHONPATH' => 'C:\\Python313\\Lib\\site-packages',
    'PYTHONHOME' => 'C:\\Python313',
    'PATH' => implode(';', [
        'C:\\Python313',
        'C:\\Python313\\Scripts',
        $systemRoot . '\\System32',
        $systemRoot . '\\System32\\Wbem',
        $systemRoot,
        $systemPath
    ]),
    'SystemRoot' => $systemRoot,
    'WINDIR' => $systemRoot,
    'TEMP' => sys_get_temp_dir(),
    'TMP' => sys_get_temp_dir(),
    'PYTHONIOENCODING' => 'utf-8',
    'NO_PROXY' => '*',
    'PYTHONDONTWRITEBYTECODE' => '1',
    'TF_CPP_MIN_LOG_LEVEL' => '2'
];

// 設定環境變數
foreach ($envVars as $key => $value) {
    putenv("$key=$value");
}

// 執行命令
$command = "{$pythonCommand} \"{$pythonScript}\" \"{$tempFile}\" 2>&1";
echo "執行命令: {$command}\n\n";

$output = [];
$returnCode = 0;
exec($command, $output, $returnCode);

echo "退出碼: {$returnCode}\n\n";

if ($returnCode === 0) {
    echo "成功執行!\n";
    echo "輸出:\n";
    $jsonOutput = implode("\n", $output);
    $result = json_decode($jsonOutput, true);

    if ($result) {
        print_r($result);
    } else {
        echo $jsonOutput . "\n";
    }
} else {
    echo "執行失敗!\n";
    echo "輸出:\n";
    echo implode("\n", $output) . "\n";
}

// 清理暫存檔案
if (file_exists($tempFile)) {
    unlink($tempFile);
}
