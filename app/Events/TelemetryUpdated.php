<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TelemetryUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $flightId,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('flight.'.$this->flightId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'TelemetryUpdated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
