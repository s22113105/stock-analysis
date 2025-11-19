<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Stock;
use App\Models\StockPrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 批次匯入股票歷史價格 CSV 檔案
 * 支援 Big5 編碼自動轉換
 */
class ImportStockPricesCommand extends Command
{
    protected $signature = 'import:stock-prices
                            {path : CSV 檔案路徑或目錄}
                            {--batch : 批次處理目錄中的所有檔案}
                            {--symbol= : 指定股票代號（可選）}
                            {--encoding=BIG5 : 檔案編碼 (UTF-8/BIG5)}
                            {--delimiter=, : CSV 分隔符號}
                            {--truncate : 清空現有資料}';

    protected $description = '批次匯入股票歷史價格資料（支援 Big5 編碼）';

    protected $successCount = 0;
    protected $failureCount = 0;

    public function handle()
    {
        $path = $this->argument('path');
        $isBatch = $this->option('batch');
        $symbol = $this->option('symbol');
        $encoding = $this->option('encoding');
        $delimiter = $this->option('delimiter');
        $truncate = $this->option('truncate');

        $this->info('========================================');
        $this->info('📊 股票歷史價格匯入工具');
        $this->info('========================================');

        if ($truncate) {
            if ($this->confirm('⚠️  確定要清空 stock_prices 資料表嗎？')) {
                DB::table('stock_prices')->truncate();
                $this->info('✓ 已清空資料表');
            } else {
                return Command::SUCCESS;
            }
        }

        try {
            if ($isBatch) {
                $this->processBatchImport($path, $symbol, $encoding, $delimiter);
            } else {
                $this->processSingleFile($path, $symbol, $encoding, $delimiter);
            }

            $this->newLine();
            $this->info('========================================');
            $this->info('📈 批次匯入完成');
            $this->info('========================================');
            $this->info("成功匯入: {$this->successCount} 筆");
            if ($this->failureCount > 0) {
                $this->warn("失敗檔案: {$this->failureCount} 個");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ 匯入失敗: ' . $e->getMessage());
            Log::error('股票價格匯入錯誤', [
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    protected function processBatchImport($directory, $symbol, $encoding, $delimiter)
    {
        $files = glob($directory . '/*.csv');

        if (empty($files)) {
            $this->error("❌ 目錄中沒有找到 CSV 檔案: {$directory}");
            return;
        }

        $this->info("✓ 找到 " . count($files) . " 個檔案");
        $this->newLine();

        foreach ($files as $file) {
            $fileName = basename($file);

            preg_match('/(\d+)\.csv$/', $fileName, $matches);
            $month = $matches[1] ?? null;

            $this->info("📁 處理檔案: {$fileName}" . ($month ? " (第 {$month} 月)" : ""));

            try {
                $result = $this->importFile($file, $symbol, $encoding, $delimiter);

                if ($result) {
                    $this->info("✓ 成功");
                    $this->successCount++;
                } else {
                    $this->warn("✗ 失敗");
                    $this->failureCount++;
                }
            } catch (\Exception $e) {
                $this->error("✗ 錯誤: " . $e->getMessage());
                $this->failureCount++;
            }

            $this->newLine();
        }
    }

    protected function processSingleFile($filePath, $symbol, $encoding, $delimiter)
    {
        if (!file_exists($filePath)) {
            $this->error("❌ 檔案不存在: {$filePath}");
            return;
        }

        $this->info("📁 處理檔案: " . basename($filePath));

        $result = $this->importFile($filePath, $symbol, $encoding, $delimiter);

        if ($result) {
            $this->info("✓ 匯入成功");
            $this->successCount++;
        } else {
            $this->error("✗ 匯入失敗");
            $this->failureCount++;
        }
    }

    protected function importFile($filePath, $symbolFilter, $encoding, $delimiter)
    {
        $this->info("⏳ 正在讀取檔案...");

        $rows = $this->readCsv($filePath, $encoding, $delimiter);

        if (empty($rows)) {
            $this->warn("⚠️  檔案是空的");
            return false;
        }

        $this->info("✓ 找到 " . count($rows) . " 筆資料");

        // 第一行是標題（例如：114年01月 2330 台積電 各日成交資訊）
        $titleRow = array_shift($rows);
        $titleRow = $this->cleanHeaders($titleRow);
        $this->comment("標題: " . implode(' ', $titleRow));

        // 第二行才是欄位名稱
        if (empty($rows)) {
            $this->warn("⚠️  檔案沒有欄位定義");
            return false;
        }

        $headers = array_shift($rows);
        $headers = $this->cleanHeaders($headers);

        $this->comment("欄位: " . implode(', ', array_slice($headers, 0, 5)));

        // 檢查是否有必要欄位
        $requiredFields = ['日期', '收盤價'];
        $missingFields = array_diff($requiredFields, $headers);

        if (!empty($missingFields)) {
            $this->error("❌ 缺少必要欄位: " . implode(', ', $missingFields));
            $this->info("💡 欄位名稱必須包含: " . implode(', ', $requiredFields));
            $this->comment("💡 當前欄位: " . implode(', ', $headers));
            return false;
        }

        // 從標題行提取股票代號（例如：114年01月 2330 台積電）
        $stockSymbol = $this->extractSymbolFromTitle($titleRow);

        return $this->importData($headers, $rows, $symbolFilter, $stockSymbol);
    }

    protected function readCsv($filePath, $encoding, $delimiter)
    {
        $rows = [];
        $handle = fopen($filePath, 'r');

        if (!$handle) {
            throw new \Exception("無法開啟檔案: {$filePath}");
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
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

    protected function cleanHeaders($headers)
    {
        return array_map(function ($header) {
            $header = str_replace("\xEF\xBB\xBF", '', $header);
            return trim($header);
        }, $headers);
    }

    protected function importData($headers, $rows, $symbolFilter, $stockSymbol = null)
    {
        $imported = 0;
        $skipped = 0;
        $errors = 0;

        // 如果沒有從標題提取到股票代號，則從資料中讀取
        if ($stockSymbol) {
            $this->comment("  股票代號: {$stockSymbol}");

            // 如果指定了篩選且不符合，跳過整個檔案
            if ($symbolFilter && $stockSymbol !== $symbolFilter) {
                $this->warn("  ⊙ 股票代號不符合篩選條件，跳過檔案");
                return false;
            }
        }

        foreach ($rows as $row) {
            try {
                if (empty(array_filter($row))) {
                    $skipped++;
                    continue;
                }

                if (count($row) !== count($headers)) {
                    if (count($row) < count($headers)) {
                        $row = array_pad($row, count($headers), '');
                    } else {
                        $row = array_slice($row, 0, count($headers));
                    }
                }

                $data = array_combine($headers, $row);

                // 使用標題行的股票代號或從資料中讀取
                $symbol = $stockSymbol ?: trim($data['證券代號'] ?? '');
                $tradeDate = $this->parseDate($data['日期'] ?? '');
                $closePrice = $this->parseNumber($data['收盤價'] ?? '');

                if (empty($symbol) || !$tradeDate || $closePrice === null) {
                    $skipped++;
                    continue;
                }

                if ($symbolFilter && $symbol !== $symbolFilter) {
                    $skipped++;
                    continue;
                }

                $stock = Stock::where('symbol', $symbol)->first();
                if (!$stock) {
                    $stock = Stock::create([
                        'symbol' => $symbol,
                        'name' => $data['證券名稱'] ?? $symbol,
                        'market' => 'TSE',
                        'is_active' => true,
                    ]);
                }

                StockPrice::updateOrCreate(
                    [
                        'stock_id' => $stock->id,
                        'trade_date' => $tradeDate,
                    ],
                    [
                        'open' => $this->parseNumber($data['開盤價'] ?? null),
                        'high' => $this->parseNumber($data['最高價'] ?? null),
                        'low' => $this->parseNumber($data['最低價'] ?? null),
                        'close' => $closePrice,
                        'volume' => $this->parseNumber($data['成交股數'] ?? 0),
                        'turnover' => $this->parseNumber($data['成交金額'] ?? 0),
                        'transaction' => $this->parseNumber($data['成交筆數'] ?? 0),
                    ]
                );

                $imported++;
            } catch (\Exception $e) {
                $errors++;
                Log::warning('股價資料匯入失敗', [
                    'symbol' => $symbol ?? 'unknown',
                    'data' => $data ?? $row,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info("  ✓ 成功: {$imported} 筆");
        if ($skipped > 0) {
            $this->comment("  ⊙ 跳過: {$skipped} 筆");
        }
        if ($errors > 0) {
            $this->warn("  ✗ 錯誤: {$errors} 筆");
        }

        return $imported > 0;
    }

    protected function parseDate($dateString)
    {
        try {
            $dateString = trim($dateString);

            if (empty($dateString)) {
                return null;
            }

            // 民國年格式 (114/01/02 或 114-01-02)
            if (preg_match('/^(\d{2,3})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $dateString, $matches)) {
                $rocYear = intval($matches[1]);
                $month = intval($matches[2]);
                $day = intval($matches[3]);

                $year = $rocYear + 1911;

                return Carbon::createFromDate($year, $month, $day)->format('Y-m-d');
            }

            // 西元年格式
            return Carbon::parse($dateString)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('日期解析失敗', [
                'date_string' => $dateString,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function parseNumber($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace([',', ' '], '', trim($value));

        if ($value === '--' || $value === 'N/A' || $value === '-') {
            return null;
        }

        return is_numeric($value) ? floatval($value) : null;
    }

    /**
     * 從標題行提取股票代號
     * 例如：["114年01月", "2330", "台積電", "各日成交資訊"] -> "2330"
     */
    protected function extractSymbolFromTitle($titleRow)
    {
        // 將標題行合併成字串
        $titleString = implode(' ', $titleRow);

        // 使用正則表達式匹配 4 位數股票代號
        if (preg_match('/\b(\d{4})\b/', $titleString, $matches)) {
            return $matches[1];
        }

        // 如果標題行是陣列且第二個元素是數字
        if (isset($titleRow[1]) && is_numeric($titleRow[1])) {
            return trim($titleRow[1]);
        }

        return null;
    }
}
