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
        'open_price',
        'high_price',
        'low_price',
        'close_price',
        'settlement_price',
        'volume',
        'open_interest',
        'bid_price',
        'ask_price',
        'implied_volatility',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'open_price' => 'decimal:2',
        'high_price' => 'decimal:2',
        'low_price' => 'decimal:2',
        'close_price' => 'decimal:2',
        'settlement_price' => 'decimal:2',
        'volume' => 'integer',
        'open_interest' => 'integer',
        'bid_price' => 'decimal:2',
        'ask_price' => 'decimal:2',
        'implied_volatility' => 'decimal:4',
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
     * 計算買賣價差
     */
    public function getSpreadAttribute()
    {
        if ($this->bid_price && $this->ask_price) {
            return $this->ask_price - $this->bid_price;
        }
        return null;
    }

    /**
     * 計算中間價
     */
    public function getMidPriceAttribute()
    {
        if ($this->bid_price && $this->ask_price) {
            return ($this->bid_price + $this->ask_price) / 2;
        }
        return $this->close_price;
    }
}