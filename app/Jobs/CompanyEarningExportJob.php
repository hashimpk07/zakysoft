<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\Order;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CompanyEarningExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected string $file_name = 'Company Earning Report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];
        $orders = $this->getReport();

        foreach ($orders as $order) {
            $data[] = [
                'order_date' => $order->delivery_date ? Carbon::parse($order->delivery_date)->format('d/m/Y') : 'N/A',
                'order_no' => $order->id,
                'client' => $order->client->user->name ?? 'N/A',
                'shop' => $order->shop->name ?? 'N/A',
                'awb' => $order->client_order_id,
                'shop_to_delivery_km' => $order->shop_to_delivery_km,
                'additional_km' => $order->thirdPartyCommission->additional_km ?? 0,
                'delivery_date' => $order->delivery_date,
                'status' => $order->progress->name ?? 'N/A',
                'captain_name' => $order->captain->user->name ?? 'N/A',
                'iqama_number' => $order->captain->iqama_number ?? 'N/A',
                'basic_delivery_earnings' => $order->thirdPartyCommission->basic_delivery_earnings ?? 0,
                'additional_km_earning' => $order->thirdPartyCommission->additional_km_earning ?? 0,
                'total_earned_commission' => $order->total_earned_commission ?? 0,
                'balance' => $order->balance ?? 0,
                'settled_amount' => $order->settled_amount ?? 0,
            ];
        }

        Log::info('CompanyEarningExportJob chunk processed', [
            'page_done' => $this->export->page_done,
            'rows' => count($data),
        ]);

        return $data;
    }

    protected function getReport()
    {
        $filters = $this->export->filters ?? [];
        $companyId = $filters['company_id_3pl'] ?? null;

        if (! $companyId) return collect();

        $offset = ($this->export->page_done ?? 0) * $this->chunk;

        return Order::with([
                'captain.user',
                'client.user',
                'shop',
                'progress',
                'thirdPartyCommission',
            ])
            ->leftJoin(
                'third_party_commissions',
                'third_party_commissions.order_id',
                '=',
                'orders.id'
            )
            ->where('third_party_commissions.third_party_company_id', $companyId)
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
            ])
            ->when($filters['from_date'] ?? null, fn($q, $from) =>
                $q->where('orders.delivery_date', '>=', Carbon::parse($from)->startOfDay())
            )
            ->when($filters['to_date'] ?? null, fn($q, $to) =>
                $q->where('orders.delivery_date', '<=', Carbon::parse($to)->endOfDay())
            )
            ->when($filters['q'] ?? null, fn($q, $search) =>
                $q->where('orders.client_order_id', 'LIKE', $search . '%')
            )
            ->when($filters['status'] ?? null, fn($q, $status) =>
                $q->where('orders.status_id', $status)
            )
            ->when($filters['client'] ?? null, fn($q, $client) =>
                $q->where('orders.client_id', $client)
            )
            ->when($filters['shop'] ?? null, fn($q, $shop) =>
                $q->where('orders.shopname', $shop)
            )
            ->limit($this->chunk)
            ->offset($offset)
            ->get();
    }

    public function count(): int
    {
        $filters = $this->export->filters ?? [];
        $companyId = $filters['company_id_3pl'] ?? null;

        if (! $companyId) return 0;

        return Order::leftJoin(
                'third_party_commissions',
                'third_party_commissions.order_id',
                '=',
                'orders.id'
            )
            ->where('third_party_commissions.third_party_company_id', $companyId)
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
                OrderStatus::CANCEL_REQUEST_ACCEPTED,
            ])
            ->when($filters['from_date'] ?? null, fn($q, $from) =>
                $q->where('orders.delivery_date', '>=', Carbon::parse($from)->startOfDay())
            )
            ->when($filters['to_date'] ?? null, fn($q, $to) =>
                $q->where('orders.delivery_date', '<=', Carbon::parse($to)->endOfDay())
            )
            ->when($filters['q'] ?? null, fn($q, $search) =>
                $q->where('orders.client_order_id', 'LIKE', $search . '%')
            )
            ->when($filters['status'] ?? null, fn($q, $status) =>
                $q->where('orders.status_id', $status)
            )
            ->when($filters['client'] ?? null, fn($q, $client) =>
                $q->where('orders.client_id', $client)
            )
            ->when($filters['shop'] ?? null, fn($q, $shop) =>
                $q->where('orders.shopname', $shop)
            )
            ->count();
    }

    public function headers(): array
    {
        return [
            'Order Date',
            'Order Number',
            'Client Name',
            'Shop Name',
            'AWB',
            'Dist.b/w Shop & Delivery',
            'Extra KM',
            'Delivered Date',
            'Order Status',
            'Captain Name',
            'Iqama No',
            'B.D Earning',
            'E.KM. Earning',
            'T. Earning',
            'Sub total',
            'Payments',
        ];
    }
}
