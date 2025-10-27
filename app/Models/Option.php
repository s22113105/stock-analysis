<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Option extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'option_code',
        'option_type',
        'strike_price',
        'expiry_date',
        'is_active',
    ];

    protected $casts = [
        'strike_price' => 'decimal:2',
        'expiry_date' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * 所屬的股票
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * 選擇權價格資料
     */
    public function prices(): HasMany
    {
        return $this->hasMany(OptionPrice::class);
    }

    /**
     * 取得最新價格
     */
    public function latestPrice()
    {
        return $this->hasOne(OptionPrice::class)->latestOfMany('trade_date');
    }

    /**
     * 是否為 Call
     */
    public function scopeCall($query)
    {
        return $query->where('option_type', 'call');
    }

    /**
     * 是否為 Put
     */
    public function scopePut($query)
    {
        return $query->where('option_type', 'put');
    }

    /**
     * 尚未到期的選擇權
     */
    public function scopeNotExpired($query)
    {
        return $query->where('expiry_date', '>=', now()->toDateString());
    }

    /**
     * 特定履約價
     */
    public function scopeStrike($query, $price)
    {
        return $query->where('strike_price', $price);
    }

    /**
     * 計算到期天數
     */
    public function getDaysToExpiryAttribute()
    {
        return now()->diffInDays($this->expiry_date);
    }

    /**
     * 檢查是否為價平
     */
    public function isAtTheMoney($spotPrice)
    {
        $threshold = $spotPrice * 0.05; // 5% 範圍內視為價平
        return abs($this->strike_price - $spotPrice) <= $threshold;
    }
}