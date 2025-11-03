<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class StockPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'trade_date',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'turnover',
        'change',
        'change_percent',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'open' => 'decimal:2',
        'high' => 'decimal:2',
        'low' => 'decimal:2',
        'close' => 'decimal:2',
        'volume' => 'integer',
        'turnover' => 'decimal:2',
        'change' => 'decimal:2',
        'change_percent' => 'decimal:2',
    ];

    /**
     * 所屬的股票
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * 計算日報酬率
     */
    public function getDailyReturnAttribute()
    {
        if ($this->change_percent !== null) {
            return $this->change_percent;
        }

        $previousClose = self::where('stock_id', $this->stock_id)
            ->where('trade_date', '<', $this->trade_date)
            ->orderBy('trade_date', 'desc')
            ->value('close');

        if (!$previousClose) {
            return null;
        }

        return (($this->close - $previousClose) / $previousClose) * 100;
    }

    /**
     * 計算對數報酬率
     */
    public function getLogReturnAttribute()
    {
        $previousClose = self::where('stock_id', $this->stock_id)
            ->where('trade_date', '<', $this->trade_date)
            ->orderBy('trade_date', 'desc')
            ->value('close');

        if (!$previousClose) {
            return null;
        }

        return log($this->close / $previousClose);
    }

    /**
     * 計算真實波動幅度 (True Range)
     */
    public function getTrueRangeAttribute()
    {
        $previousClose = self::where('stock_id', $this->stock_id)
            ->where('trade_date', '<', $this->trade_date)
            ->orderBy('trade_date', 'desc')
            ->value('close');

        if (!$previousClose) {
            return $this->high - $this->low;
        }

        return max(
            $this->high - $this->low,
            abs($this->high - $previousClose),
            abs($this->low - $previousClose)
        );
    }

    /**
     * 計算 RSI (Relative Strength Index)
     */
    public static function calculateRSI($stockId, $period = 14, $date = null)
    {
        $date = $date ?: now();
        
        $prices = self::where('stock_id', $stockId)
            ->where('trade_date', '<=', $date)
            ->orderBy('trade_date', 'desc')
            ->limit($period + 1)
            ->pluck('close')
            ->reverse()
            ->values();

        if ($prices->count() < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i <= $period; $i++) {
            $change = $prices[$i] - $prices[$i - 1];
            if ($change > 0) {
                $gains[] = $change;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($change);
            }
        }

        $avgGain = array_sum($gains) / $period;
        $avgLoss = array_sum($losses) / $period;

        if ($avgLoss == 0) {
            return 100;
        }

        $rs = $avgGain / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }

    /**
     * 計算移動平均
     */
    public static function calculateMA($stockId, $period, $date = null)
    {
        $date = $date ?: now();
        
        $avg = self::where('stock_id', $stockId)
            ->where('trade_date', '<=', $date)
            ->orderBy('trade_date', 'desc')
            ->limit($period)
            ->avg('close');

        return $avg;
    }

    /**
     * 取得特定日期範圍的價格
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('trade_date', [$startDate, $endDate]);
    }
}