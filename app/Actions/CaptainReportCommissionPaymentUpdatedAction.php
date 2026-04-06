<?php
namespace App\Actions;

use App\Captain;
use App\CaptainCommission;
use App\CaptainReport;

class CaptainReportCommissionPaymentUpdatedAction
{
  public function execute($commission)
  {
    $report = CaptainReport::where('captain_id', $commission->captain_id)->first();

    $commission_paid = CaptainCommission::where('captain_id', $commission->captain_id)->sum('settled_amount');
    $captain_balance = CaptainCommission::where('captain_id', $commission->captain_id)->latest('id')->first()->balance;

    if (!$report) {
        $report = new CaptainReport();
        $report->captain_id = $commission->captain_id;
    }

    $report->balance_commission = $captain_balance;
    $report->paid_commission = $commission_paid;
    $report->updated_at = now();
    $report->save();
  }
}