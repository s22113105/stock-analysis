<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 選擇權資料清理服務
 * 
 * 功能：
 * - 資料驗證
 * - 資料清理
 * - 資料轉換
 * - 統計分析
 */
class OptionDataCleanerService
{
    /**
     * 清理並轉換資料
     *
     * @param Collection $rawData 原始資料
     * @param string $date 日期
     * @return Collection
     */
    public function cleanAndTransform(Collection $rawData, string $date): Collection
    {
        $cleaned = $rawData
            ->filter(function ($item) {
                return $this->validateItem($item);
            })
            ->map(function ($item) use ($date) {
                return $this->transformItem($item, $date);
            })
            ->filter(function ($item) {
                return $item !== null;
            })
            ->values();

        Log::info('資料清理完成', [
            'original_count' => $rawData->count(),
            'cleaned_count' => $cleaned->count(),
            'removed_count' => $rawData->count() - $cleaned->count()
        ]);

        return $cleaned;
    }

    /**
     * 驗證單筆資料
     *
     * @param array $item 資料項目
     * @return bool
     */
    protected function validateItem(array $item): bool
    {
        // 必要欄位檢查
        $requiredFields = ['option_code', 'underlying', 'option_type', 'strike_price'];

        foreach ($requiredFields as $field) {
            if (!isset($item[$field]) || empty($item[$field])) {
                return false;
            }
        }

        // 選擇權類型檢查
        if (!in_array($item['option_type'], ['call', 'put'])) {
            return false;
        }

        // 履約價檢查
        if (!is_numeric($item['strike_price']) || $item['strike_price'] <= 0) {
            return false;
        }

        // 價格合理性檢查
        if (isset($item['close'])) {
            if (!is_numeric($item['close']) || $item['close'] < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * 轉換單筆資料
     *
     * @param array $item 資料項目
     * @param string $date 日期
     * @return array|null
     */
    protected function transformItem(array $item, string $date): ?array
    {
        try {
            return [
                'option_code' => trim($item['option_code']),
                'underlying' => trim($item['underlying']),
                'option_type' => strtolower(trim($item['option_type'])),
                'strike_price' => floatval($item['strike_price']),
                'expiry_date' => $this->extractExpiryDate($item['option_code']),
                'trade_date' => $date,
                'open' => isset($item['open']) ? floatval($item['open']) : null,
                'high' => isset($item['high']) ? floatval($item['high']) : null,
                'low' => isset($item['low']) ? floatval($item['low']) : null,
                'close' => isset($item['close']) ? floatval($item['close']) : null,
                'volume' => isset($item['volume']) ? intval($item['volume']) : 0,
                'open_interest' => isset($item['open_interest']) ? intval($item['open_interest']) : null,
                'implied_volatility' => isset($item['implied_volatility']) ? floatval($item['implied_volatility']) : null,
            ];
        } catch (\Exception $e) {
            Log::warning('資料轉換失敗', [
                'item' => $item,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 從選擇權代碼提取到期日
     *
     * @param string $optionCode 選擇權代碼
     * @return string|null
     */
    protected function extractExpiryDate(string $optionCode): ?string
    {
        // 格式: TXO_202501_C_20000
        if (preg_match('/TXO_(\d{6})_[CP]_\d+/', $optionCode, $matches)) {
            $yearMonth = $matches[1];
            $year = substr($yearMonth, 0, 4);
            $month = substr($yearMonth, 4, 2);

            // 計算該月第三個週三
            $firstDay = \Carbon\Carbon::create($year, $month, 1);
            $firstWednesday = $firstDay->copy()->next(\Carbon\Carbon::WEDNESDAY);
            $thirdWednesday = $firstWednesday->copy()->addWeeks(2);

            return $thirdWednesday->format('Y-m-d');
        }

        return null;
    }

    /**
     * 生成統計報告
     *
     * @param Collection $data 資料集合
     * @return array
     */
    public function generateStatistics(Collection $data): array
    {
        $calls = $data->where('option_type', 'call');
        $puts = $data->where('option_type', 'put');

        return [
            'total_count' => $data->count(),
            'call_count' => $calls->count(),
            'put_count' => $puts->count(),
            'avg_call_volume' => $calls->avg('volume'),
            'avg_put_volume' => $puts->avg('volume'),
            'total_volume' => $data->sum('volume'),
            'total_open_interest' => $data->sum('open_interest'),
            'strike_price_range' => [
                'min' => $data->min('strike_price'),
                'max' => $data->max('strike_price'),
            ],
            'avg_close_price' => [
                'call' => $calls->avg('close'),
                'put' => $puts->avg('close'),
            ],
        ];
    }

    /**
     * 匯出為 CSV
     *
     * @param Collection $data 資料集合
     * @param string $filename 檔案名稱
     * @return string 檔案路徑
     */
    public function exportToCsv(Collection $data, string $filename): string
    {
        $csvData = $data->map(function ($item) {
            return [
                $item['option_code'],
                $item['underlying'],
                $item['option_type'],
                $item['strike_price'],
                $item['expiry_date'],
                $item['trade_date'],
                $item['close'] ?? '',
                $item['volume'] ?? 0,
                $item['open_interest'] ?? '',
            ];
        })->toArray();

        // 加入表頭
        array_unshift($csvData, [
            'option_code',
            'underlying',
            'option_type',
            'strike_price',
            'expiry_date',
            'trade_date',
            'close',
            'volume',
            'open_interest',
        ]);

        // 轉換為 CSV 格式
        $csv = implode("\n", array_map(function ($row) {
            return implode(',', $row);
        }, $csvData));

        // 儲存檔案
        $path = "exports/{$filename}";
        Storage::put($path, $csv);

        return $path;
    }

    /**
     * 偵測異常資料
     *
     * @param Collection $data 資料集合
     * @return Collection
     */
    public function detectAnomalies(Collection $data): Collection
    {
        $anomalies = collect();

        // 檢查異常高的價格
        $avgPrice = $data->avg('close');
        $stdDev = $this->calculateStdDev($data->pluck('close')->toArray());

        $data->each(function ($item) use ($avgPrice, $stdDev, &$anomalies) {
            if (isset($item['close'])) {
                $zScore = abs(($item['close'] - $avgPrice) / $stdDev);
                
                if ($zScore > 3) {
                    $anomalies->push([
                        'type' => 'price_outlier',
                        'option_code' => $item['option_code'],
                        'value' => $item['close'],
                        'z_score' => $zScore,
                    ]);
                }
            }
        });

        // 檢查異常高的成交量
        $avgVolume = $data->avg('volume');
        $volumeStdDev = $this->calculateStdDev($data->pluck('volume')->toArray());

        $data->each(function ($item) use ($avgVolume, $volumeStdDev, &$anomalies) {
            if ($item['volume'] > 0) {
                $zScore = abs(($item['volume'] - $avgVolume) / $volumeStdDev);
                
                if ($zScore > 3) {
                    $anomalies->push([
                        'type' => 'volume_outlier',
                        'option_code' => $item['option_code'],
                        'value' => $item['volume'],
                        'z_score' => $zScore,
                    ]);
                }
            }
        });

        return $anomalies;
    }

    /**
     * 計算標準差
     *
     * @param array $values 數值陣列
     * @return float
     */
    protected function calculateStdDev(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0;
        }

        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / $count;

        return sqrt($variance);
    }
}