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
        $this->baseUrl = config('services.twse.base_url', 'https://openapi.twse.com.tw');
        $this->timeout = config('services.twse.timeout', 30);
        $this->retries = config('services.twse.retries', 3);
    }

    /**
     * 取得上市公司基本資料
     */
    public function getListedCompanies()
    {
        $endpoint = '/opendata/t187ap03_L';

        $response = $this->makeRequest($endpoint);

        if ($response) {
            return collect($response)->map(function ($item) {
                return [
                    'symbol' => $item['公司代號'] ?? null,
                    'name' => $item['公司簡稱'] ?? null,
                    'name_en' => $item['英文簡稱'] ?? null,
                    'industry' => $item['產業別'] ?? null,
                    'address' => $item['住址'] ?? null,
                    'chairman' => $item['董事長'] ?? null,
                    'general_manager' => $item['總經理'] ?? null,
                    'spokesperson' => $item['發言人'] ?? null,
                    'establishment_date' => $item['成立日期'] ?? null,
                    'listing_date' => $item['上市日期'] ?? null,
                    'website' => $item['網址'] ?? null,
                ];
            });
        }

        return collect();
    }

    /**
     * 取得每日收盤行情（全部）
     */
    public function getStockDayAll($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = "/exchangeReport/MI_INDEX";

        $params = [
            'response' => 'json',
            'date' => $date,
            'type' => 'ALL',
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data9'])) {
            return collect($response['data9'])->map(function ($item) {
                return [
                    'symbol' => $item[0] ?? null,
                    'name' => $item[1] ?? null,
                    'volume' => $this->parseVolume($item[2] ?? 0),
                    'turnover' => $this->parseNumber($item[4] ?? 0),
                    'open' => $this->parsePrice($item[5] ?? 0),
                    'high' => $this->parsePrice($item[6] ?? 0),
                    'low' => $this->parsePrice($item[7] ?? 0),
                    'close' => $this->parsePrice($item[8] ?? 0),
                    'change_sign' => $item[9] ?? null,
                    'change' => $this->parsePrice($item[10] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得個股每日收盤行情
     */
    public function getStockDay($symbol, $date = null)
    {
        $date = $date ?: now()->format('Ym01');
        $endpoint = "/exchangeReport/STOCK_DAY";

        $params = [
            'response' => 'json',
            'date' => $date,
            'stockNo' => $symbol,
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                $dateString = $this->convertToWesternDate($item[0] ?? null);

                return [
                    'date' => $dateString,
                    'volume' => $this->parseVolume($item[1] ?? 0),
                    'turnover' => $this->parseNumber($item[2] ?? 0),
                    'open' => $this->parsePrice($item[3] ?? 0),
                    'high' => $this->parsePrice($item[4] ?? 0),
                    'low' => $this->parsePrice($item[5] ?? 0),
                    'close' => $this->parsePrice($item[6] ?? 0),
                    'change' => $this->parsePrice($item[7] ?? 0),
                    'transactions' => $this->parseNumber($item[8] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得本益比、殖利率等資料
     */
    public function getStockPERatio($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = "/exchangeReport/BWIBBU_ALL";

        $params = [
            'response' => 'json',
            'date' => $date,
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'symbol' => $item[0] ?? null,
                    'name' => $item[1] ?? null,
                    'dividend_yield' => $this->parseNumber($item[2] ?? 0),
                    'dividend_year' => $item[3] ?? null,
                    'pe_ratio' => $this->parseNumber($item[4] ?? 0),
                    'pb_ratio' => $this->parseNumber($item[5] ?? 0),
                    'financial_report_date' => $item[6] ?? null,
                ];
            });
        }

        return collect();
    }

    /**
     * 取得融資融券餘額
     */
    public function getMarginTrading($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = "/exchangeReport/MI_MARGN";

        $params = [
            'response' => 'json',
            'date' => $date,
            'selectType' => 'ALL',
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'symbol' => $item[0] ?? null,
                    'name' => $item[1] ?? null,
                    'margin_purchase' => $this->parseNumber($item[2] ?? 0),
                    'margin_sale' => $this->parseNumber($item[3] ?? 0),
                    'margin_balance' => $this->parseNumber($item[4] ?? 0),
                    'short_sale' => $this->parseNumber($item[5] ?? 0),
                    'short_cover' => $this->parseNumber($item[6] ?? 0),
                    'short_balance' => $this->parseNumber($item[7] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得月營收資料
     */
    public function getMonthlyRevenue()
    {
        $endpoint = '/opendata/t187ap05_L';

        $response = $this->makeRequest($endpoint);

        if ($response) {
            return collect($response)->map(function ($item) {
                return [
                    'symbol' => $item['公司代號'] ?? null,
                    'name' => $item['公司簡稱'] ?? null,
                    'year_month' => $item['營收年月'] ?? null,
                    'revenue' => $this->parseNumber($item['營業收入'] ?? 0),
                    'revenue_yoy' => $this->parseNumber($item['營收年增率'] ?? 0),
                    'revenue_mom' => $this->parseNumber($item['營收月增率'] ?? 0),
                    'revenue_ytd' => $this->parseNumber($item['累計營業收入'] ?? 0),
                    'revenue_ytd_yoy' => $this->parseNumber($item['累計營收年增率'] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得三大法人買賣超
     */
    public function getInstitutionalTrading($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = "/exchangeReport/T86";

        $params = [
            'response' => 'json',
            'date' => $date,
            'selectType' => 'ALL',
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'symbol' => $item[0] ?? null,
                    'name' => $item[1] ?? null,
                    'foreign_buy' => $this->parseVolume($item[2] ?? 0),
                    'foreign_sell' => $this->parseVolume($item[3] ?? 0),
                    'foreign_net' => $this->parseVolume($item[4] ?? 0),
                    'trust_buy' => $this->parseVolume($item[5] ?? 0),
                    'trust_sell' => $this->parseVolume($item[6] ?? 0),
                    'trust_net' => $this->parseVolume($item[7] ?? 0),
                    'dealer_buy' => $this->parseVolume($item[8] ?? 0),
                    'dealer_sell' => $this->parseVolume($item[9] ?? 0),
                    'dealer_net' => $this->parseVolume($item[10] ?? 0),
                    'total_net' => $this->parseVolume($item[11] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得產業類別統計
     */
    public function getIndustryStatistics($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = "/exchangeReport/BFIAMU";

        $params = [
            'response' => 'json',
            'date' => $date,
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'industry' => $item[0] ?? null,
                    'total_companies' => $this->parseNumber($item[1] ?? 0),
                    'total_capital' => $this->parseNumber($item[2] ?? 0),
                    'total_market_value' => $this->parseNumber($item[3] ?? 0),
                    'avg_pe_ratio' => $this->parseNumber($item[4] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得選擇權每日交易行情
     */
    public function getOptionDayAll($date = null)
    {
        $date = $date ?: now()->format('Ym');
        $endpoint = "/exchangeReport/OPTION_DailyMarketView";

        $params = [
            'response' => 'json',
            'date' => $date,
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['aaData'])) {
            return collect($response['aaData'])->map(function ($item) {
                return [
                    'date' => $item[0] ?? null,
                    'call_volume' => $this->parseNumber($item[1] ?? 0),
                    'call_turnover' => $this->parseNumber($item[2] ?? 0),
                    'put_volume' => $this->parseNumber($item[3] ?? 0),
                    'put_turnover' => $this->parseNumber($item[4] ?? 0),
                    'call_oi' => $this->parseNumber($item[5] ?? 0),
                    'put_oi' => $this->parseNumber($item[6] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 發送 API 請求
     */
    protected function makeRequest($endpoint, $params = [])
    {
        $url = $this->baseUrl . $endpoint;
        $cacheKey = 'twse_api:' . md5($url . serialize($params));

        // 檢查快取
        if (Cache::has($cacheKey)) {
            Log::info('使用快取資料', ['endpoint' => $endpoint]);
            return Cache::get($cacheKey);
        }

        try {
            Log::info('發送 TWSE API 請求', ['url' => $url, 'params' => $params]);

            $response = Http::timeout($this->timeout)
                ->retry($this->retries, 1000)
                ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();

                // 快取 5 分鐘
                Cache::put($cacheKey, $data, now()->addMinutes(5));

                return $data;
            }

            Log::warning('TWSE API 請求失敗', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

        } catch (\Exception $e) {
            Log::error('TWSE API 請求錯誤', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * 解析價格
     */
    protected function parsePrice($value)
    {
        if (empty($value) || $value === '--' || $value === 'X0.00') {
            return null;
        }

        $value = str_replace(',', '', $value);
        return floatval($value);
    }

    /**
     * 解析數字
     */
    protected function parseNumber($value)
    {
        if (empty($value) || $value === '--') {
            return 0;
        }

        $value = str_replace(',', '', $value);
        return intval($value);
    }

    /**
     * 解析成交量
     */
    protected function parseVolume($value)
    {
        if (empty($value) || $value === '--') {
            return 0;
        }

        $value = str_replace(',', '', $value);
        return intval($value);
    }

    /**
     * 轉換民國年為西元年
     */
    protected function convertToWesternDate($dateString)
    {
        if (empty($dateString)) {
            return null;
        }

        // 格式：112/01/03
        $parts = explode('/', $dateString);
        if (count($parts) === 3) {
            $year = intval($parts[0]) + 1911;
            return Carbon::createFromFormat('Y/m/d', "{$year}/{$parts[1]}/{$parts[2]}")->format('Y-m-d');
        }

        return $dateString;
    }

    /**
     * 解析日期
     */
    protected function parseDate($dateString)
    {
        if (empty($dateString)) {
            return null;
        }

        try {
            return Carbon::parse($dateString)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}
