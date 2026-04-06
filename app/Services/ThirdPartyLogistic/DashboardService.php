<?php

namespace App\Services\ThirdPartyLogistic;

use App\Interfaces\ThirdPartyLogisticInterface;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class DashboardService
{
    public function __construct(protected readonly ThirdPartyLogisticInterface $interface) {}

    public function getCounts(Request $request): array
    {
        $dates = self::getSystemTime($request);
        $companyId = $request->get('company_id_3pl');

        $statuses = $this->interface->getDashboardCounts([...$dates], $companyId);

        $total_orders = $statuses->sum('count');
        $delivery_success = $total_orders > 0 ? round((($statistic[OrderStatus::DELIVERED]['count'] ?? 0) / ($total_orders ?? 1)) * 100, 2) : 0;
        $total_hours = now()
            ->parse($dates['startDate'])
            ->diffInHours(now()->parse($dates['endDate']));
        $avg_orders_per_hr = $total_orders > 0 ? round($total_orders / ($total_hours + 1), 2) : 0;

        return compact('total_orders', 'total_hours', 'avg_orders_per_hr', 'delivery_success', 'statuses');
    }

    protected function getSystemTime(Request $request, int $subDays = 30)
    {
        // If from_date is missing → $subDays  days ago
        $fromDate = $request->get('from_date') ? Carbon::parse($request->get('from_date')) : now()->subDays($subDays);

        // If to_date is missing → now
        $toDate = $request->get('to_date') ? Carbon::parse($request->get('to_date')) : now();

        // Dashboard display range (06:00 AM to next day 05:59 AM)
        $fromDateTime = $fromDate->copy()->setTime(6, 0, 0);
        $toDateTime = $toDate->copy()->addDay()->setTime(5, 59, 59);

        // Filtering values
        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate = $toDateTime->format('Y-m-d H:i:s');

        return compact('startDate', 'endDate');
    }
}
