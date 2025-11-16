<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Prediction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * 預測服務類別
 * 整合 Python 機器學習模型進行股價和市場預測
 */
class PredictionService
{
    // 使用 base_path() 動態取得路徑
    private function getPythonModelsPath(): string
    {
        return base_path('python') . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR;
    }
    private const SUPPORTED_MODELS = [
        'lstm' => 'lstm_model.py',
        'arima' => 'arima_model.py',
        'garch' => 'garch_model.py',
    ];

    /**
     * TXO 市場指數服務
     */
    protected TxoMarketIndexService $txoIndexService;

    public function __construct(TxoMarketIndexService $txoIndexService)
    {
        $this->txoIndexService = $txoIndexService;
    }

    // ========================================
    // 股票預測方法 (維持不變)
    // ========================================

    public function runLSTMPrediction(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            Log::info('開始執行 LSTM 預測', [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol
            ]);

            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->getHistoricalPricesFromDB($stock, $historicalDays);

            if (count($prices) < 100) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,LSTM 模型需要至少 100 天的資料。"
                ];
            }

            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'volumes' => array_column($prices, 'volume'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol' => $stock->symbol,
                'epochs' => $parameters['epochs'] ?? 100,
                'units' => $parameters['units'] ?? 128,
                'lookback' => $parameters['lookback'] ?? 60,
                'dropout' => $parameters['dropout'] ?? 0.2,
            ];

            $result = $this->executePythonModel('lstm', $inputData);

            if ($result['success']) {
                $result['historical_prices'] = $prices;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('LSTM 預測失敗', [
                'stock_id' => $stock->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    public function runARIMAPrediction(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            $historicalDays = $parameters['historical_days'] ?? 100;
            $prices = $this->getHistoricalPricesFromDB($stock, $historicalDays);

            if (count($prices) < 30) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,ARIMA 模型需要至少 30 天的資料。"
                ];
            }

            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol' => $stock->symbol,
                'p' => $parameters['p'] ?? null,
                'd' => $parameters['d'] ?? null,
                'q' => $parameters['q'] ?? null,
                'auto_select' => $parameters['auto_select'] ?? true,
            ];

            $result = $this->executePythonModel('arima', $inputData);

            if ($result['success']) {
                $result['historical_prices'] = $prices;
            }

            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    public function runGARCHPrediction(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->getHistoricalPricesFromDB($stock, $historicalDays);

            if (count($prices) < 100) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,GARCH 模型需要至少 100 天的資料。"
                ];
            }

            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol' => $stock->symbol,
                'p' => $parameters['p'] ?? 1,
                'q' => $parameters['q'] ?? 1,
                'dist' => $parameters['dist'] ?? 'normal',
            ];

            $result = $this->executePythonModel('garch', $inputData);

            if ($result['success']) {
                $result['historical_prices'] = $prices;
            }

            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    // ========================================
    // TXO 整體市場預測方法 (使用市場指數)
    // ========================================

    /**
     * 🆕 執行 TXO 整體市場 LSTM 預測
     * 使用所有契約的加權平均價格指數
     */
    public function runUnderlyingLSTMPrediction(string $underlying, int $predictionDays = 1, array $parameters = []): array
    {
        try {
            Log::info('開始執行 TXO 市場指數 LSTM 預測', [
                'underlying' => $underlying,
                'prediction_days' => $predictionDays
            ]);

            // 🔧 使用市場指數服務獲取歷史資料
            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->txoIndexService->getHistoricalIndexForPrediction($historicalDays);

            if (count($prices) < 100) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,LSTM 模型需要至少 100 天的資料。目前只有 " . count($prices) . " 天的資料。"
                ];
            }

            Log::info('獲取 TXO 市場指數資料', [
                'data_points' => count($prices),
                'date_range' => [
                    'from' => $prices[0]['date'],
                    'to' => end($prices)['date']
                ]
            ]);

            // 準備輸入資料
            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'volumes' => array_column($prices, 'volume'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'option_code' => 'TXO_MARKET_INDEX',  // 標記為市場指數
                'epochs' => $parameters['epochs'] ?? 100,
                'units' => $parameters['units'] ?? 128,
                'lookback' => $parameters['lookback'] ?? 60,
                'dropout' => $parameters['dropout'] ?? 0.2,
            ];

            // 執行 Python 腳本
            $result = $this->executePythonModel('lstm', $inputData);

            if ($result['success']) {
                $result['data_source'] = "TXO 市場整體指數(成交量加權平均)";
                $result['historical_prices'] = $prices;
                $result['current_price'] = end($prices)['close'];
                $result['current_date'] = end($prices)['date'];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('TXO 市場指數 LSTM 預測失敗', [
                'underlying' => $underlying,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 🆕 執行 TXO 整體市場 ARIMA 預測
     */
    public function runUnderlyingARIMAPrediction(string $underlying, int $predictionDays = 1, array $parameters = []): array
    {
        try {
            Log::info('開始執行 TXO 市場指數 ARIMA 預測');

            $historicalDays = $parameters['historical_days'] ?? 100;
            $prices = $this->txoIndexService->getHistoricalIndexForPrediction($historicalDays);

            if (count($prices) < 30) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,ARIMA 模型需要至少 30 天的資料。"
                ];
            }

            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'option_code' => 'TXO_MARKET_INDEX',
                'p' => $parameters['p'] ?? null,
                'd' => $parameters['d'] ?? null,
                'q' => $parameters['q'] ?? null,
                'auto_select' => $parameters['auto_select'] ?? true,
            ];

            $result = $this->executePythonModel('arima', $inputData);

            if ($result['success']) {
                $result['data_source'] = "TXO 市場整體指數(成交量加權平均)";
                $result['historical_prices'] = $prices;
                $result['current_price'] = end($prices)['close'];
                $result['current_date'] = end($prices)['date'];
            }

            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 🆕 執行 TXO 整體市場 GARCH 預測
     */
    public function runUnderlyingGARCHPrediction(string $underlying, int $predictionDays = 1, array $parameters = []): array
    {
        try {
            Log::info('開始執行 TXO 市場指數 GARCH 預測');

            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->txoIndexService->getHistoricalIndexForPrediction($historicalDays);

            if (count($prices) < 100) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,GARCH 模型需要至少 100 天的資料。"
                ];
            }

            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'option_code' => 'TXO_MARKET_INDEX',
                'p' => $parameters['p'] ?? 1,
                'q' => $parameters['q'] ?? 1,
                'dist' => $parameters['dist'] ?? 'normal',
            ];

            $result = $this->executePythonModel('garch', $inputData);

            if ($result['success']) {
                $result['data_source'] = "TXO 市場整體指數(成交量加權平均)";
                $result['historical_prices'] = $prices;
                $result['current_price'] = end($prices)['close'];
                $result['current_date'] = end($prices)['date'];
            }

            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    // ========================================
    // 私有輔助方法
    // ========================================

    private function getHistoricalPricesFromDB(Stock $stock, int $days = 100): array
    {
        $prices = StockPrice::where('stock_id', $stock->id)
            ->orderBy('trade_date', 'desc')
            ->limit($days)
            ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume'])
            ->map(function ($price) {
                return [
                    'date' => $price->trade_date,
                    'open' => (float) $price->open,
                    'high' => (float) $price->high,
                    'low' => (float) $price->low,
                    'close' => (float) $price->close,
                    'volume' => (int) $price->volume,
                ];
            })
            ->reverse()
            ->values()
            ->toArray();

        return $prices;
    }

    /**
     * 執行 Python 模型
     */
    /**
     * 執行 Python 模型
     */
    private function executePythonModel(string $modelType, array $inputData): array
    {
        if (!isset(self::SUPPORTED_MODELS[$modelType])) {
            throw new \Exception("不支援的模型類型: {$modelType}");
        }

        $scriptPath = $this->getPythonModelsPath() . self::SUPPORTED_MODELS[$modelType];

        // 安全的 JSON 編碼
        $inputJson = json_encode(
            $inputData,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        $tempFile = tempnam(sys_get_temp_dir(), 'prediction_input_');
        file_put_contents($tempFile, $inputJson);

        try {
            // 🔧 統一使用 python3 (Python 3.11.9)
            $pythonCommand = 'python3';

            $command = "{$pythonCommand} {$scriptPath} '{$tempFile}'";

            Log::info('執行 Python 命令', [
                'os' => PHP_OS_FAMILY,
                'command' => $pythonCommand,
                'script' => $scriptPath
            ]);

            // 🔧 設定環境變數
            $pythonDir = 'C:\\Python313';
            $pythonScripts = $pythonDir . '\\Scripts';
            $currentPath = getenv('PATH');

            $result = Process::timeout(120)
                ->env([
                    'PATH' => $pythonDir . ';' . $pythonScripts . ';' . $currentPath,
                    'PYTHONPATH' => '',
                    'PYTHONIOENCODING' => 'utf-8',
                    'TF_CPP_MIN_LOG_LEVEL' => '2'
                ])
                ->run($command);

            if (!$result->successful()) {
                // 清理錯誤訊息中的非 UTF-8 字元
                $errorOutput = mb_convert_encoding(
                    $result->errorOutput(),
                    'UTF-8',
                    'UTF-8, BIG5, CP950'
                );

                Log::error('Python 腳本執行失敗', [
                    'model' => $modelType,
                    'error' => $errorOutput,
                    'exit_code' => $result->exitCode()
                ]);

                throw new \Exception("Python 模型執行失敗: " . $errorOutput);
            }

            $output = json_decode($result->output(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("無法解析 Python 輸出: " . json_last_error_msg());
            }

            return $output;
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
