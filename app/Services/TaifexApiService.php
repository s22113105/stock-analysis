<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class TaifexApiService
{
    protected $baseUrl;
    protected $timeout;
    protected $retries;

    public function __construct()
    {
        $this->baseUrl = config('services.taifex.base_url', 'https://www.taifex.com.tw');
        $this->timeout = config('services.taifex.timeout', 30);
        $this->retries = config('services.taifex.retries', 3);
    }

    /**
     * 取得選擇權每日交易行情
     * API: /DailyMarketReportOpt
     */
    public function getDailyOptionsReport($date = null)
    {
        $date = $date ?: now()->format('Y-m-d');
        $endpoint = "/data_gov/taifex_open_data.asp";

        // 期交所日期格式: YYYY/MM/DD
        $dateString = Carbon::parse($date)->format('Y/m/d');

        $params = [
            'data_type' => 'DailyMarketReportOpt',
            'obj_id' => '',
            'date' => $dateString
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['RtCode']) && $response['RtCode'] === '0') {
            return $this->parseOptionsData($response['RtData'] ?? []);
        }

        return collect();
    }

    /**
     * 取得選擇權 Delta 值
     * API: /DailyOptionsDelta
     */
    public function getDailyOptionsDelta($date = null)
    {
        $date = $date ?: now()->format('Y-m-d');
        $endpoint = "/data_gov/taifex_open_data.asp";

        $dateString = Carbon::parse($date)->format('Y/m/d');

        $params = [
            'data_type' => 'DailyOptionsDelta',
            'obj_id' => '',
            'date' => $dateString
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['RtCode']) && $response['RtCode'] === '0') {
            return collect($response['RtData'] ?? []);
        }

        return collect();
    }

    /**
     * 取得 Put/Call Ratio
     * API: /PutCallRatio
     */
    public function getPutCallRatio($date = null)
    {
        $date = $date ?: now()->format('Y-m-d');
        $endpoint = "/data_gov/taifex_open_data.asp";

        $dateString = Carbon::parse($date)->format('Y/m/d');

        $params = [
            'data_type' => 'PutCallRatio',
            'obj_id' => '',
            'date' => $dateString
        ];

        $response = $this->makeRequest($endpoint, $params);

        if ($response && isset($response['RtCode']) && $response['RtCode'] === '0') {
            return $response['RtData'] ?? [];
        }

        return [];
    }

    /**
     * 解析選擇權資料
     */
    protected function parseOptionsData($data)
    {
        return collect($data)->map(function ($item) {
            // 解析選擇權代碼
            $optionCode = $item['ContractCode'] ?? '';
            $parsed = $this->parseOptionCode($optionCode);

            return [
                'option_code' => $optionCode,
                'underlying' => $parsed['underlying'],
                'expiry_date' => $this->parseExpiryDate($item['ExpirationDate'] ?? ''),
                'option_type' => $parsed['option_type'],
                'strike_price' => $this->parseNumber($item['StrikePrice'] ?? 0),
                'open' => $this->parseNumber($item['OpeningPrice'] ?? 0),
                'high' => $this->parseNumber($item['HighestPrice'] ?? 0),
                'low' => $this->parseNumber($item['LowestPrice'] ?? 0),
                'close' => $this->parseNumber($item['ClosingPrice'] ?? 0),
                'settlement' => $this->parseNumber($item['SettlementPrice'] ?? 0),
                'volume' => $this->parseNumber($item['TradingVolume'] ?? 0),
                'open_interest' => $this->parseNumber($item['OpenInterest'] ?? 0),
                'change' => $this->parseNumber($item['Change'] ?? 0),
                'bid' => $this->parseNumber($item['BestBid'] ?? 0),
                'ask' => $this->parseNumber($item['BestAsk'] ?? 0),
            ];
        })->filter(function ($item) {
            // 只保留 TXO (臺指選擇權)
            return $item['underlying'] === 'TXO' || $item['underlying'] === 'TX';
        });
    }

    /**
     * 解析選擇權代碼
     * 範例: TXO202411C23000 = TXO + 2024年11月 + Call + 23000履約價
     */
    protected function parseOptionCode($code)
    {
        $result = [
            'underlying' => '',
            'expiry_year' => '',
            'expiry_month' => '',
            'option_type' => '',
            'strike_price' => 0
        ];

        // TXO 格式: TXO + YYYYMM + C/P + 履約價
        if (preg_match('/^(TXO|TX)(\d{4})(\d{2})([CP])(\d+)$/', $code, $matches)) {
            $result['underlying'] = $matches[1];
            $result['expiry_year'] = $matches[2];
            $result['expiry_month'] = $matches[3];
            $result['option_type'] = $matches[4] === 'C' ? 'call' : 'put';
            $result['strike_price'] = intval($matches[5]);
        }

        return $result;
    }

    /**
     * 解析到期日
     * 格式: YYYY/MM/DD
     */
    protected function parseExpiryDate($dateString)
    {
        if (empty($dateString)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y/m/d', $dateString)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning("無法解析到期日: {$dateString}");
            return null;
        }
    }

    /**
     * 發送 HTTP 請求
     */
    protected function makeRequest($endpoint, $params = [])
    {
        $url = $this->baseUrl . $endpoint;

        // 快取機制 (5 分鐘)
        $cacheKey = 'taifex_' . md5($url . json_encode($params));

        if (Cache::has($cacheKey)) {
            Log::info("使用快取資料: {$cacheKey}");
            return Cache::get($cacheKey);
        }

        try {
            Log::info("發送請求至 TAIFEX API", ['url' => $url, 'params' => $params]);

            $response = Http::timeout($this->timeout)
                ->retry($this->retries, 1000)
                ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();

                // 檢查回應狀態
                if (isset($data['RtCode'])) {
                    if ($data['RtCode'] === '0') {
                        // 成功，快取 5 分鐘
                        Cache::put($cacheKey, $data, now()->addMinutes(5));
                        return $data;
                    } else {
                        Log::warning("TAIFEX API 回應錯誤", [
                            'code' => $data['RtCode'],
                            'message' => $data['RtMsg'] ?? 'Unknown error'
                        ]);
                    }
                }

                return $data;
            }

            Log::warning("TAIFEX API 回應非成功狀態", [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
        } catch (\Exception $e) {
            Log::error("TAIFEX API 請求失敗", [
                'error' => $e->getMessage(),
                'url' => $url,
                'params' => $params
            ]);
        }

        return null;
    }

    /**
     * 解析數字
     */
    protected function parseNumber($value)
    {
        if (!$value || $value === '-' || $value === '--') {
            return 0;
        }

        // 移除千分位逗號
        $cleaned = str_replace(',', '', $value);

        return floatval($cleaned);
    }
}
