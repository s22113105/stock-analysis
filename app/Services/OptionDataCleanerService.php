<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * 選擇權資料清理服務
 *
 * 功能：
 * - 資料驗證
 * - 資料清理
 * - 資料轉換（支援月選 & 週選）
 * - 統計分析
 */
class OptionDataCleanerService
{
    /**
     * 清理並轉換資料
     */
    public function cleanAndTransform(Collection $rawData, string $date): Collection
    {
        $cleaned = $rawData
            ->filter(fn($item) => $this->validateItem($item))
            ->map(fn($item) => $this->transformItem($item, $date))
            ->filter(fn($item) => $item !== null)
            ->values();

        Log::info('資料清理完成', [
            'original_count' => $rawData->count(),
            'cleaned_count'  => $cleaned->count(),
            'removed_count'  => $rawData->count() - $cleaned->count(),
        ]);

        return $cleaned;
    }

    /**
     * 驗證單筆資料
     */
    protected function validateItem(array $item): bool
    {
        $requiredFields = ['option_code', 'underlying', 'option_type', 'strike_price'];

        foreach ($requiredFields as $field) {
            if (!isset($item[$field]) || empty($item[$field])) {
                return false;
            }
        }

        if (!in_array($item['option_type'], ['call', 'put'])) {
            return false;
        }

        if (!is_numeric($item['strike_price']) || $item['strike_price'] <= 0) {
            return false;
        }

        if (isset($item['close']) && (!is_numeric($item['close']) || $item['close'] < 0)) {
            return false;
        }

        return true;
    }

    /**
     * 轉換單筆資料
     */
    protected function transformItem(array $item, string $date): ?array
    {
        try {
            $expiryDate = $this->extractExpiryDate($item['option_code'], $date);

            // expiry_date 無法解析時給保底值，避免 DB NOT NULL 錯誤
            if ($expiryDate === null) {
                $expiryDate = Carbon::parse($date)->addDays(30)->format('Y-m-d');
                Log::warning('無法解析到期日，使用預設值', [
                    'option_code' => $item['option_code'],
                    'fallback'    => $expiryDate,
                ]);
            }

            return [
                'option_code'        => trim($item['option_code']),
                'underlying'         => trim($item['underlying']),
                'option_type'        => strtolower(trim($item['option_type'])),
                'strike_price'       => floatval($item['strike_price']),
                'expiry_date'        => $expiryDate,
                'trade_date'         => $date,
                'open'               => isset($item['open'])               ? floatval($item['open'])               : null,
                'high'               => isset($item['high'])               ? floatval($item['high'])               : null,
                'low'                => isset($item['low'])                ? floatval($item['low'])                : null,
                'close'              => isset($item['close'])              ? floatval($item['close'])              : null,
                'volume'             => isset($item['volume'])             ? intval($item['volume'])               : 0,
                'open_interest'      => isset($item['open_interest'])      ? intval($item['open_interest'])        : null,
                'implied_volatility' => isset($item['implied_volatility']) ? floatval($item['implied_volatility']) : null,
            ];
        } catch (\Exception $e) {
            Log::warning('資料轉換失敗', [
                'item'  => $item,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 從選擇權代碼提取到期日
     *
     * 支援格式：
     *   月選: TXO_202501_C_20000      → 該月第三個週三
     *   週選: TXO_202501W2_C_20000    → 該月第 N 個週三
     *         TXO_202604W5_P_20260429 → 同上
     *
     * @param string      $optionCode 選擇權代碼
     * @param string|null $tradeDate  交易日（解析失敗時備援）
     * @return string|null
     */
    protected function extractExpiryDate(string $optionCode, string $tradeDate = null): ?string
    {
        try {
            // ✅ 週選格式: TXO_202604W5_C_20000 或 TXO_202604W5_P_20260429
            if (preg_match('/TXO_(\d{6})W(\d)_[CP]_\d+/', $optionCode, $matches)) {
                $yearMonth = $matches[1];
                $weekNum   = (int) $matches[2]; // 1~5
                $year      = (int) substr($yearMonth, 0, 4);
                $month     = (int) substr($yearMonth, 4, 2);

                $firstDay = Carbon::create($year, $month, 1);
                $result   = $firstDay->copy()->nthOfMonth($weekNum, Carbon::WEDNESDAY);

                // 某月沒有第5個週三時，取該月最後一個週三
                if ($result === false) {
                    $lastDay = Carbon::create($year, $month, 1)->endOfMonth();
                    while (!$lastDay->isWednesday()) {
                        $lastDay->subDay();
                    }
                    return $lastDay->format('Y-m-d');
                }

                return $result->format('Y-m-d');
            }

            // ✅ 月選格式: TXO_202501_C_20000
            if (preg_match('/TXO_(\d{6})_[CP]_\d+/', $optionCode, $matches)) {
                $yearMonth = $matches[1];
                $year      = (int) substr($yearMonth, 0, 4);
                $month     = (int) substr($yearMonth, 4, 2);

                $firstDay       = Carbon::create($year, $month, 1);
                $thirdWednesday = $firstDay->copy()->nthOfMonth(3, Carbon::WEDNESDAY);

                return $thirdWednesday->format('Y-m-d');
            }

            Log::warning('無法從代碼解析到期日', ['option_code' => $optionCode]);
            return null;

        } catch (\Exception $e) {
            Log::warning('extractExpiryDate 例外', [
                'option_code' => $optionCode,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 生成統計報告
     */
    public function generateStatistics(Collection $data): array
    {
        $calls = $data->where('option_type', 'call');
        $puts  = $data->where('option_type', 'put');

        return [
            'total_count'         => $data->count(),
            'call_count'          => $calls->count(),
            'put_count'           => $puts->count(),
            'avg_call_volume'     => $calls->avg('volume'),
            'avg_put_volume'      => $puts->avg('volume'),
            'total_volume'        => $data->sum('volume'),
            'total_open_interest' => $data->sum('open_interest'),
            'strike_price_range'  => [
                'min' => $data->min('strike_price'),
                'max' => $data->max('strike_price'),
            ],
            'avg_close_price' => [
                'call' => $calls->avg('close'),
                'put'  => $puts->avg('close'),
            ],
        ];
    }

    /**
     * 匯出為 CSV
     */
    public function exportToCsv(Collection $data, string $filename): string
    {
        $headers = ['option_code', 'underlying', 'option_type', 'strike_price',
                    'expiry_date', 'trade_date', 'close', 'volume', 'open_interest'];

        $csv = implode(',', $headers) . "\n";

        foreach ($data as $item) {
            $csv .= implode(',', [
                $item['option_code'],
                $item['underlying'],
                $item['option_type'],
                $item['strike_price'],
                $item['expiry_date']   ?? '',
                $item['trade_date'],
                $item['close']         ?? '',
                $item['volume']        ?? 0,
                $item['open_interest'] ?? 0,
            ]) . "\n";
        }

        Storage::put("exports/{$filename}", $csv);

        return storage_path("app/exports/{$filename}");
    }
}
