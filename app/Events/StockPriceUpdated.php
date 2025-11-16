<?php

namespace App\Events;

use App\Models\StockPrice;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 股票價格更新事件
 * 
 * 當股票價格更新時觸發，透過 WebSocket 推播給前端
 */
class StockPriceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $stockPrice;
    public $stock;

    /**
     * 建立事件實例
     */
    public function __construct(StockPrice $stockPrice)
    {
        $this->stockPrice = $stockPrice;
        $this->stock = $stockPrice->stock;
    }

    /**
     * 取得廣播頻道
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('stock-prices'),
            new Channel('stock-prices.' . $this->stock->symbol),
        ];
    }

    /**
     * 廣播事件名稱
     */
    public function broadcastAs(): string
    {
        return 'stock.price.updated';
    }

    /**
     * 廣播資料
     */
    public function broadcastWith(): array
    {
        return [
            'stock' => [
                'id' => $this->stock->id,
                'symbol' => $this->stock->symbol,
                'name' => $this->stock->name,
            ],
            'price' => [
                'open' => (float) $this->stockPrice->open,
                'high' => (float) $this->stockPrice->high,
                'low' => (float) $this->stockPrice->low,
                'close' => (float) $this->stockPrice->close,
                'volume' => (int) $this->stockPrice->volume,
                'change' => (float) $this->stockPrice->change,
                'change_percent' => (float) $this->stockPrice->change_percent,
                'trade_date' => $this->stockPrice->trade_date,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}