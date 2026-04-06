<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\Order;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CaptainCommissionDetailsReportExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected string $file_name = 'Captain Commission Details Report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];

        $orders = $this->getReport();

        foreach ($orders as $order) {
            $data[] = [
                'order_date'          => optional($order->created_at)->format('d/m/Y'),
                'captain'             => optional($order->captain?->user)->name,
                'client'              => optional($order->client?->user)->name,
                'shop'                => optional($order->shop)->name,
                'awb'                 => $order->client_order_id,
                'delivery_date'       => $order->delivery_date ? Carbon::parse($order->delivery_date)->format('d/m/Y') : 'N/A',
                'order_status'        => optional($order->progress)->name,
                'km'                  => $order->shop_to_delivery_km,
                'basic_commission'    => optional($order->captainCommission)->basic_delivery_earnings ?? 0,
                'extra_km_commission' => optional($order->captainCommission)->additional_km_earning ?? 0,
                'com_order'           => optional($order->captainCommission)->commission ?? 'N/A',
                'paid_com'            => optional($order->captainCommission)->settled_amount ?? '',
                'paid_date_and_time'  => optional($order->captainCommission?->updated_at)?->format('d/m/Y h:i:s A') ?? '',
                'paid_by'             => optional($order->captainCommission?->settledBy)->name ?? '',
                'payment_status'      => optional($order->captainCommission)->status() ?? '',
                'balance'             => optional($order->captainCommission)->balance ?? 'N/A',
            ];
        }

        Log::channel('commission')->info(
            'CaptainCommissionDetailsReportExportJob chunk processed',
            [
                'page_done' => $this->export->page_done,
                'rows'      => count($data),
                'filters'   => $this->export->filters
            ]
        );

        return $data;
    }

    protected function getReport()
    {
        $filters   = $this->export->filters ?? [];
        $companyId = $filters['company_id_3pl'] ?? optional($this->export->user?->employee3pl)->third_party_logistic_company_id;
        $captainId = $filters['captain_id'] ?? $filters['captain'] ?? null;

        if (! $companyId || ! $captainId) {
            Log::channel('commission')->warning('CaptainCommissionDetailsReportExportJob: Missing companyId or captainId', [
                'companyId' => $companyId,
                'captainId' => $captainId,
                'filters'   => $filters,
            ]);
            return collect();
        }

        $offset = ($this->export->page_done ?? 0) * $this->chunk;

        return Order::query()
            ->select('orders.*')
            ->with([
                'captain.user',
                'client.user',
                'shop',
                'progress',
                'payment',
                'shopPayment',
                'captainCommission.settledBy',
            ])
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->where('orders.captain_id', $captainId)
            ->has('captainCommission')
            ->belongsTo3pl($companyId)
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
            ])
            ->when($filters['from_date'] ?? null, function ($q, $from) {
                $q->where('orders.created_at', '>=', Carbon::parse($from)->startOfDay());
            })
            ->when($filters['to_date'] ?? null, function ($q, $to) {
                $q->where('orders.created_at', '<=', Carbon::parse($to)->endOfDay());
            })
            ->when($filters['region'] ?? null, function ($q, $region) {
                $q->where(function ($q) use ($region) {
                    $q->where('orders.region_id', $region)
                      ->orWhereHas('shop.region', fn ($q) => $q->where('id', $region));
                });
            })
            ->when($filters['q'] ?? null, function ($q, $search) {
                $q->where('orders.client_order_id', 'like', $search . '%');
            })
            ->when($filters['status'] ?? null, function ($q, $status) {
                $q->where('orders.status_id', $status);
            })
            ->when($filters['client'] ?? null, function ($q, $client) {
                $q->where('orders.client_id', $client);
            })
            ->when($filters['shop'] ?? null, function ($q, $shop) {
                $q->where('orders.shopname', $shop);
            })
            ->orderBy('captain_commissions.created_at', 'desc')
            ->limit($this->chunk)
            ->offset($offset)
            ->get();
    }

    public function count(): int
    {
        $filters   = $this->export->filters ?? [];
        $companyId = $filters['company_id_3pl'] ?? optional($this->export->user?->employee3pl)->third_party_logistic_company_id;
        $captainId = $filters['captain_id'] ?? $filters['captain'] ?? null;

        if (! $companyId || ! $captainId) {
            return 0;
        }

        return Order::query()
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->where('orders.captain_id', $captainId)
            ->has('captainCommission')
            ->belongsTo3pl($companyId)
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED,
            ])
            ->when($filters['from_date'] ?? null, function ($q, $from) {
                $q->where('orders.created_at', '>=', Carbon::parse($from)->startOfDay());
            })
            ->when($filters['to_date'] ?? null, function ($q, $to) {
                $q->where('orders.created_at', '<=', Carbon::parse($to)->endOfDay());
            })
            ->when($filters['region'] ?? null, function ($q, $region) {
                $q->where(function ($q) use ($region) {
                    $q->where('orders.region_id', $region)
                      ->orWhereHas('shop.region', fn ($q) => $q->where('id', $region));
                });
            })
            ->when($filters['q'] ?? null, function ($q, $search) {
                $q->where('orders.client_order_id', 'like', $search . '%');
            })
            ->when($filters['status'] ?? null, function ($q, $status) {
                $q->where('orders.status_id', $status);
            })
            ->when($filters['client'] ?? null, function ($q, $client) {
                $q->where('orders.client_id', $client);
            })
            ->when($filters['shop'] ?? null, function ($q, $shop) {
                $q->where('orders.shopname', $shop);
            })
            ->count();
    }

    public function headers(): array
    {
        return [
            'Order Date',
            'Captain',
            'Client',
            'Shop',
            'AWB',
            'Delivery Date',
            'Order Status',
            'KM',
            'B.D. Earning',
            'E.KM. Earning',
            'T. Earning',
            'Paid Com',
            'Paid Date & Time',
            'Paid By',
            'Payment Status',
            'Balance',
        ];
    }
}
