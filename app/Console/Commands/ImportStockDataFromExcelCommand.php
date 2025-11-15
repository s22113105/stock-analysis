<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Stock;
use App\Models\StockPrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 從 CSV 匯入股票資料
 * 支援 CSV 和 Excel (xlsx) 格式
 */
class ImportStockDataFromExcelCommand extends Command
{
    /**
     * 指令名稱
     *
     * @var string
     */
    protected $signature = 'import:stocks-csv
                            {file : CSV/Excel 檔案路徑}
                            {--type=prices : 匯入類型 (stocks|prices)}
                            {--truncate : 清空現有資料}
                            {--delimiter=, : CSV 分隔符號}
                            {--encoding=UTF-8 : 檔案編碼}';

    /**
     * 指令說明
     *
     * @var string
     */
    protected $description = '從 CSV 或 Excel 匯入股票資料';

    /**
     * 執行指令
     */
    public function handle()
    {
        $filePath = $this->argument('file');
        $type = $this->option('type');
        $truncate = $this->option('truncate');
        $delimiter = $this->option('delimiter');
        $encoding = $this->option('encoding');

        // 檢查檔案是否存在
        if (!file_exists($filePath)) {
            $this->error("❌ 檔案不存在: {$filePath}");
            return Command::FAILURE;
        }

        $this->info('========================================');
        $this->info('📊 開始匯入股票資料');
        $this->info('========================================');
        $this->info("檔案: {$filePath}");
        $this->info("類型: {$type}");
        $this->info("分隔符號: {$delimiter}");
        $this->info("編碼: {$encoding}");
        $this->info('========================================');
        $this->newLine();

        try {
            // 讀取檔案
            $this->info('⏳ 正在讀取檔案...');

            // 判斷檔案類型
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            if ($extension === 'xlsx' || $extension === 'xls') {
                // 使用 PhpSpreadsheet 讀取 Excel
                $rows = $this->readExcel($filePath);
            } else {
                // 讀取 CSV
                $rows = $this->readCsv($filePath, $delimiter, $encoding);
            }

            if (empty($rows)) {
                $this->error('❌ 檔案是空的！');
                return Command::FAILURE;
            }

            // 取得表頭
            $headers = array_shift($rows);

            // 清理表頭（移除 BOM 和空白）
            $headers = array_map(function ($header) {
                return trim(str_replace("\xEF\xBB\xBF", '', $header));
            }, $headers);

            $this->info('✓ 找到 ' . count($rows) . ' 筆資料');
            $this->info('✓ 欄位: ' . implode(', ', $headers));
            $this->newLine();

            // 根據類型執行不同的匯入邏輯
            if ($type === 'stocks') {
                return $this->importStocks($headers, $rows, $truncate);
            } elseif ($type === 'prices') {
                return $this->importStockPrices($headers, $rows, $truncate);
            } else {
                $this->error("❌ 不支援的類型: {$type}");
                $this->info('💡 請使用 --type=stocks 或 --type=prices');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('❌ 匯入失敗: ' . $e->getMessage());
            Log::error('CSV 匯入錯誤', [
                'file' => $filePath,
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * 讀取 CSV 檔案
     */
    protected function readCsv($filePath, $delimiter, $encoding)
    {
        $rows = [];

        // 開啟檔案
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \Exception("無法開啟檔案: {$filePath}");
        }

        // 讀取所有行
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            // 如果需要轉換編碼
            if ($encoding !== 'UTF-8') {
                $row = array_map(function ($value) use ($encoding) {
                    return mb_convert_encoding($value, 'UTF-8', $encoding);
                }, $row);
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * 讀取 Excel 檔案
     */
    protected function readExcel($filePath)
    {
        // 檢查是否安裝 PhpSpreadsheet
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            $this->warn('⚠️  PhpSpreadsheet 未安裝，無法讀取 Excel 檔案');
            $this->info('💡 請執行: composer require phpoffice/phpspreadsheet');
            throw new \Exception('PhpSpreadsheet 未安裝');
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        return $worksheet->toArray();
    }

    /**
     * 匯入股票基本資料
     */
    protected function importStocks($headers, $rows, $truncate)
    {
        if ($truncate) {
            $this->warn('⚠️  即將清空 stocks 資料表...');
            if ($this->confirm('確定要繼續嗎？')) {
                DB::table('stocks')->truncate();
                $this->info('✓ 已清空資料表');
            } else {
                $this->info('取消操作');
                return Command::SUCCESS;
            }
        }

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        $successCount = 0;
        $errorCount = 0;

        foreach ($rows as $row) {
            try {
                // 跳過空行
                if (empty(array_filter($row))) {
                    $bar->advance();
                    continue;
                }

                $data = array_combine($headers, $row);

                // 驗證必要欄位
                if (empty($data['symbol']) || empty($data['name'])) {
                    $errorCount++;
                    $bar->advance();
                    continue;
                }

                // 建立或更新股票資料
                Stock::updateOrCreate(
                    ['symbol' => trim($data['symbol'])],
                    [
                        'name' => trim($data['name']),
                        'market' => isset($data['market']) ? trim($data['market']) : 'TSE',
                        'industry' => isset($data['industry']) ? trim($data['industry']) : null,
                        'is_active' => true,
                    ]
                );

                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                Log::warning('股票資料匯入失敗', [
                    'data' => $data ?? $row,
                    'error' => $e->getMessage()
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ 匯入完成！");
        $this->info("   成功: {$successCount} 筆");
        if ($errorCount > 0) {
            $this->warn("   失敗: {$errorCount} 筆");
        }

        return Command::SUCCESS;
    }

    /**
     * 匯入股價歷史資料
     */
    protected function importStockPrices($headers, $rows, $truncate)
    {
        if ($truncate) {
            $this->warn('⚠️  即將清空 stock_prices 資料表...');
            if ($this->confirm('確定要繼續嗎？')) {
                DB::table('stock_prices')->truncate();
                $this->info('✓ 已清空資料表');
            } else {
                $this->info('取消操作');
                return Command::SUCCESS;
            }
        }

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        $successCount = 0;
        $errorCount = 0;
        $createdStocks = [];

        foreach ($rows as $row) {
            try {
                // 跳過空行
                if (empty(array_filter($row))) {
                    $bar->advance();
                    continue;
                }

                $data = array_combine($headers, $row);

                // 驗證必要欄位
                if (empty($data['symbol']) || empty($data['trade_date']) || !isset($data['close'])) {
                    $errorCount++;
                    $bar->advance();
                    continue;
                }

                $symbol = trim($data['symbol']);

                // 查找或建立股票
                $stock = Stock::where('symbol', $symbol)->first();
                if (!$stock) {
                    // 自動建立股票基本資料
                    $stock = Stock::create([
                        'symbol' => $symbol,
                        'name' => $symbol, // 使用代碼作為名稱
                        'market' => 'TSE',
                        'is_active' => true,
                    ]);
                    $createdStocks[] = $symbol;
                }

                // 處理日期格式
                $tradeDate = $this->parseDate($data['trade_date']);
                if (!$tradeDate) {
                    $errorCount++;
                    $bar->advance();
                    continue;
                }

                // 建立或更新股價資料
                StockPrice::updateOrCreate(
                    [
                        'stock_id' => $stock->id,
                        'trade_date' => $tradeDate
                    ],
                    [
                        'open' => $this->parseNumber($data['open'] ?? null),
                        'high' => $this->parseNumber($data['high'] ?? null),
                        'low' => $this->parseNumber($data['low'] ?? null),
                        'close' => $this->parseNumber($data['close']),
                        'volume' => $this->parseNumber($data['volume'] ?? 0),
                        'turnover' => $this->parseNumber($data['turnover'] ?? null),
                    ]
                );

                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                Log::warning('股價資料匯入失敗', [
                    'data' => $data ?? $row,
                    'error' => $e->getMessage()
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ 匯入完成！");
        $this->info("   成功: {$successCount} 筆");
        if ($errorCount > 0) {
            $this->warn("   失敗: {$errorCount} 筆");
        }
        if (!empty($createdStocks)) {
            $this->info("   自動建立股票: " . implode(', ', array_unique($createdStocks)));
        }

        return Command::SUCCESS;
    }

    /**
     * 解析日期
     */
    protected function parseDate($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            // 嘗試多種日期格式
            $formats = [
                'Y-m-d',
                'Y/m/d',
                'd/m/Y',
                'm/d/Y',
                'Ymd',
            ];

            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, $value);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            }

            // 最後嘗試用 Carbon 解析
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('日期解析失敗', ['value' => $value]);
            return null;
        }
    }

    /**
     * 解析數字
     */
    protected function parseNumber($value)
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }

        // 移除千分位符號和空白
        $value = str_replace([',', ' '], '', $value);

        return is_numeric($value) ? (float) $value : null;
    }
}
