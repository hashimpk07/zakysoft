<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\CommissionReport;
use App\Order;

class CaptainConsolidatedCommissionExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected int $totalData = 0;

    protected string $file_name = 'Consolidated_Captain_Commission_Report';

    public function data(): array
    {
        $request = $this->export->filters;

        $givenDate = $request['date'] ?? now()->subDay()->format('Y-m-d');
        $givenDate = Carbon::parse($givenDate)->format('Y-m-d');
        $isNonAggregated = isset($request['non_aggregated']) && $request['non_aggregated'];
        $performance_reports = $this->getReport($givenDate, $request);

        $data = [];
        if ($isNonAggregated && is_array($performance_reports)) {
            foreach ($performance_reports as $report) {
                $data[] = [
                    $report['Date'],
                    $report['Captain'],
                    $report['Captain Assigned Rule'],
                    $report['Iqama Number'],
                    $report['Employee Id'],
                    $report['Employee Type'] ?? 'N/A',
                    $report['Employer'] ?? 'N/A',
                    $report['Work Region'],
                    $report['Assigned Areas'],
                    $report['Client Name'],
                    $report['Shop Name'],
                    $report['Order ID'],
                    $report['Order Status'],
                    $report['Delivery KM'],
                    $report['B.D Commission'],
                    $report['Extra Km'],
                    $report['Extra Earning'],
                    $report['Other Payable'],
                    $report['Commission Type'],
                    $report['Total Payable'],
                    $report['Cost/Per Order'],

                ];
            }
        }else{
            foreach ($performance_reports as $report) {
                $data[] = [
                    $report['date'],
                    $report['captain'],
                    $report['commission_type'],
                    $report['rule'],
                    $report['iqama'],
                    $report['employee_id'],
                    $report['employee_type'] ?? 'N/A',
                    $report['employer'] ?? 'N/A',
                    $report['regions'],
                    $report['assigned_areas'],
                    $report['online_hours'],
                    $report['agreed_hours'],
                    $report['idle_hours'],
                    // Strat
                    $report['received_orders'],
                    $report['try_to_accept'],
                    $report['rejected_orders'],
                    $report['expired_orders'],
                    $report['accepted_orders'],
                    $report['acceptance_rate'],
                    $report['hour_commitment'],
                    $report['delivered_orders'],
                    $report['returned_orders'],
                    $report['cancelled_orders'],
                    // end
                    $report['success_rate'],
                    $report['avg_arrival_time'],
                    $report['avg_delivery_time'],
                    $report['avg_delivery_dist'],
                    $report['delivery_commission'],
                    $report['extra_km'],
                    $report['kpi_bonus'],
                    $report['total_payable'],
                    $report['cost_per_delivery'],
                    $report['selling_price'],
                    $report['pnl'],
                ];
            }
        }
        return $data;
    }

    public function getReport($givenDate, $request)
    {
        $reqCaptainId = $request['captain'] ?? null;
        $reqCode = $request['code'] ?? null;
        $reqSearch = $request['search'] ?? null;
        $reqQuadrantId = $request['quadrant_id'] ?? null;
        $reqRegionId = $request['region_id'] ?? null;
        $reqCommissionType = $request['commission_type'] ?? null;
        $typeMap = [
            1 => 'Delivery Based',
            2 => 'KPI Based'
        ];
        $reqCommissionTypes = $typeMap[$reqCommissionType] ?? null;
        $isNonAggregated = isset($request['non_aggregated']) && $request['non_aggregated'];

        if($isNonAggregated){
            $businessDayStart = Carbon::parse($givenDate)->startOfDay()->addHours(6); // 06:00 AM
            $businessDayEnd = $businessDayStart->copy()->addDay()->subSecond(); // Next day 05:59:59

            $ordersQuery = Order::query()
                ->with([
                'captain.user:id,name',
                'captain.employmentType:id,name',
                'captain.captainThirdParty.thirdPartCompany:id,name',
                'captain.regions.quadrant',
                'client.user:id,name',
                'clientShop:id,name',
                'orderStatus:id,name',
                'orderDeliveryCharge:id,order_id,basic_delivery_charge,additional_km,additional_km_earning',
                'captain.commissionRule',
                'captainCommission:id,order_id,captain_id,commission_rule_type,basic_delivery_earnings,additional_km,additional_km_earning,commission',
                'captain.bonusForDate' => function ($q) use ($givenDate) {
                    $q->select('id', 'captain_id', 'amount')
                    ->whereDate('bonus_date', $givenDate)
                    ->latest('id');
                },
            ])
            ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd])

            ->when($reqCaptainId, fn($q) =>
                $q->where('captain_id', $reqCaptainId)
            )

            ->when($reqCode, fn($q) =>
                $q->whereHas('captain', fn($sub) =>
                $sub->where('code', 'LIKE', "%{$reqCode}%"))
            )

            ->when($reqQuadrantId, fn($q) =>
                $q->whereHas('captain.regions.quadrant', fn($sub) =>
                $sub->where('quadrants.id', $reqQuadrantId))
            )

            ->when($reqSearch, fn($q) =>
                $q->whereHas('captain.user', fn($sub) =>
                    $sub->where('name', 'LIKE', "%{$reqSearch}%")
                    ->orWhere('email', 'LIKE', "%{$reqSearch}%"))
            )

            ->when($reqCommissionType, fn($q) =>
                $q->whereHas('captainCommission', fn($sub) =>
                    $sub->where('commission_rule_type', $reqCommissionType))
            )

            ->when($reqRegionId, fn($q) =>
                $q->whereHas('captain.regions', fn($sub) =>
                $sub->where('regions.id', $reqRegionId))
            );

            //total data count
            $this->totalData = $ordersQuery->count();
            
            $page = $this->export->page_done ?? 0;
            $orders = $ordersQuery
                    ->orderBy('captain_id')
                    ->orderBy('delivery_date')
                    ->limit($this->chunk)
                    ->offset($this->chunk * $page)
                    ->get();

            Log::channel('commission')->info("Order Data:", [
                'order' => $orders->toArray()
            ]);

            $data = [];
            $bonusAdded = [];
            foreach ($orders as $order) {

                if (!$order->captain) {
                    continue;
                }

                $commissionRule   = $order->captain?->commissionRule ?? null;
                $orderCommission  = $order->captainCommission ?? null;

                $deliveryDate = Carbon::parse($order->delivery_date);

                $businessDate = $deliveryDate->lt($deliveryDate->copy()->startOfDay()->addHours(6)
                    ) ? $deliveryDate->copy()->subDay()->format('Y-m-d') : $deliveryDate->format('Y-m-d');

                $commissionDetails = (new \App\Http\Controllers\CaptainCommissionReportController)->calculateCommission($order, $order->captain, $businessDate, $commissionRule);

                $captainId   = $order->captain_id;
                $bonusAmount = $order->captain?->bonusForDate?->amount ?? 0;

                $bdCommission   = $commissionDetails['B.D Commission'] ?? 0;
                $extraEarning   = $commissionDetails['Extra Earning'] ?? 0;
                $totalPayable   = $bdCommission + $extraEarning;

                $data[] = [
                    'Date' => $deliveryDate->format('m-d-Y'),
                    'Captain' => $order->captain->user->name,
                    'Captain Assigned Rule' => $commissionRule?->name,
                    'Iqama Number' => $order->captain->iqama_number,
                    'Employee Id' => $order->captain->code,
                    'Employee Type' => $order->captain->employmentType->name,
                    'Employer' => optional(optional($order->captain->captainThirdParty)->thirdPartCompany)->name,
                    'Work Region' => $order->captain->regions->pluck('quadrant.name')->unique()->join(', '),
                    'Assigned Areas' => $order->captain->regions->pluck('name')->join(', '),
                    'Client Name' => optional($order->client->user)->name,
                    'Shop Name' => optional($order->clientShop)->name,
                    'Order ID' => $order->id,
                    'Order Status' => optional($order->orderStatus)->name,
                    'Delivery KM' => $order->shop_to_delivery_km,
                    'B.D Commission' => $bdCommission,
                    'Extra Km' => $commissionDetails['Extra Km'] ?? 0,
                    'Extra Earning' => $extraEarning,
                    'Other Payable' => 0,
                    'Commission Type' => $orderCommission?->commission_rule_type == 1 ? 'Delivery Based' : 'KPI Based',
                    'Total Payable' => $totalPayable,
                    'Cost/Per Order' => round($commissionDetails['Cost/Per Order'] ?? 0, 2),
                ];

                if ($bonusAmount > 0 && !isset($bonusAdded[$captainId])) {

                    $bonusAdded[$captainId] = true;  
                    $data[] = [
                        'Date' => $deliveryDate->format('m-d-Y'),
                        'Captain' => $order->captain->user->name,
                        'Captain Assigned Rule' => $commissionRule?->name,
                        'Iqama Number' => $order->captain->iqama_number,
                        'Employee Id' => $order->captain->code,
                        'Employee Type' => $order->captain->employmentType->name,
                        'Employer' => optional(optional($order->captain->captainThirdParty)->thirdPartCompany)->name,
                        'Work Region' => $order->captain->regions->pluck('quadrant.name')->unique()->join(', '),
                        'Assigned Areas' => $order->captain->regions->pluck('name')->join(', '),
                        'Client Name' => '',
                        'Shop Name' => '',
                        'Order ID' => '',
                        'Order Status' => '',
                        'Delivery KM' => '',
                        'B.D Commission' => '',
                        'Extra Km' => '',
                        'Extra Earning' => '',
                        'Other Payable' => $bonusAmount,
                        'Commission Type' => 'Bonus',
                        'Total Payable' => $bonusAmount,
                        'Cost/Per Order' => '',
                    ];
                }
            }
            return $data;
        }else{

            $reports = CommissionReport::with([
                'captain:id,code,iqama_number,user_id',
                'captain.user:id,name,email',
                'captain.regions:id,name,quadrant_id',
                'captain.regions.quadrant:id,name',
                'captain.workingLogs' => function ($q) use ($givenDate) {
                $q->whereDate('date', $givenDate)
                    ->select(
                        'captain_id',
                        'seconds_worked',
                        'orders_received',
                        'orders_try_to_accept',
                        'orders_rejected',
                        'orders_expired',
                        'orders_accepted',
                        'orders_delivered',
                        'orders_returned',
                        'orders_cancelled',
                    );
                },
            ])
            ->whereDate('date', $givenDate)
            ->when($reqCaptainId, fn($q) =>
                $q->where('captain_id', $reqCaptainId)
            )

            ->when($reqCode, fn($q) =>
                $q->whereHas('captain', fn($sub) =>
                    $sub->where('code', 'LIKE', "%{$reqCode}%")
                )
            )

            ->when($reqSearch, fn($q) =>
                $q->whereHas('captain.user', fn($sub) =>
                    $sub->where('name', 'LIKE', "%{$reqSearch}%")
                    ->orWhere('email', 'LIKE', "%{$reqSearch}%")
                )
            )

            ->when($reqQuadrantId, fn($q) =>
                $q->whereHas('captain.regions.quadrant', fn($sub) =>
                    $sub->where('quadrants.id', $reqQuadrantId)
                )
            )

            ->when($reqRegionId, fn($q) =>
                $q->whereHas('captain.regions', fn($sub) =>
                    $sub->where('regions.id', $reqRegionId)
                )
            )

            ->when($reqCommissionTypes, fn($q) =>
                $q->where('commission_type', $reqCommissionTypes)
            )

            ->limit($this->chunk)
            ->offset($this->chunk * ($this->export->page_done ?? 0))
            ->get();

            $mappedLogs = $reports->map(function ($report) use ($givenDate) {
                $captain = $report->captain;
                $firstCommission = $report->first_commission;

                // Safely parse the date
                $formattedDate = Carbon::parse($givenDate)->format('m-d-Y');
                $log = $captain?->workingLogs?->first();

                $onlineHours     = $report->online_hours ?? 0;
                $agreedHours     = $report->agreed_hours ?? 0;
                $idleHours       = $report->idle_hours ?? 0;
                $received      = $log?->orders_received ?? 0;
                $tryToAccept   = $log?->orders_try_to_accept ?? 0;
                $rejected      = $log?->orders_rejected ?? 0;
                $expired       = $log?->orders_expired ?? 0;
                $accepted      = $log?->orders_accepted ?? 0;
                $delivered     = $log?->orders_delivered ?? 0;
                $returned      = $log?->orders_returned ?? 0;
                $cancelled     = $log?->orders_cancelled ?? 0;

                //Compute Acceptance Rate (avoid division by zero)
                $acceptanceRate = $received > 0 ? round(($accepted / $received) * 100, 2) : 0;
                $hourCommitment  = $report->hour_commitment ?? 0;
                $successRate     = $report->success_rate ?? 0;
                $avgArrival      = $report->avg_arrival_time ?? 0;
                $avgDelivery     = $report->avg_delivery_time ?? 0;
                $avgDistance     = round($report->avg_delivery_dist ?? 0, 2);
                $deliveryCommission = $report->delivery_commission ?? 0;
                $extraKm         = $report->extra_km ?? 0;
                $kpiBonus        = $report->kpi_bonus ?? 0;
                $totalPayable    = $report->total_payable ?? 0;
                $costPerDelivery = $report->cost_per_delivery ?? 0;
                $sellingPrice    = $report->selling_price ?? 0;
                $pnl             = $report->profit_loss ?? 0;


                return [
                    'date'               => $formattedDate,
                    'captain'            => $captain?->user?->name ?? 'N/A',
                    'commission_type'    => $firstCommission?->commission_rule_type != 2 ? 'Delivery Based' : 'KPI Based',
                    'rule'               => $report->rule ?? 'N/A',
                    'commission_rule_id' => $firstCommission?->commissionRule?->id ?? null,
                    'captain_id'         => $captain->id ?? null,
                    'iqama'              => $captain?->iqama_number ?? 'N/A',
                    'employee_id'        => $captain?->code ?? 'N/A',
                    'employee_type'      => $captain?->employmentType?->name ?? 'N/A',
                    'employer'           => $captain?->captainThirdParty?->thirdPartCompany?->name ?? 'N/A',
                    'regions'            => $captain?->regions?->pluck('quadrant.name')?->unique()?->join(', ') ?? '',
                    'assigned_areas'     => $captain?->regions?->pluck('name')?->join(', ') ?? '',
                    'online_hours'       => $onlineHours,
                    'agreed_hours'       => $agreedHours,
                    'idle_hours'         => $idleHours,
                    'received_orders'    => $received,
                    'try_to_accept'      => $tryToAccept,
                    'rejected_orders'    => $rejected,
                    'expired_orders'     => $expired,
                    'accepted_orders'    => $accepted,
                    'acceptance_rate'    => $acceptanceRate,
                    'hour_commitment'    => $hourCommitment,
                    'delivered_orders'   => $delivered,
                    'returned_orders'    => $returned,
                    'cancelled_orders'   => $cancelled,
                    'success_rate'       => $successRate,
                    'avg_arrival_time'   => $avgArrival,
                    'avg_delivery_time'  => $avgDelivery,
                    'avg_delivery_dist'  => $avgDistance,
                    'delivery_commission'=> $deliveryCommission,
                    'extra_km'           => $extraKm,
                    'kpi_bonus'          => $kpiBonus,
                    'total_payable'      => $totalPayable,
                    'cost_per_delivery'  => $costPerDelivery,
                    'selling_price'      => $sellingPrice,
                    'pnl'                => $pnl,
                ];
            });
            return $mappedLogs->values()->toArray();
        }
    }

    public function headers(): array
    {
        if (isset($this->export->filters['non_aggregated']) && $this->export->filters['non_aggregated']) {
            // Headers for non-aggregated data

            return [
                'Date',
                'Captain',
                'Captain Assigned Rule',
                'Iqama Number',
                'Employee Id',
                'Employee Type',
                'Employer',
                'Work Region',
                'Assigned Areas',
                'Client Name',
                'Shop Name',
                'Order ID',
                'Order Status',
                'Delivery KM',
                'B.D Commission',
                'Extra Km',
                'Extra Earning',
                'Other Payable',
                'Commission Type',
                'Total Payable',
                'Cost/Per Order',
            ];
        }
        return [
            'Date',
            'Captain',
            'Commission Type',
            'Rule',
            'Iqama',
            'Employee ID',
            'Employee Type',
            'Employer',
            'Regions',
            'Assigned Areas',
            'Online Hours',
            'Agreed Hours',
            'Idle Hours',
            'Received Orders',
            'Try to Accept',
            'Rejected Orders',
            'Expired Orders',
            'Accepted Orders',
            'Acceptance Rate (%)',
            'Hour Commitment (%)',
            'Delivered Orders',
            'Returned Orders',
            'Cancelled Orders',
            'Success Rate (%)',
            'Avg Arrival Time',
            'Avg Delivery Time',
            'Avg Delivery Distance (km)',
            'Delivery Commission',
            'Extra KM Earning',
            'Daily Bonus',
            'Total Payable',
            'Cost Per Delivery',
            'Selling Price',
            'Profit Loss',
        ];
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $givenDate = $request['date'] ?? now()->subDay()->format('Y-m-d');
        $givenDate = Carbon::parse($givenDate)->format('Y-m-d');

        $start = Carbon::parse($givenDate)->setTime(6, 0, 0);
        $end   = (clone $start)->addDay()->setTime(5, 59, 59);
        
        $isNonAggregated = isset($request['non_aggregated']) && $request['non_aggregated'];

        if( $isNonAggregated ){
             return $this->totalData;
        }else{
            return CommissionReport::whereDate('date', $givenDate)->count();

        }
    }  
}
