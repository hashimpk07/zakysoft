<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OrderAnalytics implements Graph
{
    public function data($request = null)
    {
        $colors = [
            '#3be663ff',   // Delivered
            '#f93b4eff',   // Failed
            '#2d53dcff',   // Total Orders
            '#edfc46ff',   // Success Rate
        ];

        $regionId = $request->input('region');
        $clientId = $request->input('client');

        // Reference date = current month or to_date minus 1 month to exclude current month
        $referenceDate = $request->filled('to_date')
            ? Carbon::parse($request->get('to_date'))->startOfMonth()->subMonth()
            : Carbon::now()->startOfMonth()->subMonth();

        // Generate previous 12 months excluding current month
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $referenceDate->copy()->subMonths($i)->format('Y-m');
        }
        $months = array_reverse($months);

        $startDate = Carbon::parse($months[0] . '-01')->startOfMonth();
        $endDate   = Carbon::parse(end($months) . '-01')->endOfMonth();

        // Single optimized query to get month-wise totals
        $orders = OrderReport::query()
            ->select(
                DB::raw("DATE_FORMAT(order_reports.created_at, '%Y-%m') as order_month"),
                DB::raw("COUNT(*) as total_orders"),
                DB::raw("SUM(CASE WHEN order_reports.status_id = " . OrderStatus::DELIVERED . " THEN 1 ELSE 0 END) as delivered_orders")
            )
            ->leftJoin('client_shops', 'client_shops.id', '=', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->whereBetween('order_reports.created_at', [$startDate, $endDate])
            ->when($regionId, fn($q) => $q->where('shop_region.quadrant_id', $regionId))
            ->when($clientId, fn($q) => $q->where('order_reports.client_id', $clientId))
            ->groupBy('order_month')
            ->orderBy('order_month')
            ->get()
            ->keyBy('order_month');

        $labels = [];
        $totalOrders = [];
        $deliveredOrders = [];
        $failedOrders = [];
        $successRates = [];

        foreach ($months as $month) {
            $labels[] = Carbon::parse($month . '-01')->format('M-y');
            $row = $orders[$month] ?? null;

            $total = $row->total_orders ?? 0;
            $delivered = $row->delivered_orders ?? 0;
            $failed = max(0, $total - $delivered);
            $successRate = $total > 0 ? round(($delivered / $total) * 100, 2) : 0;

            $totalOrders[] = $total;
            $deliveredOrders[] = $delivered;
            $failedOrders[] = $failed;
            $successRates[] = $successRate;
        }

        return [
            'colors' => $colors,
            'labels' => $labels,
            'total_orders' => $totalOrders,
            'delivered_orders' => $deliveredOrders,
            'failed_orders' => $failedOrders,
            'success_rate' => $successRates,
        ];
    }
}
