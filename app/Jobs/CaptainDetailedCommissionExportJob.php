<?php

// namespace App\Jobs;

// use App\Captain;
// use App\CaptainWorkingLog;
// use App\Order;
// use App\Exports\QueueExport;
// use Carbon\Carbon;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;

// class CaptainDetailedCommissionExportJob extends QueueExport
// {
//     protected int $chunk = 1000;

//     protected int $totalData = 0;

//     protected string $file_name = 'Detailed_Captain_Commission_Report';

//     public function data(): array
//     {

//         $performance_reports = $this->getReport();

//         $data = [];
//         foreach ($performance_reports as $report) {
//             $data[] = [
//                 $report['Date'],
//                 $report['Captain'],
//                 $report['Captain Assigned Rule'],
//                 $report['Iqama Number'],
//                 $report['Employee Id'],
//                 $report['Employee Type'] ?? 'N/A',
//                 $report['Employer'] ?? 'N/A',
//                 $report['Work Region'],
//                 $report['Assigned Areas'],
//                 $report['Client Name'],
//                 $report['Shop Name'],
//                 $report['Order ID'],
//                 $report['Order Status'],
//                 $report['Delivery KM'],
//                 $report['B.D Commission'],
//                 $report['Extra Km'],
//                 $report['Extra Earning'],
//                 $report['Other Payable'],
//                 $report['Commission Type'],
//                 $report['Total Payable'],
//                 $report['Cost/Per Order'],
//             ];
//         }

//         return $data;
//     }

//     public function getReport(): array
//     {
//         $request = $this->export->filters;
//         $commissionRuleId = $request['commissionRuleId'] ?? null;
//         $captainId = $request['captainId'] ?? null;
//         $date = $request['date'] ?? now()->subDay()->format('Y-m-d');

//         // Business day window
//         $businessDayStart = Carbon::parse($date)->startOfDay()->addHours(6); // 06:00 AM
//         $businessDayEnd   = $businessDayStart->copy()->addDay()->subSecond(); // Next day 05:59:59
//         // Build query from Orders table
//         $ordersQuery = Order::query()
//             ->with([
//                 'captain.user:id,name', // Captain -> User name
//                 'captain.employmentType:id,name',
//                 'captain.captainThirdParty.thirdPartCompany:id,name',
//                 'captain.regions.quadrant',
//                 //'captain.regions:id,name',
//                 'client.user:id,name',
//                 'clientShop:id,name',
//                 'orderStatus:id,name',
//                 'orderDeliveryCharge:id,order_id,basic_delivery_charge,additional_km,additional_km_earning',
//                 'captain.bonusForDate' => function ($q) use ($date) {
//                     $q->select('id', 'captain_id', 'amount')
//                     ->whereDate('bonus_date', $date)
//                     ->latest('id');
//                 },
//                 'captain.commissionRule' => fn($q) =>
//                     $q->where('id', $commissionRuleId),
//             ])
//             // ->join('captains', 'captains.id', '=', 'orders.captain_id')
//             // ->join('users', 'users.id', '=', 'captains.user_id')
//             // ->where('captains.id', $captainId)
//             ->whereHas('captain', fn($q) => $q->where('id', $captainId))
//             ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd]);

//         //total data count
//         $this->totalData = $ordersQuery->count();

//         // Fetch results
//         $page  = $this->export->page_done ?? 0;
//         $orders = $ordersQuery
//                 ->limit($this->chunk)
//                 ->offset($this->chunk * $page)
//                 ->get();

//         // Transform results to required output
//         $data = $orders->map(function ($order) {
//             $commissionRule = $order->captain->commissionRule; // pick the first commission if multiple

//             $deliveredAt = Carbon::parse($order->delivery_date); // use actual delivered_at timestamp
//             $startOfDay  = $deliveredAt->copy()->startOfDay()->addHours(6);

//             if ($deliveredAt->lt($startOfDay)) {
//                 // falls into previous day’s business day
//                 $businessDay = $deliveredAt->copy()->subDay()->toDateString();
//             } else {
//                 $businessDay = $deliveredAt->toDateString();
//             }

//             $commissionDetails = (new \App\Http\Controllers\CaptainCommissionReportController)->calculateCommission($order, $order->captain, $businessDay, $commissionRule);

//             return [
//                 'Date'                 => Carbon::parse($order->delivery_date)->format('m-d-Y'),
//                 'Captain'              => $order->captain->user->name ?? null,
//                 'Captain Assigned Rule'=> $commissionRule?->name,
//                 'Iqama Number'         => $order->captain->iqama_number ?? null,
//                 'Employee Id'          => $order->captain->code ?? null,
//                 'Employee Type'        => $order->captain->employmentType->name ?? null,
//                 'Employer'             => $order->captain->captainThirdParty->thirdPartCompany->name ?? null,
//                 'Work Region'          => $order->captain->regions->pluck('quadrant.name')->unique()->join(', '),
//                 'Assigned Areas'       => $order->captain->regions->pluck('name')->join(', '),
//                 'Client Name'          => $order->client->user->name ?? null,
//                 'Shop Name'            => $order->clientShop->name ?? null,
//                 'Order ID'             => $order->id,
//                 'Order Status'         => $order->orderStatus->name ?? null,
//                 'Delivery KM'          => $order->shop_to_delivery_km,
//                 'B.D Commission'       => $commissionDetails['B.D Commission'] ?? 0,
//                 'Extra Km'             => $commissionDetails['Extra Km'] ?? 0,
//                 'Extra Earning'        => $commissionDetails['Extra Earning'] ?? 0,
//                 'Other Payable'        => $order->captain?->bonusForDate?->amount ?? 0,
//                 'Commission Type'      => $commissionRule?->commission_type == 1 ? 'Delivery Based' : 'KPI Based',
//                 'Total Payable'        => $commission?->balance ?? 0,
//                 'Cost/Per Order'       => $commissionDetails['Cost/Per Order'] ?? 0,
//             ];
//         });

//         return $data->values()->toArray();
//     }

//     public function headers(): array
//     {
//         return [
//             'Date',
//             'Captain',
//             'Captain Assigned Rule',
//             'Iqama Number',
//             'Employee Id',
//             'Employee Type',
//             'Employer',
//             'Work Region',
//             'Assigned Areas',
//             'Client Name',
//             'Shop Name',
//             'Order ID',
//             'Order Status',
//             'Delivery KM',
//             'B.D Commission',
//             'Extra Km',
//             'Extra Earning',
//             'Other Payable',
//             'Commission Type',
//             'Total Payable',
//             'Cost/Per Order',
//         ];
//     }

//     public function count(): int
//     {
//         return $this->totalData;
//     }

//     function formatMinutesToHuman($minutes)
//     {
//         $minutes = round($minutes); // round to nearest minute

//         if ($minutes < 60) {
//             return $minutes . ' mins';
//         }

//         $hours = floor($minutes / 60);
//         $mins  = $minutes % 60;

//         return $hours . 'h ' . ($mins > 0 ? $mins . 'm' : '');
//     }
// };



namespace App\Jobs;

use App\Captain;
use App\CaptainWorkingLog;
use App\Order;
use App\OrderStatus;
use App\Exports\QueueExport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CaptainDetailedCommissionExportJob extends QueueExport
{
    protected int $chunk = 1000;

    protected int $totalData = 0;

    protected string $file_name = 'Detailed_Captain_Commission_Report';

    public function data(): array
    {

        $performance_reports = $this->getReport();

        $data = [];
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

        return $data;
    }

    public function getReport(): array
    {
        $request = $this->export->filters;
        $commissionRuleId = $request['commissionRuleId'] ?? null;
        $captainId = $request['captainId'] ?? null;
        $date = $request['date'] ?? now()->subDay()->format('Y-m-d');

        // Business day window
        $businessDayStart = Carbon::parse($date)->startOfDay()->addHours(6); // 06:00 AM
        $businessDayEnd = $businessDayStart->copy()->addDay()->subSecond(); // Next day 05:59:59
        // Build query from Orders table
        $ordersQuery = Order::query()
            ->with([
                'captain.user:id,name', // Captain -> User name
                'captain.employmentType:id,name',
                'captain.captainThirdParty.thirdPartCompany:id,name',
                'captain.regions.quadrant',
                //'captain.regions:id,name',
                'client.user:id,name',
                'clientShop:id,name',
                'orderStatus:id,name',
                'orderDeliveryCharge:id,order_id,basic_delivery_charge,additional_km,additional_km_earning',
                'captain.bonusForDate' => function ($q) use ($date) {
                    $q->select('id', 'captain_id', 'amount')
                        ->whereDate('bonus_date', $date)
                        ->latest('id');
                },
                'captain.commissionRule' => fn($q) =>
                    $q->where('id', $commissionRuleId),
            ])
            // ->join('captains', 'captains.id', '=', 'orders.captain_id')
            // ->join('users', 'users.id', '=', 'captains.user_id')
            // ->where('captains.id', $captainId)
            ->whereHas('captain', fn($q) => $q->where('id', $captainId))
            ->whereBetween('orders.delivery_date', [$businessDayStart, $businessDayEnd]);

        //total data count
        $this->totalData = $ordersQuery->count();

        // Fetch results
        $page = $this->export->page_done ?? 0;
        $orders = $ordersQuery
            ->limit($this->chunk)
            ->offset($this->chunk * $page)
            ->get();
        $data = [];
        // Transform results to required output
        // $data = $orders->map(function ($order) {
        foreach ($orders as $order) {
            $commissionRule = $order->captain->commissionRule; // pick the first commission if multiple
            $orderCommission = $order->captainCommission;

               $deliveryDateTime = Carbon::parse($order->delivery_date);
            $businessDate = $deliveryDateTime->copy()->lt($deliveryDateTime->copy()->startOfDay()->addHours(6))
            ? $deliveryDateTime->copy()->subDay()->format('Y-m-d')
            : $deliveryDateTime->format('Y-m-d');
            $commissionDetails = (new \App\Http\Controllers\CaptainCommissionReportController)->calculateCommission($order, $order->captain, $businessDate, $commissionRule);

            $bonusAmount = $order->captain?->bonusForDate?->amount ?? 0;
            $bdCommission = $commissionDetails['B.D Commission'] ?? 0;
            $extraEarning = $commissionDetails['Extra Earning'] ?? 0;
            $totalPayable = $bdCommission + $extraEarning;

            
            // $isDeliveryBased = $commissionRule?->commission_type == 1;
            $isDeliveryBased = $orderCommission?->commission_rule_type == 1;
            $isKpiBased = $orderCommission?->commission_rule_type == 2;

            $data[] = [
                'Date' => Carbon::parse($order->delivery_date)->format('m-d-Y'),
                'Captain' => $order->captain->user->name ?? null,
                'Captain Assigned Rule' => $commissionRule?->name,
                'Iqama Number' => $order->captain->iqama_number ?? null,
                'Employee Id' => $order->captain->code ?? null,
                'Employee Type' => $order->captain->employmentType->name ?? null,
                'Employer' => $order->captain->captainThirdParty->thirdPartCompany->name ?? null,
                'Work Region' => $order->captain->regions->pluck('quadrant.name')->unique()->join(', '),
                'Assigned Areas' => $order->captain->regions->pluck('name')->join(', '),
                'Client Name' => $order->client->user->name ?? null,
                'Shop Name' => $order->clientShop->name ?? null,
                'Order ID' => $order->id,
                'Order Status' => $order->orderStatus->name ?? null,
                'Delivery KM' => $order->shop_to_delivery_km,
                'B.D Commission' =>  $isDeliveryBased ? $commissionDetails['B.D Commission'] : 0,
                'Extra Km' => $commissionDetails['Extra Km'] ?? 0,
                'Extra Earning' => $commissionDetails['Extra Earning'] ?? 0,
                'Other Payable' => 0,
                'Commission Type' => $isDeliveryBased ? 'Delivery Based' : 'KPI Based',
                'Total Payable' => $isDeliveryBased ? $totalPayable : 0,
                'Cost/Per Order' => $order->status_id == OrderStatus::DELIVERED ? round($commissionDetails['Cost/Per Order'], 2) ?? 0 : 0,round($commissionDetails['Cost/Per Order'], 2) ?? 0,
            ];
        }
        // Add bonus row for delivery based if bonus exists
        if ($orders->count() > 0) {
            $firstOrder = $orders->first();
            $commissionRule = $firstOrder->captain->commissionRule;
            $bonusAmount = $firstOrder->captain?->bonusForDate?->amount ?? 0;
            $orderCommission = $firstOrder->captainCommission;


            $isDeliveryBased = $orderCommission?->commission_rule_type == 1;
            $isKpiBased = $orderCommission?->commission_rule_type == 2;

                 $deliveredAt = Carbon::parse($firstOrder->delivery_date); // use actual delivered_at timestamp
                    $startOfDay  = $deliveredAt->copy()->startOfDay()->addHours(6);

                    if ($deliveredAt->lt($startOfDay)) {
                        // falls into previous day’s business day
                        $businessDay = $deliveredAt->copy()->subDay()->toDateString();
                    } else {
                        $businessDay = $deliveredAt->toDateString();
                    }

                    $commissionDetails = (new \App\Http\Controllers\CaptainCommissionReportController)->calculateCommission($firstOrder, $firstOrder->captain, $businessDate, $commissionRule);

                $bdCommission = $commissionDetails['B.D Commission'] ?? 0;
            $type = $commissionDetails['type'] ?? null;
            if ($isDeliveryBased && $bonusAmount > 0) {
                $data[] = [
                    'Date' => Carbon::parse($firstOrder->delivery_date)->format('m-d-Y'),
                    'Captain' => $firstOrder->captain->user->name ?? null,
                    'Captain Assigned Rule' => $commissionRule?->name,
                    'Iqama Number' => $firstOrder->captain->iqama_number ?? null,
                    'Employee Id' => $firstOrder->captain->code ?? null,
                    'Employee Type' => $firstOrder->captain->employmentType->name ?? null,
                    'Employer' => $firstOrder->captain->captainThirdParty->thirdPartCompany->name ?? null,
                    'Work Region' => $firstOrder->captain->regions->pluck('quadrant.name')->unique()->join(', '),
                    'Assigned Areas' => $firstOrder->captain->regions->pluck('name')->join(', '),
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
            }   else if ($type === 'Kpi_Normal') {
                $data[] = [
                        'Date' => Carbon::parse($firstOrder->delivery_date)->format('m-d-Y'),
                        'Captain' => $firstOrder->captain->user->name ?? null,
                        'Captain Assigned Rule' => $commissionRule?->name,
                        'Iqama Number' => $firstOrder->captain->iqama_number ?? null,
                        'Employee Id' => $firstOrder->captain->code ?? null,
                        'Employee Type' => $firstOrder->captain->employmentType->name ?? null,
                        'Employer' => $firstOrder->captain->captainThirdParty->thirdPartCompany->name ?? null,
                        'Work Region' => $firstOrder->captain->regions->pluck('quadrant.name')->unique()->join(', '),
                        'Assigned Areas' => $firstOrder->captain->regions->pluck('name')->join(', '),
                        'Client Name' => '',
                        'Shop Name' => '',
                        'Order ID' => '',
                        'Order Status' => '',
                        'Delivery KM' => '',
                        'B.D Commission' => '',
                        'Extra Km' => '',
                        'Extra Earning' => '',
                        'Other Payable' => $bdCommission ?? 0,
                        'Commission Type' => 'KPI Based',
                        'Total Payable' => $bdCommission ?? 0,
                        'Cost/Per Order' => '',
                ];
            }
        }

        return $data;
    }

    public function headers(): array
    {
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

    public function count(): int
    {
        return $this->totalData;
    }


}
