<?php

namespace App\Actions;

use App\CaptainCommission;
use App\CaptainReport;
use App\Order;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;

class CaptainReportCommissionDeletedAction
{
    public function execute(CaptainCommission $commission)
    {
        $report = CaptainReport::where('captain_id', $commission->captain_id)->first();

        $commission_paid = CaptainCommission::where('captain_id', $commission->captain_id)->sum('settled_amount');
        $captain_balance = CaptainCommission::where('captain_id', $commission->captain_id)->latest('id')->first()->balance;
        $total_commission = CaptainCommission::where('captain_id', $commission->captain_id)->sum('commission');

        if (!$report) {
            $report = new CaptainReport();
            $report->captain_id = $commission->captain_id;
        }

        $report->commissioned_attended_orders = ($report->commissioned_attended_orders ?? 1) - 1;
        $report->total_commission = $total_commission;
        $report->paid_commission = $commission_paid;
        $report->balance_commission = $captain_balance ?? 0;
        $report->updated_at = now();
        $report->save();
    }
}
