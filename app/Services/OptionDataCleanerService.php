<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 選擇權資料清理與轉換服務
 * 負責將原始 API 資料清理、驗證、轉換成標準格式
 */
class OptionDataCleanerService
{
    /**
     * 清理並轉換選擇權資料
     *
     * @param Collection $rawData 原始 API 資料
     * @param string $date 交易日期
     * @return Collection 清理後的資料
     */
    public function cleanAndTransform(Collection $rawData, string $date): Collection
    {
        Log::info('開始清理選擇權資料', [
            'raw_count' => $rawData->count(),
            'date' => $date
        ]);

        $cleaned = $rawData
            ->map(function ($item) use ($date) {
                return $this->cleanSingleRecord($item, $date);
            })
            ->filter(function ($item) {
                return $this->validateRecord($item);
            })
            ->values(); // 重新索引

        Log::info('資料清理完成', [
            'original_count' => $rawData->count(),
            'cleaned_count' => $cleaned->count(),
            'removed_count' => $rawData->count() - $cleaned->count()
        ]);

        return $cleaned;
    }

    /**
     * 清理單筆記錄
     *
     * @param array $item 原始資料項目
     * @param string $date 交易日期
     * @return array 清理後的資料
     */
    protected function cleanSingleRecord(array $item, string $date): array
    {
        // 解析選擇權代碼
        $optionCode = $item['ContractCode'] ?? '';
        $parsed = $this->parseOptionCode($optionCode);

        return [
            // === 基本資訊 ===
            'date' => $date,
            'option_code' => $this->cleanString($optionCode),
            'underlying' => $parsed['underlying'],
            'expiry_month' => $parsed['expiry_year'] . '-' . $parsed['expiry_month'],
            'expiry_date' => $this->parseExpiryDate($item['ExpirationDate'] ?? ''),
            'strike_price' => $this->cleanPrice($item['StrikePrice'] ?? 0),
            'option_type' => $parsed['option_type'], // call 或 put
            'option_type_zh' => $parsed['option_type'] === 'call' ? '買權' : '賣權',

            // === 價格資訊 ===
            'open_price' => $this->cleanPrice($item['OpeningPrice'] ?? 0),
            'high_price' => $this->cleanPrice($item['HighestPrice'] ?? 0),
            'low_price' => $this->cleanPrice($item['LowestPrice'] ?? 0),
            'close_price' => $this->cleanPrice($item['ClosingPrice'] ?? 0),
            'settlement_price' => $this->cleanPrice($item['SettlementPrice'] ?? 0),
            'change' => $this->cleanPrice($item['Change'] ?? 0),
            'change_percent' => $this->calculateChangePercent(
                $item['ClosingPrice'] ?? 0,
                $item['Change'] ?? 0
            ),

            // === 交易量資訊 ===
            'volume_general' => $this->cleanVolume($item['TradingVolume'] ?? 0), // 一般交易時段
            'volume_afterhours' => $this->cleanVolume($item['AfterHoursVolume'] ?? 0), // 盤後
            'volume_total' => $this->cleanVolume($item['TotalVolume'] ?? 0), // 合計
            'open_interest' => $this->cleanVolume($item['OpenInterest'] ?? 0), // 未平倉
            'open_interest_change' => $this->cleanVolume($item['OpenInterestChange'] ?? 0),

            // === 買賣報價 ===
            'best_bid' => $this->cleanPrice($item['BestBid'] ?? 0),
            'best_ask' => $this->cleanPrice($item['BestAsk'] ?? 0),
            'bid_volume' => $this->cleanVolume($item['BestBidVolume'] ?? 0),
            'ask_volume' => $this->cleanVolume($item['BestAskVolume'] ?? 0),

            // === 計算欄位 ===
            'spread' => $this->calculateSpread(
                $item['BestAsk'] ?? 0,
                $item['BestBid'] ?? 0
            ),
            'mid_price' => $this->calculateMidPrice(
                $item['BestAsk'] ?? 0,
                $item['BestBid'] ?? 0
            ),

            // === 價內價外判斷 (需要標的價格,稍後補充) ===
            'moneyness' => null, // ITM/ATM/OTM
            'intrinsic_value' => null, // 內含價值
            'time_value' => null, // 時間價值

            // === 原始資料 (保留供查詢) ===
            'raw_data' => $item
        ];
    }

    /**
     * 驗證記錄是否有效
     *
     * @param array $record 清理後的記錄
     * @return bool 是否有效
     */
    protected function validateRecord(array $record): bool
    {
        // 必要欄位檢查
        if (empty($record['option_code'])) {
            Log::warning('選擇權代碼為空', ['record' => $record]);
            return false;
        }

        if (empty($record['underlying'])) {
            Log::warning('無法識別標的', ['option_code' => $record['option_code']]);
            return false;
        }

        // 只保留 TXO (台指選擇權)
        if (!in_array($record['underlying'], ['TXO', 'TX'])) {
            return false;
        }

        // 履約價必須大於 0
        if ($record['strike_price'] <= 0) {
            Log::warning('履約價無效', [
                'option_code' => $record['option_code'],
                'strike_price' => $record['strike_price']
            ]);
            return false;
        }

        // 到期日必須有效
        if (empty($record['expiry_date'])) {
            Log::warning('到期日無效', ['option_code' => $record['option_code']]);
            return false;
        }

        // 價格合理性檢查 (開高低收)
        if ($record['high_price'] > 0 && $record['low_price'] > 0) {
            if ($record['high_price'] < $record['low_price']) {
                Log::warning('價格異常: 最高價 < 最低價', [
                    'option_code' => $record['option_code'],
                    'high' => $record['high_price'],
                    'low' => $record['low_price']
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * 清理字串 (移除空白、特殊字元)
     */
    protected function cleanString(?string $value): string
    {
        if (empty($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * 清理價格資料
     * 處理: null, '-', '--', 空字串, 千分位逗號
     */
    protected function cleanPrice($value): float
    {
        if (is_null($value) || $value === '' || $value === '-' || $value === '--') {
            return 0.0;
        }

        // 移除千分位逗號
        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }

        $cleaned = floatval($value);

        // 價格不應該是負數 (除了變動幅度)
        return $cleaned;
    }

    /**
     * 清理交易量資料
     */
    protected function cleanVolume($value): int
    {
        if (is_null($value) || $value === '' || $value === '-' || $value === '--') {
            return 0;
        }

        // 移除千分位逗號
        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }

        $cleaned = intval($value);

        // 交易量不應該是負數
        return max(0, $cleaned);
    }

    /**
     * 解析選擇權代碼
     * 範例: TXO202411C23000 = TXO + 2024年11月 + Call + 23000履約價
     */
    protected function parseOptionCode(string $code): array
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
    protected function parseExpiryDate(?string $dateString): ?string
    {
        if (empty($dateString)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y/m/d', $dateString)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning("無法解析到期日", ['date' => $dateString]);
            return null;
        }
    }

    /**
     * 計算漲跌幅百分比
     */
    protected function calculateChangePercent($closePrice, $change): float
    {
        $close = $this->cleanPrice($closePrice);
        $changeValue = $this->cleanPrice($change);

        if ($close == 0 || $changeValue == 0) {
            return 0.0;
        }

        $previousClose = $close - $changeValue;

        if ($previousClose == 0) {
            return 0.0;
        }

        return round(($changeValue / $previousClose) * 100, 2);
    }

    /**
     * 計算買賣價差
     */
    protected function calculateSpread($ask, $bid): float
    {
        $askPrice = $this->cleanPrice($ask);
        $bidPrice = $this->cleanPrice($bid);

        if ($askPrice <= 0 || $bidPrice <= 0) {
            return 0.0;
        }

        return round($askPrice - $bidPrice, 2);
    }

    /**
     * 計算中間價
     */
    protected function calculateMidPrice($ask, $bid): float
    {
        $askPrice = $this->cleanPrice($ask);
        $bidPrice = $this->cleanPrice($bid);

        if ($askPrice <= 0 || $bidPrice <= 0) {
            return 0.0;
        }

        return round(($askPrice + $bidPrice) / 2, 2);
    }

    /**
     * 根據標的價格計算價內價外狀態
     *
     * @param array $record 選擇權記錄
     * @param float $underlyingPrice 標的價格 (台指期貨或現貨)
     * @return array 更新後的記錄
     */
    public function calculateMoneyness(array $record, float $underlyingPrice): array
    {
        $strikePrice = $record['strike_price'];
        $optionType = $record['option_type'];

        // 計算內含價值
        if ($optionType === 'call') {
            $intrinsicValue = max(0, $underlyingPrice - $strikePrice);

            if ($underlyingPrice > $strikePrice) {
                $moneyness = 'ITM'; // In The Money (價內)
            } elseif ($underlyingPrice == $strikePrice) {
                $moneyness = 'ATM'; // At The Money (價平)
            } else {
                $moneyness = 'OTM'; // Out of The Money (價外)
            }
        } else { // put
            $intrinsicValue = max(0, $strikePrice - $underlyingPrice);

            if ($underlyingPrice < $strikePrice) {
                $moneyness = 'ITM';
            } elseif ($underlyingPrice == $strikePrice) {
                $moneyness = 'ATM';
            } else {
                $moneyness = 'OTM';
            }
        }

        // 計算時間價值 = 市價 - 內含價值
        $timeValue = max(0, $record['close_price'] - $intrinsicValue);

        $record['moneyness'] = $moneyness;
        $record['intrinsic_value'] = round($intrinsicValue, 2);
        $record['time_value'] = round($timeValue, 2);
        $record['underlying_price'] = $underlyingPrice;

        return $record;
    }

    /**
     * 產生資料統計報告
     */
    public function generateStatistics(Collection $data): array
    {
        if ($data->isEmpty()) {
            return [
                'total_count' => 0,
                'call_count' => 0,
                'put_count' => 0,
                'avg_volume' => 0,
                'total_volume' => 0,
                'total_open_interest' => 0
            ];
        }

        $calls = $data->where('option_type', 'call');
        $puts = $data->where('option_type', 'put');

        return [
            'total_count' => $data->count(),
            'call_count' => $calls->count(),
            'put_count' => $puts->count(),
            'avg_volume' => round($data->avg('volume_total'), 2),
            'total_volume' => $data->sum('volume_total'),
            'total_open_interest' => $data->sum('open_interest'),
            'price_range' => [
                'min_strike' => $data->min('strike_price'),
                'max_strike' => $data->max('strike_price'),
            ],
            'volume_range' => [
                'min' => $data->min('volume_total'),
                'max' => $data->max('volume_total'),
            ]
        ];
    }

    /**
     * 匯出為 CSV 格式 (類似 pandas DataFrame)
     */
    public function exportToCsv(Collection $data, string $filename): string
    {
        $headers = [
            '日期',
            '契約',
            '到期月份',
            '履約價',
            '買賣權',
            '開盤價',
            '最高價',
            '最低價',
            '收盤價',
            '結算價',
            '漲跌',
            '漲跌幅%',
            '盤後成交量',
            '一般成交量',
            '合計成交量',
            '未平倉量',
            '最佳買價',
            '最佳賣價',
            '價差',
            '中間價'
        ];

        $csv = implode(',', $headers) . "\n";

        foreach ($data as $row) {
            $csv .= implode(',', [
                $row['date'],
                $row['option_code'],
                $row['expiry_month'],
                $row['strike_price'],
                $row['option_type_zh'],
                $row['open_price'],
                $row['high_price'],
                $row['low_price'],
                $row['close_price'],
                $row['settlement_price'],
                $row['change'],
                $row['change_percent'],
                $row['volume_afterhours'],
                $row['volume_general'],
                $row['volume_total'],
                $row['open_interest'],
                $row['best_bid'],
                $row['best_ask'],
                $row['spread'],
                $row['mid_price'],
            ]) . "\n";
        }

        $filepath = storage_path("app/exports/{$filename}");

        // 確保目錄存在
        if (!file_exists(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        file_put_contents($filepath, $csv);

        return $filepath;
    }

    /**
     * 匯出為 JSON 格式
     */
    public function exportToJson(Collection $data, string $filename): string
    {
        $filepath = storage_path("app/exports/{$filename}");

        // 確保目錄存在
        if (!file_exists(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        file_put_contents(
            $filepath,
            json_encode($data->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        return $filepath;
    }
}
