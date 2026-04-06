<?php

namespace App\Events;

use App\EmployeeCallLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncomingCallEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public EmployeeCallLog $call;
    public array $payload;

    public function __construct(EmployeeCallLog $call, array $payload = [])
    {
        // keep minimal data on the event to reduce payload size
        $this->call = $call->load('participants');
        $this->payload = $payload;
    }

    /**
     * Broadcast to multiple private channels (one per user).
     * Laravel will send one broadcast to all returned channels.
     *
     * @return array
     */
    public function broadcastOn(): array
    {
        return $this->call->participants
            ->map(function ($p) {
                // private-user.{id} is the convention used by Echo.private('user.{id}')
                return new PrivateChannel("user.{$p->user_id}");
            })
            ->toArray();
    }

    public function broadcastAs(): string
    {
        return 'incoming.call';
    }

    public function broadcastWith(): array
    {
        // Send only the minimal data receivers need
        return [
            'call_id' => $this->call->id,
            'room_id' => $this->call->room_id,
            'initiator_id' => $this->call->initiator_id,
            'initiator_name' => $this->call->initiator->name ?? null,
            'type' => $this->call->type,
            'payload' => $this->payload,
        ];
    }
}
