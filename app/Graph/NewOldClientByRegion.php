<?php
namespace App\Graph;

use App\Client;
use App\Quadrant;
use Illuminate\Support\Facades\DB;

class NewOldClientByRegion implements Graph
{
    public function data($request = null)
    {
        $fromDate = $request->get('from_date', now()->format('Y-m-d'));
        $toDate   = $request->get('to_date', now()->format('Y-m-d'));

        $fromDateTime = \Carbon\Carbon::parse($fromDate)->setTime(6, 0, 0);
        $toDateTime   = \Carbon\Carbon::parse($toDate)->addDay()->setTime(5, 59, 59);

        $startDate = $fromDateTime->format('Y-m-d H:i:s');
        $endDate   = $toDateTime->format('Y-m-d H:i:s');

        $colors = ['#36a2eb','#ff8389','#3ddbd9'];

        $quadrants = Quadrant::excludeQuadrants()->select('id','name')->get();

       
        $activeClients = DB::table('order_reports')
            ->selectRaw('COALESCE(shop_region.quadrant_id, -1) as quadrant_id')
            ->selectRaw('order_reports.client_id')
            ->join('clients','clients.id','=','order_reports.client_id')
            ->leftJoin('client_shops','client_shops.client_id','=','clients.id')
            ->leftJoin('zones as shop_zone','shop_zone.id','=','client_shops.zone_id')
            ->leftJoin('regions as shop_region','shop_region.id','=','shop_zone.region_id')
            ->whereBetween('order_reports.final_status_at', [$startDate, $endDate])
            ->distinct()
            ->get()
            ->groupBy('quadrant_id');

       
        $labels = $oldDelivery = $newDelivery = $successRate = [];

        foreach ($quadrants as $quadrant) {
            $qid = $quadrant->id;

            $clients = $activeClients[$qid] ?? collect();

            $old = $clients->filter(fn($c) => \Carbon\Carbon::parse(
                Client::find($c->client_id)->created_at
            )->lt($startDate))->count();

            $new = $clients->filter(fn($c) => \Carbon\Carbon::parse(
                Client::find($c->client_id)->created_at
            )->between($startDate, $endDate))->count();

            $active = $clients->count();

            $labels[] = $quadrant->name;
            $oldDelivery[] = $old;
            $newDelivery[] = $new;
            $successRate[] = $active; 
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
