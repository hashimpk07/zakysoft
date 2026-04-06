<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use Illuminate\Support\Facades\Log;
use App\Client;
use App\ClientShop;

class ShopDetailsExportJob extends QueueExport
{
    protected int $chunk = 1000;
     protected $totalData = 0;
    protected string $file_name = 'shop_detail_list';

    public function data(): array
    {
        $request = $this->export->filters;
        $report = $this->getReport($request);
        return $report->toArray();
    }

    public function getReport($request)
    {
        $clientId     = $request['client_id'] ?? null;
        $reqSearch    = $request['search'] ?? null;


        Log::channel('commission')->info('Clinet  details', [
            'client' => $clientId ,
            'search' => $reqSearch,
            
        ]);

        if (!$clientId) {
            return collect([]);
        }

        $client = Client::find($clientId);
        if (!$client) {
            return collect([]);
        }

        $query = ClientShop::query()
            ->with([
                'zone',
                'dispatchRuleForExpress',
                'createdBy',
                'deliveryChargeRule',
            ])->where('client_id', $client->id);
            
        if ($reqSearch) {
            $query->where(function ($q) use ($reqSearch) {
                $q->where('name', 'like', "%$reqSearch%")
                  ->orWhere('id', $reqSearch)
                  ->orWhere('location', 'like', "%$reqSearch%");
            });
        }

        $query->limit($this->chunk)->offset(($this->export->page_done ?? 0) * $this->chunk);

        $shops = $query->get();
        $this->totalData = $shops->count();

        return $shops->map(function ($shop) {

            $brand = 'No Brand';
            if ($shop->brand) {
                $brandId = str_pad($shop->brand->id, 3, '0', STR_PAD_LEFT);
                $brand = $shop->brand->name_en . " (BRD{$brandId})";
            }
            $zone = $shop->zone->name ?? '';  
            return [
                'date'                => $shop->created_at?->format('d-m-Y h:i:s a') ?? '',
                'name'                => $shop->name ?? '',
                'shop_id'             => sprintf('%04d', $shop->id),
                'location'            => $shop->location ?? '',
                'brand'               => $brand,
                'dispatch_rule'       => $shop->auto_assignable
                                            ? ($shop->dispatchRuleForExpress->name ?? '')
                                            : 'Manual',
                'applied_price_rule'  => $shop->deliveryChargeRule?->name ?? '',
                'internal_reference_id' => $shop->partner_id ?? '',
                'created_by'          => $shop->createdBy?->name ?? '',
                'status'              => $shop->status ?? '',
                'zone'               => $zone,
            ];
        });
        
    }

    public function headers(): array
    {
        return [
            'Date',
            'Name',
            'Shop id',
            'Location',
            'Brand',
            'Dispatch Rule(express)',
            'Applied Price Rule',
            'Internal Reference Id',
            'Created By',
            'Status',
            'Zone'
        ]; 
    }

    public function count(): int
    {
        return $this->totalData;
    }  
}
