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
        'exchange',
        'industry',
        'market_cap',
        'shares_outstanding',
        'is_active',
        'meta_data',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta_data' => 'array',
        'market_cap' => 'decimal:2',
        'shares_outstanding' => 'decimal:2',
    ];

    /**
     * 股票價格歷史
     */
    public function prices(): HasMany
    {
        return $this->hasMany(StockPrice::class);
    }

    /**
     * 選擇權
     */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class);
    }

    /**
     * 波動率數據
     */
    public function volatilities(): HasMany
    {
        return $this->hasMany(Volatility::class);
    }

    /**
     * 預測數據
     */
    public function predictions()
    {
        return $this->morphMany(Prediction::class, 'predictable');
    }

    /**
     * 回測結果
     */
    public function backtestResults(): HasMany
    {
        return $this->hasMany(BacktestResult::class);
    }

    /**
     * 取得最新價格
     */
    public function latestPrice()
    {
        return $this->hasOne(StockPrice::class)->latestOfMany('trade_date');
    }

    /**
     * 取得特定日期的價格
     */
    public function priceAt($date)
    {
        return $this->prices()->where('trade_date', $date)->first();
    }

    /**
     * 取得價格區間
     */
    public function pricesBetween($startDate, $endDate)
    {
        return $this->prices()
            ->whereBetween('trade_date', [$startDate, $endDate])
            ->orderBy('trade_date')
            ->get();
    }

    /**
     * 計算報酬率
     */
    public function calculateReturn($startDate, $endDate)
    {
        $startPrice = $this->priceAt($startDate)?->close;
        $endPrice = $this->priceAt($endDate)?->close;
        
        if (!$startPrice || !$endPrice) {
            return null;
        }
        
        return (($endPrice - $startPrice) / $startPrice) * 100;
    }

    /**
     * 取得活躍的股票
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 依交易所篩選
     */
    public function scopeByExchange($query, $exchange)
    {
        return $query->where('exchange', $exchange);
    }
}