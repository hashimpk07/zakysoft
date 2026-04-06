<?php
namespace App\Listeners;

use App\Actions\CaptainReportCommissionPayedAction;
use App\Actions\CaptainReportCommissionPaymentUpdatedAction;
use App\Events\CaptainCommissionPaymentCreated;
use App\Events\CaptainCommissionPaymentUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;

class CaptainReportCommissionPaymentUpdated implements ShouldQueue
{
  /**
   * Create the event listener.
   */
  public function __construct(private CaptainReportCommissionPaymentUpdatedAction $action)
  {
  }

  /**
   * Handle the event.
   */
  public function handle(CaptainCommissionPaymentUpdated $event): void
  {
    $this->action->execute($event->commission);
  }
}
