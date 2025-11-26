<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;
use App\Models\OptionPrice;
use App\Models\Volatility;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * 波動率計算服務 (優化版)
 * 
 * 功能：
 * - 計算歷史波動率 (Historical Volatility, HV)
 * - 計算隱含波動率 (Implied Volatility, IV)
 * - 計算實現波動率 (Realized Volatility)
 * - 波動率偏斜 (Volatility Skew)
 * - 波動率曲面 (Volatility Surface)
 * - 多種波動率計算方法支援
 */
class VolatilityService
{
    protected $blackScholesService;

    // 每年交易日數
    const TRADING_DAYS_PER_YEAR = 252;

    public function __construct(BlackScholesService $blackScholesService)
    {
        $this->blackScholesService = $blackScholesService;
    }

    /**
     * 計算歷史波動率 (Historical Volatility)
     * 使用對數收益率的標準差
     *
     * @param int $stockId 股票 ID
     * @param int $periodDays 計算期間(天數)
     * @param string|null $endDate 結束日期
     * @param bool $annualized 是否年化
     * @param string $method 計算方法
     * @return float|null 歷史波動率
     */
    public function calculateHistoricalVolatility(
        int $stockId,
        int $periodDays = 30,
        ?string $endDate = null,
        bool $annualized = true,
        string $method = 'close-to-close'
    ): ?float {
        $endDate = $endDate ?: now()->format('Y-m-d');

        // 取得價格資料 (需要 periodDays + 1 筆資料來計算 periodDays 個收益率)
        $prices = StockPrice::where('stock_id', $stockId)
            ->where('trade_date', '<=', $endDate)
            ->orderBy('trade_date', 'desc')
            ->limit($periodDays + 1)
            ->get()
            ->reverse()
            ->values();

        if ($prices->count() < 2) {
            Log::warning('資料不足以計算歷史波動率', [
                'stock_id' => $stockId,
                'period_days' => $periodDays,
                'data_count' => $prices->count()
            ]);
            return null;
        }

        // 根據方法選擇計算方式
        $volatility = match ($method) {
            'parkinson' => $this->calculateParkinsonVolatility($prices),
            'garman-klass' => $this->calculateGarmanKlassVolatility($prices),
            'rogers-satchell' => $this->calculateRogersSatchellVolatility($prices),
            'yang-zhang' => $this->calculateYangZhangVolatility($prices),
            default => $this->calculateCloseToCloseVolatility($prices),
        };

        // 年化處理
        if ($annualized && $volatility !== null) {
            $volatility = $volatility * sqrt(self::TRADING_DAYS_PER_YEAR);
        }

        return $volatility !== null ? round($volatility, 6) : null;
    }

    /**
     * Close-to-Close 波動率計算
     * 標準的歷史波動率計算方法
     */
    private function calculateCloseToCloseVolatility($prices): ?float
    {
        $returns = [];
        for ($i = 1; $i < $prices->count(); $i++) {
            if ($prices[$i - 1]->close > 0) {
                $returns[] = log($prices[$i]->close / $prices[$i - 1]->close);
            }
        }

        if (empty($returns)) {
            return null;
        }

        // 計算標準差
        $mean = array_sum($returns) / count($returns);
        $variance = 0;
        
        foreach ($returns as $return) {
            $variance += pow($return - $mean, 2);
        }
        
        // 使用 n-1 (樣本標準差)
        $variance = $variance / (count($returns) - 1);
        
        return sqrt($variance);
    }

    /**
     * Parkinson 波動率計算
     * 使用日內高低價，比 Close-to-Close 更有效率
     */
    private function calculateParkinsonVolatility($prices): ?float
    {
        $sumSquaredLogHL = 0;
        $validCount = 0;

        foreach ($prices as $price) {
            if ($price->high > 0 && $price->low > 0 && $price->high >= $price->low) {
                $logHL = log($price->high / $price->low);
                $sumSquaredLogHL += pow($logHL, 2);
                $validCount++;
            }
        }

        if ($validCount < 2) {
            return null;
        }

        // Parkinson 常數: 1 / (4 * ln(2))
        $constant = 1 / (4 * log(2));
        
        return sqrt($constant * $sumSquaredLogHL / $validCount);
    }

    /**
     * Garman-Klass 波動率計算
     * 同時考慮開盤、收盤、最高、最低價
     */
    private function calculateGarmanKlassVolatility($prices): ?float
    {
        $sum = 0;
        $validCount = 0;

        foreach ($prices as $price) {
            if ($price->high > 0 && $price->low > 0 && 
                $price->open > 0 && $price->close > 0 &&
                $price->high >= $price->low) {
                
                $logHL = log($price->high / $price->low);
                $logCO = log($price->close / $price->open);
                
                // Garman-Klass 公式
                $sum += 0.5 * pow($logHL, 2) - (2 * log(2) - 1) * pow($logCO, 2);
                $validCount++;
            }
        }

        if ($validCount < 2) {
            return null;
        }

        return sqrt($sum / $validCount);
    }

    /**
     * Rogers-Satchell 波動率計算
     * 適用於有漂移的市場
     */
    private function calculateRogersSatchellVolatility($prices): ?float
    {
        $sum = 0;
        $validCount = 0;

        foreach ($prices as $price) {
            if ($price->high > 0 && $price->low > 0 && 
                $price->open > 0 && $price->close > 0) {
                
                $logHC = log($price->high / $price->close);
                $logHO = log($price->high / $price->open);
                $logLC = log($price->low / $price->close);
                $logLO = log($price->low / $price->open);
                
                $sum += $logHC * $logHO + $logLC * $logLO;
                $validCount++;
            }
        }

        if ($validCount < 2) {
            return null;
        }

        return sqrt($sum / $validCount);
    }

    /**
     * Yang-Zhang 波動率計算
     * 結合 overnight 和 open-to-close 波動率
     */
    private function calculateYangZhangVolatility($prices): ?float
    {
        if ($prices->count() < 3) {
            return null;
        }

        $overnightReturns = [];
        $openCloseReturns = [];
        $rogersSatchell = [];

        for ($i = 1; $i < $prices->count(); $i++) {
            $prev = $prices[$i - 1];
            $curr = $prices[$i];

            if ($prev->close > 0 && $curr->open > 0 && $curr->close > 0 &&
                $curr->high > 0 && $curr->low > 0) {
                
                // Overnight return
                $overnightReturns[] = log($curr->open / $prev->close);
                
                // Open-to-close return
                $openCloseReturns[] = log($curr->close / $curr->open);
                
                // Rogers-Satchell component
                $logHC = log($curr->high / $curr->close);
                $logHO = log($curr->high / $curr->open);
                $logLC = log($curr->low / $curr->close);
                $logLO = log($curr->low / $curr->open);
                $rogersSatchell[] = $logHC * $logHO + $logLC * $logLO;
            }
        }

        if (count($overnightReturns) < 2) {
            return null;
        }

        $n = count($overnightReturns);
        $k = 0.34 / (1.34 + ($n + 1) / ($n - 1));

        // 計算各組成部分的變異數
        $overnightVar = $this->calculateVariance($overnightReturns);
        $openCloseVar = $this->calculateVariance($openCloseReturns);
        $rsVar = array_sum($rogersSatchell) / $n;

        // Yang-Zhang 公式
        $variance = $overnightVar + $k * $openCloseVar + (1 - $k) * $rsVar;

        return sqrt(max(0, $variance));
    }

    /**
     * 計算實現波動率 (Realized Volatility)
     * 使用日內高低價格範圍
     *
     * @param int $stockId 股票 ID
     * @param int $periodDays 計算期間
     * @param string|null $endDate 結束日期
     * @return float|null
     */
    public function calculateRealizedVolatility(
        int $stockId,
        int $periodDays = 30,
        ?string $endDate = null
    ): ?float {
        $endDate = $endDate ?: now()->format('Y-m-d');

        $prices = StockPrice::where('stock_id', $stockId)
            ->where('trade_date', '<=', $endDate)
            ->orderBy('trade_date', 'desc')
            ->limit($periodDays)
            ->get();

        if ($prices->count() < 2) {
            return null;
        }

        // 使用 Parkinson 估計器
        $sum = 0;
        $validCount = 0;

        foreach ($prices as $price) {
            if ($price->high > 0 && $price->low > 0 && $price->high >= $price->low) {
                $logHL = log($price->high / $price->low);
                $sum += pow($logHL, 2);
                $validCount++;
            }
        }

        if ($validCount < 2) {
            return null;
        }

        // Parkinson 常數: 1 / (4 * ln(2))
        $constant = 1 / (4 * log(2));
        $dailyVol = sqrt($constant * $sum / $validCount);
        
        // 年化
        return round($dailyVol * sqrt(self::TRADING_DAYS_PER_YEAR), 6);
    }

    /**
     * 計算 EWMA (指數加權移動平均) 波動率
     *
     * @param int $stockId 股票 ID
     * @param int $periodDays 計算期間
     * @param float $lambda 衰減因子 (通常 0.94)
     * @param string|null $endDate 結束日期
     * @return float|null
     */
    public function calculateEWMAVolatility(
        int $stockId,
        int $periodDays = 30,
        float $lambda = 0.94,
        ?string $endDate = null
    ): ?float {
        $endDate = $endDate ?: now()->format('Y-m-d');

        $prices = StockPrice::where('stock_id', $stockId)
            ->where('trade_date', '<=', $endDate)
            ->orderBy('trade_date', 'desc')
            ->limit($periodDays + 1)
            ->pluck('close')
            ->reverse()
            ->values();

        if ($prices->count() < $periodDays + 1) {
            return null;
        }

        // 計算對數收益率
        $returns = [];
        for ($i = 1; $i < $prices->count(); $i++) {
            if ($prices[$i - 1] > 0) {
                $returns[] = log($prices[$i] / $prices[$i - 1]);
            }
        }

        if (empty($returns)) {
            return null;
        }

        // EWMA 計算
        $variance = pow($returns[0], 2);
        
        for ($i = 1; $i < count($returns); $i++) {
            $variance = $lambda * $variance + (1 - $lambda) * pow($returns[$i], 2);
        }

        // 年化
        return round(sqrt($variance * self::TRADING_DAYS_PER_YEAR), 6);
    }

    /**
     * 批次更新波動率
     *
     * @param int $stockId 股票 ID
     * @param string|null $date 計算日期
     * @return array 計算結果
     */
    public function batchUpdateVolatilities(int $stockId, ?string $date = null): array
    {
        $date = $date ?: now()->format('Y-m-d');
        
        Log::info('開始批次更新波動率', [
            'stock_id' => $stockId,
            'date' => $date
        ]);

        $results = [];

        // 計算不同期間的波動率
        $periods = [10, 20, 30, 60, 90, 120, 252];
        $methods = ['close-to-close', 'parkinson', 'garman-klass'];

        foreach ($periods as $period) {
            foreach ($methods as $method) {
                $hv = $this->calculateHistoricalVolatility($stockId, $period, $date, true, $method);
                
                if ($hv !== null) {
                    // 只儲存 close-to-close 方法的結果到資料庫
                    if ($method === 'close-to-close') {
                        $rv = $this->calculateRealizedVolatility($stockId, $period, $date);
                        $ewma = $this->calculateEWMAVolatility($stockId, $period, 0.94, $date);

                        Volatility::updateOrCreate(
                            [
                                'stock_id' => $stockId,
                                'calculation_date' => $date,
                                'period_days' => $period
                            ],
                            [
                                'historical_volatility' => $hv,
                                'realized_volatility' => $rv,
                                'calculation_params' => json_encode([
                                    'method' => $method,
                                    'ewma' => $ewma,
                                    'lambda' => 0.94,
                                ])
                            ]
                        );
                    }

                    $results[$period][$method] = [
                        'historical_volatility' => $hv,
                        'historical_volatility_pct' => round($hv * 100, 2) . '%',
                    ];
                }
            }
        }

        // 清除相關快取
        Cache::tags(['volatility', "stock:{$stockId}"])->flush();

        Log::info('波動率更新完成', [
            'stock_id' => $stockId,
            'date' => $date,
            'periods' => count($results)
        ]);

        return $results;
    }

    /**
     * 計算波動率錐 (Volatility Cone)
     * 顯示不同期間的波動率分佈
     *
     * @param int $stockId
     * @param int $lookbackDays 回測天數
     * @return array
     */
    public function calculateVolatilityCone(
        int $stockId,
        int $lookbackDays = 252
    ): array {
        $periods = [10, 20, 30, 60, 90, 120, 180, 252];
        $coneData = [];

        foreach ($periods as $period) {
            $hvValues = [];

            // 取得足夠的價格資料
            $prices = StockPrice::where('stock_id', $stockId)
                ->orderBy('trade_date', 'desc')
                ->limit($lookbackDays + $period + 1)
                ->pluck('close')
                ->reverse()
                ->values()
                ->toArray();

            if (count($prices) < $period + 1) {
                continue;
            }

            // 滾動計算每天的 HV
            for ($i = $period; $i < count($prices); $i++) {
                $slice = array_slice($prices, $i - $period, $period + 1);
                
                // 計算對數收益率
                $returns = [];
                for ($j = 1; $j < count($slice); $j++) {
                    if ($slice[$j - 1] > 0) {
                        $returns[] = log($slice[$j] / $slice[$j - 1]);
                    }
                }

                if (count($returns) >= 2) {
                    $mean = array_sum($returns) / count($returns);
                    $variance = 0;
                    foreach ($returns as $return) {
                        $variance += pow($return - $mean, 2);
                    }
                    $variance /= (count($returns) - 1);
                    $hv = sqrt($variance * self::TRADING_DAYS_PER_YEAR);
                    $hvValues[] = $hv;
                }
            }

            if (count($hvValues) < 10) {
                continue;
            }

            // ⭐ 重要：在排序前先保存當前值（最新計算的波動率）
            $currentHV = end($hvValues);

            // 排序計算統計值
            sort($hvValues);
            $count = count($hvValues);

            $coneData[] = [
                'period' => $period,
                'period_label' => $period . '天',
                'current' => round($currentHV * 100, 2),  // ✅ 使用排序前保存的當前值
                'min' => round($hvValues[0] * 100, 2),
                'max' => round($hvValues[$count - 1] * 100, 2),
                'p10' => round($hvValues[intval($count * 0.1)] * 100, 2),
                'p25' => round($hvValues[intval($count * 0.25)] * 100, 2),
                'median' => round($hvValues[intval($count * 0.5)] * 100, 2),
                'p75' => round($hvValues[intval($count * 0.75)] * 100, 2),
                'p90' => round($hvValues[intval($count * 0.9)] * 100, 2),
                'mean' => round((array_sum($hvValues) / $count) * 100, 2),
                'std' => round($this->calculateStd($hvValues) * 100, 2),
                'sample_count' => $count,
            ];
        }

        return $coneData;
    }

    /**
     * 計算變異數
     */
    private function calculateVariance(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }

        $mean = array_sum($values) / count($values);
        $variance = 0;
        
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        
        return $variance / (count($values) - 1);
    }

    /**
     * 計算標準差
     */
    private function calculateStd(array $values): float
    {
        return sqrt($this->calculateVariance($values));
    }

    /**
     * 取得波動率歷史趨勢
     *
     * @param int $stockId
     * @param int $periodDays 計算期間
     * @param int $historyDays 歷史天數
     * @return array
     */
    public function getVolatilityTrend(
        int $stockId,
        int $periodDays = 30,
        int $historyDays = 90
    ): array {
        $trend = [];
        $endDate = now();

        for ($i = 0; $i < $historyDays; $i++) {
            $date = $endDate->copy()->subDays($i)->format('Y-m-d');
            $hv = $this->calculateHistoricalVolatility($stockId, $periodDays, $date);
            
            if ($hv !== null) {
                $trend[] = [
                    'date' => $date,
                    'hv' => round($hv * 100, 2),
                ];
            }
        }

        return array_reverse($trend);
    }
}