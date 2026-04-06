<?php

namespace App\Actions;

use App\CaptainReport;

class UpdateCaptainCommissionAction
{
    /**
     * Handle the captain report update.
     *
     * @param object $order
     * @return void
     */
    public function execute(array $captainReportData)
    {

        $captainId = $captainReportData['captain_id'];
        $amountPaid = $captainReportData['amount_paid'];

        //* Get the latest CaptainReport for the captain, or null if it doesn't exist
        $captain_reports = CaptainReport::where('captain_id', $captainId)->latest()->first();

        $previous_paid_commission = $captain_reports->paid_commission ?? 0;

        $paid_commission = $previous_paid_commission + $amountPaid;

        //* Update or create the CaptainReport with the new values
        CaptainReport::updateOrCreate(
            ['captain_id' => $captainId],
            [
                'paid_commission' => $paid_commission,

            ]
        );
    }
}
