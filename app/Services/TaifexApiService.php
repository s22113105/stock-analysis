<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * 期交所 API 服務 (真實數據版)
 *
 * 功能：
 * - 擷取臺指選擇權每日行情 (含週選/月選識別)
 * - 擷取 Delta 資料
 * - 擷取加權指數收盤價 (作為標的價格)
 */
class TaifexApiService
{
    protected $baseUrl;
    protected $timeout;

    public function __construct()
    {
        $this->baseUrl = 'https://www.taifex.com.tw';
        $this->timeout = config('services.taifex.timeout', 30);
    }

    /**
     * 取得臺指選擇權每日行情
     */
    public function getDailyOptionsReport(string $date): Collection
    {
        // 快取 1 小時，避免頻繁爬取被擋
        $cacheKey = "taifex_options_real_{$date}";

        return Cache::remember($cacheKey, 3600, function () use ($date) {
            $url = "{$this->baseUrl}/cht/3/optDailyMarketReport";
            $queryDate = Carbon::parse($date)->format('Y/m/d');

            Log::info("開始爬取期交所選擇權行情: {$date}");

            try {
                $response = Http::timeout($this->timeout)
                    ->asForm()
                    ->post($url, [
                        'queryDate' => $queryDate,
                        'commodity_id' => 'TXO',
                        'MarketCode' => '0', // 一般交易時段
                    ]);

                if (!$response->successful()) {
                    Log::error("期交所回應錯誤: {$response->status()}");
                    return collect();
                }

                return $this->parseOptionsHtml($response->body(), $date);

            } catch (\Exception $e) {
                Log::error("期交所爬蟲例外: " . $e->getMessage());
                return collect();
            }
        });
    }

    /**
     * 取得 Delta 資料 (真實爬取)
     */
    public function getDailyOptionsDelta(string $date): Collection
    {
        $cacheKey = "taifex_delta_real_{$date}";

        return Cache::remember($cacheKey, 3600, function () use ($date) {
            $url = "{$this->baseUrl}/cht/3/optDailyDelta";
            $queryDate = Carbon::parse($date)->format('Y/m/d');

            try {
                $response = Http::timeout($this->timeout)
                    ->asForm()
                    ->post($url, [
                        'queryDate' => $queryDate,
                        'commodity_id' => 'TXO',
                    ]);

                if (!$response->successful()) return collect();

                $crawler = new Crawler($response->body());
                $rows = $crawler->filter('table tr');
                $data = [];

                $rows->each(function (Crawler $row, $i) use (&$data, $date) {
                    if ($i < 2) return; // 跳過表頭
                    $cells = $row->filter('td');
                    if ($cells->count() < 5) return;

                    // 表格結構: 契約 | 到期月份 | 履約價 | 買權Delta | 賣權Delta
                    $month = trim($cells->eq(1)->text());
                    $strike = $this->cleanNumber($cells->eq(2)->text());
                    $callDelta = $this->cleanNumber($cells->eq(3)->text());
                    $putDelta = $this->cleanNumber($cells->eq(4)->text());

                    if ($strike) {
                        $data[] = [
                            'contract_month' => $month,
                            'strike_price' => $strike,
                            'call_delta' => $callDelta,
                            'put_delta' => $putDelta
                        ];
                    }
                });

                return collect($data);

            } catch (\Exception $e) {
                Log::error("Delta 爬取失敗: " . $e->getMessage());
                return collect();
            }
        });
    }

    /**
     * 解析選擇權 HTML
     */
    protected function parseOptionsHtml(string $html, string $date): Collection
    {
        $crawler = new Crawler($html);
        // 通常資料在第二個 table (視網頁結構而定，有時候是主要內容區的 table)
        $tables = $crawler->filter('.table_f'); // 期交所 CSS class

        if ($tables->count() === 0) {
            $tables = $crawler->filter('table');
        }

        $data = [];

        // 尋找正確的資料表格
        $tables->each(function (Crawler $table) use (&$data, $date) {
            // 簡單判斷：如果已經解析出大量資料就不再解析其他表
            if (count($data) > 10) return;

            $rows = $table->filter('tr');
            
            $rows->each(function (Crawler $row, $index) use (&$data, $date) {
                // 跳過表頭
                if ($index < 1) return; 

                $cells = $row->filter('td');
                
                // 確保欄位數足夠 (期交所報表通常有 10+ 欄)
                // 結構通常為: 契約(0) | 到期月份(1) | 履約價(2) | 買權開(3)... | 賣權開(...)
                if ($cells->count() < 10) return;

                // 1. 提取關鍵資訊
                $contractName = trim($cells->eq(0)->text()); // TXO
                if ($contractName !== 'TXO') return;

                $contractMonth = trim($cells->eq(1)->text()); // ex: 202511 或 202511W2
                $strikePrice = $this->cleanNumber($cells->eq(2)->text());

                if (!$strikePrice) return;

                // 2. 提取買權 (Call) 資料 - 假設在左側
                // 注意：期交所欄位可能會變，這裡以常見結構為準
                // 買權: 開(3) 高(4) 低(5) 結算(6) 量(7) OI(8)
                // 賣權: 開(9) 高(10) 低(11) 結算(12) 量(13) OI(14)
                
                // 判斷是否為合併儲存格結構 (有些週選表會不一樣)
                // 這裡採用較通用的索引抓取
                
                // Call Data
                $callClose = $this->cleanNumber($cells->eq(6)->text());
                $callVol = $this->cleanNumber($cells->eq(7)->text());
                $callOi = $this->cleanNumber($cells->eq(8)->text());

                if ($callClose !== null) {
                    $data[] = [
                        'option_code' => $this->generateOptionCode($contractMonth, $strikePrice, 'call'),
                        'underlying' => 'TXO',
                        'option_type' => 'call',
                        'strike_price' => $strikePrice,
                        'expiry_date' => $this->parseExpiryDate($contractMonth, $date),
                        'close' => $callClose,
                        'volume' => $callVol ?? 0,
                        'open_interest' => $callOi ?? 0,
                        'trade_date' => $date,
                        'contract_month' => $contractMonth // 保留原始月份代碼
                    ];
                }

                // Put Data
                // 賣權欄位位移，通常在 Call 之後
                // 如果 Call 佔用了 6 欄 (3,4,5,6,7,8)，Put 從 9 開始
                $putClose = $this->cleanNumber($cells->eq(12)->text());
                $putVol = $this->cleanNumber($cells->eq(13)->text());
                $putOi = $this->cleanNumber($cells->eq(14)->text());

                if ($putClose !== null) {
                    $data[] = [
                        'option_code' => $this->generateOptionCode($contractMonth, $strikePrice, 'put'),
                        'underlying' => 'TXO',
                        'option_type' => 'put',
                        'strike_price' => $strikePrice,
                        'expiry_date' => $this->parseExpiryDate($contractMonth, $date),
                        'close' => $putClose,
                        'volume' => $putVol ?? 0,
                        'open_interest' => $putOi ?? 0,
                        'trade_date' => $date,
                        'contract_month' => $contractMonth
                    ];
                }
            });
        });

        Log::info("解析完成: 取得 " . count($data) . " 筆資料");
        return collect($data);
    }

    /**
     * 取得標的價格 (從證交所 API 抓取加權指數)
     * 這是計算 Black-Scholes 的關鍵 Input S
     */
    public function getUnderlyingPrice(string $date): ?float
    {
        $cacheKey = "twse_index_{$date}";

        return Cache::remember($cacheKey, 3600, function () use ($date) {
            // 使用證交所公開數據 API (MI_INDEX)
            // 格式: yyyymmdd
            $formattedDate = Carbon::parse($date)->format('Ymd');
            $url = "https://www.twse.com.tw/rwd/zh/afterTrading/MI_INDEX";

            try {
                Log::info("查詢加權指數: {$date}");
                $response = Http::timeout(10)->get($url, [
                    'date' => $formattedDate,
                    'type' => 'ALLBUT0999', // 包含指數的大表
                    'response' => 'json'
                ]);

                if (!$response->successful()) return null;

                $json = $response->json();
                
                // 解析 JSON 結構尋找「發行量加權股價指數」
                // 通常在 'tables' 陣列中的第一個 (指數資訊)
                if (isset($json['tables'])) {
                    foreach ($json['tables'] as $table) {
                        if (str_contains($table['title'] ?? '', '指數')) {
                            foreach ($table['data'] as $row) {
                                // row[0] 是指數名稱, row[1] 是收盤指數
                                if ($row[0] === '發行量加權股價指數') {
                                    $price = $this->cleanNumber($row[1]);
                                    Log::info("取得加權指數: {$price}");
                                    return $price;
                                }
                            }
                        }
                    }
                }
                
                return null;

            } catch (\Exception $e) {
                Log::error("加權指數爬取失敗: " . $e->getMessage());
                return null; // 失敗回傳 null，不要給假資料
            }
        });
    }

    /**
     * 生成標準化選擇權代碼
     * 格式: TXO_202511_C_16000 或 TXO_202511W2_P_16000
     */
    protected function generateOptionCode(string $contractMonth, float $strikePrice, string $type): string
    {
        $typeCode = strtoupper(substr($type, 0, 1)); // C or P
        $strike = intval($strikePrice);
        
        // 清理月份字串 (移除多餘空白)
        $month = trim($contractMonth);
        
        return "TXO_{$month}_{$typeCode}_{$strike}";
    }

    /**
     * 解析到期日 (這是一個估算，精確日期應參照期交所行事曆)
     */
    protected function parseExpiryDate(string $contractMonth, string $tradeDate): string
    {
        // 這裡做一個簡單的推算邏輯
        // 如果是月選 (202511): 第三個週三
        // 如果是週選 (202511W2): 該週的週三
        
        try {
            $year = substr($contractMonth, 0, 4);
            $month = substr($contractMonth, 4, 2);
            
            $dt = Carbon::createFromDate($year, $month, 1);
            
            if (str_contains($contractMonth, 'W')) {
                // 週選邏輯 (W1, W2, W4, W5)
                $weekPart = substr($contractMonth, strpos($contractMonth, 'W') + 1);
                $weekNum = intval($weekPart);
                
                // 找到該月第 N 個週三
                $dt->nthOfMonth($weekNum, Carbon::WEDNESDAY);
            } else {
                // 月選邏輯 (第三個週三)
                $dt->nthOfMonth(3, Carbon::WEDNESDAY);
            }
            
            return $dt->format('Y-m-d');
        } catch (\Exception $e) {
            // 如果解析失敗，暫時回傳交易日+30天
            return Carbon::parse($tradeDate)->addDays(30)->format('Y-m-d');
        }
    }

    /**
     * 清理數字 (移除逗號)
     */
    protected function cleanNumber($text)
    {
        $val = str_replace([',', ' '], '', trim($text));
        if ($val === '' || $val === '-' || $val === '--') return null;
        return floatval($val);
    }
}