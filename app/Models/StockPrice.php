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
        'open_price',
        'high_price',
        'low_price',
        'close_price',
        'volume',
        'turnover',
        'transaction',
        'change',
        'change_percent',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'open_price' => 'decimal:2',
        'high_price' => 'decimal:2',
        'low_price' => 'decimal:2',
        'close_price' => 'decimal:2',
        'volume' => 'integer',
        'turnover' => 'decimal:2',
        'transaction' => 'integer',
        'change' => 'decimal:2',
        'change_percent' => 'decimal:4',
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
    public function getDailyReturnAttribute()
    {
        return $this->change_percent / 100;
    }

    /**
     * 取得特定股票的歷史資料
     */
    public function scopeForStock($query, $stockId)
    {
        return $query->where('stock_id', $stockId)->orderBy('trade_date', 'desc');
    }
}