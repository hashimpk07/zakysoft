<?php

namespace App\Repositories\General\ClientReports;

use App\Interfaces\General\ClientReports\ClientSaleInterface;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Order;
use App\OrderStatus;

use Illuminate\Pagination\LengthAwarePaginator;

class ClientSaleRepository implements ClientSaleInterface
{
   public function getSales(array $filters,int $perPage): LengthAwarePaginator
{
    return Order::select(
            'id',
            'client_id',
            'status_id',
            'created_at',
            'delivery_date',
            'shop_to_delivery_km',
            'client_order_id',
            'captain_id',
            'shopname' // ✅ fixed (was shop_id)
        )
        ->withClient()
        ->withCaptain()
        ->withCaptainIqamaNo()
        ->withShop()
        ->with('progress:id,name','orderDeliveryCharge') // removed status_class if not exists

        ->when($filters['client'], fn($q)=>$q->where('client_id',$filters['client']))
        ->when($filters['shopname'], fn($q)=>$q->where('shopname',$filters['shopname']))
        ->when($filters['captain'], fn($q)=>$q->where('captain_id',$filters['captain']))
        ->when($filters['orderID'], fn($q)=>$q->whereLike('client_order_id',$filters['orderID']))

        ->withinDateRange($filters['from'],$filters['to'],'orders.delivery_date')

        ->whereIn('status_id',[
            OrderStatus::DELIVERED,
            OrderStatus::CLIENT_RETURN_ACCEPTED,
            OrderStatus::CANCEL_REQUEST_ACCEPTED,
            OrderStatus::CANCEL
        ])

        ->orderBy('delivery_date','desc')
        ->paginate($perPage)
        ->withQueryString();
}

}