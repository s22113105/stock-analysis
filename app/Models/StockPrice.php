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
     * 取得特定期間的價格
     */
    public function scopePeriod($query, $days)
    {
        $startDate = now()->subDays($days);
        return $query->where('trade_date', '>=', $startDate);
    }

    /**
     * 計算日報酬率
     */
    public function getDailyReturnAttribute()
    {
        $previousPrice = self::where('stock_id', $this->stock_id)
            ->where('trade_date', '<', $this->trade_date)
            ->orderBy('trade_date', 'desc')
            ->first();

        if ($previousPrice && $previousPrice->close_price > 0) {
            return ($this->close_price - $previousPrice->close_price) / $previousPrice->close_price;
        }

        return null;
    }

    /**
     * 計算真實波動幅度 (True Range)
     */
    public function getTrueRangeAttribute()
    {
        $previousClose = self::where('stock_id', $this->stock_id)
            ->where('trade_date', '<', $this->trade_date)
            ->orderBy('trade_date', 'desc')
            ->value('close_price');

        if ($previousClose) {
            return max(
                $this->high_price - $this->low_price,
                abs($this->high_price - $previousClose),
                abs($this->low_price - $previousClose)
            );
        }

        return $this->high_price - $this->low_price;
    }

    /**
     * 計算價格區間
     */
    public function getPriceRangeAttribute()
    {
        return $this->high_price - $this->low_price;
    }

    /**
     * 是否為漲停
     */
    public function getIsLimitUpAttribute()
    {
        return $this->change_percent >= 9.5;
    }

    /**
     * 是否為跌停
     */
    public function getIsLimitDownAttribute()
    {
        return $this->change_percent <= -9.5;
    }
}
