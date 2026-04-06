<?php

namespace App\Repositories\General\Reports;

use App\Interfaces\General\Reports\OrderTimeLineReportInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use App\OrderReport;

class OrderTimeLineReportInterfaceRepository implements OrderTimeLineReportInterface
{
    public function getOrderTimeLineReport(array $filters, int $perPage): LengthAwarePaginator
    {
        return OrderReport::query()
            ->select('order_reports.*')
            ->with([
                'order:id,client_order_id,shop_to_delivery_km',
                'createdBy:id,name',
                'assignedBy:id,name',
                'client:id,user_id',
                'client.user:id,name',
                'shop:id,name',
                'orderStatus:id,name',
                'captain:id,user_id,iqama_number',
                'captain.user:id,name'
            ])
            ->belongsToUser(Auth::user())
            ->finishedOrders()
            ->whereBetween('order_reports.final_status_at', [
                $filters['startDateTime'],
                $filters['endDateTime']
            ])
            ->when($filters['client_order_id'] ?? null, function ($query, $term) {
                $query->whereHas('order', function ($q) use ($term) {
                    $q->where('client_order_id', $term);
                });
            })
            ->when($filters['client'] ?? null, fn($q,$client)=>$q->where('client_id',$client))
            ->when($filters['shop'] ?? null, fn($q,$shop)=>$q->where('shop_id',$shop))
            ->orderBy('final_status_at','desc')
            ->paginate($perPage);
    }
}