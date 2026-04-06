<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\Order;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SpecialThirdPartyCommissionReportExport extends QueueExport
{
    protected int $chunk = 2000;

    protected string $file_name = 'third-party-commission-report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];

        $orders = $this->getData();

        foreach ($orders as $order) {
            $data[] = [
                now()->parse($order->delivery_date)->format('d/m/Y'),
                $order->id,
                $order->client->user->name ?? 'N/A',
                $order->shop->name ?? 'N/A',
                $order->client_order_id,
                $order->shop_to_delivery_km,
                $order->additional_km,
                $order->delivery_date,
                $order->progress->name ?? 'N/A',
                $order->captain->user->name ?? "N/A",
                $order->captain->iqama_number ?? "N/A",
                $order->basic_delivery_earnings,
                $order->additional_km_earning,
                $order->total_earned_commission,
                $order->balance,
                $order->settled_amount
            ];
        }

        return $data;
    }

    public function headers(): array
    {
        return [
            'Order Date',
            'Order No',
            'Client',
            'Shop',
            'AWB',
            'Shop To Delivery KM',
            'Additional KM',
            'Delivery Date',
            'Status',
            'Captain Name',
            'Iqama Number',
            'Basic Delivery Earnings',
            'Additional KM Earning',
            'Total Earned Commission',
            'Balance',
            'Settled Amount'
        ];
    }

    public function getData()
    {
        $request = $this->export->filters;

        $thirdPartyCompanyId = $request['third_party_company_id'] ?? null;

        $query = Order::query()
            ->select(
                'orders.id',
                'orders.client_id',
                'orders.captain_id',
                'orders.status_id',
                'orders.delivery_date',
                'orders.client_order_id',
                'orders.shopname',
                'orders.shop_to_delivery_km',
                'third_party_commissions.additional_km',
                'third_party_commissions.additional_km_earning',
                'third_party_commissions.basic_delivery_earnings',
                'third_party_commissions.total_earned_commission',
                'third_party_commissions.balance',
                'third_party_commissions.settled_amount'
            )
            ->with([
                'captain.user',
                'client.user',
                'shop',
                'progress',
                'thirdPartyCommission'
            ])
            ->leftJoin('third_party_commissions', 'third_party_commissions.order_id', '=', 'orders.id')
            ->where('third_party_commissions.third_party_company_id', $thirdPartyCompanyId)
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED
            ])
            ->when($request['from_date'] ?? null, function ($query, $from_date) {
                $query->where('orders.delivery_date', '>=', now()->parse($from_date)->format('Y-m-d') . ' 00:00:00');
            })
            ->when($request['to_date'] ?? null, function ($query, $to_date) {
                $query->where('orders.delivery_date', '<=', now()->parse($to_date)->format('Y-m-d') . ' 23:59:59');
            })
            ->when($request['q'] ?? null, function ($query, $q) {
                $query->where('orders.client_order_id', 'LIKE', $q . '%');
            })
            ->when($request['status'] ?? null, function ($query, $status) {
                $query->where('orders.status_id', $status);
            })
            ->when($request['client'] ?? null, function ($query, $client) {
                $query->where('orders.client_id', $client);
            })
            ->when($request['captain_id'] ?? null, function ($query, $captain_id) {
                $query->where('orders.captain_id', $captain_id);
            })
            ->orderBy('third_party_commissions.id', 'desc');

        return $query->limit($this->chunk)
            ->offset($this->chunk * ($this->export->page_done ?? 0))
            ->get();
    }

    public function count(): int
    {
        $request = $this->export->filters;
        $thirdPartyCompanyId = $request['third_party_company_id'] ?? null;

        return Order::query()
            ->join('third_party_commissions', 'third_party_commissions.order_id', '=', 'orders.id')
            ->where('third_party_commissions.third_party_company_id', $thirdPartyCompanyId)
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED
            ])
            ->when($request['from_date'] ?? null, function ($query, $from_date) {
                $query->where('orders.delivery_date', '>=', now()->parse($from_date)->format('Y-m-d') . ' 00:00:00');
            })
            ->when($request['to_date'] ?? null, function ($query, $to_date) {
                $query->where('orders.delivery_date', '<=', now()->parse($to_date)->format('Y-m-d') . ' 23:59:59');
            })
            ->when($request['q'] ?? null, function ($query, $q) {
                $query->where('orders.client_order_id', 'LIKE', $q . '%');
            })
            ->when($request['status'] ?? null, function ($query, $status) {
                $query->where('orders.status_id', $status);
            })
            ->when($request['client'] ?? null, function ($query, $client) {
                $query->where('orders.client_id', $client);
            })
            ->when($request['captain_id'] ?? null, function ($query, $captain_id) {
                $query->where('orders.captain_id', $captain_id);
            })
            ->count();
    }
}