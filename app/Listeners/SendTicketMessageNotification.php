<?php
namespace App\Listeners;

use App\Events\NewTicketMessage;
use App\Reminder;
use App\Services\Firebase\CloudMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendTicketMessageNotification implements ShouldQueue {
        /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Handle the event.
     *
     * @param  NewTicketMessage  $event
     * @return void
     */
    public function handle(NewTicketMessage $event)
    {
        $message = $event->message;
        $ticket = $message->ticket;
        $captain = $ticket->captain;

        if(!$captain || $message->sender->captain) {
            return ;
        }

        $token = $captain->accessToken->fb_token;
        $metadata = Reminder::getNotificationMetadata(Reminder::TICKET_MESSAGE_REMINDER);
        (new CloudMessage($captain->firebaseVersion()))
            ->send([
                'to' => $token,
                'notification' => [
                    'title' => 'Ticket ' . $ticket->id,
                    'body' => $message->message,
                ],
                'data' => [
                    'priority' => 'High',
                    'title' => 'Ticket ' . $ticket->id,
                    'body' => $message->message,
                    'ticket_id' => $ticket->id,                    
                    'ticket_type' => $ticket->type,
                    'reminder_type' => Reminder::TICKET_MESSAGE_REMINDER,
                    "sound" => $metadata['sound'],
                    "android_channel_id" => $metadata['android_channel_id'],
                    "content_available" => true,
                    "mutable_content" => true,
                ] 
            ]);
    }
}