<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Volatility extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'calculation_date',
        'period_days',
        'historical_volatility',
        'implied_volatility_call',
        'implied_volatility_put',
        'volatility_skew',
        'volatility_smile',
        'garch_volatility',
        'realized_volatility',
        'volatility_surface',
        'meta_data',
    ];

    protected $casts = [
        'calculation_date' => 'date',
        'period_days' => 'integer',
        'historical_volatility' => 'decimal:6',
        'implied_volatility_call' => 'decimal:6',
        'implied_volatility_put' => 'decimal:6',
        'volatility_skew' => 'decimal:6',
        'volatility_smile' => 'decimal:6',
        'garch_volatility' => 'decimal:6',
        'realized_volatility' => 'decimal:6',
        'volatility_surface' => 'array',
        'meta_data' => 'array',
    ];

    /**
     * 所屬的股票
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * 計算歷史波動率
     */
    public static function calculateHistoricalVolatility($stockId, $period = 20, $date = null)
    {
        $date = $date ?: now();
        
        // 獲取價格數據
        $prices = StockPrice::where('stock_id', $stockId)
            ->where('trade_date', '<=', $date)
            ->orderBy('trade_date', 'desc')
            ->limit($period + 1)
            ->pluck('close')
            ->reverse()
            ->values();

        if ($prices->count() < $period + 1) {
            return null;
        }

        // 計算日報酬率
        $returns = [];
        for ($i = 1; $i <= $period; $i++) {
            $returns[] = log($prices[$i] / $prices[$i - 1]);
        }

        // 計算標準差
        $mean = array_sum($returns) / count($returns);
        $variance = 0;
        
        foreach ($returns as $return) {
            $variance += pow($return - $mean, 2);
        }
        
        $variance /= (count($returns) - 1);
        $stdDev = sqrt($variance);
        
        // 年化波動率
        return $stdDev * sqrt(252);
    }

    /**
     * 計算實現波動率 (Realized Volatility)
     */
    public static function calculateRealizedVolatility($stockId, $period = 20, $date = null)
    {
        $date = $date ?: now();
        
        $prices = StockPrice::where('stock_id', $stockId)
            ->where('trade_date', '<=', $date)
            ->orderBy('trade_date', 'desc')
            ->limit($period)
            ->get();

        if ($prices->count() < $period) {
            return null;
        }

        $squaredReturns = 0;
        
        foreach ($prices as $price) {
            $logReturn = log($price->high / $price->low);
            $squaredReturns += pow($logReturn, 2);
        }
        
        // Parkinson volatility estimator
        return sqrt($squaredReturns / (4 * $period * log(2))) * sqrt(252);
    }

    /**
     * 計算 EWMA 波動率
     */
    public static function calculateEWMAVolatility($stockId, $lambda = 0.94, $period = 20, $date = null)
    {
        $date = $date ?: now();
        
        $prices = StockPrice::where('stock_id', $stockId)
            ->where('trade_date', '<=', $date)
            ->orderBy('trade_date', 'desc')
            ->limit($period + 1)
            ->pluck('close')
            ->reverse()
            ->values();

        if ($prices->count() < $period + 1) {
            return null;
        }

        $variance = 0;
        $weight = 1;
        $totalWeight = 0;

        for ($i = $period; $i > 0; $i--) {
            $return = log($prices[$i] / $prices[$i - 1]);
            $variance += $weight * pow($return, 2);
            $totalWeight += $weight;
            $weight *= $lambda;
        }

        $variance /= $totalWeight;
        
        return sqrt($variance * 252);
    }

    /**
     * 取得最新的波動率數據
     */
    public function scopeLatest($query, $stockId = null)
    {
        $query->orderBy('calculation_date', 'desc');
        
        if ($stockId) {
            $query->where('stock_id', $stockId);
        }
        
        return $query;
    }

    /**
     * 取得特定期間的波動率
     */
    public function scopeByPeriod($query, $period)
    {
        return $query->where('period_days', $period);
    }

    /**
     * 計算波動率錐 (Volatility Cone)
     */
    public static function calculateVolatilityCone($stockId, $periods = [10, 20, 30, 60, 90])
    {
        $cone = [];
        
        foreach ($periods as $period) {
            $volatilities = self::where('stock_id', $stockId)
                ->where('period_days', $period)
                ->orderBy('calculation_date', 'desc')
                ->limit(252)
                ->pluck('historical_volatility')
                ->toArray();
            
            if (count($volatilities) > 0) {
                sort($volatilities);
                $cone[$period] = [
                    'min' => $volatilities[0],
                    'p25' => $volatilities[intval(count($volatilities) * 0.25)],
                    'median' => $volatilities[intval(count($volatilities) * 0.5)],
                    'p75' => $volatilities[intval(count($volatilities) * 0.75)],
                    'max' => end($volatilities),
                    'current' => $volatilities[count($volatilities) - 1],
                ];
            }
        }
        
        return $cone;
    }
}