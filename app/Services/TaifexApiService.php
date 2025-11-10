<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * 期交所 API 服務
 * 
 * 功能：
 * - 擷取臺指選擇權每日行情
 * - 擷取 Delta 資料
 * - 解析 HTML 表格
 */
class TaifexApiService
{
    protected $baseUrl;
    protected $timeout;
    protected $retries;

    public function __construct()
    {
        $this->baseUrl = 'https://www.taifex.com.tw';
        $this->timeout = config('services.taifex.timeout', 30);
        $this->retries = config('services.taifex.retries', 3);
    }

    /**
     * 取得臺指選擇權每日行情
     *
     * @param string $date 日期 (Y-m-d)
     * @return Collection
     */
    public function getDailyOptionsReport(string $date): Collection
    {
        $cacheKey = "taifex_options_{$date}";

        return Cache::remember($cacheKey, 3600, function () use ($date) {
            $url = "{$this->baseUrl}/cht/3/optDailyMarketReport";
            
            $queryDate = Carbon::parse($date)->format('Y/m/d');

            $payload = [
                'queryDate' => $queryDate,
                'commodity_id' => 'TXO',
                'MarketCode' => '0',
            ];

            Log::info('發送 TAIFEX API 請求', [
                'url' => $url,
                'date' => $date,
                'query_date' => $queryDate
            ]);

            try {
                $response = Http::timeout($this->timeout)
                    ->asForm()
                    ->post($url, $payload);

                if (!$response->successful()) {
                    Log::error('TAIFEX API 請求失敗', [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    return collect();
                }

                $html = $response->body();

                // 解析 HTML
                return $this->parseOptionsHtml($html, $date);

            } catch (\Exception $e) {
                Log::error('TAIFEX API 例外', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return collect();
            }
        });
    }

    /**
     * 取得 Delta 資料
     *
     * @param string $date 日期 (Y-m-d)
     * @return Collection
     */
    public function getDailyOptionsDelta(string $date): Collection
    {
        $cacheKey = "taifex_delta_{$date}";

        return Cache::remember($cacheKey, 3600, function () use ($date) {
            // 期交所沒有直接提供 Delta API
            // 這裡返回空集合,實際應該從其他來源取得或自行計算
            return collect();
        });
    }

    /**
     * 解析選擇權 HTML
     *
     * @param string $html HTML 內容
     * @param string $date 日期
     * @return Collection
     */
    protected function parseOptionsHtml(string $html, string $date): Collection
    {
        try {
            $crawler = new Crawler($html);
            $tables = $crawler->filter('table');

            if ($tables->count() === 0) {
                Log::warning('找不到表格', ['date' => $date]);
                return collect();
            }

            // 期交所網頁結構: 通常資料在第二個表格
            $dataTable = $tables->eq(1);

            if ($dataTable->count() === 0) {
                Log::warning('找不到資料表格', ['date' => $date]);
                return collect();
            }

            $rows = $dataTable->filter('tr');
            $data = [];

            // 跳過表頭 (前 2 行)
            $rows->each(function (Crawler $row, $index) use (&$data, $date) {
                if ($index < 2) {
                    return;
                }

                $cells = $row->filter('td');
                
                if ($cells->count() < 20) {
                    return;
                }

                // 解析 Call 資料
                try {
                    $callData = $this->parseCellData($cells, 'call', $date);
                    if ($callData) {
                        $data[] = $callData;
                    }
                } catch (\Exception $e) {
                    Log::debug('解析 Call 資料失敗', [
                        'row' => $index,
                        'error' => $e->getMessage()
                    ]);
                }

                // 解析 Put 資料
                try {
                    $putData = $this->parseCellData($cells, 'put', $date);
                    if ($putData) {
                        $data[] = $putData;
                    }
                } catch (\Exception $e) {
                    Log::debug('解析 Put 資料失敗', [
                        'row' => $index,
                        'error' => $e->getMessage()
                    ]);
                }
            });

            Log::info('解析完成', [
                'date' => $date,
                'rows' => count($data)
            ]);

            return collect($data);

        } catch (\Exception $e) {
            Log::error('解析 HTML 失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return collect();
        }
    }

    /**
     * 解析儲存格資料
     *
     * @param Crawler $cells 儲存格集合
     * @param string $type 類型 (call/put)
     * @param string $date 日期
     * @return array|null
     */
    protected function parseCellData(Crawler $cells, string $type, string $date): ?array
    {
        try {
            // 期交所表格結構 (範例)
            // Call: 0-9 欄位
            // 履約價: 10 欄位
            // Put: 11-20 欄位

            if ($type === 'call') {
                $strikePrice = $this->cleanNumber($cells->eq(10)->text());
                $close = $this->cleanNumber($cells->eq(6)->text());
                $volume = $this->cleanNumber($cells->eq(8)->text());
                $openInterest = $this->cleanNumber($cells->eq(9)->text());
                
                // 生成選擇權代碼
                $optionCode = $this->generateOptionCode($date, $strikePrice, 'C');

            } else {
                $strikePrice = $this->cleanNumber($cells->eq(10)->text());
                $close = $this->cleanNumber($cells->eq(16)->text());
                $volume = $this->cleanNumber($cells->eq(18)->text());
                $openInterest = $this->cleanNumber($cells->eq(19)->text());
                
                $optionCode = $this->generateOptionCode($date, $strikePrice, 'P');
            }

            // 基本驗證
            if (!$strikePrice || $strikePrice <= 0) {
                return null;
            }

            return [
                'option_code' => $optionCode,
                'underlying' => 'TXO',
                'option_type' => $type,
                'strike_price' => $strikePrice,
                'close' => $close,
                'volume' => $volume ?? 0,
                'open_interest' => $openInterest ?? 0,
                'trade_date' => $date,
            ];

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 清理數字字串
     *
     * @param string $text 原始文字
     * @return float|int|null
     */
    protected function cleanNumber(string $text)
    {
        $cleaned = trim($text);
        $cleaned = str_replace(',', '', $cleaned);
        $cleaned = str_replace(' ', '', $cleaned);

        if ($cleaned === '' || $cleaned === '-' || $cleaned === 'N/A') {
            return null;
        }

        if (strpos($cleaned, '.') !== false) {
            return floatval($cleaned);
        }

        return intval($cleaned);
    }

    /**
     * 生成選擇權代碼
     *
     * @param string $date 日期
     * @param float $strikePrice 履約價
     * @param string $type C=Call, P=Put
     * @return string
     */
    protected function generateOptionCode(string $date, float $strikePrice, string $type): string
    {
        // 推算到期月份 (假設為最近的結算日)
        $carbon = Carbon::parse($date);
        $expiryMonth = $carbon->format('Ym');
        
        // 格式: TXO_202501_C_20000
        return sprintf('TXO_%s_%s_%d', $expiryMonth, $type, intval($strikePrice));
    }

    /**
     * 取得最近的結算日
     *
     * @param string $date 日期
     * @return string
     */
    protected function getExpiryDate(string $date): string
    {
        $carbon = Carbon::parse($date);
        
        // 台指選擇權: 每個月第三個週三
        $year = $carbon->year;
        $month = $carbon->month;
        
        $firstDay = Carbon::create($year, $month, 1);
        $firstWednesday = $firstDay->copy()->next(Carbon::WEDNESDAY);
        
        $thirdWednesday = $firstWednesday->copy()->addWeeks(2);
        
        return $thirdWednesday->format('Y-m-d');
    }

    /**
     * 取得標的價格 (台指期貨)
     *
     * @param string $date 日期
     * @return float|null
     */
    public function getUnderlyingPrice(string $date): ?float
    {
        // 這裡應該從期交所取得台指期貨價格
        // 暫時返回模擬值
        return 20000.0;
    }
}