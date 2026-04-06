<?php
namespace App\Graph;

use App\Quadrant;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NewOldBranchByRegion implements Graph
{
    public function data($request = null)
    {
        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate   = $request->get('to_date', now()->format('Y-m-d'));

        // Dashboard display range (Carbon objects)
        $fromDateTime = Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime   = Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        // DB filter strings
        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate   = $toDateTime->format('Y-m-d H:i:s');

        $colors = [
            '#36a2eb', // old branch
            '#ff8389', // new branch
            '#3ddbd9', // success rate
        ];

        $labels = $oldDelivery = $newDelivery = $successRate = [];


        $quadrants = Quadrant::excludeQuadrants()->toBase()->select('name', 'id')->get();
        $activeShops = DB::table('order_reports')
            ->selectRaw('DISTINCT client_shops.id as shop_id,
                         COALESCE(shop_region.quadrant_id, -1) as quadrant_id,
                         client_shops.created_at as shop_created_at')
            ->join('client_shops', 'client_shops.id', '=', 'order_reports.shop_id')
            ->leftJoin('zones as shop_zone', 'shop_zone.id', '=', 'client_shops.zone_id')
            ->leftJoin('regions as shop_region', 'shop_region.id', '=', 'shop_zone.region_id')
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->get()
            ->groupBy('quadrant_id'); 
            
        foreach ($quadrants as $quadrant) {
            $qid = $quadrant->id;

            $shops = $activeShops[$qid] ?? collect();

            $activeCount = $shops->pluck('shop_id')->unique()->count();

            $oldCount = $shops->filter(function ($r) use ($fromDateTime) {
                return Carbon::parse($r->shop_created_at)->lt($fromDateTime);
            })->pluck('shop_id')->unique()->count();

            $newCount = $shops->filter(function ($r) use ($fromDateTime, $toDateTime) {
                $created = Carbon::parse($r->shop_created_at);
                return $created->between($fromDateTime, $toDateTime);
            })->pluck('shop_id')->unique()->count();

            $labels[] = $quadrant->name;
            $oldDelivery[] = $oldCount;
            $newDelivery[] = $newCount;
            $successRate[] = $activeCount;
        }

        return [
            'colors'            => $colors,
            'labels'            => $labels,
            'oldClientDelivery' => $oldDelivery,
            'newClientDelivery' => $newDelivery,
            'success_rate'      => $successRate,
        ];
    }
}
