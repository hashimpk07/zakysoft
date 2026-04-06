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
use Illuminate\Support\Facades\Log;

class TicketUpdated implements ShouldBroadcastNow
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
        $this->ticket = $ticket
                            ->unsetRelations()
                            ->load(
                                'client.user:id,name',
                                'captain:id,user_id,code,phone_number,firstname,lastname', 
                                'order.shop:id,name',
                                'order:id,shopname,client_order_id',
                                'takenByUser:id,name,email'
                            )
                            ->loadCount(['notUserSeenMessages as not_seen_messages_count'])
                            ->loadCount(['notCaptainSeenMessages as not_seen_messages_count_by_user']);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return [new PrivateChannel('ticket'), new PrivateChannel('ticket.'.$this->ticket->id)];
    }
}
