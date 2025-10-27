<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Volatility extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'calculation_date',
        'hv_10',
        'hv_20',
        'hv_30',
        'hv_60',
        'iv_call',
        'iv_put',
        'iv_atm',
    ];

    protected $casts = [
        'calculation_date' => 'date',
        'hv_10' => 'decimal:4',
        'hv_20' => 'decimal:4',
        'hv_30' => 'decimal:4',
        'hv_60' => 'decimal:4',
        'iv_call' => 'decimal:4',
        'iv_put' => 'decimal:4',
        'iv_atm' => 'decimal:4',
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
        return $query->whereBetween('calculation_date', [$startDate, $endDate]);
    }

    /**
     * 取得最新的波動率
     */
    public function scopeLatest($query, $stockId)
    {
        return $query->where('stock_id', $stockId)
            ->orderBy('calculation_date', 'desc')
            ->first();
    }

    /**
     * 計算 HV-IV 差異
     */
    public function getHvIvSpreadAttribute()
    {
        if ($this->hv_20 && $this->iv_atm) {
            return $this->iv_atm - $this->hv_20;
        }
        return null;
    }

    /**
     * 判斷 IV 是否被高估
     */
    public function isIvOvervalued($threshold = 0.05)
    {
        $spread = $this->hv_iv_spread;
        return $spread !== null && $spread > $threshold;
    }

    /**
     * 判斷 IV 是否被低估
     */
    public function isIvUndervalued($threshold = -0.05)
    {
        $spread = $this->hv_iv_spread;
        return $spread !== null && $spread < $threshold;
    }
}