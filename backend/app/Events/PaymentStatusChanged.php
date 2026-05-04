<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $chargeId;
    public string $accountId;
    public string $status;
    public array $charge;
    public float $amount;
    public string $currency;
    public ?string $customerEmail;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $chargeId,
        string $accountId,
        string $status,
        array $charge,
        float $amount,
        string $currency = 'BRL',
        ?string $customerEmail = null
    ) {
        $this->chargeId = $chargeId;
        $this->accountId = $accountId;
        $this->status = $status;
        $this->charge = $charge;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->customerEmail = $customerEmail;
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
            new PrivateChannel('charge.' . $this->chargeId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'payment.status.changed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'charge_id' => $this->chargeId,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'customer_email' => $this->customerEmail,
            'charge' => $this->charge,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
