<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Option;
use App\Models\OptionPrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 從期交所格式 CSV 匯入選擇權資料（改進版 v2）
 * 自動處理編碼和欄位數量不一致問題
 */
class ImportTaifexDataCommand extends Command
{
    protected $signature = 'import:taifex
                            {file : CSV 檔案路徑}
                            {--create-options : 自動建立選擇權基本資料}
                            {--truncate : 清空現有資料}
                            {--encoding=BIG5 : 檔案編碼 (UTF-8/BIG5)}';

    protected $description = '從期交所 CSV 匯入選擇權資料（v2 改進版）';

    public function handle()
    {
        $filePath = $this->argument('file');
        $createOptions = $this->option('create-options');
        $truncate = $this->option('truncate');
        $encoding = $this->option('encoding');

        if (!file_exists($filePath)) {
            $this->error("❌ 檔案不存在: {$filePath}");
            return Command::FAILURE;
        }

        $this->info('========================================');
        $this->info('📊 期交所選擇權資料匯入（v2 改進版）');
        $this->info('========================================');
        $this->info("檔案: {$filePath}");
        $this->info("編碼: {$encoding}");
        $this->info("自動建立選擇權: " . ($createOptions ? '是' : '否'));
        $this->info('========================================');
        $this->newLine();

        try {
            // 讀取 CSV
            $this->info('⏳ 正在讀取檔案...');
            $rows = $this->readCsv($filePath, $encoding);

            if (empty($rows)) {
                $this->error('❌ 檔案是空的！');
                return Command::FAILURE;
            }

            // 取得表頭
            $headers = array_shift($rows);
            $headers = $this->cleanHeaders($headers);

            $this->info('✓ 找到 ' . count($rows) . ' 筆資料');
            $this->info('✓ 表頭欄位數: ' . count($headers));
            $this->newLine();

            // 顯示欄位名稱（前10個）
            $this->comment('主要欄位: ' . implode(', ', array_slice($headers, 0, 10)));
            $this->newLine();

            // 驗證必要欄位
            $requiredFields = ['交易日期', '到期月份(週別)', '履約價', '買賣權'];
            $missingFields = array_diff($requiredFields, $headers);

            if (!empty($missingFields)) {
                $this->error('❌ 缺少必要欄位: ' . implode(', ', $missingFields));
                return Command::FAILURE;
            }

            // 清空資料表
            if ($truncate) {
                $this->warn('⚠️  即將清空資料表...');
                if ($this->confirm('確定要繼續嗎？')) {
                    DB::table('option_prices')->truncate();
                    if ($createOptions) {
                        DB::table('options')->truncate();
                    }
                    $this->info('✓ 已清空資料表');
                    $this->newLine();
                }
            }

            // 執行匯入
            return $this->importData($headers, $rows, $createOptions);
        } catch (\Exception $e) {
            $this->error('❌ 匯入失敗: ' . $e->getMessage());
            Log::error('期交所資料匯入錯誤', [
                'file' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * 讀取 CSV 檔案
     */
    protected function readCsv($filePath, $encoding)
    {
        $rows = [];
        $handle = fopen($filePath, 'r');

        if (!$handle) {
            throw new \Exception("無法開啟檔案");
        }

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            // 編碼轉換
            if ($encoding === 'BIG5') {
                $row = array_map(function ($value) {
                    return mb_convert_encoding($value, 'UTF-8', 'BIG5');
                }, $row);
            }

            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    /**
     * 清理表頭
     */
    protected function cleanHeaders($headers)
    {
        return array_map(function ($header) {
            $header = str_replace("\xEF\xBB\xBF", '', $header);
            return trim($header);
        }, $headers);
    }

    /**
     * 匯入資料
     */
    protected function importData($headers, $rows, $createOptions)
    {
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        $successCount = 0;
        $skipCount = 0;
        $errorCount = 0;
        $createdOptions = [];
        $headerCount = count($headers);

        foreach ($rows as $index => $row) {
            try {
                // 跳過空行
                if (empty(array_filter($row))) {
                    $skipCount++;
                    $bar->advance();
                    continue;
                }

                // 🔧 處理欄位數量不一致（關鍵修正）
                if (count($row) !== $headerCount) {
                    if (count($row) < $headerCount) {
                        // 欄位不足：補齊空值
                        $row = array_pad($row, $headerCount, '');
                    } else {
                        // 欄位過多：截斷多餘欄位
                        $row = array_slice($row, 0, $headerCount);
                    }
                }

                // 組合資料
                $data = array_combine($headers, $row);

                // 解析必要欄位
                $tradeDate = $this->parseTaifexDate($data['交易日期'] ?? '');
                $contractMonth = trim($data['到期月份(週別)'] ?? '');
                $strikePrice = $this->parseNumber($data['履約價'] ?? '');
                $optionType = $this->parseOptionType($data['買賣權'] ?? '');

                // 驗證必要欄位
                if (!$tradeDate || !$strikePrice || !$optionType || !$contractMonth) {
                    $skipCount++;
                    $bar->advance();
                    continue;
                }

                // 計算到期日
                $expiryDate = $this->calculateExpiryDate($contractMonth);
                if (!$expiryDate) {
                    $skipCount++;
                    $bar->advance();
                    continue;
                }

                // 生成選擇權代碼
                $optionCode = $this->generateOptionCode($contractMonth, $optionType, $strikePrice);

                // 建立或查找選擇權
                $option = Option::where('option_code', $optionCode)->first();

                if (!$option) {
                    if ($createOptions) {
                        $option = Option::create([
                            'option_code' => $optionCode,
                            'underlying' => 'TXO',
                            'option_type' => $optionType,
                            'strike_price' => $strikePrice,
                            'expiry_date' => $expiryDate,
                            'contract_size' => 1,
                            'is_active' => true,
                        ]);
                        $createdOptions[] = $optionCode;
                    } else {
                        $skipCount++;
                        $bar->advance();
                        continue;
                    }
                }

                // 建立或更新價格資料
                OptionPrice::updateOrCreate(
                    [
                        'option_id' => $option->id,
                        'trade_date' => $tradeDate
                    ],
                    [
                        'open' => $this->parseNumber($data['開盤價'] ?? null),
                        'high' => $this->parseNumber($data['最高價'] ?? null),
                        'low' => $this->parseNumber($data['最低價'] ?? null),
                        'close' => $this->parseNumber($data['收盤價'] ?? null),
                        'volume' => $this->parseNumber($data['成交量'] ?? 0),
                        'settlement_price' => $this->parseNumber($data['結算價'] ?? null),
                        'open_interest' => $this->parseNumber($data['未沖銷契約數'] ?? null),
                    ]
                );

                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                if ($errorCount <= 5) {
                    Log::warning("第 " . ($index + 2) . " 行匯入失敗: " . $e->getMessage());
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // 顯示結果
        $this->info("========================================");
        $this->info("✅ 匯入完成！");
        $this->info("========================================");
        $this->info("   成功: {$successCount} 筆");

        if ($skipCount > 0) {
            $this->warn("   跳過: {$skipCount} 筆");
        }

        if ($errorCount > 0) {
            $this->error("   失敗: {$errorCount} 筆");
        }

        if (!empty($createdOptions)) {
            $uniqueOptions = array_unique($createdOptions);
            $this->info("   建立選擇權: " . count($uniqueOptions) . " 個");
        }

        return Command::SUCCESS;
    }

    protected function parseTaifexDate($value)
    {
        if (empty($value)) return null;

        try {
            if (preg_match('#(\d{4})/(\d{1,2})/(\d{1,2})#', $value, $matches)) {
                return sprintf("%s-%02d-%02d", $matches[1], $matches[2], $matches[3]);
            }
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseOptionType($value)
    {
        $value = trim($value);
        if ($value === '賣權' || strtoupper($value) === 'P') return 'put';
        if ($value === '買權' || strtoupper($value) === 'C') return 'call';
        return null;
    }

    protected function calculateExpiryDate($contractMonth)
    {
        try {
            // 週選擇權: 202501/W5
            if (preg_match('/(\d{4})(\d{2})\/W(\d)/', $contractMonth, $m)) {
                $first = Carbon::create($m[1], $m[2], 1);
                $firstWed = $first->copy()->next(Carbon::WEDNESDAY);
                return $firstWed->addWeeks($m[3] - 1)->format('Y-m-d');
            }

            // 月選擇權: 202501
            if (preg_match('/^(\d{4})(\d{2})$/', $contractMonth, $m)) {
                $first = Carbon::create($m[1], $m[2], 1);
                $firstWed = $first->copy()->next(Carbon::WEDNESDAY);
                return $firstWed->addWeeks(2)->format('Y-m-d');
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function generateOptionCode($contractMonth, $optionType, $strikePrice)
    {
        $contract = str_replace('/', '', $contractMonth);
        $type = strtoupper($optionType[0]);
        return "TXO{$contract}{$type}{$strikePrice}";
    }

    protected function parseNumber($value)
    {
        if ($value === null || $value === '' || $value === '-') return null;
        $value = str_replace([',', ' '], '', $value);
        return is_numeric($value) ? (float) $value : null;
    }
}
