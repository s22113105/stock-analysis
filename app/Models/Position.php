<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Position extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'symbol',
        'name',
        'type',
        'expiry_date',
        'option_type',
        'strike_price',
        'quantity',
        'avg_price',
        'cost',
        'current_price',
        'market_value',
        'unrealized_pnl',
        'unrealized_pnl_percent',
        'is_active',
    ];

    protected $casts = [
        'expiry_date'             => 'date',
        'strike_price'            => 'decimal:2',
        'avg_price'               => 'decimal:4',
        'cost'                    => 'decimal:2',
        'current_price'           => 'decimal:4',
        'market_value'            => 'decimal:2',
        'unrealized_pnl'          => 'decimal:2',
        'unrealized_pnl_percent'  => 'decimal:4',
        'is_active'               => 'boolean',
    ];

    // ==========================================
    // Relations
    // ==========================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * 只取有效持倉 (quantity > 0)
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('quantity', '>', 0);
    }

    /**
     * 只取股票持倉
     */
    public function scopeStocks($query)
    {
        return $query->where('type', 'stock');
    }

    /**
     * 只取選擇權持倉
     */
    public function scopeOptions($query)
    {
        return $query->where('type', 'option');
    }

    // ==========================================
    // Helpers
    // ==========================================

    /**
     * 根據最新市價更新損益
     */
    public function refreshPnl(float $currentPrice): void
    {
        $marketValue      = $currentPrice * $this->quantity;
        $unrealizedPnl    = $marketValue - $this->cost;
        $unrealizedPct    = $this->cost > 0 ? ($unrealizedPnl / $this->cost) * 100 : 0;

        $this->update([
            'current_price'           => $currentPrice,
            'market_value'            => $marketValue,
            'unrealized_pnl'          => $unrealizedPnl,
            'unrealized_pnl_percent'  => $unrealizedPct,
        ]);
    }
}
