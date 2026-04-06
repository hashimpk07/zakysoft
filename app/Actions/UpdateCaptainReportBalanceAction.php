<?php

namespace App\Actions;

use App\Captain;
use App\CaptainReport;

class UpdateCaptainReportBalanceAction
{
    /**
     * Handle the captain report update.
     *
     * @param object $order
     * @return void
     */
    public function execute(object $captainBalanceData)
    {

        $captainId = $captainBalanceData->captain_id;

        $captain_balance = Captain::query()
            ->leftJoin('captain_order_payments', function ($join) {
                $join->on('captain_order_payments.captain_id', '=', 'captains.id')
                    ->whereRaw('captain_order_payments.id = (SELECT MAX(id) FROM captain_order_payments WHERE captain_id = captains.id)');
            })
            ->selectRaw('SUM(CASE WHEN captain_order_payments.balance THEN captain_order_payments.balance ELSE 0 END) AS total_balance')
            ->where('captains.id', $captainId)->first();

        CaptainReport::updateOrCreate(
            [
                'captain_id' => $captainId,
            ],
            [
                'balance' => $captain_balance->total_balance ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

    }
}
