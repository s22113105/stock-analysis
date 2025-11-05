<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockDataUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $date;
    public $symbol;

    /**
     * Create a new event instance.
     */
    public function __construct($date, $symbol = null)
    {
        $this->date = $date;
        $this->symbol = $symbol;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('stock-updates'),
        ];
    }
}
