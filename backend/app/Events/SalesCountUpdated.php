<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SalesCountUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $accountId;
    public int $totalSales;
    public float $totalRevenue;
    public string $currency;
    public array $todayStats;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $accountId,
        int $totalSales,
        float $totalRevenue,
        string $currency = 'BRL',
        array $todayStats = []
    ) {
        $this->accountId = $accountId;
        $this->totalSales = $totalSales;
        $this->totalRevenue = $totalRevenue;
        $this->currency = $currency;
        $this->todayStats = $todayStats;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('account.' . $this->accountId . '.stats'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'sales.count.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'total_sales' => $this->totalSales,
            'total_revenue' => $this->totalRevenue,
            'currency' => $this->currency,
            'today' => $this->todayStats,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
