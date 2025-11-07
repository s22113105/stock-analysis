<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * 期交所 API 服務 (正確版本)
 * 使用 POST 方法和 HTML 解析
 */
class TaifexApiService
{
    protected $timeout;
    protected $retries;

    public function __construct()
    {
        $this->timeout = config('services.taifex.timeout', 30);
        $this->retries = config('services.taifex.retries', 3);
    }

    /**
     * 取得選擇權每日交易行情
     * 使用 HTML 解析方式 (類似 Python 版本)
     *
     * @param string $date 日期 (Y-m-d)
     * @return \Illuminate\Support\Collection
     */
    public function getDailyOptionsReport($date = null)
    {
        $date = $date ?: now()->format('Y-m-d');

        // 期交所網址 (HTML 版本)
        $url = 'https://www.taifex.com.tw/cht/3/optDailyMarketReport';

        // 日期格式: YYYY/MM/DD
        $queryDate = Carbon::parse($date)->format('Y/m/d');

        // POST 參數
        $payload = [
            'queryDate' => $queryDate,
            'commodity_id' => 'TXO',  // 台指選擇權
            'MarketCode' => '0',      // 日盤 (0=日盤, 1=夜盤)
        ];

        Log::info('發送請求至 TAIFEX (HTML)', [
            'url' => $url,
            'params' => $payload,
            'date' => $date
        ]);

        try {
            // 發送 POST 請求
            $response = Http::timeout($this->timeout)
                ->asForm()  // 使用 form data
                ->retry($this->retries, 1000)
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error('TAIFEX API 請求失敗', [
                    'status' => $response->status(),
                    'url' => $url
                ]);
                return collect();
            }

            $html = $response->body();

            Log::info('收到 HTML 回應', [
                'size' => strlen($html) . ' bytes',
                'status' => $response->status()
            ]);

            // 解析 HTML 表格
            $data = $this->parseHtmlTable($html, $date);

            Log::info('HTML 解析完成', [
                'parsed_count' => $data->count()
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error('TAIFEX API 請求異常', [
                'error' => $e->getMessage(),
                'url' => $url,
                'date' => $date
            ]);
            return collect();
        }
    }

    /**
     * 解析 HTML 表格 (類似 Python 的 pd.read_html)
     *
     * @param string $html HTML 內容
     * @param string $date 日期
     * @return \Illuminate\Support\Collection
     */
    protected function parseHtmlTable($html, $date)
    {
        try {
            $crawler = new Crawler($html);

            // 尋找資料表格 (通常是第 3 個表格, 索引從 0 開始)
            $tables = $crawler->filter('table');

            Log::info('找到表格數量', ['count' => $tables->count()]);

            if ($tables->count() < 3) {
                Log::warning('表格數量不足', [
                    'found' => $tables->count(),
                    'expected' => '至少 3 個'
                ]);
                return collect();
            }

            // 取得第 3 個表格 (索引 2)
            $dataTable = $tables->eq(2);

            // 解析表格資料
            $rows = $dataTable->filter('tr');
            $data = collect();

            Log::info('表格行數', ['rows' => $rows->count()]);

            // 找到標題行 (通常在第 4 行，索引 3)
            $headerRow = null;
            $headerIndex = -1;

            $rows->each(function (Crawler $row, $index) use (&$headerRow, &$headerIndex) {
                $text = trim($row->text());
                // 尋找包含「契約」的行作為標題行
                if (strpos($text, '契約') !== false || strpos($text, '履約價') !== false) {
                    $headerRow = $row;
                    $headerIndex = $index;
                    Log::info('找到標題行', ['index' => $index]);
                }
            });

            if (!$headerRow) {
                Log::warning('找不到標題行');
                return collect();
            }

            // 解析標題
            $headers = [];
            $headerRow->filter('th, td')->each(function (Crawler $cell) use (&$headers) {
                $headers[] = trim($cell->text());
            });

            Log::info('表格標題', ['headers' => $headers]);

            // 解析資料行 (從標題行的下一行開始)
            $dataStartIndex = $headerIndex + 1;
            $recordCount = 0;

            $rows->each(function (Crawler $row, $index) use (&$data, $dataStartIndex, $headers, $date, &$recordCount) {
                // 跳過標題行之前的行
                if ($index <= $dataStartIndex) {
                    return;
                }

                $cells = $row->filter('td');

                // 如果沒有 td 元素，跳過
                if ($cells->count() == 0) {
                    return;
                }

                // 取得第一個 cell 的文字，檢查是否為小計或總計
                $firstCell = trim($cells->eq(0)->text());

                // 跳過小計、總計等行
                if (
                    empty($firstCell) ||
                    strpos($firstCell, '小計') !== false ||
                    strpos($firstCell, '總計') !== false ||
                    strpos($firstCell, '合計') !== false
                ) {
                    return;
                }

                // 解析每個 cell
                $rowData = [];
                $cells->each(function (Crawler $cell, $cellIndex) use (&$rowData) {
                    $rowData[] = trim($cell->text());
                });

                // 確保有足夠的欄位
                if (count($rowData) < 10) {
                    return;
                }

                // 建立資料結構
                $record = $this->buildRecordFromTableRow($rowData, $headers, $date);

                if ($record) {
                    $data->push($record);
                    $recordCount++;
                }
            });

            Log::info('解析完成', [
                'total_records' => $recordCount,
                'sample' => $data->take(2)->toArray()
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error('HTML 解析失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return collect();
        }
    }

    /**
     * 從表格行資料建立記錄
     *
     * @param array $rowData 行資料
     * @param array $headers 標題
     * @param string $date 日期
     * @return array|null
     */
    protected function buildRecordFromTableRow($rowData, $headers, $date)
    {
        try {
            // 期交所表格欄位順序:
            // 契約, 到期月份(週別), 履約價, 買賣權, 開盤價, 最高價, 最低價,
            // 最後成交價, 漲跌, 結算價, 成交量, 未平倉, ...

            // 基本資訊
            $underlying = $rowData[0] ?? '';  // 標的 (TXO, TEO, TFO...)
            $expiryMonth = $rowData[1] ?? '';  // 到期月份 (202511, 202511W1...)
            $strikePrice = $this->cleanPrice($rowData[2] ?? 0);  // 履約價
            $optionType = $rowData[3] ?? '';  // 買賣權 (Call/Put)

            // 價格資訊
            $openPrice = $this->cleanPrice($rowData[4] ?? 0);  // 開盤價
            $highPrice = $this->cleanPrice($rowData[5] ?? 0);  // 最高價
            $lowPrice = $this->cleanPrice($rowData[6] ?? 0);  // 最低價
            $closePrice = $this->cleanPrice($rowData[7] ?? 0);  // 最後成交價
            $change = $this->cleanPrice($rowData[8] ?? 0);  // 漲跌
            $settlementPrice = $this->cleanPrice($rowData[9] ?? 0);  // 結算價

            // 交易量資訊
            $volumeAfterHours = $this->cleanVolume($rowData[10] ?? 0);  // 盤後成交量
            $volumeGeneral = $this->cleanVolume($rowData[11] ?? 0);  // 一般交易時段成交量
            $volumeTotal = $this->cleanVolume($rowData[12] ?? 0);  // 合計成交量
            $openInterest = $this->cleanVolume($rowData[13] ?? 0);  // 未平倉量

            // 驗證基本欄位
            if (empty($underlying) || empty($expiryMonth) || $strikePrice <= 0) {
                return null;
            }

            // 解析選擇權類型
            $optionTypeStd = 'unknown';
            $optionTypeCode = '';
            if (strpos($optionType, 'Call') !== false || $optionType === 'C' || strpos($optionType, '買') !== false) {
                $optionTypeStd = 'call';
                $optionTypeCode = 'C';
            } elseif (strpos($optionType, 'Put') !== false || $optionType === 'P' || strpos($optionType, '賣') !== false) {
                $optionTypeStd = 'put';
                $optionTypeCode = 'P';
            }

            // 建立完整的選擇權代碼
            // 格式: TXO + 到期月份 + C/P + 履約價
            // 例如: TXO202511W1C24700
            $optionCode = $underlying . $expiryMonth . $optionTypeCode . intval($strikePrice);

            return [
                'ContractCode' => $optionCode,
                'Underlying' => $underlying,
                'ExpirationMonth' => $expiryMonth,
                'ExpirationDate' => $this->parseExpiryDate($expiryMonth),
                'StrikePrice' => $strikePrice,
                'OptionType' => $optionTypeStd,
                'OptionTypeZh' => $optionType,
                'OpeningPrice' => $openPrice,
                'HighestPrice' => $highPrice,
                'LowestPrice' => $lowPrice,
                'ClosingPrice' => $closePrice,
                'Change' => $change,
                'SettlementPrice' => $settlementPrice,
                'TradingVolume' => $volumeTotal,
                'VolumeGeneral' => $volumeGeneral,
                'VolumeAfterHours' => $volumeAfterHours,
                'OpenInterest' => $openInterest,
                'TradeDate' => $date,
            ];
        } catch (\Exception $e) {
            Log::warning('建立記錄失敗', [
                'error' => $e->getMessage(),
                'row_data' => $rowData
            ]);
            return null;
        }
    }

    /**
     * 清理價格資料
     */
    protected function cleanPrice($value)
    {
        if (is_null($value) || $value === '' || $value === '-' || $value === '--') {
            return 0.0;
        }

        // 移除千分位逗號
        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }

        return floatval($value);
    }

    /**
     * 清理交易量資料
     */
    protected function cleanVolume($value)
    {
        if (is_null($value) || $value === '' || $value === '-' || $value === '--') {
            return 0;
        }

        // 移除千分位逗號
        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }

        return max(0, intval($value));
    }

    /**
     * 解析到期日
     * 支援月選擇權和週選擇權格式
     *
     * @param string $expiryMonth 到期月份 (YYYYMM 或 YYYYMMWN)
     * @return string|null 到期日 (Y-m-d)
     */
    protected function parseExpiryDate($expiryMonth)
    {
        if (empty($expiryMonth)) {
            return null;
        }

        try {
            // 格式 1: 週選擇權 YYYYMMWN (例如: 202511W1, 202511W2)
            if (preg_match('/^(\d{4})(\d{2})W(\d+)$/', $expiryMonth, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $weekNum = intval($matches[3]);

                // 計算該月第 N 個週三
                $date = Carbon::create($year, $month, 1);
                $nthWednesday = $date->nthOfMonth($weekNum, Carbon::WEDNESDAY);

                return $nthWednesday->format('Y-m-d');
            }

            // 格式 2: 月選擇權 YYYYMM (例如: 202511)
            if (preg_match('/^(\d{4})(\d{2})$/', $expiryMonth, $matches)) {
                $year = $matches[1];
                $month = $matches[2];

                // 台指選擇權到期日: 每月第三個週三
                $date = Carbon::create($year, $month, 1);
                $thirdWednesday = $date->nthOfMonth(3, Carbon::WEDNESDAY);

                return $thirdWednesday->format('Y-m-d');
            }

            return null;
        } catch (\Exception $e) {
            Log::warning("無法解析到期日", [
                'expiry_month' => $expiryMonth,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 解析數字
     */
    protected function parseNumber($value)
    {
        return $this->cleanPrice($value);
    }
}
