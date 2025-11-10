<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptionPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'option_id',
        'trade_date',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'open_interest',
        'implied_volatility',
        'delta',
        'gamma',
        'theta',
        'vega',
        'rho',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'open' => 'decimal:2',
        'high' => 'decimal:2',
        'low' => 'decimal:2',
        'close' => 'decimal:2',
        'volume' => 'integer',
        'open_interest' => 'integer',
        'implied_volatility' => 'decimal:6',
        'delta' => 'decimal:6',
        'gamma' => 'decimal:6',
        'theta' => 'decimal:6',
        'vega' => 'decimal:6',
        'rho' => 'decimal:6',
    ];

    /**
     * 所屬的選擇權
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }

    /**
     * 依日期範圍查詢
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('trade_date', [$startDate, $endDate]);
    }

    /**
     * 計算中間價
     */
    public function getMidPriceAttribute(): ?float
    {
        if ($this->high && $this->low) {
            return ($this->high + $this->low) / 2;
        }
        return null;
    }

    /**
     * 計算買賣價差
     */
    public function getSpreadAttribute(): ?float
    {
        if ($this->high && $this->low) {
            return $this->high - $this->low;
        }
        return null;
    }
}