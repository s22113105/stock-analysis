<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 台灣證券交易所 API 服務
 * * 使用 TWSE OpenAPI 取得股票資料
 * API 文件: https://openapi.twse.com.tw
 */
class TwseApiService
{
    protected $baseUrl;
    protected $openApiUrl;
    protected $timeout;
    protected $retries;

    public function __construct()
    {
        // 舊版 API (用於特定功能)
        $this->baseUrl = config('services.twse.base_url', 'https://www.twse.com.tw');
        
        // OpenAPI (主要使用)
        // ✅ 修正: 加上 /v1 版本號
        $this->openApiUrl = 'https://openapi.twse.com.tw/v1';
        
        $this->timeout = config('services.twse.timeout', 30);
        $this->retries = config('services.twse.retries', 3);
    }

    /**
     * 取得所有股票當日行情 (使用 OpenAPI)
     * * @param string $dateString 日期格式 Ymd (例: 20251113)
     * @return Collection
     */
    public function getStockDayAll($dateString)
    {
        // 快取 key
        $cacheKey = 'twse_all_' . $dateString;

        // 檢查快取
        if (Cache::has($cacheKey)) {
            Log::info("使用快取資料: {$cacheKey}");
            return Cache::get($cacheKey);
        }

        try {
            // OpenAPI URL
            // 注意: STOCK_DAY_ALL 不需要 date 參數，它總是返回最新資料
            $url = "{$this->openApiUrl}/exchangeReport/STOCK_DAY_ALL";
            
            $params = []; 

            Log::info('發送 TWSE OpenAPI 請求', [
                'url' => $url,
                'params' => $params
            ]);

            // 發送請求
            $response = Http::timeout($this->timeout)
                ->retry($this->retries, 1000)
                ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();

                // 檢查是否有資料
                if (empty($data)) {
                    Log::warning("TWSE API 回傳空資料", [
                        'date' => $dateString,
                        'note' => '可能是假日或資料尚未更新'
                    ]);
                    return collect();
                }

                // 轉換資料格式
                $collection = collect($data)->map(function ($item) use ($dateString) {
                    return $this->transformStockData($item, $dateString);
                });

                // 快取 10 分鐘
                Cache::put($cacheKey, $collection, now()->addMinutes(10));

                Log::info("成功取得 {$collection->count()} 筆股票資料");

                return $collection;
            }

            Log::warning("TWSE API 回應非成功狀態", [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500)
            ]);

            return collect();

        } catch (\Exception $e) {
            Log::error("TWSE API 請求失敗", [
                'error' => $e->getMessage(),
                'url' => $url ?? '',
                'params' => $params ?? []
            ]);
            return collect();
        }
    }

    /**
     * 轉換股票資料格式
     * * @param array $item 原始資料
     * @param string $dateString 日期
     * @return array
     */
    protected function transformStockData($item, $dateString)
    {
        // OpenAPI 欄位對應
        // 注意: OpenAPI 的欄位名稱可能是英文
        return [
            'symbol' => $item['Code'] ?? $item['股票代號'] ?? '',
            'name' => $item['Name'] ?? $item['股票名稱'] ?? '',
            // 如果 API 沒有回傳日期，就使用傳入的日期
            'trade_date' => $this->formatDate($dateString),
            'volume' => $this->parseNumber($item['TradeVolume'] ?? $item['成交股數'] ?? 0),
            'turnover' => $this->parseNumber($item['TradeValue'] ?? $item['成交金額'] ?? 0),
            'open' => $this->parsePrice($item['OpeningPrice'] ?? $item['開盤價'] ?? 0),
            'high' => $this->parsePrice($item['HighestPrice'] ?? $item['最高價'] ?? 0),
            'low' => $this->parsePrice($item['LowestPrice'] ?? $item['最低價'] ?? 0),
            'close' => $this->parsePrice($item['ClosingPrice'] ?? $item['收盤價'] ?? 0),
            'change' => $this->parsePrice($item['Change'] ?? $item['漲跌價差'] ?? 0),
            'transaction' => $this->parseNumber($item['Transaction'] ?? $item['成交筆數'] ?? 0),
        ];
    }

    /**
     * 取得單一股票的每日行情 (舊版 API - 取得整月資料)
     * * @param string $symbol 股票代碼
     * @param string|null $date 日期 (Y-m-d)
     * @return Collection
     */
    public function getStockDay($symbol, $date = null)
    {
        $endpoint = "/exchangeReport/STOCK_DAY";
        
        // 如果有指定日期，使用該日期，否則使用當前日期
        $targetDate = $date ? Carbon::parse($date) : Carbon::now();
        $dateString = $targetDate->format('Ymd');

        $params = [
            'response' => 'json',
            'date' => $dateString,
            'stockNo' => $symbol
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data']) && $response['stat'] === 'OK') {
            return collect($response['data'])->map(function ($item) use ($symbol, $response) {
                return [
                    'symbol' => $symbol,
                    'name' => $this->extractStockName($response['title'] ?? ''),
                    'trade_date' => $this->parseROCDate($item[0]),
                    'volume' => $this->parseNumber($item[1]),
                    'turnover' => $this->parseNumber($item[2]),
                    'open' => $this->parsePrice($item[3]),
                    'high' => $this->parsePrice($item[4]),
                    'low' => $this->parsePrice($item[5]),
                    'close' => $this->parsePrice($item[6]),
                    'change' => $this->parsePrice($item[7]),
                    'transaction' => $this->parseNumber($item[8]),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得所有股票列表
     * * @return Collection
     */
    public function getAllStockSymbols()
    {
        // 主要權值股清單
        $stocks = [
            '2330', // 台積電
            '2317', // 鴻海
            '2454', // 聯發科
            '2412', // 中華電
            '2882', // 國泰金
            '2881', // 富邦金
            '2303', // 聯電
            '2308', // 台達電
            '2886', // 兆豐金
            '2884', // 玉山金
            '1301', // 台塑
            '1303', // 南亞
            '2002', // 中鋼
            '3045', // 台灣大
            '2891', // 中信金
        ];

        return collect($stocks);
    }

    /**
     * 發送 HTTP 請求 (舊版 API) - 增加 User-Agent 偽裝
     * * @param string $endpoint
     * @param array $params
     * @return array|null
     */
    protected function makeRequest($endpoint, $params = [])
    {
        $url = $this->baseUrl . $endpoint;

        // 加入快取機制
        $cacheKey = 'twse_' . md5($url . json_encode($params));

        if (Cache::has($cacheKey)) {
            Log::info("使用快取資料: {$cacheKey}");
            return Cache::get($cacheKey);
        }

        try {
            Log::info("發送請求至 TWSE API", ['url' => $url, 'params' => $params]);

            // ✅ 修正: 加入 User-Agent Header，偽裝成瀏覽器
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Accept-Language' => 'zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                'Referer' => 'https://www.twse.com.tw/zh/page/trading/exchange/STOCK_DAY.html',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->timeout($this->timeout)
            ->retry($this->retries, 2000) // 增加 retry 間隔到 2 秒
            ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();

                // 檢查 API 回應狀態
                if (isset($data['stat']) && $data['stat'] !== 'OK') {
                    Log::warning("TWSE API 回應狀態異常", [
                        'status' => $data['stat'],
                        'params' => $params
                    ]);
                    // 可能是因為查詢的日期該股票未上市或無資料，不視為嚴重錯誤
                    return null;
                }

                // 快取 10 分鐘
                Cache::put($cacheKey, $data, now()->addMinutes(10));

                return $data;
            }

            Log::warning("TWSE API 回應非成功狀態", [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500)
            ]);
        } catch (\Exception $e) {
            Log::error("TWSE API 請求失敗", [
                'error' => $e->getMessage(),
                'url' => $url,
                'params' => $params
            ]);
        }

        return null;
    }

    /**
     * 從標題中提取股票名稱
     * * @param string $title
     * @return string
     */
    protected function extractStockName($title)
    {
        // 標題格式: "114年11月 2317 鴻海 各日成交資訊"
        if (preg_match('/\d{4}\s+(.+?)\s+各日成交資訊/', $title, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    /**
     * 解析民國年日期為西元年
     * * @param string $dateString
     * @return string|null
     */
    protected function parseROCDate($dateString)
    {
        // 格式: "114/11/03" 或 "114/11/3"
        if (preg_match('/(\d{2,3})\/(\d{1,2})\/(\d{1,2})/', $dateString, $matches)) {
            $rocYear = intval($matches[1]);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
            
            // 民國年轉西元年
            $adYear = $rocYear + 1911;
            
            return "{$adYear}-{$month}-{$day}";
        }

        return null;
    }

    /**
     * 格式化日期 (從 Ymd 轉為 Y-m-d)
     * * @param string $dateString
     * @return string
     */
    protected function formatDate($dateString)
    {
        // 如果是 8 位數字格式 (如: 20251113)
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $dateString, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }
        
        // 如果已經是 Y-m-d 格式，直接返回
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return $dateString;
        }
        
        // 嘗試使用 Carbon 解析
        try {
            return Carbon::parse($dateString)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning("無法解析日期格式", ['date' => $dateString]);
            return date('Y-m-d');
        }
    }

    /**
     * 解析數字 (移除逗號和特殊符號)
     * * @param mixed $value
     * @return float
     */
    protected function parseNumber($value)
    {
        if (!$value || $value === '--' || $value === 'X' || $value === '-') {
            return 0;
        }

        // 如果已經是數字，直接返回
        if (is_numeric($value)) {
            return floatval($value);
        }

        // 移除逗號和其他非數字字符
        $cleaned = str_replace(',', '', $value);
        $cleaned = preg_replace('/[^0-9.-]/', '', $cleaned);

        return floatval($cleaned);
    }

    /**
     * 解析價格
     * * @param mixed $value
     * @return float|null
     */
    protected function parsePrice($value)
    {
        if (!$value || $value === '--' || $value === 'X' || $value === '-') {
            return null;
        }

        // 處理正負號
        $value = str_replace(['+', '<', '>'], '', $value);

        return $this->parseNumber($value);
    }

    /**
     * 檢查特定日期是否有資料
     * * @param string $symbol 股票代碼
     * @param string|null $date 日期
     * @return bool
     */
    public function checkDataAvailable($symbol, $date = null)
    {
        // 使用較早的日期測試 (避免當天資料未更新)
        $testDate = $date ?? Carbon::now()->subDays(3)->format('Y-m-d');
        $dateString = Carbon::parse($testDate)->format('Ymd');
        
        $data = $this->getStockDayAll($dateString);
        
        if ($data->isEmpty()) {
            return false;
        }

        // 如果指定股票，檢查該股票是否在資料中
        if ($symbol) {
            return $data->contains('symbol', $symbol) || 
                   $data->contains('Code', $symbol);
        }

        return true;
    }

    /**
     * 取得最近有資料的日期
     * * @param int $maxDaysBack 最多往回幾天
     * @return string|null
     */
    public function getLatestAvailableDate($maxDaysBack = 10)
    {
        for ($i = 3; $i <= $maxDaysBack; $i++) {
            $date = Carbon::now()->subDays($i);
            
            // 跳過週末
            if ($date->isWeekend()) {
                continue;
            }
            
            $dateString = $date->format('Ymd');
            $data = $this->getStockDayAll($dateString);
            
            if (!$data->isEmpty()) {
                Log::info("找到有資料的日期: {$date->format('Y-m-d')}");
                return $date->format('Y-m-d');
            }
        }
        
        return null;
    }

    /**
     * 批次取得多檔股票資料
     * * @param array $symbols 股票代碼陣列
     * @param string|null $date 日期
     * @return Collection
     */
    public function getBatchStockData(array $symbols, $date = null)
    {
        $targetDate = $date ?? $this->getLatestAvailableDate();
        
        if (!$targetDate) {
            Log::error("無法找到有資料的日期");
            return collect();
        }
        
        $dateString = Carbon::parse($targetDate)->format('Ymd');
        $allData = $this->getStockDayAll($dateString);
        
        // 過濾出指定的股票
        return $allData->filter(function ($item) use ($symbols) {
            $symbol = $item['symbol'] ?? $item['Code'] ?? '';
            return in_array($symbol, $symbols);
        });
    }
}