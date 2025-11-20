<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * 期交所 OpenAPI 服務（修正版）
 * 
 * 使用 TAIFEX OpenAPI 取得選擇權資料
 * API 文件: https://openapi.taifex.com.tw
 * 
 * 注意事項:
 * 1. API 只返回最新資料，不支援歷史查詢
 * 2. 資料通常在收盤後 30-60 分鐘更新
 * 3. 只抓取 TXO (台指選擇權) 資料
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
     *
     * @param string|null $date 日期參數（僅用於記錄，API 總是返回最新資料）
     * @return Collection
     */
    public function getDailyOptionsData(?string $date = null): Collection
    {
        $url = $this->baseUrl . '/DailyMarketReportOpt';

        Log::info('呼叫期交所 OpenAPI', [
            'url' => $url,
            'requested_date' => $date ?? 'latest',
            'note' => 'API 只返回最新資料'
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
                Log::warning('API 返回空資料', [
                    'note' => '可能是假日或資料尚未更新'
                ]);
                return collect();
            }

            // 檢測實際返回的日期
            $actualDate = null;
            if (!empty($data)) {
                $actualDate = $this->parseTradeDate($data[0]);
            }

            Log::info('API 請求成功', [
                'total_records' => count($data),
                'actual_date' => $actualDate,
                'requested_date' => $date
            ]);

            // 如果指定日期與實際日期不符，發出警告
            if ($date && $actualDate && $date !== $actualDate) {
                Log::warning('API 返回的日期與請求不符', [
                    'requested' => $date,
                    'actual' => $actualDate,
                    'note' => 'API 只提供最新資料，將使用實際日期'
                ]);
            }

            // 過濾並轉換資料
            $filtered = $this->filterAndTransform($data);

            Log::info('TXO 資料過濾完成', [
                'original_count' => count($data),
                'filtered_count' => $filtered->count(),
                'actual_date' => $actualDate
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
     *
     * @param array $data
     * @return Collection
     */
    protected function filterAndTransform(array $data): Collection
    {
        $results = collect();

        foreach ($data as $item) {
            // 只過濾條件: Contract 必須是 TXO
            $contract = $item['Contract'] ?? '';
            if ($contract !== 'TXO') {
                continue;
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
     *
     * @param array $item
     * @return array|null
     */
    protected function transformRecord(array $item): ?array
    {
        try {
            // 基本欄位
            $contract = $item['Contract'] ?? '';
            $contractMonth = $item['ContractMonth(Week)'] ?? '';
            $strikePrice = $this->cleanNumber($item['StrikePrice'] ?? 0);
            $callPut = $item['Call/Put'] ?? '';

            // 判斷選擇權類型
            $optionType = match ($callPut) {
                '買權', 'Call', 'C' => 'CALL',
                '賣權', 'Put', 'P' => 'PUT',
                default => null
            };

            if (!$optionType || $strikePrice <= 0) {
                return null;
            }

            // 生成選擇權代碼 (格式: TXO 202412 C 21000)
            $optionCode = sprintf(
                '%s %s %s %d',
                $contract,
                $contractMonth,
                substr($optionType, 0, 1),
                intval($strikePrice)
            );

            // 解析到期月份 (格式: 202412)
            $expiryDate = $this->parseExpiryDate($contractMonth);

            // 價格資訊
            $openPrice = $this->cleanNumber($item['OpeningPrice'] ?? 0);
            $highPrice = $this->cleanNumber($item['HighestPrice'] ?? 0);
            $lowPrice = $this->cleanNumber($item['LowestPrice'] ?? 0);
            $closePrice = $this->cleanNumber($item['ClosingPrice'] ?? 0);
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
                'option_code' => $optionCode,
                'underlying' => 'TXO',
                'contract' => $contract,
                'strike_price' => $strikePrice,
                'option_type' => $optionType,
                'expiry_date' => $expiryDate,
                'expiry_month' => $contractMonth,
                'open_price' => $openPrice,
                'high_price' => $highPrice,
                'low_price' => $lowPrice,
                'close_price' => $closePrice,
                'settlement_price' => $settlementPrice,
                'change' => $this->cleanNumber($item['Change'] ?? 0),
                'change_percent' => $this->cleanNumber($item['ChangePercent'] ?? 0),
                'volume_total' => $volume,
                'volume_general' => 0,
                'volume_afterhours' => 0,
                'open_interest' => $openInterest,
                'best_bid' => $bestBid,
                'best_ask' => $bestAsk,
                'bid_volume' => 0,
                'ask_volume' => 0,
                'spread' => round($spread, 2),
                'mid_price' => round($midPrice, 2),
                'date' => $date ?? date('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        } catch (\Exception $e) {
            Log::warning('轉換記錄失敗', [
                'error' => $e->getMessage(),
                'item' => $item
            ]);
            return null;
        }
    }

    /**
     * 清理數字
     *
     * @param mixed $value
     * @return float
     */
    protected function cleanNumber($value): float
    {
        if (empty($value) || $value === '-' || $value === 'N/A') {
            return 0;
        }

        // 移除逗號和其他非數字字符
        $cleaned = str_replace(',', '', $value);
        $cleaned = preg_replace('/[^0-9.-]/', '', $cleaned);

        return floatval($cleaned);
    }

    /**
     * 清理交易量
     *
     * @param mixed $value
     * @return int
     */
    protected function cleanVolume($value): int
    {
        return intval($this->cleanNumber($value));
    }

    /**
     * 解析到期日期
     *
     * @param string $contractMonth 格式: 202412
     * @return string|null
     */
    protected function parseExpiryDate($contractMonth): ?string
    {
        try {
            // 格式: 202412 -> 2024-12
            if (preg_match('/^(\d{4})(\d{2})$/', $contractMonth, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                
                // 假設到期日為該月第三個星期三（選擇權到期規則）
                $firstDay = Carbon::createFromFormat('Y-m-d', "{$year}-{$month}-01");
                $thirdWednesday = $firstDay->copy()->nthOfMonth(3, Carbon::WEDNESDAY);
                
                return $thirdWednesday->format('Y-m-d');
            }
            
            return null;
        } catch (\Exception $e) {
            Log::warning('解析到期日期失敗', [
                'contract_month' => $contractMonth,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 解析交易日期
     *
     * @param array $item
     * @return string|null
     */
    protected function parseTradeDate($item): ?string
    {
        // 嘗試多個可能的日期欄位
        $dateFields = ['TradeDate', 'Date', '交易日期', '日期'];
        
        foreach ($dateFields as $field) {
            if (!empty($item[$field])) {
                $dateValue = $item[$field];
                
                // 處理各種日期格式
                // 格式1: 20241113
                if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $dateValue, $matches)) {
                    return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
                }
                
                // 格式2: 2024/11/13 或 2024-11-13
                if (preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})$/', $dateValue, $matches)) {
                    return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
                }
                
                // 格式3: 113/11/13 (民國年)
                if (preg_match('/^(\d{3})[-\/](\d{2})[-\/](\d{2})$/', $dateValue, $matches)) {
                    $year = intval($matches[1]) + 1911;
                    return "{$year}-{$matches[2]}-{$matches[3]}";
                }
            }
        }
        
        // 如果都沒有，使用當前日期
        return date('Y-m-d');
    }

    /**
     * 檢查資料是否可用
     *
     * @return bool
     */
    public function checkDataAvailable(): bool
    {
        try {
            $data = $this->getDailyOptionsData();
            return !$data->isEmpty();
        } catch (\Exception $e) {
            Log::error('檢查資料可用性失敗', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 取得最新資料的日期
     *
     * @return string|null
     */
    public function getLatestDataDate(): ?string
    {
        try {
            $data = $this->getDailyOptionsData();
            
            if (!$data->isEmpty()) {
                $firstItem = $data->first();
                return $firstItem['date'] ?? null;
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('取得最新資料日期失敗', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 取得特定履約價的選擇權資料
     *
     * @param float $strikePrice
     * @param string|null $date
     * @return Collection
     */
    public function getOptionsByStrike(float $strikePrice, ?string $date = null): Collection
    {
        $data = $this->getDailyOptionsData($date);
        
        return $data->filter(function ($item) use ($strikePrice) {
            return $item['strike_price'] == $strikePrice;
        });
    }

    /**
     * 取得價平附近的選擇權資料
     *
     * @param float $spotPrice 現貨價格
     * @param int $strikeCount 上下各取幾檔
     * @param string|null $date
     * @return Collection
     */
    public function getNearATMOptions(float $spotPrice, int $strikeCount = 5, ?string $date = null): Collection
    {
        $data = $this->getDailyOptionsData($date);
        
        if ($data->isEmpty()) {
            return collect();
        }
        
        // 找出所有不重複的履約價並排序
        $strikes = $data->pluck('strike_price')
            ->unique()
            ->sort()
            ->values();
        
        // 找出最接近現貨價格的履約價
        $closestIndex = $strikes->search(function ($strike) use ($spotPrice) {
            return $strike >= $spotPrice;
        });
        
        if ($closestIndex === false) {
            $closestIndex = $strikes->count() - 1;
        }
        
        // 取得上下各 N 檔
        $startIndex = max(0, $closestIndex - $strikeCount);
        $endIndex = min($strikes->count() - 1, $closestIndex + $strikeCount);
        
        $selectedStrikes = $strikes->slice($startIndex, $endIndex - $startIndex + 1);
        
        // 過濾出選定的履約價資料
        return $data->filter(function ($item) use ($selectedStrikes) {
            return $selectedStrikes->contains($item['strike_price']);
        });
    }
}