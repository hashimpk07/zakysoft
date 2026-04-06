<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;


use App\Ticket;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ClientTicket implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $ticket;
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Ticket $ticket)
    {
        $ticket->load('order:id,client_order_id,client_id,email,customer_name');
        $this->ticket = $ticket;
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
                'ticket' => [
                    'id' => $this->ticket->id,
                    'subject' => $this->ticket->subject,
                    'order_id' => $this->ticket->order_id,
                    'captain_id' => $this->ticket->captain_id,
                    'taken_at' => $this->ticket->taken_at,
                    'created_at' => $this->ticket->created_at,
                    'order' => [
                        'id' => $this->ticket->order->id,
                        'client_order_id' => $this->ticket->order->client_order_id,
                        'client_id' => $this->ticket->order->client_id,
                        'email' => $this->ticket->order->email,
                        'customer_name' => $this->ticket->order->customer_name,
                    ],
                ]
            ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('ticket');
    }
}
