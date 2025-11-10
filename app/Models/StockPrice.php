<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'transactions',
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
        'transactions' => 'integer',
    ];

    /**
     * 所屬的股票
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * 依日期範圍查詢
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('trade_date', [$startDate, $endDate]);
    }

    /**
     * 計算日報酬率
     */
    public function getDailyReturnAttribute(): ?float
    {
        if ($this->change && $this->close) {
            $previousClose = $this->close - $this->change;
            if ($previousClose > 0) {
                return ($this->change / $previousClose) * 100;
            }
        }
        return null;
    }

    /**
     * 計算對數報酬率
     */
    public function getLogReturnAttribute(): ?float
    {
        if ($this->change && $this->close) {
            $previousClose = $this->close - $this->change;
            if ($previousClose > 0) {
                return log($this->close / $previousClose);
            }
        }
        return null;
    }

    /**
     * 計算真實波幅 (True Range)
     */
    public function getTrueRangeAttribute(): ?float
    {
        $previousClose = $this->close - $this->change;
        
        return max(
            $this->high - $this->low,
            abs($this->high - $previousClose),
            abs($this->low - $previousClose)
        );
    }
}