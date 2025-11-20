<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * 取得所有股價記錄
     */
    public function prices(): HasMany
    {
        return $this->hasMany(StockPrice::class);
    }

    /**
     * ========================================
     * 修正: 新增 latestPrice 關聯
     * 取得最新的股價記錄
     * ========================================
     */
    public function latestPrice(): HasOne
    {
        return $this->hasOne(StockPrice::class)
            ->latestOfMany('trade_date');
    }

    /**
     * ========================================
     * 修正: 新增輔助方法
     * ========================================
     */

    /**
     * 取得最新收盤價
     */
    public function getLatestClosePriceAttribute()
    {
        return $this->latestPrice?->close;
    }

    /**
     * 取得最新交易日期
     */
    public function getLatestTradeDateAttribute()
    {
        return $this->latestPrice?->trade_date;
    }

    /**
     * 檢查是否有價格資料
     */
    public function hasPriceData(): bool
    {
        return $this->prices()->exists();
    }

    /**
     * 取得指定日期範圍的價格
     */
    public function getPricesBetween($startDate, $endDate)
    {
        return $this->prices()
            ->whereBetween('trade_date', [$startDate, $endDate])
            ->orderBy('trade_date', 'asc')
            ->get();
    }

    /**
     * 取得最近 N 天的價格
     */
    public function getRecentPrices(int $days = 30)
    {
        return $this->prices()
            ->orderBy('trade_date', 'desc')
            ->limit($days)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * ========================================
     * Scope 查詢
     * ========================================
     */

    /**
     * 只查詢活躍的股票
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 只查詢有價格資料的股票
     */
    public function scopeWithPrices($query)
    {
        return $query->whereHas('prices');
    }

    /**
     * 依市場篩選
     */
    public function scopeByMarket($query, string $market)
    {
        return $query->where('market', $market);
    }

    /**
     * 依產業篩選
     */
    public function scopeByIndustry($query, string $industry)
    {
        return $query->where('industry', $industry);
    }

    /**
     * 搜尋股票（代碼或名稱）
     */
    public function scopeSearch($query, string $keyword)
    {
        return $query->where(function ($q) use ($keyword) {
            $q->where('symbol', 'LIKE', "%{$keyword}%")
                ->orWhere('name', 'LIKE', "%{$keyword}%");
        });
    }
}
