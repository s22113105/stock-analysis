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
        'period',
        'historical_volatility',
        'realized_volatility',
        'garch_volatility',
        'atm_iv',
        'iv_smile',
    ];

    protected $casts = [
        'calculation_date' => 'date',
        'historical_volatility' => 'decimal:4',
        'realized_volatility' => 'decimal:4',
        'garch_volatility' => 'decimal:4',
        'atm_iv' => 'decimal:4',
        'iv_smile' => 'array',
    ];

    /**
     * 所屬的股票
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * 依計算日期查詢
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('calculation_date', $date);
    }

    /**
     * 依期間查詢
     */
    public function scopeForPeriod($query, $period)
    {
        return $query->where('period', $period);
    }

    /**
     * 取得最新的波動率
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('calculation_date', 'desc');
    }

    /**
     * 取得波動率錐形數據
     */
    public static function getVolatilityCone($stockId, $lookback = 252)
    {
        $endDate = now();
        $startDate = now()->subDays($lookback);

        $periods = ['7', '14', '21', '30', '60', '90'];
        $percentiles = [10, 25, 50, 75, 90];

        $cone = [];

        foreach ($periods as $period) {
            $volatilities = self::where('stock_id', $stockId)
                ->where('period', $period)
                ->whereBetween('calculation_date', [$startDate, $endDate])
                ->pluck('historical_volatility')
                ->sort()
                ->values();

            if ($volatilities->isEmpty()) {
                continue;
            }

            $cone[$period] = [];
            foreach ($percentiles as $percentile) {
                $index = (int) (($percentile / 100) * ($volatilities->count() - 1));
                $cone[$period]["p{$percentile}"] = $volatilities[$index];
            }

            // 加入當前值
            $current = self::where('stock_id', $stockId)
                ->where('period', $period)
                ->latest('calculation_date')
                ->value('historical_volatility');

            $cone[$period]['current'] = $current;
        }

        return $cone;
    }

    /**
     * 取得波動率微笑數據
     */
    public function getSmileDataAttribute()
    {
        if (!$this->iv_smile) {
            return null;
        }

        // 格式化波動率微笑數據
        $smileData = [];
        foreach ($this->iv_smile as $strike => $iv) {
            $smileData[] = [
                'strike' => $strike,
                'iv' => $iv,
                'moneyness' => $this->calculateMoneyness($strike),
            ];
        }

        return collect($smileData)->sortBy('strike')->values();
    }

    /**
     * 計算 Moneyness
     */
    private function calculateMoneyness($strike)
    {
        $spotPrice = $this->stock->latestPrice->close_price ?? 0;

        if ($spotPrice > 0) {
            return $strike / $spotPrice;
        }

        return null;
    }

    /**
     * 計算期限結構
     */
    public static function getTermStructure($stockId, $date = null)
    {
        $date = $date ?? now();

        $volatilities = self::where('stock_id', $stockId)
            ->where('calculation_date', $date)
            ->orderBy('period')
            ->get()
            ->map(function ($vol) {
                return [
                    'period' => $vol->period,
                    'days' => (int) $vol->period,
                    'hv' => $vol->historical_volatility,
                    'iv' => $vol->atm_iv,
                    'rv' => $vol->realized_volatility,
                ];
            });

        return $volatilities;
    }
}
