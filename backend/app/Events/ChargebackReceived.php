<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChargebackReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $chargeId;
    public string $accountId;
    public float $amount;
    public string $currency;
    public string $reason;
    public array $details;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $chargeId,
        string $accountId,
        float $amount,
        string $currency,
        string $reason,
        array $details = []
    ) {
        $this->chargeId = $chargeId;
        $this->accountId = $accountId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->reason = $reason;
        $this->details = $details;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('account.' . $this->accountId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'chargeback.received';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'charge_id' => $this->chargeId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'details' => $this->details,
            'timestamp' => now()->toIso8601String(),
            'severity' => 'high',
        ];
    }
}
