<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use App\Quadrant;
use App\Region;
use Illuminate\Support\Facades\DB;

class ManagementOrderCancellationStatisticsPerHour implements Graph
{
    public function data($request = null)
    {
        // $startDate = request()->has('from_date')
        // ? now()->parse(request()->get('from_date'))->startOfDay()
        // : now()->startOfDay();

        // $endDate = request()->has('to_date')
        // ? now()->parse(request()->get('to_date'))->endOfDay()
        // : now()->endOfDay();

        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        //This is for showing the date range in the dashboard
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime =  \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        //This is for filtering the orders based on the date range
        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate =  $toDateTime->format('Y-m-d H:i:s');

        $quadrant = request()->get('quadrant');

        $colors = [
            '#36a2eb',
            '#ff6384',
            '#ff9f40',
            '#ffcd56',
            '#14b8a6',
            '#6b7280',
            '#1f6f70',
            '#b5d1af',
            '#5f4c60',
            '#97a7c4',
            '#F5921B',
            '#e1a692',
            '#54bebe',
            '#dedad2',
            '#badbdb',
            '#e8daff',
            '#ff8389',
            '#3ddbd9',
            '#20B2AA',
            '#ADD8E6',
            '#90EE90',
            '#FF6347',
        ];

        //$labels = array_map(fn($hour) => str_pad($hour, 2, '0', STR_PAD_LEFT) . ":00", range(0, 23));

        // Generate labels for 24 hours (6 AM to 5 AM next day)
        $hours = array_merge(range(6, 23), range(0, 5));
        $labels = array_map(fn($hour) => str_pad($hour, 2, '0', STR_PAD_LEFT) . ":00", $hours);

        $cancelledOrders = array_fill(0, 24, 0); // Initialize array for 24 hours

        if ($quadrant) {
            // Query for specific quadrant
            $regions = Region::toBase()
                ->select('name', 'id')
                ->where('regions.quadrant_id', $quadrant)
                ->get();

            $data = OrderReport::select(
                DB::raw('COALESCE(shop_region.id, "-1") as region_id'),
                DB::raw('HOUR(order_reports.final_status_at) as hour'),
                DB::raw('SUM(CASE WHEN order_reports.status_id IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as cancelled_orders')
            )
                ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
                ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
                ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
                ->whereRaw('shop_region.quadrant_id = ?', [$quadrant])
                ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
                ->groupByRaw('COALESCE(shop_region.id, "-1"), HOUR(order_reports.final_status_at)')
                ->setBindings([
                    OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED,
                ], 'select')
                ->belongsToMe()
                ->excludeQuadrants('shop_region.quadrant_id')
                ->get()
                ->keyBy(fn($item) => $item->region_id . '_' . $item->hour);

            foreach ($regions as $region) {
                $regionId = $region->id;

                // Loop 6 AM to 5 AM next day
                foreach (array_merge(range(6, 23), range(0, 5)) as $index => $hour) {
                    $key = $regionId . '_' . $hour;
                    $cancelledOrders[$index] += $data[$key]->cancelled_orders ?? 0;
                }
            }
        } else {
            // Query for all quadrants
            $quadrants = Quadrant::excludeQuadrants()->toBase()->select('name', 'id')->get();

            $data = OrderReport::select(
                DB::raw('COALESCE(shop_quadrant.id, "-1") as quadrant_id'),
                DB::raw('HOUR(order_reports.final_status_at) as hour'),
                DB::raw('SUM(CASE WHEN order_reports.status_id IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as cancelled_orders')
            )
                ->leftJoin('client_shops', 'client_shops.id', 'order_reports.shop_id')
                ->leftJoin('zones as shop_zone', 'shop_zone.id', 'client_shops.zone_id')
                ->leftJoin('regions as shop_region', 'shop_region.id', 'shop_zone.region_id')
                ->leftJoin('quadrants as shop_quadrant', 'shop_region.quadrant_id', 'shop_quadrant.id')
                ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
                ->groupByRaw('COALESCE(shop_quadrant.id, "-1"), HOUR(order_reports.final_status_at)')
                ->setBindings([
                    OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED, OrderStatus::FORYOU_RETURN_ACCEPTED,
                ], 'select')
                ->belongsToMe()
                ->excludeQuadrants('shop_region.quadrant_id')
                ->get()
                ->keyBy(fn($item) => $item->quadrant_id . '_' . $item->hour);

            foreach ($quadrants as $quadrant) {
                $quadrantId = $quadrant->id;

                // Loop 6 AM to 5 AM next day
                foreach (array_merge(range(6, 23), range(0, 5)) as $index => $hour) {
                    $key = $quadrantId . '_' . $hour;
                    $cancelledOrders[$index] += $data[$key]->cancelled_orders ?? 0;
                }
            }
        }

        return [
            'colors'    => $colors,
            'labels'    => $labels,
            'cancelled' => $cancelledOrders,
        ];
    }
}
