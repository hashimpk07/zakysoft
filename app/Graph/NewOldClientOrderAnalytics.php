<?php
namespace App\Graph;

use App\OrderReport;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NewOldClientOrderAnalytics implements Graph
{
    public function data($request = null)
    {
        $colors = [
            '#99eca7ff',   // Old Client Deliveries
            '#0ac48cff',   // New Client Deliveries
            '#2d53dcff',   // Total Deliveries
            '#edfc46ff',   // Success Rate
        ];

        $regionId = $request->input('region');

        // Determine reference date
        if ($request->filled('to_date')) {
            $referenceDate = Carbon::parse($request->get('to_date'))->startOfMonth()->subMonth();
        } else {
            $referenceDate = Carbon::now()->startOfMonth()->subMonth();
        }

        // Generate last 12 months range
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $referenceDate->copy()->subMonths($i)->format('Y-m');
        }
        $months = array_reverse($months);

        $startDate = Carbon::parse($months[0] . '-01')->startOfMonth();
        $endDate   = Carbon::parse(end($months) . '-01')->endOfMonth();

        // --- Optimized single query ---
        $aggregated = OrderReport::query()
            ->select(
                DB::raw("DATE_FORMAT(order_reports.final_status_at, '%Y-%m') as order_month"),
                DB::raw("COUNT(*) as total_orders"),
                DB::raw("SUM(CASE WHEN order_reports.status_id = " . OrderStatus::DELIVERED . " THEN 1 ELSE 0 END) as delivered_orders"),
                DB::raw("SUM(CASE WHEN clients.created_at BETWEEN DATE_FORMAT(order_reports.final_status_at, '%Y-%m-01') AND LAST_DAY(order_reports.final_status_at) THEN 1 ELSE 0 END) as new_client_orders"),
                DB::raw("SUM(CASE WHEN clients.created_at < DATE_FORMAT(order_reports.final_status_at, '%Y-%m-01') THEN 1 ELSE 0 END) as old_client_orders")
            )
            ->leftJoin('clients', 'clients.id', '=', 'order_reports.client_id')
            ->leftJoin('client_shops', 'client_shops.id', '=', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->when($regionId, fn($q) => $q->where('shop_region.quadrant_id', $regionId))
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->whereIn('order_reports.status_id', OrderStatus::FINISHED)
            ->groupBy('order_month')
            ->orderBy('order_month')
            ->get()
            ->keyBy('order_month');

        $labels = [];
        $totalOrders = [];
        $deliveredOrders = [];
        $failedOrders = [];
        $newClientOrders = [];
        $oldClientOrders = [];
        $successRates = [];

        foreach ($months as $month) {
            $labels[] = Carbon::parse($month . '-01')->format('M-y');
            $row = $aggregated[$month] ?? null;

            $total = $row->total_orders ?? 0;
            $delivered = $row->delivered_orders ?? 0;
            $failed = max(0, $total - $delivered);
            $newClients = $row->new_client_orders ?? 0;
            $oldClients = $row->old_client_orders ?? 0;
            $successRate = $total > 0 ? round(($delivered / $total) * 100, 2) : 0;

            $totalOrders[] = $total;
            $deliveredOrders[] = $delivered;
            $failedOrders[] = $failed;
            $newClientOrders[] = $newClients;
            $oldClientOrders[] = $oldClients;
            $successRates[] = $successRate;
        }

        return response()->json([
            'colors' => $colors,
            'labels' => $labels,
            'total_orders' => $totalOrders,
            'delivered_orders' => $deliveredOrders,
            'failed_orders' => $failedOrders,
            'new_client_orders' => $newClientOrders,
            'old_client_orders' => $oldClientOrders,
            'success_rate' => $successRates,
        ]);
    }
}
