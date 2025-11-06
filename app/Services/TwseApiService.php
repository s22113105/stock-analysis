<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class TwseApiService
{
    protected $baseUrl;
    protected $timeout;
    protected $retries;

    public function __construct()
    {
        $this->baseUrl = config('services.twse.base_url', 'https://www.twse.com.tw');
        $this->timeout = config('services.twse.timeout', 30);
        $this->retries = config('services.twse.retries', 3);
    }

    /**
     * 取得單一股票的每日行情 (當月所有日期)
     */
    public function getStockDay($symbol)
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
     * 取得所有股票列表 (從交易所取得)
     */
    public function getAllStockSymbols()
    {
        // 台股主要股票代碼範圍
        // 上市公司: 1000-9999
        // 這裡提供常用的股票代碼,實際專案中可以從其他來源取得完整列表
        
        $stocks = [
            '2330', '2317', '2454', '2308', '2303', // 科技股
            '2882', '2881', '2891', '2892', '2886', // 金融股
            '1301', '1303', '1326', '2002', '2105', // 傳產股
            '0050', '0056', '00878', '006208',      // ETF
        ];

        return collect($stocks);
    }

    /**
     * 從標題中提取股票名稱
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
     * 發送 HTTP 請求
     */
    protected function makeRequest($endpoint, $params = [])
    {
        $url = $this->baseUrl . $endpoint;

        // 加入快取機制 (快取 10 分鐘)
        $cacheKey = 'twse_' . md5($url . json_encode($params));

        if (Cache::has($cacheKey)) {
            Log::info("使用快取資料: {$cacheKey}");
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
     * 解析數字 (移除逗號)
     */
    protected function parseNumber($value)
    {
        if (!$value || $value === '--' || $value === 'X') {
            return 0;
        }

        // 移除逗號和其他非數字字符
        $cleaned = str_replace(',', '', $value);

        return floatval($cleaned);
    }

    /**
     * 解析價格
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
     */
    public function checkDataAvailable($symbol, $date = null)
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