<?php

namespace App\Events;

use App\Models\OptionPrice;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 選擇權價格更新事件
 * 
 * 當選擇權價格更新時觸發，透過 WebSocket 推播給前端
 */
class OptionPriceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $optionPrice;
    public $option;

    /**
     * 建立事件實例
     */
    public function __construct(OptionPrice $optionPrice)
    {
        $this->optionPrice = $optionPrice;
        $this->option = $optionPrice->option;
    }

    /**
     * 取得廣播頻道
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('option-prices'),
            new Channel('option-prices.' . $this->option->underlying),
        ];
    }

    /**
     * 廣播事件名稱
     */
    public function broadcastAs(): string
    {
        return 'option.price.updated';
    }

    /**
     * 廣播資料
     */
    public function broadcastWith(): array
    {
        return [
            'option' => [
                'id' => $this->option->id,
                'underlying' => $this->option->underlying,
                'option_code' => $this->option->option_code,
                'option_type' => $this->option->option_type,
                'strike_price' => (float) $this->option->strike_price,
                'expiry_date' => $this->option->expiry_date,
            ],
            'price' => [
                'open' => (float) $this->optionPrice->open,
                'high' => (float) $this->optionPrice->high,
                'low' => (float) $this->optionPrice->low,
                'close' => (float) $this->optionPrice->close,
                'settlement_price' => (float) $this->optionPrice->settlement_price,
                'volume' => (int) $this->optionPrice->volume,
                'open_interest' => (int) $this->optionPrice->open_interest,
                'implied_volatility' => (float) $this->optionPrice->implied_volatility,
                'trade_date' => $this->optionPrice->trade_date,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}