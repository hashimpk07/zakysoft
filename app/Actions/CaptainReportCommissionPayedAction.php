<?php
namespace App\Actions;

use App\CaptainCommission;
use App\CaptainReport;

class CaptainReportCommissionPayedAction
{
    public function execute(CaptainCommission $commission)
    {
        $report = CaptainReport::where('captain_id', $commission->captain_id)->first();

        if (!$report) {
            $report = new CaptainReport();
            $report->captain_id = $commission->captain_id;
        }

        $report->balance_commission = $commission->balance;
        $report->paid_commission = $report->paid_commission ? $report->paid_commission + $commission->settled_amount: $commission->settled_amount;
        $report->updated_at = now();
        $report->save();
    }
}