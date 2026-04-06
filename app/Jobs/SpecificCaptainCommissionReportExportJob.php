<?php

namespace App\Jobs;

use App\Exports\QueueExport;
use App\Order;
use App\Captain;
use App\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SpecificCaptainCommissionReportExportJob extends QueueExport
{
    protected int $chunk = 2000;

    protected string $file_name = 'individual-captain-commission-report';

    public $timeout = 100000;

    public function data(): array
    {
        $data = [];

        $orders = $this->getData();

        foreach ($orders as $order) {

            $data[] = [
                $order->order_date,
                $order->captain,
                $order->client,
                $order->shop,
                $order->awb,
                $order->delivery_date,
                $order->order_status,
                $order->km,
                $order->basic_commission,
                $order->extra_km_commission,
                $order->com_order,
                $order->paid_com,
                $order->paid_date_and_time,
                $order->paid_by,
                $order->payment_status,
                $order->balance,
            ];
        }

        return $data;
    }

    public function headers(): array
    {
        return [
            "Order Date",
            "Captain",
            "Client",
            "Shop",
            "AWB",
            "Delivery Date",
            "Order Status",
            "KM",
            "Basic Commission",
            "Extra KM Commission",
            "Commission Order",
            "Paid Commission",
            "Paid Date & Time",
            "Paid By",
            "Payment Status",
            "Balance"
        ];
    }

    public function getData()
    {
        $request = $this->export->filters;

        $captainId = $request['captain_id'] ?? null;

        $fromDate = isset($request['from_date'])
            ? Carbon::parse($request['from_date'])->startOfDay()
            : null;

        $toDate = isset($request['to_date'])
            ? Carbon::parse($request['to_date'])->endOfDay()
            : null;

        $orders = Order::query()
            ->select('orders.*')
            ->with([
                'captain.user',
                'client.user',
                'shop',
                'progress',
                'captainCommission.settledBy',
            ])
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->where('orders.captain_id', $captainId)
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED
            ])
            ->has('captainCommission')

            ->when($fromDate, function ($query) use ($fromDate) {
                $query->where('orders.created_at', '>=', $fromDate);
            })

            ->when($toDate, function ($query) use ($toDate) {
                $query->where('orders.created_at', '<=', $toDate);
            })

            ->when(isset($request['region']), function ($query) use ($request) {

                $region = $request['region'];

                $query->where(function ($q) use ($region) {
                    $q->where('region_id', $region)
                        ->orWhereHas('shop.region', function ($q2) use ($region) {
                            $q2->where('id', $region);
                        });
                });

            })

            ->when(isset($request['q']), function ($query) use ($request) {
                $query->where('orders.client_order_id', 'LIKE', $request['q'].'%');
            })

            ->when(isset($request['status']), function ($query) use ($request) {
                $query->where('orders.status_id', $request['status']);
            })

            ->when(isset($request['client']), function ($query) use ($request) {
                $query->where('orders.client_id', $request['client']);
            })

            ->when(isset($request['shop']), function ($query) use ($request) {
                $query->where('orders.shopname', $request['shop']);
            })

            ->orderBy('captain_commissions.created_at', 'desc')

            ->limit($this->chunk)
            ->offset($this->chunk * ($this->export->page_done ?? 0))

            ->get()
            ->map(function ($order) {

                return (object)[
                    "order_date" => $order->created_at->format('d/m/Y'),
                    "captain" => $order->captain->user->name ?? '',
                    "client" => $order->client->user->name ?? '',
                    "shop" => $order->shop->name ?? '',
                    "awb" => $order->client_order_id,
                    "delivery_date" => $order->delivery_date
                        ? Carbon::parse($order->delivery_date)->format('d/m/Y')
                        : '',
                    "order_status" => $order->progress->name ?? '',
                    "km" => $order->shop_to_delivery_km,
                    "basic_commission" => $order->captainCommission->basic_delivery_earnings ?? 0,
                    "extra_km_commission" => $order->captainCommission->additional_km_earning ?? 0,
                    "com_order" => $order->captainCommission->commission ?? "N/A",
                    "paid_com" => $order->captainCommission->settled_amount ?? "",
                    "paid_date_and_time" => $order->captainCommission && $order->captainCommission->settled_amount
                        ? $order->captainCommission->updated_at->format('d/m/Y h:i:s A')
                        : "",
                    "paid_by" => $order->captainCommission->settledBy->name ?? "",
                    "payment_status" => $order->captainCommission
                        ? $order->captainCommission->status()
                        : "",
                    "balance" => $order->captainCommission->balance ?? "N/A"
                ];

            });

        return $orders;
    }

    public function count(): int
    {
        $request = $this->export->filters;

        return Order::query()
            ->leftJoin('captain_commissions', 'captain_commissions.order_id', '=', 'orders.id')
            ->where('orders.captain_id', $request['captain_id'])
            ->whereIn('orders.status_id', [
                OrderStatus::DELIVERED,
                OrderStatus::CLIENT_RETURN_ACCEPTED
            ])
            ->has('captainCommission')
            ->count();
    }
}