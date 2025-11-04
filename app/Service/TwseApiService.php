<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TwseApiService
{
    protected $baseUrl = 'https://openapi.twse.com.tw/v1';
    protected $timeout = 30;
    protected $retryTimes = 3;
    protected $retryDelay = 1000; // milliseconds

    /**
     * 取得上市公司基本資料
     */
    public function getListedCompanies()
    {
        $cacheKey = 'twse_listed_companies';

        return Cache::remember($cacheKey, 3600, function () {
            $response = $this->makeRequest('/opendata/t187ap03_L');

            if ($response) {
                return collect($response)->map(function ($item) {
                    return [
                        'symbol' => $item['公司代號'] ?? null,
                        'name' => $item['公司名稱'] ?? null,
                        'name_en' => $item['英文簡稱'] ?? null,
                        'industry' => $item['產業別'] ?? null,
                        'address' => $item['住址'] ?? null,
                        'chairman' => $item['董事長'] ?? null,
                        'general_manager' => $item['總經理'] ?? null,
                        'spokesperson' => $item['發言人'] ?? null,
                        'capital' => $this->parseAmount($item['實收資本額'] ?? 0),
                        'establishment_date' => $this->parseDate($item['成立日期'] ?? null),
                        'listing_date' => $this->parseDate($item['上市日期'] ?? null),
                        'website' => $item['網址'] ?? null,
                    ];
                });
            }

            return collect();
        });
    }

    /**
     * 取得個股日成交資訊
     */
    public function getStockDayAll($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = '/exchangeReport/STOCK_DAY_ALL';

        $response = $this->makeRequest($endpoint, ['date' => $date]);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) use ($date) {
                return [
                    'symbol' => $item[0] ?? null,
                    'name' => $item[1] ?? null,
                    'trade_date' => Carbon::parse($date)->format('Y-m-d'),
                    'volume' => $this->parseNumber($item[2] ?? 0),
                    'transactions' => $this->parseNumber($item[3] ?? 0),
                    'turnover' => $this->parseAmount($item[4] ?? 0),
                    'open' => $this->parsePrice($item[5] ?? 0),
                    'high' => $this->parsePrice($item[6] ?? 0),
                    'low' => $this->parsePrice($item[7] ?? 0),
                    'close' => $this->parsePrice($item[8] ?? 0),
                    'change_sign' => $item[9] ?? '',
                    'change' => $this->parsePrice($item[10] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得大盤統計資訊
     */
    public function getMarketIndex($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = '/exchangeReport/MI_INDEX';

        $response = $this->makeRequest($endpoint, ['date' => $date]);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'index_name' => $item[0] ?? null,
                    'close' => $this->parsePrice($item[1] ?? 0),
                    'change_sign' => $item[2] ?? '',
                    'change' => $this->parsePrice($item[3] ?? 0),
                    'change_percent' => $this->parseNumber($item[4] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得個股本益比、殖利率及股價淨值比
     */
    public function getStockPERatio($date = null)
    {
        $date = $date ?: now()->format('Ymd');
        $endpoint = '/exchangeReport/BWIBBU_d';

        $response = $this->makeRequest($endpoint, ['date' => $date]);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'symbol' => $item[0] ?? null,
                    'name' => $item[1] ?? null,
                    'dividend_yield' => $this->parseNumber($item[2] ?? 0),
                    'dividend_year' => $item[3] ?? null,
                    'pe_ratio' => $this->parseNumber($item[4] ?? 0),
                    'pb_ratio' => $this->parseNumber($item[5] ?? 0),
                    'fiscal_year' => $item[6] ?? null,
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
        $endpoint = '/exchangeReport/MI_MARGN';

        $response = $this->makeRequest($endpoint, ['date' => $date]);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'symbol' => $item[0] ?? null,
                    'name' => $item[1] ?? null,
                    'margin_buy' => $this->parseNumber($item[2] ?? 0),
                    'margin_sell' => $this->parseNumber($item[3] ?? 0),
                    'margin_balance' => $this->parseNumber($item[4] ?? 0),
                    'short_sell' => $this->parseNumber($item[5] ?? 0),
                    'short_cover' => $this->parseNumber($item[6] ?? 0),
                    'short_balance' => $this->parseNumber($item[7] ?? 0),
                    'margin_limit' => $this->parseNumber($item[8] ?? 0),
                    'short_limit' => $this->parseNumber($item[9] ?? 0),
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
                    'name' => $item['公司名稱'] ?? null,
                    'year_month' => $item['資料年月'] ?? null,
                    'revenue' => $this->parseAmount($item['當月營收'] ?? 0),
                    'revenue_mom' => $this->parseNumber($item['上月比較增減(%)'] ?? 0),
                    'revenue_yoy' => $this->parseNumber($item['去年同月增減(%)'] ?? 0),
                    'revenue_ytd' => $this->parseAmount($item['當月累計營收'] ?? 0),
                    'revenue_ytd_yoy' => $this->parseNumber($item['前期比較增減(%)'] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得各產業EPS統計資訊
     */
    public function getIndustryEPS()
    {
        $endpoint = '/opendata/t187ap14_L';

        $response = $this->makeRequest($endpoint);

        if ($response) {
            return collect($response)->map(function ($item) {
                return [
                    'industry' => $item['產業別'] ?? null,
                    'year' => $item['年度'] ?? null,
                    'quarter' => $item['季別'] ?? null,
                    'eps_avg' => $this->parseNumber($item['平均每股盈餘'] ?? 0),
                    'companies_count' => $this->parseNumber($item['公司數'] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得權證基本資料
     */
    public function getWarrantData()
    {
        $endpoint = '/opendata/t187ap37_L';

        $response = $this->makeRequest($endpoint);

        if ($response) {
            return collect($response)->map(function ($item) {
                return [
                    'warrant_code' => $item['權證代號'] ?? null,
                    'warrant_name' => $item['權證簡稱'] ?? null,
                    'underlying_symbol' => $item['標的代號'] ?? null,
                    'underlying_name' => $item['標的名稱'] ?? null,
                    'type' => $item['權證類別'] ?? null, // 認購/認售
                    'strike_price' => $this->parsePrice($item['履約價格'] ?? 0),
                    'expiry_date' => $this->parseDate($item['到期日'] ?? null),
                    'issue_date' => $this->parseDate($item['發行日'] ?? null),
                    'conversion_ratio' => $this->parseNumber($item['行使比例'] ?? 0),
                    'issuer' => $item['發行人'] ?? null,
                ];
            });
        }

        return collect();
    }

    /**
     * 取得除權除息預告
     */
    public function getDividendSchedule()
    {
        $endpoint = '/exchangeReport/TWT48U_ALL';

        $response = $this->makeRequest($endpoint);

        if ($response && isset($response['data'])) {
            return collect($response['data'])->map(function ($item) {
                return [
                    'symbol' => $item[0] ?? null,
                    'name' => $item[1] ?? null,
                    'ex_dividend_date' => $this->parseDate($item[2] ?? null),
                    'ex_rights_date' => $this->parseDate($item[3] ?? null),
                    'cash_dividend' => $this->parseNumber($item[4] ?? 0),
                    'stock_dividend' => $this->parseNumber($item[5] ?? 0),
                    'capital_reduction' => $this->parseNumber($item[6] ?? 0),
                ];
            });
        }

        return collect();
    }

    /**
     * 取得市場開休市日期
     */
    public function getHolidaySchedule($year = null)
    {
        $year = $year ?: now()->year;
        $endpoint = '/holidaySchedule/holidaySchedule';

        $response = $this->makeRequest($endpoint, ['year' => $year]);

        if ($response) {
            return collect($response)->map(function ($item) {
                return [
                    'date' => $this->parseDate($item['日期'] ?? null),
                    'name' => $item['名稱'] ?? null,
                    'description' => $item['說明'] ?? null,
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
        try {
            $url = $this->baseUrl . $endpoint;

            Log::info('TWSE API Request', ['url' => $url, 'params' => $params]);

            $response = Http::timeout($this->timeout)
                ->retry($this->retryTimes, $this->retryDelay)
                ->get($url, $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('TWSE API Error', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('TWSE API Exception', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * 解析數字
     */
    protected function parseNumber($value)
    {
        if (is_null($value) || $value === '--' || $value === '') {
            return null;
        }

        // 移除千分位符號和空格
        $value = str_replace([',', ' '], '', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * 解析價格
     */
    protected function parsePrice($value)
    {
        $number = $this->parseNumber($value);
        return $number ? round($number, 2) : null;
    }

    /**
     * 解析金額
     */
    protected function parseAmount($value)
    {
        return $this->parseNumber($value);
    }

    /**
     * 解析日期
     */
    protected function parseDate($value)
    {
        if (empty($value) || $value === '--') {
            return null;
        }

        // 處理民國年 (例如: 113/01/01)
        if (preg_match('/^(\d{2,3})\/(\d{2})\/(\d{2})$/', $value, $matches)) {
            $year = intval($matches[1]) + 1911;
            return Carbon::createFromFormat('Y/m/d', "$year/{$matches[2]}/{$matches[3]}")->format('Y-m-d');
        }

        // 處理西元年
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}
