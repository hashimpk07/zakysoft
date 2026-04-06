<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\OrderReport;
use App\OrderStatus;
use App\User;
use Carbon\Carbon;

class OrderTimeLineV2ReportExport extends QueueExport
{
    protected int $chunk = 2000;

    protected string $file_name = 'order-performance';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $orders = $this->getOrders();
        foreach ($orders as $order) {
            $data[] = [
                $order->order->id,
                $order->createdBy->name ?? '',
                $order->order->client_order_id,
                $order->client->user->name ?? '',
                $order->shop->name ?? '',
                $order->shop->zone->name ?? '',
                $order->shop->zone->region->name ?? '',
                $order->captain->company->name ?? ($order->captain->employmentType->name ?? ''),
                $order->captain->user->name ?? '',
                $order->captain->iqama_number ?? '',
                $order->captain && $order->captain->regions ? $order->captain->regions->pluck('name')->unique()->join(', ') : "",
                $order->captain && $order->captain->regions ? $order->captain->regions->pluck('quadrant.name')->unique()->join(', ') : "",
                $order->assigned(),
                $order->orderStatus->name ?? '',
                $order->created_at_date,
                $order->created_at_time,
                $order->formattedDateReport("order_accepted_at"),
                $order->formattedDateReport("order_accepted_at", true),
                $order->acceptanceTime(),
                $order->formattedDateReport('start_ride_at'),
                $order->formattedDateReport('start_ride_at', true),
                $order->formattedDateReport('reached_shop_at'),
                $order->formattedDateReport('reached_shop_at', true),
                $order->reachedShopTime(),
                $order->formattedDateReport('order_picked_at'),
                $order->formattedDateReport('order_picked_at', true),
                $order->orderPickedTime(),
                $order->formattedDateReport('shipped_at'),
                $order->formattedDateReport('shipped_at', true),
                $order->formattedDateReport('reached_dest_at'),
                $order->formattedDateReport('reached_dest_at', true),
                $order->formattedDateReport('final_status_at'),
                $order->formattedDateReport('final_status_at', true),
                $order->deliveringTime(),
                $order->shop_to_delivery_km,
                $order->processingTime(),
                $order->ticket ?? '',
                $order->pending_ticket ?? '',
                $order->client_ticket ?? '',
                $order->cancellation_reason ?? '',
            ];
        }

        return $data;
    }

    public function headers(): array
    {
        return [
            'Order ID',
            'Order Created By',
            'Client Order Id',
            'Client Name',
            'Shop Name',
            'Shop Zone',
            'Shop Area',
            'Captain Employment type',
            'Captain',
            'Iqama No',
            'Work Area',
            'Work Region',
            'Assigned by',
            'Order Status',
            'Date',
            'New Order (Created At) Time',
            'Order Accepted Date',
            'Order Accepted Time',
            'Acceptance time',
            'Start Ride Date',
            'Start Ride Time',
            'Reached Shop Date',
            'Reached Shop Time',
            'Reached Time',
            'Order Picked Date',
            'Order Picked Time',
            'Picked Time',
            'Shipped Date',
            'Shipped Time',
            'Reached Destination Date',
            'Reached Destination Time',
            'Final Status',
            'Final Status Time',
            'Pickup to Delivery Time',
            'Distance B/W',
            'Process time(in Minutes)',
            'Ticket',
            'Pending Ticket',
            'Client Ticket',
            'Cancellation Reason'
        ];
    }

    public function getOrders()
    {
        $request = $this->export->filters;

        $client = isset($request['client']) ? $request['client'] : null;
        $shop = isset($request['shop']) ? $request['shop'] : null;
        $term = isset($request['client_order_id']) ? $request['client_order_id'] : '';

        $fromDate = $request['from_date'] ?? now()->subDays(6)->format('Y-m-d');
        $toDate = $request['to_date'] ?? now()->format('Y-m-d');
        $orderTimeFrom = $request['order_time_from'] ?? '06:00:00';
        $orderTimeTo = $request['order_time_to'] ?? '05:59:59';

        $startDateTime = now()->parse($fromDate . ' ' . $orderTimeFrom)->format('Y-m-d H:i:s');
        $endDateTime = now()->parse($toDate . ' ' . $orderTimeTo)->addDay()->format('Y-m-d H:i:s');

        $orders = OrderReport::query()
            ->select('order_reports.*')
            ->with(
                'order',
                'assignedBy',
                'createdBy',
                'client:id,user_id',
                'client.user:id,name',
                'shop:id,name,zone_id',
                'shop.zone:id,name,region_id',
                'shop.zone.region:id,name',
                'orderStatus:id,name',
                'captain:id,user_id,captain_employment_type_id,iqama_number',
                'captain.user:id,name',
                'captain.employmentType',
                'captain.regions:id,name,quadrant_id',
                'captain.regions.quadrant:id,name',
                'captain.company:third_party_logistic_companies.id,third_party_logistic_companies.name',
            )
            ->when($client, function ($query, $client) {
                return $query->where('order_reports.client_id', $client);
            })
            ->when($shop, function ($query, $shop) {
                return $query->where('order_reports.shop_id', $shop);
            })
            ->whereBetween('order_reports.final_status_at', [$startDateTime, $endDateTime])
            // ->when($start_date, function ($query, $start_date) use ($order_time_from) {
            //     $start_date = now()->parse($start_date . ' ' . $order_time_from)->format('Y-m-d H:i:s');
            //     return $query->where('order_reports.final_status_at', '>=', $start_date);
            // })
            // ->when($end_date, function ($query, $end_date) use ($order_time_to) {
            //     $end_date = now()->parse($end_date . ' ' . $order_time_to)->format('Y-m-d H:i:s');
            //     return $query->where('order_reports.final_status_at', '<=', $end_date);
            // })
            ->when($term, function ($query, $term) {
                return $query->whereHas('order', function ($query) use ($term) {
                    $query->where('client_order_id', $term);
                });
            })
            ->whereIn('order_reports.status_id', [OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED])
            ->orderBy('order_reports.final_status_at', 'desc')
            ->belongsToUser(User::find($this->export->created_by))
            ->limit($this->chunk)
            ->offset($this->chunk * $this->export->page_done ?? 0)
            ->get();

        return $orders;
    }

    public function count(): int
    {
        $request = $this->export->filters;

        $client = isset($request['client']) ? $request['client'] : null;
        $shop = isset($request['shop']) ? $request['shop'] : null;

        $term = isset($request['client_order_id']) ? $request['client_order_id'] : '';

        $fromDate = $request['from_date'] ?? now()->subDays(6)->format('Y-m-d');
        $toDate = $request['to_date'] ?? now()->format('Y-m-d');
        $orderTimeFrom = $request['order_time_from'] ?? '06:00:00';
        $orderTimeTo = $request['order_time_to'] ?? '05:59:59';

        $startDateTime = now()->parse($fromDate . ' ' . $orderTimeFrom)->format('Y-m-d H:i:s');
        $endDateTime = now()->parse($toDate . ' ' . $orderTimeTo)->addDay()->format('Y-m-d H:i:s');

        return OrderReport::query()
            ->when($client, function ($query, $client) {
                return $query->where('order_reports.client_id', $client);
            })
            ->when($shop, function ($query, $shop) {
                return $query->where('order_reports.shop_id', $shop);
            })
            ->whereBetween('order_reports.final_status_at', [$startDateTime, $endDateTime])
            ->when($term, function ($query, $term) {
                return $query->where('order_reports.client_order_id', $term);
            })
            ->whereIn('order_reports.status_id', [OrderStatus::DELIVERED, OrderStatus::CANCEL, OrderStatus::CLIENT_RETURN_ACCEPTED, OrderStatus::CANCEL_REQUEST_ACCEPTED])
            ->belongsToUser(User::find($this->export->created_by))
            ->count() ?? 0;
    }

}
