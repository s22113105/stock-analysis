<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'name',
        'market',
        'industry',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * 取得股票的價格資料
     */
    public function prices(): HasMany
    {
        return $this->hasMany(StockPrice::class);
    }

    /**
     * 取得股票的選擇權
     */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class);
    }

    /**
     * 取得股票的波動率資料
     */
    public function volatilities(): HasMany
    {
        return $this->hasMany(Volatility::class);
    }

    /**
     * 取得股票的預測資料
     */
    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    /**
     * 取得股票的回測結果
     */
    public function backtestResults(): HasMany
    {
        return $this->hasMany(BacktestResult::class);
    }

    /**
     * 取得最新的價格資料
     */
    public function latestPrice()
    {
        return $this->hasOne(StockPrice::class)->latestOfMany('trade_date');
    }

    /**
     * 根據股票代碼尋找
     */
    public function scopeBySymbol($query, string $symbol)
    {
        return $query->where('symbol', $symbol);
    }

    /**
     * 只取得啟用的股票
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}