<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 市場警報事件
 * 
 * 用於推播重要市場訊息、波動率異常警示等
 */
class MarketAlert implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $alertType;
    public $title;
    public $message;
    public $severity;
    public $data;

    /**
     * 建立事件實例
     * 
     * @param string $alertType 警報類型 (volatility, volume, price, system)
     * @param string $title 警報標題
     * @param string $message 警報訊息
     * @param string $severity 嚴重程度 (info, warning, error, critical)
     * @param array $data 額外資料
     */
    public function __construct(
        string $alertType,
        string $title,
        string $message,
        string $severity = 'info',
        array $data = []
    ) {
        $this->alertType = $alertType;
        $this->title = $title;
        $this->message = $message;
        $this->severity = $severity;
        $this->data = $data;
    }

    /**
     * 取得廣播頻道
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('market-alerts'),
        ];
    }

    /**
     * 廣播事件名稱
     */
    public function broadcastAs(): string
    {
        return 'market.alert';
    }

    /**
     * 廣播資料
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->alertType,
            'title' => $this->title,
            'message' => $this->message,
            'severity' => $this->severity,
            'data' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}