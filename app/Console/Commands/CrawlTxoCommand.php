<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * TXO 選擇權爬蟲指令（新版）
 * 
 * 使用期交所 OpenData API 取得台指選擇權資料
 * API: https://openapi.taifex.com.tw/v1/DailyMarketReportOpt
 * 
 * 使用方式:
 * php artisan crawl:txo              # 取得最新資料
 * php artisan crawl:txo --test       # 測試模式（不寫入資料庫）
 * php artisan crawl:txo --force      # 強制重新抓取（忽略快取）
 */
class CrawlTxoCommand extends Command
{
    protected $signature = 'crawl:txo
                            {--test : 測試模式，只顯示資料不寫入}
                            {--force : 強制重新抓取}
                            {--limit=0 : 限制處理筆數，0=全部}';

    protected $description = '爬取 TXO (台指選擇權) 資料 - 使用期交所 OpenData';

    /**
     * API 基礎設定
     */
    protected $apiUrl = 'https://openapi.taifex.com.tw/v1/DailyMarketReportOpt';
    protected $timeout = 60;

    public function handle()
    {
        $this->info('');
        $this->info('╔════════════════════════════════════════╗');
        $this->info('║       TXO 選擇權爬蟲 (新版)           ║');
        $this->info('╠════════════════════════════════════════╣');
        $this->info('║  資料來源: 期交所 OpenData API         ║');
        $this->info('║  標的物: TXO (台指選擇權)              ║');
        $this->info('╚════════════════════════════════════════╝');
        $this->info('');

        $isTest = $this->option('test');
        $limit = (int) $this->option('limit');

        if ($isTest) {
            $this->warn('⚠️  測試模式：不會寫入資料庫');
            $this->info('');
        }

        // 1. 從 API 取得資料
        $this->info('📡 正在連接期交所 API...');
        $this->info("   URL: {$this->apiUrl}");
        $this->info('');

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                ->get($this->apiUrl);

            if (!$response->successful()) {
                $this->error("❌ API 回應錯誤: HTTP {$response->status()}");
                $this->error("   回應內容: " . substr($response->body(), 0, 200));
                return Command::FAILURE;
            }

            $rawData = $response->json();

            if (empty($rawData)) {
                $this->error('❌ API 回傳空資料');
                $this->warn('可能原因:');
                $this->line('  - 非交易日（週末或假日）');
                $this->line('  - 資料尚未更新（收盤後約 30-60 分鐘）');
                $this->line('  - API 暫時維護中');
                return Command::FAILURE;
            }

            $this->info("✅ 成功取得 " . count($rawData) . " 筆原始資料");

        } catch (\Exception $e) {
            $this->error("❌ API 連接失敗: " . $e->getMessage());
            Log::error('TXO 爬蟲 API 錯誤', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        // 2. 過濾 TXO 資料
        $this->info('');
        $this->info('🔍 過濾 TXO 資料...');

        $txoData = collect($rawData)->filter(function ($item) {
            // 只取 TXO（台指選擇權）
            $productId = $item['SettleMonth'] ?? $item['ContractMonth'] ?? '';
            $commodityId = $item['ProductID'] ?? $item['CommodityID'] ?? '';
            
            return str_contains($commodityId, 'TXO') || 
                   str_contains($productId, 'TXO') ||
                   ($commodityId === 'TXO');
        });

        if ($txoData->isEmpty()) {
            // 如果上面的過濾沒找到，嘗試其他方式
            $txoData = collect($rawData)->filter(function ($item) {
                // 期交所資料中，選擇權通常有履約價
                return isset($item['StrikePrice']) && 
                       floatval($item['StrikePrice']) > 0;
            });
        }

        if ($txoData->isEmpty()) {
            $this->error('❌ 找不到 TXO 資料');
            $this->info('');
            $this->info('📋 原始資料欄位參考:');
            if (!empty($rawData[0])) {
                foreach (array_keys($rawData[0]) as $key) {
                    $this->line("   - {$key}");
                }
            }
            return Command::FAILURE;
        }

        $this->info("✅ 過濾出 {$txoData->count()} 筆 TXO 資料");

        // 限制筆數
        if ($limit > 0) {
            $txoData = $txoData->take($limit);
            $this->warn("⚠️  限制處理 {$limit} 筆");
        }

        // 3. 顯示資料範例
        $this->info('');
        $this->info('📋 資料範例 (前 3 筆):');
        $this->table(
            ['欄位', '值'],
            collect($txoData->first())->map(function ($value, $key) {
                return [$key, is_array($value) ? json_encode($value) : $value];
            })->take(15)->toArray()
        );

        // 4. 解析並轉換資料
        $this->info('');
        $this->info('⚙️  解析資料中...');

        $parsedData = $this->parseData($txoData);

        if ($parsedData->isEmpty()) {
            $this->error('❌ 資料解析失敗');
            return Command::FAILURE;
        }

        $this->info("✅ 解析完成: {$parsedData->count()} 筆有效資料");

        // 統計
        $callCount = $parsedData->where('option_type', 'call')->count();
        $putCount = $parsedData->where('option_type', 'put')->count();
        $this->info("   📊 買權 (Call): {$callCount} 筆");
        $this->info("   📊 賣權 (Put): {$putCount} 筆");

        // 取得資料日期
        $dataDate = $parsedData->first()['trade_date'] ?? now()->format('Y-m-d');
        $this->info("   📅 資料日期: {$dataDate}");

        // 5. 測試模式 - 顯示解析後的資料
        if ($isTest) {
            $this->info('');
            $this->info('📋 解析後資料範例:');
            $sample = $parsedData->take(5);
            foreach ($sample as $item) {
                $this->line("   {$item['option_code']} | {$item['option_type']} | 履約價:{$item['strike_price']} | 收盤:{$item['close']} | IV:{$item['implied_volatility']}");
            }
            $this->info('');
            $this->warn('⚠️  測試模式結束，未寫入資料庫');
            return Command::SUCCESS;
        }

        // 6. 寫入資料庫
        $this->info('');
        $this->info('💾 寫入資料庫...');

        $result = $this->saveToDatabase($parsedData);

        $this->info('');
        $this->info('╔════════════════════════════════════════╗');
        $this->info('║             執行結果                   ║');
        $this->info('╠════════════════════════════════════════╣');
        $this->info("║  新增選擇權: {$result['options_created']} 筆");
        $this->info("║  更新選擇權: {$result['options_updated']} 筆");
        $this->info("║  新增價格: {$result['prices_created']} 筆");
        $this->info("║  更新價格: {$result['prices_updated']} 筆");
        $this->info("║  有 IV 值: {$result['with_iv']} 筆");
        $this->info('╚════════════════════════════════════════╝');

        Log::info('TXO 爬蟲執行完成', $result);

        return Command::SUCCESS;
    }

    /**
     * 解析 API 資料
     */
    protected function parseData($txoData): \Illuminate\Support\Collection
    {
        return $txoData->map(function ($item) {
            try {
                // 期交所 OpenAPI 欄位對應
                // 參考: https://openapi.taifex.com.tw/v1/DailyMarketReportOpt
                
                // 日期
                $dateStr = $item['Date'] ?? $item['TradeDate'] ?? null;
                $tradeDate = $dateStr ? $this->parseDate($dateStr) : now()->format('Y-m-d');

                // 到期月份 (格式可能是 202512 或 202512W2)
                $contractMonth = $item['SettleMonth'] ?? $item['ContractMonth'] ?? '';
                $expiryDate = $this->parseExpiryMonth($contractMonth, $tradeDate);

                // 履約價
                $strikePrice = floatval($item['StrikePrice'] ?? 0);
                if ($strikePrice <= 0) return null;

                // 選擇權類型 (Call/Put)
                $optionType = $this->parseOptionType($item['CallPut'] ?? $item['OptionType'] ?? '');
                if (!$optionType) return null;

                // 生成選擇權代碼
                $optionCode = $this->generateOptionCode($contractMonth, $strikePrice, $optionType);

                // 價格資料
                $open = $this->cleanNumber($item['Open'] ?? $item['OpeningPrice'] ?? 0);
                $high = $this->cleanNumber($item['High'] ?? $item['HighestPrice'] ?? 0);
                $low = $this->cleanNumber($item['Low'] ?? $item['LowestPrice'] ?? 0);
                $close = $this->cleanNumber($item['Close'] ?? $item['ClosingPrice'] ?? $item['Last'] ?? 0);
                $settlement = $this->cleanNumber($item['Settle'] ?? $item['SettlePrice'] ?? $item['Settlement'] ?? $close);

                // 成交量與未平倉
                $volume = intval($this->cleanNumber($item['Volume'] ?? $item['TradingVolume'] ?? 0));
                $openInterest = intval($this->cleanNumber($item['OI'] ?? $item['OpenInterest'] ?? 0));

                // 隱含波動率 (如果 API 有提供)
                $iv = $this->cleanNumber($item['IV'] ?? $item['ImpliedVolatility'] ?? 0);
                // 轉換成小數 (如果是百分比)
                if ($iv > 1) {
                    $iv = $iv / 100;
                }

                // Greeks (如果 API 有提供)
                $delta = $this->cleanNumber($item['Delta'] ?? 0);
                $gamma = $this->cleanNumber($item['Gamma'] ?? 0);
                $theta = $this->cleanNumber($item['Theta'] ?? 0);
                $vega = $this->cleanNumber($item['Vega'] ?? 0);

                return [
                    'option_code' => $optionCode,
                    'underlying' => 'TXO',
                    'option_type' => $optionType,
                    'strike_price' => $strikePrice,
                    'expiry_date' => $expiryDate,
                    'contract_month' => $contractMonth,
                    'trade_date' => $tradeDate,
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                    'settlement' => $settlement,
                    'volume' => $volume,
                    'open_interest' => $openInterest,
                    'implied_volatility' => $iv > 0 ? $iv : null,
                    'delta' => $delta != 0 ? $delta : null,
                    'gamma' => $gamma != 0 ? $gamma : null,
                    'theta' => $theta != 0 ? $theta : null,
                    'vega' => $vega != 0 ? $vega : null,
                ];

            } catch (\Exception $e) {
                Log::warning('TXO 資料解析錯誤', [
                    'item' => $item,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        })->filter()->values();
    }

    /**
     * 儲存到資料庫
     */
    protected function saveToDatabase($parsedData): array
    {
        $result = [
            'options_created' => 0,
            'options_updated' => 0,
            'prices_created' => 0,
            'prices_updated' => 0,
            'with_iv' => 0,
        ];

        $progressBar = $this->output->createProgressBar($parsedData->count());
        $progressBar->start();

        DB::beginTransaction();

        try {
            foreach ($parsedData as $data) {
                // 1. 新增或更新 options 表
                $option = Option::updateOrCreate(
                    [
                        'option_code' => $data['option_code'],
                    ],
                    [
                        'underlying' => $data['underlying'],
                        'option_type' => $data['option_type'],
                        'strike_price' => $data['strike_price'],
                        'expiry_date' => $data['expiry_date'],
                        'contract_size' => 50, // TXO 契約乘數
                        'exercise_style' => 'european', // 歐式
                        'is_active' => Carbon::parse($data['expiry_date'])->isFuture(),
                    ]
                );

                if ($option->wasRecentlyCreated) {
                    $result['options_created']++;
                } else {
                    $result['options_updated']++;
                }

                // 2. 新增或更新 option_prices 表
                $priceData = [
                    'option_id' => $option->id,
                    'trade_date' => $data['trade_date'],
                ];

                $priceValues = [
                    'open' => $data['open'],
                    'high' => $data['high'],
                    'low' => $data['low'],
                    'close' => $data['close'],
                    'settlement' => $data['settlement'],
                    'volume' => $data['volume'],
                    'open_interest' => $data['open_interest'],
                    'implied_volatility' => $data['implied_volatility'],
                    'delta' => $data['delta'],
                    'gamma' => $data['gamma'],
                    'theta' => $data['theta'],
                    'vega' => $data['vega'],
                ];

                $price = OptionPrice::updateOrCreate($priceData, $priceValues);

                if ($price->wasRecentlyCreated) {
                    $result['prices_created']++;
                } else {
                    $result['prices_updated']++;
                }

                if ($data['implied_volatility'] > 0) {
                    $result['with_iv']++;
                }

                $progressBar->advance();
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("資料庫寫入失敗: " . $e->getMessage());
            Log::error('TXO 爬蟲資料庫錯誤', ['error' => $e->getMessage()]);
            throw $e;
        }

        $progressBar->finish();
        $this->info('');

        return $result;
    }

    /**
     * 解析日期
     */
    protected function parseDate(string $dateStr): string
    {
        // 處理各種日期格式
        // 20251126 -> 2025-11-26
        // 2025/11/26 -> 2025-11-26
        // 114/11/26 -> 2025-11-26 (民國年)
        
        $dateStr = trim($dateStr);
        
        // 民國年格式 (114/11/26)
        if (preg_match('/^(\d{2,3})\/(\d{1,2})\/(\d{1,2})$/', $dateStr, $matches)) {
            $year = intval($matches[1]) + 1911;
            return sprintf('%04d-%02d-%02d', $year, $matches[2], $matches[3]);
        }

        // YYYYMMDD 格式
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $dateStr, $matches)) {
            return sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]);
        }

        // 嘗試 Carbon 解析
        try {
            return Carbon::parse($dateStr)->format('Y-m-d');
        } catch (\Exception $e) {
            return now()->format('Y-m-d');
        }
    }

    /**
     * 解析到期月份
     */
    protected function parseExpiryMonth(string $contractMonth, string $referenceDate): string
    {
        // 格式: 202512 或 202512W2 (週選)
        $contractMonth = trim($contractMonth);
        
        // 移除週選標記
        $monthPart = preg_replace('/W\d+$/', '', $contractMonth);
        
        if (strlen($monthPart) === 6) {
            // 202512 -> 2025-12-15 (假設每月第三個週三)
            $year = substr($monthPart, 0, 4);
            $month = substr($monthPart, 4, 2);
            
            // 找到該月份的第三個週三
            $date = Carbon::createFromDate($year, $month, 1);
            $wednesdayCount = 0;
            
            while ($wednesdayCount < 3) {
                if ($date->isWednesday()) {
                    $wednesdayCount++;
                    if ($wednesdayCount === 3) break;
                }
                $date->addDay();
            }
            
            return $date->format('Y-m-d');
        }

        // 無法解析，使用下個月底
        return Carbon::parse($referenceDate)->addMonth()->endOfMonth()->format('Y-m-d');
    }

    /**
     * 解析選擇權類型
     */
    protected function parseOptionType(string $type): ?string
    {
        $type = strtoupper(trim($type));
        
        if (in_array($type, ['C', 'CALL', '買權', 'BUY'])) {
            return 'call';
        }
        
        if (in_array($type, ['P', 'PUT', '賣權', 'SELL'])) {
            return 'put';
        }

        return null;
    }

    /**
     * 生成選擇權代碼
     */
    protected function generateOptionCode(string $contractMonth, float $strikePrice, string $optionType): string
    {
        $typeCode = $optionType === 'call' ? 'C' : 'P';
        $strike = intval($strikePrice);
        
        return "TXO{$contractMonth}{$typeCode}{$strike}";
    }

    /**
     * 清理數字
     */
    protected function cleanNumber($value): float
    {
        if (is_numeric($value)) {
            return floatval($value);
        }
        
        if (is_string($value)) {
            // 移除逗號和空白
            $cleaned = str_replace([',', ' ', '-'], '', trim($value));
            return is_numeric($cleaned) ? floatval($cleaned) : 0;
        }
        
        return 0;
    }
}