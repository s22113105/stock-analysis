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
     * 取得每日收盤行情
     */
    public function getStockDayAll($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = "/exchangeReport/STOCK_DAY_ALL";

        $params = [
            'response' => 'json',
            'date' => $date
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'symbol' => $item[0] ?? null,
                    'name' => $item[1] ?? null,
                    'volume' => $this->parseNumber($item[2] ?? 0),
                    'turnover' => $this->parseNumber($item[4] ?? 0),
                    'open' => $this->parsePrice($item[5] ?? 0),
                    'high' => $this->parsePrice($item[6] ?? 0),
                    'low' => $this->parsePrice($item[7] ?? 0),
                    'close' => $this->parsePrice($item[8] ?? 0),
                    'change' => $this->parsePrice($item[10] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得個股資料
     */
    public function getStockDay($symbol, $date = null)
    {
        $date = $date ?: now()->format('Ym');
        $endpoint = "/exchangeReport/STOCK_DAY";

        $params = [
            'response' => 'json',
            'date' => $date . '01',
            'stockNo' => $symbol
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data'])) {
            $lastData = collect($response['data'])->last();
            if ($lastData) {
                return [
                    'symbol' => $symbol,
                    'date' => $lastData[0] ?? null,
                    'volume' => $this->parseNumber($lastData[1] ?? 0),
                    'turnover' => $this->parseNumber($lastData[2] ?? 0),
                    'open' => $this->parsePrice($lastData[3] ?? 0),
                    'high' => $this->parsePrice($lastData[4] ?? 0),
                    'low' => $this->parsePrice($lastData[5] ?? 0),
                    'close' => $this->parsePrice($lastData[6] ?? 0),
                    'change' => $this->parsePrice($lastData[7] ?? 0),
                ];
            }
        }

        return [];
    }

    /**
     * 取得上市公司列表
     */
    public function getListedCompanies()
    {
        $endpoint = "/opendata/t187ap03_L";

        $response = $this->makeRequest($endpoint);

        if ($response) {
            return collect($response)->map(function ($item) {
                return [
                    'symbol' => $item['公司代號'] ?? null,
                    'name' => $item['公司名稱'] ?? null,
                    'industry' => $item['產業別'] ?? null,
                    'market' => $item['市場別'] ?? '上市',
                ];
            })->filter(function ($item) {
                return !empty($item['symbol']);
            });
        }

        return collect();
    }

    /**
     * 取得本益比、殖利率、股價淨值比資料
     */
    public function getStockPERatio($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = "/exchangeReport/BWIBBU_d";

        $params = [
            'response' => 'json',
            'date' => $date,
            'selectType' => 'ALL'
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'symbol' => $item[0] ?? null,
                    'name' => $item[1] ?? null,
                    'pe_ratio' => $this->parsePrice($item[2] ?? null),
                    'dividend_yield' => $this->parsePrice($item[3] ?? null),
                    'pb_ratio' => $this->parsePrice($item[4] ?? null),
                    'dividend_year' => $item[5] ?? null,
                ];
            })->filter(function ($item) {
                return !empty($item['symbol']);
            });
        }

        return collect();
    }

    /**
     * 取得融資融券資料
     */
    public function getMarginTrading($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = "/exchangeReport/MI_MARGN";

        $params = [
            'response' => 'json',
            'date' => $date,
            'selectType' => 'ALL'
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'symbol' => $item[0] ?? null,
                    'name' => $item[1] ?? null,
                    'margin_buy' => $this->parseNumber($item[2] ?? 0),
                    'margin_sell' => $this->parseNumber($item[3] ?? 0),
                    'margin_net' => $this->parseNumber($item[4] ?? 0),
                    'margin_balance' => $this->parseNumber($item[5] ?? 0),
                    'short_covering' => $this->parseNumber($item[6] ?? 0),
                    'short_sell' => $this->parseNumber($item[7] ?? 0),
                    'short_net' => $this->parseNumber($item[8] ?? 0),
                    'short_balance' => $this->parseNumber($item[9] ?? 0),
                    'offset' => $this->parseNumber($item[10] ?? 0),
                    'note' => $item[11] ?? null,
                ];
            })->filter(function ($item) {
                return !empty($item['symbol']);
            });
        }

        return collect();
    }

    /**
     * 取得選擇權日資料
     */
    public function getOptionDayAll($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = "/exchangeReport/FMTQIK";

        $params = [
            'response' => 'json',
            'date' => $date
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'contract_name' => $item[0] ?? null,
                    'expiry_date' => $item[1] ?? null,
                    'strike_price' => $this->parseNumber($item[2] ?? 0),
                    'call_volume' => $this->parseNumber($item[3] ?? 0),
                    'call_oi' => $this->parseNumber($item[4] ?? 0),
                    'call_best_bid' => $this->parsePrice($item[5] ?? null),
                    'call_best_ask' => $this->parsePrice($item[6] ?? null),
                    'put_best_bid' => $this->parsePrice($item[7] ?? null),
                    'put_best_ask' => $this->parsePrice($item[8] ?? null),
                    'put_volume' => $this->parseNumber($item[9] ?? 0),
                    'put_oi' => $this->parseNumber($item[10] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得權證資料
     */
    public function getWarrantData($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = "/exchangeReport/TWTB4U";

        $params = [
            'response' => 'json',
            'date' => $date
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'warrant_code' => $item[0] ?? null,
                    'warrant_name' => $item[1] ?? null,
                    'underlying_symbol' => $item[2] ?? null,
                    'underlying_name' => $item[3] ?? null,
                    'type' => $item[4] ?? null,
                    'strike_price' => $this->parsePrice($item[5] ?? null),
                    'last_trade_date' => $item[6] ?? null,
                    'volume' => $this->parseNumber($item[7] ?? 0),
                    'turnover' => $this->parseNumber($item[8] ?? 0),
                    'open' => $this->parsePrice($item[9] ?? null),
                    'high' => $this->parsePrice($item[10] ?? null),
                    'low' => $this->parsePrice($item[11] ?? null),
                    'close' => $this->parsePrice($item[12] ?? null),
                ];
            })->filter(function ($item) {
                return !empty($item['warrant_code']);
            });
        }

        return collect();
    }

    /**
     * 取得月營收資料
     */
    public function getMonthlyRevenue($year = null, $month = null)
    {
        $endpoint = "/opendata/t187ap05_L";

        $response = $this->makeRequest($endpoint);

        if ($response) {
            return collect($response)->map(function ($item) {
                return [
                    'symbol' => $item['公司代號'] ?? null,
                    'name' => $item['公司名稱'] ?? null,
                    'year' => $item['資料年度'] ?? null,
                    'month' => $item['資料月份'] ?? null,
                    'revenue' => $this->parseNumber($item['當月營收'] ?? 0),
                    'revenue_yoy' => $this->parseNumber($item['去年同月營收'] ?? 0),
                    'revenue_mom' => $this->parseNumber($item['上月營收'] ?? 0),
                    'accumulated_revenue' => $this->parseNumber($item['當月累計營收'] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得股利資料
     */
    public function getDividendData()
    {
        $endpoint = "/opendata/t187ap45_L";

        $response = $this->makeRequest($endpoint);

        if ($response) {
            return collect($response);
        }

        return collect();
    }

    /**
     * 發送 HTTP 請求
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

            $response = Http::timeout($this->timeout)
                ->retry($this->retries, 1000)
                ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();

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
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * 解析數字
     */
    protected function parseNumber($value)
    {
        if (!$value || $value === '--') {
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

        return $this->parseNumber($value);
    }
}
