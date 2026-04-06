<?php

namespace App\Actions;

use App\Captain;
use App\CommissionReport;
use App\CaptainWorkingLog;
use App\OrderReport;
 
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ConsolidatedCommissionReportAction
{
    public function execute(string $date): void
    {
        $date = Carbon::parse($date)->toDateString();
        $workingLogsQuery = CaptainWorkingLog::query()
            ->select('captain_working_logs.captain_id')
            ->selectRaw('
            SUM(seconds_worked) as online_hours,
            SUM(orders_received) as received,
            SUM(orders_try_to_accept) as try_to_accept,
            SUM(orders_rejected) as rejected,
            SUM(orders_expired) as expired,
            SUM(orders_accepted) as accepted,
            SUM(orders_delivered) as delivered,
            SUM(orders_returned) as returned,
            SUM(orders_cancelled) as cancelled')
            // ->join('captain_commissions', function($join) use ($date) {
            //     $join->on('captain_commissions.captain_id', '=', 'captain_working_logs.captain_id')
            //     ->whereRaw('DATE(captain_commissions.date) = ?', [$date]);
            // })
            ->whereExists(function ($q) use ($date) {
                $q->selectRaw(1)
                ->from('captain_commissions')
                ->whereColumn('captain_commissions.captain_id', 'captain_working_logs.captain_id')
                ->whereDate('captain_commissions.date', $date);
            })
            ->whereRaw('DATE(captain_working_logs.date) = ?', [$date])
                // ->when($request->get('captain'), function ($q, $captainId) {
                //     $q->where('captain_working_logs.captain_id', $captainId);   // Filter specific captain
                // })
            ->groupBy('captain_working_logs.captain_id');


       
            // dd($workingLogsQuery->pluck('seconds_worked'));

        $businessStart = Carbon::parse($date)->setTime(6, 0, 0);  
        $businessEnd   = (clone $businessStart)->addDay()->subMinute();

        $orderReportsQuery = OrderReport::query()
                ->join('orders', 'orders.id', '=', 'order_reports.order_id')
                ->select('order_reports.captain_id')
                ->selectRaw('
                    AVG(TIMESTAMPDIFF(SECOND, order_accepted_at, reached_shop_at)) as avg_arrival,
                    AVG(CASE WHEN orders.status_id = 10 THEN TIMESTAMPDIFF(SECOND, start_ride_at, orders.delivery_date) END) as avg_delivery,
                    AVG(order_reports.shop_to_delivery_km) as avg_distance,
                    SUM(TIMESTAMPDIFF(SECOND, order_accepted_at, orders.delivery_date)) as total_delivery_seconds
                ')
                ->whereBetween('orders.delivery_date', [$businessStart, $businessEnd])
                ->groupBy('order_reports.captain_id');

        // Step 3: Get working logs + order reports merged
        $logsCollection = $workingLogsQuery->get()->keyBy('captain_id');
        $orderAvgs = $orderReportsQuery->get()->keyBy('captain_id');
//  Log::info("Raw captain logs", [$logsCollection]);
        // Step 4: Load related relations for captains
        $captainIds = $logsCollection->keys();
        $captains = Captain::with([
            'user',
            'employmentType',
            'captainThirdParty.thirdPartCompany',
            'regions.quadrant',
            'bonusForDate' => function ($q) use ($date) {
                $q->select('id', 'captain_id', 'amount')
                ->whereDate('bonus_date', $date)
                ->latest('id');
            },
            'commissions' => function ($q) use ($date) {
                $q->withoutGlobalScope('excludeKpi')   // remove the global scope
                ->whereRaw('DATE(date) = ?', [$date])
                ->with(['commissionRule' => function($q) {
                    $q->select('id', 'name')        // fetch only needed columns, including name
                        ->with('hourCommitments');    // eager load hourCommitments
                    }]);
                },
                //'orderReports.order.clientShop.client.fallbackRule.deliveryPice.rulePrice.extraRules',
            ])
            // ->whereIn("id",[4093])
            ->whereIn('id', $captainIds)
            ->get()
            ->keyBy('id');

        // Step 5: Map the data
        $mappedLogs = $logsCollection->filter(function ($logsForCaptain, $captainId) use ($captains, $date) {
            $captain = $captains->get($captainId);
                if (!$captain) {
                    return false;
                }
            $orders = $captain->orderReports->pluck('order')
                    ->filter(fn($order) => $order && Carbon::parse($order->delivery_date)->format('Y-m-d') == $date);
                    return $orders->isNotEmpty(); 
            })->map(function ($logsForCaptain, $captainId) use ($orderAvgs, $captains, $date) {
                     $businessStart = Carbon::parse($date)->setTime(6, 0, 0);  
                $businessEnd = (clone $businessStart)->addDay()->subMinute(); // Next day 5:59:59 AM

                    $captain = $captains->get($captainId);
                    // Orders (we know it's not empty now)

                    $orders = $captain->orderReports->pluck('order')
                    ->filter(fn($order) => $order && 
                    $order->delivery_date >= $businessStart && 
                    $order->delivery_date <= $businessEnd);
            //         $captain = $captains->get($captainId);
            //         // Orders (we know it's not empty now)
            // $orders  = $captain->orderReports->pluck('order')
            //             ->filter(fn($order) => $order && Carbon::parse($order->delivery_date)->format('Y-m-d') == $date);



// Basic counts
            $onlineHours = round($logsForCaptain->online_hours / 3600, 2);
// Log::channel('discord')->info("onlineHours", [$logsForCaptain->online_hours]);
            $received    = $logsForCaptain->received;
            $tryToAccept = $logsForCaptain->try_to_accept;
            $rejected    = $logsForCaptain->rejected;
            $expired     = $logsForCaptain->expired;
            $accepted    = $logsForCaptain->accepted;
            $delivered   = $logsForCaptain->delivered;
            $returned    = $logsForCaptain->returned;
            $cancelled   = $logsForCaptain->cancelled;

            $successRate = $received > 0 ? round(($delivered / $accepted) * 100, 2) : 0;
            $acceptanceRate = $received > 0 ? round((($accepted + $tryToAccept) / $received * 100), 2) : 0;

            // Commissions
            $commissions     = $captain->commissions;
            $firstCommission = $commissions->first();
            $hourCommitment = 0;

            if ($firstCommission?->commission_rule_type == 2) { 
                $deliveryCommission = $firstCommission->commission ?? 0;
                $kpiBonus           = $captain->bonusForDate?->amount ?? 0;
                $extraKm            = $firstCommission->additional_km_earning ?? 0;
                // $hourCommitment = optional($firstCommission->commissionRule->hourCommitments)->max('hours_from') ?? 0;
                // $hourCommitment = ($firstCommission->hour_component_value ?? 0);

                $acceptanceRate     = $acceptanceRate;
                $totalPayable = $deliveryCommission + $kpiBonus + $extraKm;
                // $totalPayable       = $firstCommission->balance ?? 0;
                $agreedHours        = optional($firstCommission->commissionRule->hourCommitments)->max('hours_from') ?? 0;

                $hourCommitment = 0;
                    if ($agreedHours > 0) {
                        $hourCommitment = round(($onlineHours / $agreedHours) * 100, 2);
                        $hourCommitment = min($hourCommitment, 100); // cap at 100
                    }
            } else { 
                $bonus = $captain->bonusForDate?->amount ?? 0;
                $deliveryCommission = $commissions->sum('commission');
                $captain_commission_rule = $captain->commissionRule;

                $kpiBonus           = $bonus;
          
                $extraKm = $commissions->sum('additional_km_earning');
   
                // $hourCommitment     = 0;
                $acceptanceRate     = $acceptanceRate;
                // $totalPayable       = $firstCommission->balance ?? 0;
                $totalPayable = $deliveryCommission + $kpiBonus;
                $agreedHours        = 0;
                $agreedHours = $captain_commission_rule->fallback_hour ?? 0;
                $hourCommitment     = 0;
                 $hourCommitment = 0;
                    if ($agreedHours > 0) {
                        $hourCommitment = round(($onlineHours / $agreedHours) * 100, 2);
                        $hourCommitment = min($hourCommitment, 100); // cap at 100
                    }
            }
            
            
   

            // Averages
            $avgArrival  = $orderAvgs->get($captainId)?->avg_arrival ?? 0;
            $avgDelivery = $orderAvgs->get($captainId)?->avg_delivery ?? 0;
            $avgDistance = $orderAvgs->get($captainId)?->avg_distance ?? 0;

            $avgArrival  = $this->formatMinutesToHuman(round($avgArrival / 60, 2));
            $avgDelivery = $this->formatMinutesToHuman(round($avgDelivery / 60, 2));

            // --- IDLE HOURS ---
            // $totalDeliverySeconds = $logsForCaptain->total_delivery_seconds ?? 0;
            $totalDeliverySeconds = $orderAvgs->get($captainId)?->total_delivery_seconds ?? 0;

            $idleSeconds = ($onlineHours * 3600) - $totalDeliverySeconds;
            $idleHours   = round($idleSeconds / 3600, 2);
          $idleHours = max($idleHours, 0);

            // Selling price
            $sellingPrice = $orders->sum(fn($order) => $order->delivery_charge);
            // Log::channel('discord')->info("sellingPrice" . $sellingPrice);

            // collect($orders)->map(function ($order) {
            //     return [
            //         'id'             => $order->id,
            //         'status_id'      => $order->status_id,
            //         'delivery_date'  => $order->delivery_date,
            //         'delivery_charge'=> $order->delivery_charge,
            //     ];
            // })->chunk(10)->each(function ($chunk, $index) {
            //     $json = json_encode($chunk->toArray(), JSON_PRETTY_PRINT);

            //     // Keep within Discord’s 2000 char limit
            //     if (strlen($json) > 1900) {
            //         $json = substr($json, 0, 1900) . '... [truncated]';
            //     }

            //     Log::channel('discord')->info("Orders Chunk {$index}:\n" . $json);
            // });
            $costPerDelivery = $delivered ? $totalPayable / $delivered : 0;
            // $pnl             = $deliveryCommission - $sellingPrice;     
            $pnl = $sellingPrice - $totalPayable;


            // Log::channel('discord')->info("idleHours" . $idleHours);

 

            CommissionReport::updateOrCreate(
            [
                'date'       => Carbon::parse($date)->format('Y-m-d'), 
                'captain_id' => $captain->id,
            ],
            [
                'commission_type'     => $firstCommission?->commission_rule_type != 2 ? 'Delivery Based' : 'KPI Based',
                'rule'                => $firstCommission->commissionRule->name ?? 'N/A',
                'employee_type'       => $captain->employmentType?->name,
                'employer'            => $captain->captainThirdParty->thirdPartCompany->name ?? 'N/A',
                'regions'             => $captain->regions->pluck('quadrant.name')->unique()->join(', '),
                'assigned_areas'      => $captain->regions->pluck('name')->join(', '),
                'online_hours'        => $onlineHours,
                'agreed_hours'        => $agreedHours,
                'idle_hours'          => $idleHours,
                'acceptance_rate'     => $acceptanceRate,
                'hour_commitment'     => $hourCommitment,
                'success_rate'        => $successRate,
                'avg_arrival_time'    => $avgArrival,
                'avg_delivery_time'   => $avgDelivery,
                'avg_delivery_dist'   => $avgDistance,
                'delivery_commission' => $deliveryCommission,
                'extra_km'            => $extraKm,
                'kpi_bonus'           => $kpiBonus,
                'total_payable'       => $totalPayable,
                'cost_per_delivery'   => $costPerDelivery,
                'selling_price'       => $sellingPrice,
                'profit_loss'         => $pnl
            ]);

            
        });
        Log::info("Consolidated commission report generated successfully {$date}");  
    }
    function formatMinutesToHuman($minutes)
    {
        $seconds = $minutes * 60; // convert minutes to seconds
        return gmdate('H:i:s', $seconds);
    }

}