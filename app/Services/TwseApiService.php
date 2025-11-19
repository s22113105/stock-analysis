<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * 台灣證券交易所 API 服務
 * 
 * 提供台股市場資料查詢功能
 */
class TwseApiService
{
    protected $baseUrl;
    protected $openApiBaseUrl;
    protected $timeout;
    protected $retries;

    public function __construct()
    {
        $this->baseUrl = config('services.twse.base_url', 'https://www.twse.com.tw');
        $this->openApiBaseUrl = config('services.twse.openapi_base_url', 'https://openapi.twse.com.tw/v1');
        $this->timeout = config('services.twse.timeout', 30);
        $this->retries = config('services.twse.retries', 3);
    }

    /**
     * 取得所有股票的單日資料
     * 
     * @param string $date 日期 (Ymd 格式, 例如: 20251105)
     * @return \Illuminate\Support\Collection
     */
    public function getStockDayAll(string $date): \Illuminate\Support\Collection
    {
        // 確保日期格式正確 (轉換為 Ymd)
        if (strlen($date) === 10 && strpos($date, '-') !== false) {
            // 如果是 Y-m-d 格式,轉換為 Ymd
            $date = Carbon::parse($date)->format('Ymd');
        }

        $endpoint = "/data/BWIBBU_d";  // 每日收盤行情

        $params = [
            'date' => $date,
            'response' => 'json'
        ];

        Log::info("嘗試從 TWSE API 取得所有股票資料", [
            'date' => $date,
            'endpoint' => $this->openApiBaseUrl . $endpoint
        ]);

        $response = $this->makeOpenApiRequest($endpoint, $params);

        if (!$response || !isset($response['data'])) {
            Log::warning("TWSE API 回傳空資料或格式錯誤", [
                'date' => $date,
                'response' => $response
            ]);
            return collect();
        }

        // 解析資料
        $stocks = collect($response['data'])->map(function ($item) use ($date) {
            // 資料格式: [代碼, 名稱, 成交股數, 成交金額, 開盤價, 最高價, 最低價, 收盤價, 漲跌, 成交筆數]
            return [
                'symbol' => $this->cleanSymbol($item['Code'] ?? $item[0] ?? ''),
                'name' => $this->cleanStockName($item['Name'] ?? $item[1] ?? ''),
                'trade_date' => $this->formatDateFromTWSE($date),
                'volume' => $this->parseNumber($item['TradeVolume'] ?? $item[2] ?? 0),
                'turnover' => $this->parseNumber($item['TradeValue'] ?? $item[3] ?? 0),
                'open' => $this->parsePrice($item['OpeningPrice'] ?? $item[4] ?? null),
                'high' => $this->parsePrice($item['HighestPrice'] ?? $item[5] ?? null),
                'low' => $this->parsePrice($item['LowestPrice'] ?? $item[6] ?? null),
                'close' => $this->parsePrice($item['ClosingPrice'] ?? $item[7] ?? null),
                'change' => $this->parsePrice($item['Change'] ?? $item[8] ?? 0),
                'transaction' => $this->parseNumber($item['Transaction'] ?? $item[9] ?? 0),
            ];
        })->filter(function ($item) {
            // 過濾掉無效資料
            return !empty($item['symbol']) && !empty($item['close']);
        });

        Log::info("成功取得股票資料", [
            'date' => $date,
            'count' => $stocks->count()
        ]);

        return $stocks;
    }

    /**
     * 取得單一股票的每日行情 (當月所有日期)
     * 
     * @param string $symbol 股票代碼
     * @return \Illuminate\Support\Collection
     */
    public function getStockDay(string $symbol): \Illuminate\Support\Collection
    {
        $endpoint = "/exchangeReport/STOCK_DAY";

        $params = [
            'response' => 'json',
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
     * 取得所有股票代碼列表
     * 
     * @return \Illuminate\Support\Collection
     */
    public function getAllStockSymbols(): \Illuminate\Support\Collection
    {
        // 台股主要股票代碼
        $stocks = [
            // 科技股
            '2330', '2317', '2454', '2308', '2303', '2327', '3034', '6505', '2382', '2379',
            // 金融股
            '2882', '2881', '2891', '2892', '2886', '2887', '2880', '2883', '2885', '2884',
            // 傳產股
            '1301', '1303', '1326', '2002', '2105', '2207', '2301', '2357', '1402', '1216',
            // ETF
            '0050', '0056', '00878', '006208', '00692', '00881', '00885',
        ];

        return collect($stocks);
    }

    /**
     * 發送請求到 TWSE 舊版 API
     * 
     * @param string $endpoint API 端點
     * @param array $params 請求參數
     * @return array|null
     */
    protected function makeRequest(string $endpoint, array $params = []): ?array
    {
        $url = $this->baseUrl . $endpoint;

        // 加入快取機制 (快取 10 分鐘)
        $cacheKey = 'twse_' . md5($url . json_encode($params));

        if (Cache::has($cacheKey)) {
            Log::info("使用快取資料", ['key' => $cacheKey]);
            return Cache::get($cacheKey);
        }

        try {
            Log::info("發送請求至 TWSE API", ['url' => $url, 'params' => $params]);

            $response = Http::timeout($this->timeout)
                ->retry($this->retries, 1000)
                ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();

                // 檢查 API 回應狀態
                if (isset($data['stat']) && $data['stat'] !== 'OK') {
                    Log::warning("TWSE API 回應狀態異常", [
                        'status' => $data['stat'],
                        'params' => $params
                    ]);
                    return null;
                }

                // 快取 10 分鐘
                Cache::put($cacheKey, $data, now()->addMinutes(10));

                return $data;
            }

            Log::warning("TWSE API 回應非成功狀態", [
                'status' => $response->status(),
                'body' => $response->body()
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
     * 發送請求到 TWSE OpenAPI
     * 
     * @param string $endpoint API 端點
     * @param array $params 請求參數
     * @return array|null
     */
    protected function makeOpenApiRequest(string $endpoint, array $params = []): ?array
    {
        $url = $this->openApiBaseUrl . $endpoint;

        // 加入快取機制 (快取 10 分鐘)
        $cacheKey = 'twse_openapi_' . md5($url . json_encode($params));

        if (Cache::has($cacheKey)) {
            Log::info("使用快取資料", ['key' => $cacheKey]);
            return Cache::get($cacheKey);
        }

        try {
            Log::info("發送請求至 TWSE OpenAPI", ['url' => $url, 'params' => $params]);

            $response = Http::timeout($this->timeout)
                ->retry($this->retries, 1000)
                ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();

                // 快取 10 分鐘
                Cache::put($cacheKey, $data, now()->addMinutes(10));

                return $data;
            }

            Log::warning("TWSE OpenAPI 回應非成功狀態", [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

        } catch (\Exception $e) {
            Log::error("TWSE OpenAPI 請求失敗", [
                'error' => $e->getMessage(),
                'url' => $url,
                'params' => $params
            ]);
        }

        return null;
    }

    /**
     * 從標題中提取股票名稱
     * 
     * @param string $title
     * @return string
     */
    protected function extractStockName(string $title): string
    {
        // 標題格式: "114年11月 2317 鴻海 各日成交資訊"
        if (preg_match('/\d{4}\s+(.+?)\s+各日成交資訊/', $title, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * 清理股票代碼 (移除空白和特殊字符)
     * 
     * @param string $symbol
     * @return string
     */
    protected function cleanSymbol(string $symbol): string
    {
        return trim(str_replace([' ', '　'], '', $symbol));
    }

    /**
     * 清理股票名稱 (移除空白)
     * 
     * @param string $name
     * @return string
     */
    protected function cleanStockName(string $name): string
    {
        return trim($name);
    }

    /**
     * 解析民國日期格式
     * 
     * @param string $rocDate 民國日期 (例如: 113/11/05)
     * @return string 西元日期 (Y-m-d)
     */
    protected function parseROCDate(string $rocDate): string
    {
        // 格式: 113/11/05 -> 2024-11-05
        $parts = explode('/', $rocDate);
        
        if (count($parts) === 3) {
            $year = (int)$parts[0] + 1911;  // 民國轉西元
            $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($parts[2], 2, '0', STR_PAD_LEFT);
            
            return "{$year}-{$month}-{$day}";
        }

        return $rocDate;
    }

    /**
     * 格式化 TWSE 日期為標準格式
     * 
     * @param string $date Ymd 格式
     * @return string Y-m-d 格式
     */
    protected function formatDateFromTWSE(string $date): string
    {
        // 20251105 -> 2025-11-05
        if (strlen($date) === 8) {
            return substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        }

        return $date;
    }

    /**
     * 解析數字 (移除逗號)
     * 
     * @param mixed $value
     * @return float
     */
    protected function parseNumber($value): float
    {
        if (!$value || $value === '--' || $value === 'X' || $value === '-') {
            return 0;
        }

        // 移除逗號和其他非數字字符(保留小數點和負號)
        $cleaned = preg_replace('/[^0-9.-]/', '', $value);

        return floatval($cleaned);
    }

    /**
     * 解析價格
     * 
     * @param mixed $value
     * @return float|null
     */
    protected function parsePrice($value): ?float
    {
        if (!$value || $value === '--' || $value === 'X' || $value === '-' || $value === '') {
            return null;
        }

        // 處理正負號和特殊字符
        $value = str_replace(['+', '<', '>',  ' '], '', $value);

        return $this->parseNumber($value);
    }

    /**
     * 檢查特定日期是否有資料
     * 
     * @param string $symbol
     * @param string|null $date
     * @return bool
     */
    public function checkDataAvailable(string $symbol, ?string $date = null): bool
    {
        $data = $this->getStockDay($symbol);
        
        if ($data->isEmpty()) {
            return false;
        }

        // 如果指定日期,檢查該日期是否在資料中
        if ($date) {
            $targetDate = Carbon::parse($date)->format('Y-m-d');
            return $data->contains('trade_date', $targetDate);
        }

        return true;
    }
}