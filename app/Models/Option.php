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
        'contract_size',
        'exercise_style',
        'is_active',
        'meta_data',
    ];

    protected $casts = [
        'strike_price' => 'decimal:2',
        'expiry_date' => 'date',
        'is_active' => 'boolean',
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
     * 選擇權價格資料
     */
    public function prices(): HasMany
    {
        return $this->hasMany(OptionPrice::class);
    }

    /**
     * 預測數據
     */
    public function predictions()
    {
        return $this->morphMany(Prediction::class, 'predictable');
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
        return now()->diffInDays($this->expiry_date, false);
    }

    /**
     * 計算到期時間 (年)
     */
    public function getTimeToExpiryAttribute()
    {
        return $this->days_to_expiry / 365;
    }

    /**
     * 檢查是否為價內 (In The Money)
     */
    public function isInTheMoney($spotPrice)
    {
        if ($this->option_type === 'call') {
            return $spotPrice > $this->strike_price;
        } else {
            return $spotPrice < $this->strike_price;
        }
    }

    /**
     * 檢查是否為價平 (At The Money)
     */
    public function isAtTheMoney($spotPrice, $threshold = 0.01)
    {
        $percentageDiff = abs(($spotPrice - $this->strike_price) / $spotPrice);
        return $percentageDiff <= $threshold;
    }

    /**
     * 檢查是否為價外 (Out of The Money)
     */
    public function isOutOfTheMoney($spotPrice)
    {
        return !$this->isInTheMoney($spotPrice) && !$this->isAtTheMoney($spotPrice);
    }

    /**
     * 計算內含價值
     */
    public function getIntrinsicValue($spotPrice)
    {
        if ($this->option_type === 'call') {
            return max(0, $spotPrice - $this->strike_price);
        } else {
            return max(0, $this->strike_price - $spotPrice);
        }
    }

    /**
     * 計算時間價值
     */
    public function getTimeValue($optionPrice, $spotPrice)
    {
        return max(0, $optionPrice - $this->getIntrinsicValue($spotPrice));
    }

    /**
     * 取得相同到期日的選擇權鏈
     */
    public function getOptionChain()
    {
        return self::where('stock_id', $this->stock_id)
            ->where('expiry_date', $this->expiry_date)
            ->orderBy('strike_price')
            ->get();
    }
}