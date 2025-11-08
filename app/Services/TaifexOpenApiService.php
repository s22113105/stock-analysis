<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * 期交所 OpenAPI 服務（正確版本）
 * 根據實際 API 結構調整
 */
class TaifexOpenApiService
{
    protected $baseUrl = 'https://openapi.taifex.com.tw/v1';
    protected $timeout;
    protected $retries;

    public function __construct()
    {
        $this->timeout = config('services.taifex.timeout', 30);
        $this->retries = config('services.taifex.retries', 3);
    }

    /**
     * 取得選擇權每日交易行情（只取 TXO）
     */
    public function getDailyOptionsData(?string $date = null): Collection
    {
        $url = $this->baseUrl . '/DailyMarketReportOpt';

        Log::info('呼叫期交所 OpenAPI', [
            'url' => $url,
            'date' => $date ?? 'latest'
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->retry($this->retries, 1000)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'Mozilla/5.0',
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::error('API 請求失敗', [
                    'status' => $response->status(),
                    'url' => $url
                ]);
                return collect();
            }

            $data = $response->json();

            if (empty($data)) {
                Log::warning('API 返回空資料');
                return collect();
            }

            Log::info('API 請求成功', [
                'total_records' => count($data)
            ]);

            // 過濾並轉換資料
            $filtered = $this->filterAndTransform($data, $date);

            Log::info('TXO 資料過濾完成', [
                'original_count' => count($data),
                'filtered_count' => $filtered->count()
            ]);

            return $filtered;
        } catch (\Exception $e) {
            Log::error('API 請求異常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return collect();
        }
    }

    /**
     * 過濾並轉換資料（只保留 TXO）
     */
    protected function filterAndTransform(array $data, ?string $targetDate = null): Collection
    {
        $results = collect();

        foreach ($data as $item) {
            // 過濾條件 1: Contract 必須是 TXO
            $contract = $item['Contract'] ?? '';
            if ($contract !== 'TXO') {
                continue;
            }

            // 過濾條件 2: 如果指定日期，只要該日期的資料
            if ($targetDate) {
                $tradeDate = $this->parseTradeDate($item);
                if ($tradeDate && $tradeDate !== $targetDate) {
                    continue;
                }
            }

            // 轉換並清理資料
            $transformed = $this->transformRecord($item);

            if ($transformed) {
                $results->push($transformed);
            }
        }

        return $results;
    }

    /**
     * 轉換單筆記錄
     */
    protected function transformRecord(array $item): ?array
    {
        try {
            // 基本欄位
            $contract = $item['Contract'] ?? '';  // TXO
            $contractMonth = $item['ContractMonth(Week)'] ?? '';  // 202511F1 或 202511
            $strikePrice = $this->cleanNumber($item['StrikePrice'] ?? 0);
            $callPut = $item['CallPut'] ?? '';  // 買權/賣權

            // 驗證必要欄位
            if (empty($contract) || empty($contractMonth) || $strikePrice <= 0) {
                return null;
            }

            // 轉換 CallPut
            $optionType = $this->parseOptionType($callPut);
            if (!$optionType) {
                return null;
            }

            // 建立完整的選擇權代碼
            // TXO + 202511F1 + C/P + 24700
            $typeCode = $optionType === 'call' ? 'C' : 'P';
            $optionCode = $contract . $contractMonth . $typeCode . intval($strikePrice);

            // 解析到期日
            $expiryDate = $this->parseExpiryDate($contractMonth);

            // 價格資訊
            $openPrice = $this->cleanNumber($item['Open'] ?? 0);
            $highPrice = $this->cleanNumber($item['High'] ?? 0);
            $lowPrice = $this->cleanNumber($item['Low'] ?? 0);
            $closePrice = $this->cleanNumber($item['Close'] ?? 0);
            $settlementPrice = $this->cleanNumber($item['SettlementPrice'] ?? 0);

            // 交易量資訊
            $volume = $this->cleanVolume($item['Volume'] ?? 0);
            $openInterest = $this->cleanVolume($item['OpenInterest'] ?? 0);

            // 買賣報價
            $bestBid = $this->cleanNumber($item['BestBid'] ?? 0);
            $bestAsk = $this->cleanNumber($item['BestAsk'] ?? 0);

            // 計算欄位
            $spread = $bestAsk > 0 && $bestBid > 0 ? $bestAsk - $bestBid : 0;
            $midPrice = $bestAsk > 0 && $bestBid > 0 ? ($bestAsk + $bestBid) / 2 : 0;

            // 日期處理
            $date = $this->parseTradeDate($item);

            return [
                // 基本資訊
                'option_code' => $optionCode,
                'underlying' => 'TXO',
                'contract' => $contract,
                'strike_price' => $strikePrice,
                'option_type' => $optionType,
                'expiry_date' => $expiryDate,
                'expiry_month' => $contractMonth,

                // 價格資訊
                'open_price' => $openPrice,
                'high_price' => $highPrice,
                'low_price' => $lowPrice,
                'close_price' => $closePrice,
                'settlement_price' => $settlementPrice,

                // 漲跌（API 沒提供，可以後續計算）
                'change' => 0,
                'change_percent' => 0,

                // 交易量資訊
                'volume_total' => $volume,
                'volume_general' => 0,  // API 沒細分
                'volume_afterhours' => 0,
                'open_interest' => $openInterest,

                // 買賣報價
                'best_bid' => $bestBid,
                'best_ask' => $bestAsk,
                'bid_volume' => 0,  // API 沒提供
                'ask_volume' => 0,

                // 計算欄位
                'spread' => $spread,
                'mid_price' => $midPrice,

                // 日期
                'date' => $date ?? now()->format('Y-m-d'),

                // 原始資料
                'raw_data' => $item
            ];
        } catch (\Exception $e) {
            Log::warning('記錄轉換失敗', [
                'error' => $e->getMessage(),
                'item' => array_slice($item, 0, 5)
            ]);
            return null;
        }
    }

    /**
     * 解析選擇權類型
     */
    protected function parseOptionType(string $callPut): ?string
    {
        // 中文
        if (mb_strpos($callPut, '買權') !== false || $callPut === '買') {
            return 'call';
        }
        if (mb_strpos($callPut, '賣權') !== false || $callPut === '賣') {
            return 'put';
        }

        // 英文
        if (stripos($callPut, 'Call') !== false || $callPut === 'C') {
            return 'call';
        }
        if (stripos($callPut, 'Put') !== false || $callPut === 'P') {
            return 'put';
        }

        return null;
    }

    /**
     * 解析到期日
     *
     * @param string $contractMonth 例如: 202511F1, 202511W1, 202511
     * @return string|null
     */
    protected function parseExpiryDate(string $contractMonth): ?string
    {
        try {
            // F 代表 Future（月選擇權），W 代表 Week（週選擇權）

            // 週選擇權: 202511W1, 202511W2, 202511W3, 202511W4
            if (preg_match('/^(\d{4})(\d{2})W(\d+)$/', $contractMonth, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $weekNum = intval($matches[3]);

                $date = Carbon::create($year, $month, 1);
                $nthWednesday = $date->nthOfMonth($weekNum, Carbon::WEDNESDAY);

                return $nthWednesday->format('Y-m-d');
            }

            // 月選擇權: 202511F1, 202511F2 或 202511
            if (preg_match('/^(\d{4})(\d{2})(F\d+)?$/', $contractMonth, $matches)) {
                $year = $matches[1];
                $month = $matches[2];

                // 月選擇權到期日是第三個週三
                $date = Carbon::create($year, $month, 1);
                $thirdWednesday = $date->nthOfMonth(3, Carbon::WEDNESDAY);

                return $thirdWednesday->format('Y-m-d');
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('無法解析到期日', [
                'contract_month' => $contractMonth,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 解析交易日期
     */
    protected function parseTradeDate(array $item): ?string
    {
        $dateStr = $item['Date'] ?? '';

        if (empty($dateStr)) {
            return null;
        }

        try {
            // 格式: 20251107
            if (strlen($dateStr) === 8 && is_numeric($dateStr)) {
                return Carbon::createFromFormat('Ymd', $dateStr)->format('Y-m-d');
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('日期解析失敗', ['date_str' => $dateStr]);
            return null;
        }
    }

    /**
     * 清理數字
     * 處理: null, '-', '', 千分位
     */
    protected function cleanNumber($value): float
    {
        // 處理 '-' 表示無資料
        if (is_null($value) || $value === '' || $value === '-' || $value === '--') {
            return 0.0;
        }

        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }

        return floatval($value);
    }

    /**
     * 清理交易量
     */
    protected function cleanVolume($value): int
    {
        if (is_null($value) || $value === '' || $value === '-' || $value === '--') {
            return 0;
        }

        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }

        return max(0, intval($value));
    }
}
