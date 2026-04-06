<?php
namespace App\Graph;

use Illuminate\Support\Facades\DB;
use App\Client;
use App\Quadrant;

class ActiveInactiveClientByRegion implements Graph
{
    public function data($request = null)
    {
        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate   = $request->get('to_date', now()->format('Y-m-d'));

        // Standardized date range (6 AM to next day 5:59 AM)
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime   = \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate   = $toDateTime->format('Y-m-d H:i:s');

        $colors = [
            '#36a2eb', // Active clients
            '#ff8389', // Inactive clients
            '#3ddbd9', // Total (success rate line)
        ];

        $labels          = [];
        $activeClients   = [];
        $inactiveClients = [];
        $successRate     = [];

        $quadrants = Quadrant::excludeQuadrants()->toBase()->select('name', 'id')->get();

        $data = Client::query()
            ->leftJoin('client_shops', 'client_shops.client_id', '=', 'clients.id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->leftJoinSub(DB::table('order_reports')
            ->select('client_id')
            ->whereBetween('final_status_at', [$startDate, $endDate])
            ->groupBy('client_id'),'active_orders','active_orders.client_id','=','clients.id')
            ->excludeQuadrants('shop_region.quadrant_id')
            ->groupBy(DB::raw('COALESCE(shop_region.quadrant_id, "-1")'))
            ->selectRaw('
                COALESCE(shop_region.quadrant_id, "-1") as quadrant_id,
                COUNT(DISTINCT clients.id) as total_client_count,
                COUNT(DISTINCT active_orders.client_id) as active_client_count
            ')
            ->get()
            ->keyBy('quadrant_id');

        foreach ($quadrants as $quadrant) {
            $qid      = $quadrant->id;
            $total    = $data[$qid]->total_client_count ?? 0;
            $active   = $data[$qid]->active_client_count ?? 0;
            $inactive = $total - $active;

            $labels[]         = $quadrant->name;
            $activeClients[]  = $active;
            $inactiveClients[]= $inactive;
            $successRate[]    = $total;
        }

        return [
            'colors'       => $colors,
            'labels'       => $labels,
            'active'       => $activeClients,
            'inactive'     => $inactiveClients,
            'success_rate' => $successRate,
        ];
    }
}

