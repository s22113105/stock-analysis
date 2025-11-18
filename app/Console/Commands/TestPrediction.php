<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class TestPrediction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:prediction {model=lstm} {--simple : 使用簡單測試}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '測試預測模型執行';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $model = $this->argument('model');
        $useSimple = $this->option('simple');

        $this->info('開始測試預測模型...');

        // 準備測試資料
        $testData = $this->prepareTestData();

        // 寫入暫存檔案
        $tempFile = tempnam(sys_get_temp_dir(), 'test_prediction_');
        file_put_contents($tempFile, json_encode($testData));

        try {
            if ($useSimple) {
                $result = $this->runSimpleTest($tempFile);
            } else {
                $result = $this->runModelTest($model, $tempFile);
            }

            if ($result['success']) {
                $this->info('測試成功！');
                $this->line('預測結果:');
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('測試失敗！');
                $this->error($result['error'] ?? '未知錯誤');
            }
        } catch (\Exception $e) {
            $this->error('發生錯誤: ' . $e->getMessage());
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return 0;
    }

    /**
     * 準備測試資料
     */
    private function prepareTestData(): array
    {
        $prices = [];
        for ($i = 0; $i < 200; $i++) {
            $prices[] = 100 + $i * 0.5 + rand(-5, 5);
        }

        return [
            'prices' => $prices,
            'dates' => array_map(fn($i) => date('Y-m-d', strtotime("-$i days")), range(0, 199)),
            'volumes' => array_fill(0, 200, 1000000),
            'base_date' => date('Y-m-d'),
            'prediction_days' => 1,
            'stock_symbol' => 'TEST',
            'epochs' => 10,
            'units' => 32,
            'lookback' => 30,
            'dropout' => 0.2
        ];
    }

    /**
     * 執行簡單測試
     */
    private function runSimpleTest(string $tempFile): array
    {
        $pythonCommand = 'C:\\Python313\\python.exe';
        $scriptPath = base_path('python/test_simple.py');
        $command = "{$pythonCommand} {$scriptPath} \"{$tempFile}\"";

        $this->line("執行簡單測試: {$command}");

        $result = $this->executePython($command);

        return $this->parseResult($result);
    }

    /**
     * 執行模型測試
     */
    private function runModelTest(string $model, string $tempFile): array
    {
        $pythonCommand = 'C:\\Python313\\python.exe';
        $scriptPath = base_path("python/models/{$model}_model.py");

        if (!file_exists($scriptPath)) {
            return [
                'success' => false,
                'error' => "模型腳本不存在: {$scriptPath}"
            ];
        }

        $command = "{$pythonCommand} {$scriptPath} \"{$tempFile}\"";

        $this->line("執行模型測試: {$command}");

        $result = $this->executePython($command);

        return $this->parseResult($result);
    }

    /**
     * 執行 Python 命令
     */
    private function executePython(string $command): \Illuminate\Contracts\Process\ProcessResult
    {
        // 取得系統環境變數
        $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
        $systemPath = getenv('PATH');
        $programFiles = getenv('ProgramFiles') ?: 'C:\\Program Files';

        return Process::timeout(120)
            ->env([
                'PYTHONPATH' => 'C:\\Python313\\Lib\\site-packages',
                'PYTHONHOME' => 'C:\\Python313',
                'PATH' => implode(';', [
                    'C:\\Python313',
                    'C:\\Python313\\Scripts',
                    $systemRoot . '\\System32',
                    $systemRoot . '\\System32\\Wbem',
                    $systemRoot . '\\System32\\WindowsPowerShell\\v1.0',
                    $systemRoot,
                    $programFiles . '\\Windows Kits\\10\\Windows Performance Toolkit',
                    $systemPath
                ]),
                'SystemRoot' => $systemRoot,
                'WINDIR' => $systemRoot,
                'ComSpec' => $systemRoot . '\\System32\\cmd.exe',
                'TEMP' => sys_get_temp_dir(),
                'TMP' => sys_get_temp_dir(),
                'PYTHONIOENCODING' => 'utf-8',
                'PYTHONUTF8' => '1',
                'NO_PROXY' => '*',
                'PYTHONDONTWRITEBYTECODE' => '1',
                'TF_CPP_MIN_LOG_LEVEL' => '2'
            ])
            ->run($command);
    }

    /**
     * 解析結果
     */
    private function parseResult(\Illuminate\Contracts\Process\ProcessResult $result): array
    {
        if (!$result->successful()) {
            return [
                'success' => false,
                'error' => $result->errorOutput()
            ];
        }

        $output = json_decode($result->output(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'JSON 解析錯誤: ' . json_last_error_msg(),
                'output' => $result->output()
            ];
        }

        return $output;
    }
}
