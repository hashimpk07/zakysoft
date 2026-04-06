<?php

namespace App\Events;

use App\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $message;
    public array $payload;
    public User $user;

    public function __construct(string $message, User $user, array $payload = [])
    {
        // keep minimal data on the event to reduce payload size
        $this->message = $message;
        $this->payload = $payload;
        $this->user = $user;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->user->id}")];
    }

    public function broadcastAs(): string
    {
        return 'call.status';
    }

    public function broadcastWith(): array
    {
        // Send only the minimal data receivers need
        return [
            'message' => $this->message,
            'payload' => $this->payload,
        ];
    }
}
