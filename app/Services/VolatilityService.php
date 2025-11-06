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
 * 波動率計算服務
 * 
 * 功能：
 * - 計算歷史波動率 (Historical Volatility, HV)
 * - 計算隱含波動率 (Implied Volatility, IV)
 * - 計算實現波動率 (Realized Volatility)
 * - 波動率偏斜 (Volatility Skew)
 * - 波動率曲面 (Volatility Surface)
 */
class VolatilityService
{
    protected $blackScholesService;

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
     * @param string $endDate 結束日期
     * @param bool $annualized 是否年化
     * @return float|null 歷史波動率
     */
    public function calculateHistoricalVolatility(
        int $stockId,
        int $periodDays = 30,
        ?string $endDate = null,
        bool $annualized = true
    ): ?float {
        $endDate = $endDate ?: now()->format('Y-m-d');

        // 取得價格資料 (需要 periodDays + 1 筆資料來計算 periodDays 個收益率)
        $prices = StockPrice::where('stock_id', $stockId)
            ->where('trade_date', '<=', $endDate)
            ->orderBy('trade_date', 'desc')
            ->limit($periodDays + 1)
            ->pluck('close')
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

        // 計算對數收益率
        $returns = [];
        for ($i = 1; $i < $prices->count(); $i++) {
            if ($prices[$i-1] > 0) {
                $returns[] = log($prices[$i] / $prices[$i-1]);
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
        
        $variance = $variance / count($returns);
        $stdDev = sqrt($variance);

        // 年化處理 (假設一年有 252 個交易日)
        if ($annualized) {
            $stdDev = $stdDev * sqrt(252);
        }

        return round($stdDev, 6);
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

        // 取得價格資料
        $prices = StockPrice::where('stock_id', $stockId)
            ->where('trade_date', '<=', $endDate)
            ->orderBy('trade_date', 'desc')
            ->limit($periodDays)
            ->get(['high', 'low', 'close']);

        if ($prices->count() < 2) {
            return null;
        }

        // 使用 Parkinson's volatility estimator
        // σ = sqrt((1/(4n*ln2)) * Σ(ln(High/Low))^2)
        $sumSquaredRange = 0;
        
        foreach ($prices as $price) {
            if ($price->high > 0 && $price->low > 0) {
                $logRange = log($price->high / $price->low);
                $sumSquaredRange += pow($logRange, 2);
            }
        }

        $n = $prices->count();
        $variance = $sumSquaredRange / (4 * $n * log(2));
        $volatility = sqrt($variance) * sqrt(252); // 年化

        return round($volatility, 6);
    }

    /**
     * 計算選擇權的隱含波動率
     *
     * @param int $optionId 選擇權 ID
     * @param string|null $date 日期
     * @param float $riskFreeRate 無風險利率
     * @return float|null
     */
    public function calculateImpliedVolatilityForOption(
        int $optionId,
        ?string $date = null,
        float $riskFreeRate = 0.015
    ): ?float {
        $date = $date ?: now()->format('Y-m-d');

        // 取得選擇權資料
        $option = Option::with(['latestPrice'])->find($optionId);
        
        if (!$option) {
            Log::warning('找不到選擇權', ['option_id' => $optionId]);
            return null;
        }

        // 取得選擇權當日價格
        $optionPrice = OptionPrice::where('option_id', $optionId)
            ->where('trade_date', $date)
            ->first();

        if (!$optionPrice || !$optionPrice->close) {
            Log::warning('找不到選擇權價格', [
                'option_id' => $optionId,
                'date' => $date
            ]);
            return null;
        }

        // 如果已經有 IV 值，直接返回
        if ($optionPrice->implied_volatility) {
            return $optionPrice->implied_volatility;
        }

        // 取得標的價格
        // 對於 TXO，需要取得台指期或加權指數
        $spotPrice = $this->getUnderlyingPrice($option->underlying, $date);
        
        if (!$spotPrice) {
            Log::warning('無法取得標的價格', [
                'underlying' => $option->underlying,
                'date' => $date
            ]);
            return null;
        }

        // 計算到期時間(年)
        $now = Carbon::parse($date);
        $expiry = Carbon::parse($option->expiry_date);
        $timeToExpiry = $now->diffInDays($expiry) / 365;

        if ($timeToExpiry <= 0) {
            Log::warning('選擇權已到期', [
                'option_id' => $optionId,
                'expiry_date' => $option->expiry_date
            ]);
            return null;
        }

        // 使用 Black-Scholes 反推隱含波動率
        $impliedVol = $this->blackScholesService->calculateImpliedVolatility(
            $optionPrice->close,
            $spotPrice,
            $option->strike_price,
            $timeToExpiry,
            $riskFreeRate,
            $option->option_type
        );

        // 儲存計算結果
        if ($impliedVol !== null) {
            $optionPrice->update(['implied_volatility' => $impliedVol]);
        }

        return $impliedVol;
    }

    /**
     * 計算波動率偏斜 (Volatility Skew)
     * OTM Put IV - OTM Call IV
     *
     * @param string $underlying 標的代碼 (例如: TXO)
     * @param string $expiryDate 到期日
     * @param float $spotPrice 標的現價
     * @param string|null $date 計算日期
     * @return array|null
     */
    public function calculateVolatilitySkew(
        string $underlying,
        string $expiryDate,
        float $spotPrice,
        ?string $date = null
    ): ?array {
        $date = $date ?: now()->format('Y-m-d');

        // 找出該到期月份的所有選擇權
        $options = Option::where('underlying', $underlying)
            ->where('expiry_date', $expiryDate)
            ->where('is_active', true)
            ->with(['prices' => function ($query) use ($date) {
                $query->where('trade_date', $date)
                    ->whereNotNull('implied_volatility');
            }])
            ->get();

        if ($options->isEmpty()) {
            return null;
        }

        // 分類 Call 和 Put
        $calls = $options->where('option_type', 'call');
        $puts = $options->where('option_type', 'put');

        // 找出 OTM 選擇權的 IV
        $otmCallIV = $this->getOTMImpliedVolatility($calls, $spotPrice, 'call');
        $otmPutIV = $this->getOTMImpliedVolatility($puts, $spotPrice, 'put');

        if (!$otmCallIV || !$otmPutIV) {
            return null;
        }

        $skew = $otmPutIV - $otmCallIV;

        return [
            'date' => $date,
            'underlying' => $underlying,
            'expiry_date' => $expiryDate,
            'spot_price' => $spotPrice,
            'otm_call_iv' => $otmCallIV,
            'otm_put_iv' => $otmPutIV,
            'skew' => round($skew, 6),
            'skew_percentage' => round(($skew / $otmCallIV) * 100, 2)
        ];
    }

    /**
     * 建立波動率曲面 (Volatility Surface)
     * 
     * @param string $underlying 標的代碼
     * @param string|null $date 計算日期
     * @return array
     */
    public function buildVolatilitySurface(
        string $underlying,
        ?string $date = null
    ): array {
        $date = $date ?: now()->format('Y-m-d');

        // 取得所有活躍的選擇權及其 IV
        $options = Option::where('underlying', $underlying)
            ->where('is_active', true)
            ->where('expiry_date', '>=', $date)
            ->with(['prices' => function ($query) use ($date) {
                $query->where('trade_date', $date)
                    ->whereNotNull('implied_volatility');
            }])
            ->get();

        if ($options->isEmpty()) {
            return [];
        }

        // 取得標的價格
        $spotPrice = $this->getUnderlyingPrice($underlying, $date);

        // 按到期日和履約價分組
        $surface = [];
        
        foreach ($options as $option) {
            $price = $option->prices->first();
            
            if (!$price || !$price->implied_volatility) {
                continue;
            }

            // 計算 Moneyness (K/S)
            $moneyness = $spotPrice > 0 ? $option->strike_price / $spotPrice : 0;
            
            // 計算到期天數
            $daysToExpiry = Carbon::parse($date)->diffInDays(Carbon::parse($option->expiry_date));

            $surface[] = [
                'option_id' => $option->id,
                'option_code' => $option->option_code,
                'option_type' => $option->option_type,
                'strike_price' => $option->strike_price,
                'expiry_date' => $option->expiry_date,
                'days_to_expiry' => $daysToExpiry,
                'moneyness' => round($moneyness, 4),
                'implied_volatility' => $price->implied_volatility,
                'volume' => $price->volume,
                'open_interest' => $price->open_interest
            ];
        }

        // 按到期天數和履約價排序
        usort($surface, function ($a, $b) {
            if ($a['days_to_expiry'] === $b['days_to_expiry']) {
                return $a['strike_price'] <=> $b['strike_price'];
            }
            return $a['days_to_expiry'] <=> $b['days_to_expiry'];
        });

        return [
            'date' => $date,
            'underlying' => $underlying,
            'spot_price' => $spotPrice,
            'data_points' => $surface,
            'total_points' => count($surface)
        ];
    }

    /**
     * 批次更新波動率資料
     *
     * @param int $stockId 股票 ID
     * @param string|null $date 計算日期
     * @return array
     */
    public function batchUpdateVolatilities(
        int $stockId,
        ?string $date = null
    ): array {
        $date = $date ?: now()->format('Y-m-d');
        
        Log::info('開始批次更新波動率', [
            'stock_id' => $stockId,
            'date' => $date
        ]);

        $results = [];

        // 計算不同期間的歷史波動率
        $periods = [10, 20, 30, 60, 90, 120];
        
        foreach ($periods as $period) {
            $hv = $this->calculateHistoricalVolatility($stockId, $period, $date);
            $rv = $this->calculateRealizedVolatility($stockId, $period, $date);

            if ($hv !== null) {
                // 儲存到資料庫
                Volatility::updateOrCreate(
                    [
                        'stock_id' => $stockId,
                        'calculation_date' => $date,
                        'period_days' => $period
                    ],
                    [
                        'historical_volatility' => $hv,
                        'realized_volatility' => $rv,
                    ]
                );

                $results[$period] = [
                    'historical_volatility' => $hv,
                    'realized_volatility' => $rv
                ];
            }
        }

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
        $periods = [10, 20, 30, 60, 90, 120, 252];
        $cone = [];

        foreach ($periods as $period) {
            // 計算過去 lookbackDays 中每個 period 的 HV
            $hvValues = [];
            
            for ($i = 0; $i < $lookbackDays; $i++) {
                $date = now()->subDays($i)->format('Y-m-d');
                $hv = $this->calculateHistoricalVolatility($stockId, $period, $date);
                
                if ($hv !== null) {
                    $hvValues[] = $hv;
                }
            }

            if (!empty($hvValues)) {
                sort($hvValues);
                $count = count($hvValues);

                $cone[$period] = [
                    'min' => round(min($hvValues), 4),
                    'percentile_10' => round($hvValues[intval($count * 0.1)], 4),
                    'percentile_25' => round($hvValues[intval($count * 0.25)], 4),
                    'median' => round($hvValues[intval($count * 0.5)], 4),
                    'percentile_75' => round($hvValues[intval($count * 0.75)], 4),
                    'percentile_90' => round($hvValues[intval($count * 0.9)], 4),
                    'max' => round(max($hvValues), 4),
                    'current' => round($hvValues[0], 4) // 最新的值
                ];
            }
        }

        return $cone;
    }

    /**
     * 取得 OTM 選擇權的平均隱含波動率
     */
    protected function getOTMImpliedVolatility($options, float $spotPrice, string $type): ?float
    {
        $ivValues = [];

        foreach ($options as $option) {
            $price = $option->prices->first();
            
            if (!$price || !$price->implied_volatility) {
                continue;
            }

            // 判斷是否為 OTM
            $isOTM = false;
            if ($type === 'call') {
                $isOTM = $option->strike_price > $spotPrice;
            } else {
                $isOTM = $option->strike_price < $spotPrice;
            }

            if ($isOTM) {
                $ivValues[] = $price->implied_volatility;
            }
        }

        if (empty($ivValues)) {
            return null;
        }

        // 返回平均值
        return array_sum($ivValues) / count($ivValues);
    }

    /**
     * 取得標的資產價格
     */
    protected function getUnderlyingPrice(string $underlying, string $date): ?float
    {
        // 快取 key
        $cacheKey = "underlying_price_{$underlying}_{$date}";

        return Cache::remember($cacheKey, 3600, function () use ($underlying, $date) {
            if ($underlying === 'TXO') {
                // 對於台指選擇權，取得加權指數
                $stock = Stock::where('symbol', '^TWII')->first();
                
                if ($stock) {
                    $price = StockPrice::where('stock_id', $stock->id)
                        ->where('trade_date', '<=', $date)
                        ->orderBy('trade_date', 'desc')
                        ->first();
                    
                    return $price ? $price->close : null;
                }
            }

            return null;
        });
    }
}