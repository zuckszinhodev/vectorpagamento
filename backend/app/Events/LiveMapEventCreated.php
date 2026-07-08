<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveMapEventCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $accountId,
        public array $event
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('account.' . $this->accountId),
        ];
    }

    public function broadcastAs(): string
    {
        return ($this->event['kind'] ?? 'checkout') === 'api'
            ? 'api.request.created'
            : 'checkout.visit.created';
    }

    public function broadcastWith(): array
    {
        return $this->event;
    }
}
