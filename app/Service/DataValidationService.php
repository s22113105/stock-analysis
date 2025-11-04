<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DataValidationService
{
    /**
     * 驗證股票價格資料
     */
    public function validateStockPrices($date = null)
    {
        $date = $date ?: now()->format('Y-m-d');
        $errors = [];

        // 取得當日所有股票價格
        $prices = StockPrice::where('trade_date', $date)->get();

        foreach ($prices as $price) {
            $validationErrors = [];

            // 檢查價格合理性
            if ($price->high < $price->low) {
                $validationErrors[] = '最高價低於最低價';
            }

            if ($price->open < $price->low || $price->open > $price->high) {
                $validationErrors[] = '開盤價超出最高最低價範圍';
            }

            if ($price->close < $price->low || $price->close > $price->high) {
                $validationErrors[] = '收盤價超出最高最低價範圍';
            }

            // 檢查成交量
            if ($price->volume < 0) {
                $validationErrors[] = '成交量為負數';
            }

            // 檢查漲跌幅是否超過10%（漲跌停）
            if (abs($price->change_percent) > 10) {
                $validationErrors[] = '漲跌幅超過10%限制';
            }

            // 檢查是否有重複資料
            $duplicates = StockPrice::where('stock_id', $price->stock_id)
                ->where('trade_date', $price->trade_date)
                ->count();

            if ($duplicates > 1) {
                $validationErrors[] = '存在重複資料';
            }

            if (!empty($validationErrors)) {
                $errors[] = [
                    'stock_id' => $price->stock_id,
                    'symbol' => $price->stock->symbol ?? 'N/A',
                    'date' => $price->trade_date,
                    'errors' => $validationErrors
                ];
            }
        }

        // 記錄錯誤
        if (!empty($errors)) {
            Log::warning('股票價格資料驗證失敗', $errors);
        }

        return [
            'date' => $date,
            'total_records' => $prices->count(),
            'error_count' => count($errors),
            'errors' => $errors
        ];
    }

    /**
     * 清理異常資料
     */
    public function cleanAnomalousData($date = null, $autoFix = false)
    {
        $date = $date ?: now()->format('Y-m-d');
        $fixed = [];

        DB::beginTransaction();

        try {
            // 修正最高最低價錯誤
            $wrongHighLow = StockPrice::where('trade_date', $date)
                ->whereRaw('high < low')
                ->get();

            foreach ($wrongHighLow as $price) {
                if ($autoFix) {
                    // 交換最高最低價
                    $temp = $price->high;
                    $price->high = $price->low;
                    $price->low = $temp;
                    $price->save();

                    $fixed[] = [
                        'stock_id' => $price->stock_id,
                        'issue' => 'high_low_swap',
                        'action' => 'swapped'
                    ];
                }
            }

            // 移除重複資料
            $duplicates = DB::select("
                SELECT stock_id, trade_date, COUNT(*) as count
                FROM stock_prices
                WHERE trade_date = ?
                GROUP BY stock_id, trade_date
                HAVING count > 1
            ", [$date]);

            foreach ($duplicates as $duplicate) {
                if ($autoFix) {
                    // 保留最新的一筆，刪除其他
                    $prices = StockPrice::where('stock_id', $duplicate->stock_id)
                        ->where('trade_date', $duplicate->trade_date)
                        ->orderBy('updated_at', 'desc')
                        ->get();

                    $prices->skip(1)->each(function ($price) {
                        $price->delete();
                    });

                    $fixed[] = [
                        'stock_id' => $duplicate->stock_id,
                        'issue' => 'duplicate',
                        'action' => 'removed_duplicates'
                    ];
                }
            }

            // 修正不合理的成交量（負數）
            $negativeVolumes = StockPrice::where('trade_date', $date)
                ->where('volume', '<', 0)
                ->get();

            foreach ($negativeVolumes as $price) {
                if ($autoFix) {
                    $price->volume = 0;
                    $price->save();

                    $fixed[] = [
                        'stock_id' => $price->stock_id,
                        'issue' => 'negative_volume',
                        'action' => 'set_to_zero'
                    ];
                }
            }

            if ($autoFix) {
                DB::commit();
                Log::info('資料清理完成', ['fixed' => $fixed]);
            } else {
                DB::rollBack();
            }

            return [
                'date' => $date,
                'issues_found' => count($wrongHighLow) + count($duplicates) + count($negativeVolumes),
                'fixed' => $autoFix ? count($fixed) : 0,
                'details' => $fixed
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('資料清理失敗', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * 檢查資料完整性
     */
    public function checkDataCompleteness($date = null)
    {
        $date = $date ?: now()->format('Y-m-d');
        $missing = [];

        // 取得所有活躍的股票
        $activeStocks = Stock::active()->get();

        foreach ($activeStocks as $stock) {
            // 檢查是否有當日價格資料
            $hasPrice = StockPrice::where('stock_id', $stock->id)
                ->where('trade_date', $date)
                ->exists();

            if (!$hasPrice) {
                $missing[] = [
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'type' => 'price_data'
                ];
            }
        }

        // 檢查選擇權資料
        $activeOptions = Option::where('is_active', true)
            ->where('expiry_date', '>=', $date)
            ->get();

        foreach ($activeOptions as $option) {
            $hasPrice = OptionPrice::where('option_id', $option->id)
                ->where('trade_date', $date)
                ->exists();

            if (!$hasPrice) {
                $missing[] = [
                    'option_id' => $option->id,
                    'option_code' => $option->option_code,
                    'type' => 'option_price_data'
                ];
            }
        }

        return [
            'date' => $date,
            'total_stocks' => $activeStocks->count(),
            'total_options' => $activeOptions->count(),
            'missing_count' => count($missing),
            'missing_data' => $missing
        ];
    }

    /**
     * 驗證交易日
     */
    public function validateTradingDay($date)
    {
        $carbon = Carbon::parse($date);

        // 檢查是否為週末
        if ($carbon->isWeekend()) {
            return [
                'is_trading_day' => false,
                'reason' => 'weekend'
            ];
        }

        // 檢查是否為假日（需要從資料庫或 API 取得假日資料）
        // 這裡可以整合 TWSE 的假日 API

        return [
            'is_trading_day' => true,
            'date' => $date
        ];
    }

    /**
     * 產生資料品質報告
     */
    public function generateQualityReport($startDate, $endDate)
    {
        $report = [
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'statistics' => [],
            'issues' => []
        ];

        // 計算資料覆蓋率
        $totalDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate));
        $tradingDays = 0;
        $dataCoverage = [];

        $current = Carbon::parse($startDate);
        while ($current <= Carbon::parse($endDate)) {
            if (!$current->isWeekend()) {
                $tradingDays++;

                $stockCount = StockPrice::where('trade_date', $current->format('Y-m-d'))
                    ->distinct('stock_id')
                    ->count();

                $dataCoverage[] = [
                    'date' => $current->format('Y-m-d'),
                    'stock_count' => $stockCount
                ];
            }
            $current->addDay();
        }

        $report['statistics'] = [
            'total_days' => $totalDays,
            'trading_days' => $tradingDays,
            'average_daily_records' => collect($dataCoverage)->avg('stock_count'),
            'data_coverage_rate' => ($tradingDays > 0)
                ? round((collect($dataCoverage)->filter(fn($d) => $d['stock_count'] > 0)->count() / $tradingDays) * 100, 2)
                : 0
        ];

        // 找出資料異常
        $anomalies = StockPrice::whereBetween('trade_date', [$startDate, $endDate])
            ->where(function ($query) {
                $query->whereRaw('high < low')
                    ->orWhere('volume', '<', 0)
                    ->orWhere('change_percent', '>', 15)
                    ->orWhere('change_percent', '<', -15);
            })
            ->count();

        $report['issues']['anomalies'] = $anomalies;

        // 找出缺失資料
        $missingData = [];
        foreach ($dataCoverage as $coverage) {
            if ($coverage['stock_count'] < Stock::active()->count()) {
                $missingData[] = $coverage['date'];
            }
        }

        $report['issues']['missing_data_dates'] = $missingData;

        return $report;
    }

    /**
     * 修復缺失資料
     */
    public function repairMissingData($date, $symbol = null)
    {
        Log::info('開始修復缺失資料', ['date' => $date, 'symbol' => $symbol]);

        try {
            // 重新觸發爬蟲來補充資料
            $job = new \App\Jobs\FetchStockDataJob($date, $symbol);
            dispatch($job);

            return [
                'success' => true,
                'message' => '資料修復任務已加入佇列'
            ];

        } catch (\Exception $e) {
            Log::error('資料修復失敗', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => '資料修復失敗: ' . $e->getMessage()
            ];
        }
    }
}
