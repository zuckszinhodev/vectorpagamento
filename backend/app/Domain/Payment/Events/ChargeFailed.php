<?php

namespace App\Domain\Payment\Events;

use App\Domain\Payment\Entities\Charge;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChargeFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Charge $charge,
        public string $reason
    ) {}
}
