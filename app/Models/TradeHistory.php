<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'symbol',
        'name',
        'type',
        'order_type',
        'trade_date',
        'quantity',
        'price',
        'amount',
        'commission',
        'tax',
        'net_amount',
        'realized_pnl',
        'realized_pnl_percent',
        'note',
    ];

    protected $casts = [
        'trade_date'           => 'date',
        'price'                => 'decimal:4',
        'amount'               => 'decimal:2',
        'commission'           => 'decimal:2',
        'tax'                  => 'decimal:2',
        'net_amount'           => 'decimal:2',
        'realized_pnl'         => 'decimal:2',
        'realized_pnl_percent' => 'decimal:4',
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

    public function scopeBySymbol($query, string $symbol)
    {
        return $query->where('symbol', $symbol);
    }

    public function scopeBetweenDates($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('trade_date', [$startDate, $endDate]);
    }

    public function scopeBuys($query)
    {
        return $query->where('order_type', 'buy');
    }

    public function scopeSells($query)
    {
        return $query->where('order_type', 'sell');
    }
}
