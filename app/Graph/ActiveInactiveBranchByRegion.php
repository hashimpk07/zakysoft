<?php
namespace App\Graph;

use App\ClientShop;
use Illuminate\Support\Facades\DB;
use App\Quadrant;

class ActiveInactiveBranchByRegion implements Graph
{
    public function data($request = null)
    {
        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate   = $request->get('to_date', now()->format('Y-m-d'));

        // Standardized date range (6 AM → next day 5:59 AM)
        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime   = \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate   = $toDateTime->format('Y-m-d H:i:s');

        $colors = [
            '#36a2eb', // Active Branches
            '#ff8389', // Inactive Branches
            '#3ddbd9', // Total (success rate line)
        ];

        $labels           = [];
        $activeBranches   = [];
        $inactiveBranches = [];
        $successRate      = [];

        $quadrants = Quadrant::excludeQuadrants()->toBase()->select('name', 'id')->get();

        $data = $data = ClientShop::query()
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->leftJoinSub(DB::table('order_reports')
                ->select('shop_id')
                ->whereBetween('final_status_at', [$startDate, $endDate])
                ->groupBy('shop_id'),'active_orders','active_orders.shop_id','=','client_shops.id'
            )
            ->excludeQuadrants('shop_region.quadrant_id')
            ->belongsToMe()
            ->groupBy(DB::raw('COALESCE(shop_region.quadrant_id, -1)'))
            ->selectRaw('
                COALESCE(shop_region.quadrant_id, -1) as quadrant_id,
                COUNT(DISTINCT client_shops.id) as total_branch_count,
                COUNT(DISTINCT active_orders.shop_id) as active_branch_count
            ')
            ->get()
            ->keyBy('quadrant_id');

        foreach ($quadrants as $quadrant) {
            $qid      = $quadrant->id;
            $total    = $data[$qid]->total_branch_count ?? 0;
            $active   = $data[$qid]->active_branch_count ?? 0;
            $inactive = $total - $active;

            $labels[]           = $quadrant->name;
            $activeBranches[]   = $active;
            $inactiveBranches[] = $inactive;
            $successRate[]      = $total;
        }

        return [
            'colors'       => $colors,
            'labels'       => $labels,
            'active'       => $activeBranches,
            'inactive'     => $inactiveBranches,
            'success_rate' => $successRate,
        ];
    }
}
