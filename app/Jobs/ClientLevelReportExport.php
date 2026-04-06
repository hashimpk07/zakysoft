<?php
namespace App\Jobs;

use App\Exports\QueueExport;
use App\GeneralExport;
use App\OrderReport;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClientLevelReportExport extends QueueExport
{
    protected string $file_name = 'client_level_report';

    public function __construct(GeneralExport $export)
    {
        parent::__construct($export);
    }

    /**
     * Total rows to export (GROUP COUNT)
     */
    public function count(): int
    {
        return $this->baseQuery()->get()->count();
    }

    /**
     * CSV headers
     */
    public function headers(): array
    {
        return ['Date', 'Client Name', 'Shop ID', 'Shop Name', 'Shop Zone', 'Received Orders', 'Delivered Orders', 'Failed Orders', 'Success Rate', 'Avg of Arrival', 'Avg of Waiting', 'Avg Pickup - Delivery', 'Avg TCT', 'Avg Delivery Distance', 'Delivered Capt. Count'];
    }

    /**
     * Return next chunk of data
     */
    public function data(): array
    {
        $limit  = $this->chunk;
        $offset = $this->export->page_done * $limit;

        return $this->baseQuery()
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                $successRate = $item->received_orders ? round(($item->delivered_orders / $item->received_orders) * 100, 2) . '%' : '0%';

                return [Carbon::parse($item->report_date)->format('d-m-Y'), $item->client->name ?? '-', $item->shop->id ?? '-', $item->shop->name ?? '-', $item->shop->zone->name ?? '-', $item->received_orders, $item->delivered_orders, $item->failed_orders, $successRate, $item->avg_arrival_sec ? gmdate('H:i:s', (int) $item->avg_arrival_sec) : '00:00:00', $item->avg_waiting_sec ? gmdate('H:i:s', (int) $item->avg_waiting_sec) : '00:00:00', $item->avg_pickup_to_delivery_sec ? gmdate('H:i:s', (int) $item->avg_pickup_to_delivery_sec) : '00:00:00', $item->avg_tct_sec ? gmdate('H:i:s', (int) $item->avg_tct_sec) : '00:00:00', $item->avg_distance !== null ? round(max(0, $item->avg_distance), 2) . ' KM' : '0 KM', $item->delivered_captains];
            })
            ->values()
            ->toArray();
    }

    /**
     * Base aggregated query (SCALABLE)
     */
    protected function baseQuery()
    {

        $filters  = $this->export->filters;
        [$from, $to] = $this->generateDateTime();
        $clientId = $filters['client'] ?? false;
        $shopId   = $filters['shopname'] ?? false;

        $delivered      = OrderStatus::DELIVERED;
        $finishedOrders = OrderStatus::FINISHED;

        return  OrderReport::query()
            ->select([
                DB::raw('MIN(created_at) as report_date'),
                'client_id',
                'shop_id',

                DB::raw('COUNT(*) as received_orders'),
                DB::raw("SUM(CASE WHEN status_id = {$delivered} THEN 1 ELSE 0 END) as delivered_orders"),
                DB::raw("SUM(CASE WHEN status_id != {$delivered} THEN 1 ELSE 0 END) as failed_orders"),

                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_created_at, reached_shop_at)) as avg_arrival_sec'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, reached_shop_at, order_picked_at)) as avg_waiting_sec'),
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, order_picked_at, final_status_at)) as avg_pickup_to_delivery_sec'),
                DB::raw("
                    AVG(
                        CASE 
                            WHEN status_id = {$delivered}
                            THEN TIMESTAMPDIFF(SECOND, order_created_at, final_status_at)
                        END
                    ) as avg_tct_sec
                "),

                // prevent negative distance and fetch only delivered orders
                 DB::raw("AVG(CASE WHEN status_id = {$delivered} THEN GREATEST(shop_to_delivery_km, 0)
                            ELSE NULL
                            END
                            ) as avg_distance"),

                DB::raw('COUNT(DISTINCT captain_id) as delivered_captains'),
            ])
            ->whereIn('status_id', $finishedOrders)
            ->whereBetween('created_at', [$from, $to])
            ->when($clientId, fn($q) => $q->where('client_id', $clientId))
            ->when($shopId, fn($q) => $q->where('shop_id', $shopId))
            // GROUPING
            ->groupBy(DB::raw('DATE(created_at)'), 'client_id', 'shop_id')
            // Relations
            ->with([
                'shop' => fn($q) => $q->select('id', 'zone_id', 'name')->with([
                    'zone' => fn($z) => $z->select('id', 'name'),
                ]),
                'client' => fn($q) => $q
                    ->select('id') // required for relation
                    ->withName(), // scope that selects name
            ])
            // Sorting (latest date first)
            ->orderBy('report_date', 'desc');
    }

    private function generateDateTime(): array
    {
        $filters     = $this->export->filters;
        $fromDateRaw = $filters['from_date'] ?? now()->subDays(value: 6)->format('Y-m-d');
        $toDateRaw   = $filters['to_date'] ?? now()->format('Y-m-d');

        $fromTime = $filters['order_time_from'] ?? '06:00 AM';
        $toTime   = $filters['order_time_to'] ?? '05:59 AM';

        $fromDateTime = Carbon::parse("$fromDateRaw $fromTime");
        $toDateTime   = Carbon::parse("$toDateRaw $toTime");

        // Handle overnight time window
        if (Carbon::parse($toTime)->lt(Carbon::parse($fromTime))) {
            $toDateTime->addDay();
        }

        return [$fromDateTime, $toDateTime];
    }
}
