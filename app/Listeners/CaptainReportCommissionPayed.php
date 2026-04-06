<?php
namespace App\Listeners;

use App\Actions\CaptainReportCommissionPayedAction;
use App\Events\CaptainCommissionPaymentCreated;
use Illuminate\Contracts\Queue\ShouldQueue;

class CaptainReportCommissionPayed implements ShouldQueue
{
  /**
   * Create the event listener.
   */
  public function __construct(private CaptainReportCommissionPayedAction $action)
  {
  }

  /**
   * Handle the event.
   */
  public function handle(CaptainCommissionPaymentCreated $event): void
  {
    $this->action->execute($event->commission);
  }
}
