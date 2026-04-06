<?php
namespace App\Listeners;

use App\Events\NewTicket;
use App\Ticket;
use App\TicketReason;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendTicketAutoResponse implements ShouldQueue {

    /**
     * The time (seconds) before the job should be processed.
     *
     * @var int
     */
    public $delay = 20;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  NewTicket  $event
     * @return void
     */
    public function handle(NewTicket $event)
    {
        $ticket = $event->ticket;
        
        if(!$ticket->autoResponsable()) {
            return ;
        }

        $proffered_language = $ticket->captain->user->language ?? 'en';

        $subject = $ticket->subject;

        $ticket_reason = TicketReason::where('reason', $subject)->first();
        $ticket_reason_ar = TicketReason::where('reason_ar', $subject)->first();
        $message = __('app/chat.auto_response_fallback', [], $proffered_language);

        if($ticket_reason && $proffered_language == 'en') {
            $message = $ticket_reason->auto_response ?? $message;
        }

        if($ticket_reason_ar && $proffered_language == 'ar') {
            $message = $ticket_reason->auto_response_ar ?? $message;
        }

        $ticket->messages()->create([
            'message' => $message,
            'sender_id' => config('app.bot_user')
        ]);

    }
}