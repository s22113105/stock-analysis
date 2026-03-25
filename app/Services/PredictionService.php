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
    private function getPythonModelsPath(): string
    {
        return base_path('python') . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR;
    }

    private const SUPPORTED_MODELS = [
        'lstm'  => 'lstm_model.py',
        'arima' => 'arima_model.py',
        'garch' => 'garch_model.py',
    ];

    protected TxoMarketIndexService $txoIndexService;

    public function __construct(TxoMarketIndexService $txoIndexService)
    {
        $this->txoIndexService = $txoIndexService;
    }

    // ========================================
    // 股票預測方法
    // ========================================

    /**
     * 執行 LSTM 股票預測
     */
    public function runLSTMPrediction(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            Log::info('開始執行 LSTM 預測', [
                'stock_id' => $stock->id,
                'symbol'   => $stock->symbol,
            ]);

            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->getHistoricalPricesFromDB($stock, $historicalDays);

            if (count($prices) < 100) {
                return ['success' => false, 'message' => 'LSTM 模型需要至少 100 天的資料。'];
            }

            $inputData = [
                'prices'          => array_column($prices, 'close'),
                'dates'           => array_column($prices, 'date'),
                'volumes'         => array_column($prices, 'volume'),
                'base_date'       => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol'    => $stock->symbol,
                'epochs'          => $parameters['epochs']  ?? 100,
                'units'           => $parameters['units']   ?? 128,
                'lookback'        => $parameters['lookback'] ?? 60,
                'dropout'         => $parameters['dropout'] ?? 0.2,
            ];

            $result = $this->executePythonModel('lstm', $inputData);

            if ($result['success']) {
                // ✅ 儲存股票預測結果（使用 morphs 欄位）
                $this->saveStockPredictions($stock, 'lstm', $result['predictions'] ?? [], $parameters);
                $result['historical_prices'] = $prices;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('LSTM 預測失敗', ['stock_id' => $stock->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '預測失敗: ' . $e->getMessage()];
        }
    }

    /**
     * 執行 ARIMA 股票預測
     */
    public function runARIMAPrediction(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            $historicalDays = $parameters['historical_days'] ?? 100;
            $prices = $this->getHistoricalPricesFromDB($stock, $historicalDays);

            if (count($prices) < 30) {
                return ['success' => false, 'message' => 'ARIMA 模型需要至少 30 天的資料。'];
            }

            $inputData = [
                'prices'          => array_column($prices, 'close'),
                'dates'           => array_column($prices, 'date'),
                'base_date'       => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol'    => $stock->symbol,
                'p'               => $parameters['p'] ?? null,
                'd'               => $parameters['d'] ?? null,
                'q'               => $parameters['q'] ?? null,
                'auto_select'     => $parameters['auto_select'] ?? true,
            ];

            $result = $this->executePythonModel('arima', $inputData);

            if ($result['success']) {
                // ✅ 儲存股票預測結果
                $this->saveStockPredictions($stock, 'arima', $result['predictions'] ?? [], $parameters);
                $result['historical_prices'] = $prices;
            }

            return $result;
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '預測失敗: ' . $e->getMessage()];
        }
    }

    /**
     * 執行 GARCH 股票預測
     */
    public function runGARCHPrediction(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->getHistoricalPricesFromDB($stock, $historicalDays);

            if (count($prices) < 100) {
                return ['success' => false, 'message' => 'GARCH 模型需要至少 100 天的資料。'];
            }

            $inputData = [
                'prices'          => array_column($prices, 'close'),
                'dates'           => array_column($prices, 'date'),
                'base_date'       => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol'    => $stock->symbol,
                'p'               => $parameters['p'] ?? 1,
                'q'               => $parameters['q'] ?? 1,
                'dist'            => $parameters['dist'] ?? 'normal',
            ];

            $result = $this->executePythonModel('garch', $inputData);

            if ($result['success']) {
                // ✅ 儲存股票預測結果
                $this->saveStockPredictions($stock, 'garch', $result['predictions'] ?? [], $parameters);
                $result['historical_prices'] = $prices;
            }

            return $result;
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '預測失敗: ' . $e->getMessage()];
        }
    }

    // ========================================
    // TXO 市場預測方法
    // ========================================

    /**
     * 執行 TXO 市場指數 LSTM 預測
     */
    public function runTxoMarketLSTMPrediction(string $underlying = 'TXO', int $predictionDays = 1, array $parameters = []): array
    {
        try {
            Log::info('開始執行 TXO 市場指數 LSTM 預測', [
                'underlying'      => $underlying,
                'prediction_days' => $predictionDays,
            ]);

            $historicalDays = $parameters['historical_days'] ?? 180;
            $prices = $this->txoIndexService->getHistoricalIndexForPrediction($historicalDays);

            Log::info('獲取 TXO 市場指數資料', [
                'data_points' => count($prices),
                'date_range'  => [
                    'from' => $prices[0]['date'] ?? null,
                    'to'   => end($prices)['date'] ?? null,
                ],
            ]);

            if (count($prices) < 60) {
                return [
                    'success' => false,
                    'message' => "LSTM 模型需要至少 60 天的資料，目前只有 " . count($prices) . " 天。",
                ];
            }

            $inputData = [
                'prices'          => array_column($prices, 'close'),
                'dates'           => array_column($prices, 'date'),
                'volumes'         => array_column($prices, 'volume'),
                'base_date'       => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol'    => $underlying,
                'epochs'          => $parameters['epochs']  ?? 100,
                'units'           => $parameters['units']   ?? 128,
                'lookback'        => $parameters['lookback'] ?? 60,
                'dropout'         => $parameters['dropout'] ?? 0.2,
            ];

            $result = $this->executePythonModel('lstm', $inputData);

            if ($result['success']) {
                $result['data_source']    = 'TXO 市場整體指數(成交量加權平均)';
                $result['historical_prices'] = $prices;
                $result['current_price']  = end($prices)['close'];
                $result['current_date']   = end($prices)['date'];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('TXO 市場指數 LSTM 預測失敗', ['underlying' => $underlying, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '預測失敗: ' . $e->getMessage()];
        }
    }

    /**
     * 執行 TXO 市場指數 ARIMA 預測
     */
    public function runTxoMarketARIMAPrediction(string $underlying = 'TXO', int $predictionDays = 1, array $parameters = []): array
    {
        try {
            $historicalDays = $parameters['historical_days'] ?? 100;
            $prices = $this->txoIndexService->getHistoricalIndexForPrediction($historicalDays);

            if (count($prices) < 30) {
                return ['success' => false, 'message' => 'ARIMA 模型需要至少 30 天的資料。'];
            }

            $inputData = [
                'prices'          => array_column($prices, 'close'),
                'dates'           => array_column($prices, 'date'),
                'base_date'       => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol'    => $underlying,
                'p'               => $parameters['p'] ?? null,
                'd'               => $parameters['d'] ?? null,
                'q'               => $parameters['q'] ?? null,
                'auto_select'     => $parameters['auto_select'] ?? true,
            ];

            $result = $this->executePythonModel('arima', $inputData);

            if ($result['success']) {
                $result['data_source']    = 'TXO 市場整體指數(成交量加權平均)';
                $result['historical_prices'] = $prices;
                $result['current_price']  = end($prices)['close'];
                $result['current_date']   = end($prices)['date'];
            }

            return $result;
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '預測失敗: ' . $e->getMessage()];
        }
    }

    /**
     * 執行 TXO 市場指數 GARCH 預測
     */
    public function runTxoMarketGARCHPrediction(string $underlying = 'TXO', int $predictionDays = 1, array $parameters = []): array
    {
        try {
            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->txoIndexService->getHistoricalIndexForPrediction($historicalDays);

            if (count($prices) < 100) {
                return ['success' => false, 'message' => 'GARCH 模型需要至少 100 天的資料。'];
            }

            $inputData = [
                'prices'          => array_column($prices, 'close'),
                'dates'           => array_column($prices, 'date'),
                'base_date'       => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol'    => $underlying,
                'p'               => $parameters['p'] ?? 1,
                'q'               => $parameters['q'] ?? 1,
                'dist'            => $parameters['dist'] ?? 'normal',
            ];

            $result = $this->executePythonModel('garch', $inputData);

            if ($result['success']) {
                $result['data_source']    = 'TXO 市場整體指數(成交量加權平均)';
                $result['historical_prices'] = $prices;
                $result['current_price']  = end($prices)['close'];
                $result['current_date']   = end($prices)['date'];
            }

            return $result;
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '預測失敗: ' . $e->getMessage()];
        }
    }

    // ========================================
    // 私有輔助方法
    // ========================================

    /**
     * ✅ 新增：儲存股票預測結果到資料庫（對齊 morphs 欄位）
     *
     * @param Stock  $stock       股票 Model
     * @param string $modelType   lstm / arima / garch
     * @param array  $predictions Python 回傳的預測陣列
     * @param array  $parameters  模型參數
     */
    private function saveStockPredictions(Stock $stock, string $modelType, array $predictions, array $parameters): void
    {
        if (empty($predictions)) {
            return;
        }

        try {
            DB::beginTransaction();

            foreach ($predictions as $prediction) {
                Prediction::create([
                    // ✅ 使用 morphs 欄位，不使用 stock_id
                    'predictable_type'    => Stock::class,
                    'predictable_id'      => $stock->id,
                    'model_type'          => $modelType,
                    'prediction_date'     => now()->toDateString(),
                    'prediction_days'     => 1,
                    'predicted_price'     => $prediction['predicted_price']     ?? null,
                    'predicted_volatility'=> $prediction['predicted_volatility'] ?? null,
                    // ✅ 對齊 migration 欄位名（upper_bound / lower_bound）
                    'upper_bound'         => $prediction['confidence_upper']    ?? null,
                    'lower_bound'         => $prediction['confidence_lower']    ?? null,
                    'confidence_level'    => ($prediction['confidence_level']   ?? 0.95) * 100,
                    'model_parameters'    => $parameters,
                ]);
            }

            DB::commit();

            Log::info('股票預測結果已儲存', [
                'stock_id'          => $stock->id,
                'symbol'            => $stock->symbol,
                'model_type'        => $modelType,
                'predictions_count' => count($predictions),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('儲存股票預測結果失敗', [
                'stock_id'   => $stock->id,
                'model_type' => $modelType,
                'error'      => $e->getMessage(),
            ]);
            // 儲存失敗不拋出例外，預測結果仍正常回傳
        }
    }

    /**
     * 從資料庫取得股票歷史價格
     */
    private function getHistoricalPricesFromDB(Stock $stock, int $days = 100): array
    {
        return StockPrice::where('stock_id', $stock->id)
            ->orderBy('trade_date', 'desc')
            ->limit($days)
            ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume'])
            ->map(fn($p) => [
                'date'   => $p->trade_date,
                'open'   => (float) $p->open,
                'high'   => (float) $p->high,
                'low'    => (float) $p->low,
                'close'  => (float) $p->close,
                'volume' => (int)   $p->volume,
            ])
            ->reverse()
            ->values()
            ->toArray();
    }

    /**
     * 取得 Python 命令路徑（根據環境自動判斷）
     */
    private function getPythonCommand(): string
    {
        $envPython = env('PYTHON_PATH');
        if ($envPython && file_exists($envPython)) {
            return $envPython;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['C:\\Python313\\python.exe', 'C:\\Python312\\python.exe', 'C:\\Python311\\python.exe'] as $path) {
                if (file_exists($path)) return $path;
            }
            return 'python';
        }

        foreach (['/usr/bin/python3', '/usr/local/bin/python3', '/usr/bin/python'] as $path) {
            if (file_exists($path)) return $path;
        }
        return 'python3';
    }

    /**
     * 取得 Python 執行環境變數
     */
    private function getPythonEnv(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $systemRoot  = getenv('SystemRoot')   ?: 'C:\\Windows';
            $systemPath  = getenv('PATH');
            $programFiles = getenv('ProgramFiles') ?: 'C:\\Program Files';
            return [
                'PYTHONPATH'             => 'C:\\Python313\\Lib\\site-packages',
                'PYTHONHOME'             => 'C:\\Python313',
                'PATH'                   => implode(';', [
                    'C:\\Python313', 'C:\\Python313\\Scripts',
                    $systemRoot . '\\System32', $systemRoot . '\\System32\\Wbem',
                    $systemRoot . '\\System32\\WindowsPowerShell\\v1.0',
                    $systemRoot, $programFiles . '\\Windows Kits\\10\\Windows Performance Toolkit',
                    $systemPath,
                ]),
                'SystemRoot'             => $systemRoot,
                'WINDIR'                 => $systemRoot,
                'ComSpec'                => $systemRoot . '\\System32\\cmd.exe',
                'TEMP'                   => sys_get_temp_dir(),
                'TMP'                    => sys_get_temp_dir(),
                'PYTHONIOENCODING'       => 'utf-8',
                'PYTHONUTF8'             => '1',
                'NO_PROXY'               => '*',
                'PYTHONDONTWRITEBYTECODE'=> '1',
                'TF_CPP_MIN_LOG_LEVEL'   => '2',
            ];
        }

        // Linux / Docker
        return [
            'PYTHONIOENCODING'       => 'utf-8',
            'PYTHONUTF8'             => '1',
            'PYTHONDONTWRITEBYTECODE'=> '1',
            'TF_CPP_MIN_LOG_LEVEL'   => '2',
            'PATH'                   => '/usr/local/bin:/usr/bin:/bin',
        ];
    }

    /**
     * 執行 Python 模型（支援多環境）
     */
    private function executePythonModel(string $modelType, array $inputData): array
    {
        if (!isset(self::SUPPORTED_MODELS[$modelType])) {
            throw new \Exception("不支援的模型類型: {$modelType}");
        }

        $scriptPath = $this->getPythonModelsPath() . self::SUPPORTED_MODELS[$modelType];
        $inputJson  = json_encode($inputData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $tempFile   = tempnam(sys_get_temp_dir(), 'prediction_input_');
        file_put_contents($tempFile, $inputJson);

        try {
            $pythonCommand = $this->getPythonCommand();
            $command       = "{$pythonCommand} {$scriptPath} \"{$tempFile}\"";

            Log::info('執行 Python 命令', [
                'os'        => PHP_OS_FAMILY,
                'command'   => $pythonCommand,
                'script'    => $scriptPath,
                'temp_file' => $tempFile,
            ]);

            $result = Process::timeout(120)
                ->env($this->getPythonEnv())
                ->run($command);

            if (!$result->successful()) {
                $errorOutput = mb_convert_encoding($result->errorOutput(), 'UTF-8', 'UTF-8, BIG5, CP950');
                Log::error('Python 腳本執行失敗', [
                    'model'     => $modelType,
                    'error'     => $errorOutput,
                    'exit_code' => $result->exitCode(),
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
