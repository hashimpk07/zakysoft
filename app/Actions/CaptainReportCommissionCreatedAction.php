<?php

namespace App\Actions;

use App\CaptainCommission;
use App\CaptainReport;
use App\Order;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class CaptainReportCommissionCreatedAction
{
    public function execute(CaptainCommission $commission)
    {
        $report = CaptainReport::where('captain_id', $commission->captain_id)->first();

        if (!$report) {
            $report = new CaptainReport();
            $report->captain_id = $commission->captain_id;
        }

        $report->commissioned_attended_orders = ($report->commissioned_attended_orders ?? 0) + 1;
        $report->total_commission = $report->total_commission ? $report->total_commission + $commission->commission : $commission->commission;
        $report->balance_commission = $report->balance_commission ?? 0;
        $report->updated_at = now();
        $report->save();
    }
}
