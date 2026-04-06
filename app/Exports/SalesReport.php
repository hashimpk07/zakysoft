<?php

namespace App\Exports;

use App\SalesReport as AppSalesReport;
use App\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Ramsey\Uuid\Uuid;

class SalesReport implements FromQuery
{
    protected $filters = [];

    protected $user = null;

    protected $name = null;

    protected $unique_id = null;

    public function __construct(?array $filters, ?User $user, ?string $name)
    {
        if (is_null($name)) {
            $name = $this->generateName();
        }

        $this->unique_id = $this->generateName();

        $this->name = $name;
        $this->filters = $filters;
        $this->user = $user;
    }

    public function query(): Builder
    {
        return (new AppSalesReport($this->filters, $this->user))
                ->query()
                ->select(
                    'orders.created_at',
                    'orders.id',
                    'client.name as client_name',
                    'client_shops.name as shop_name',
                    'orders.client_order_id',
                    'orders.shop_to_delivery_km',
                    'order_logs.created_at as last_order_status_changed_at',
                    DB::raw('(SELECT order_statuses.name FROM order_statuses WHERE orders.status_id = order_statuses.id) as status'),
                    'captain.name as captain_name',
                    'captains.iqama_number as iqama_number',
                    DB::raw('(CASE WHEN order_payments.payment_mode THEN order_payments.payment_mode ELSE orders.delivery_payment_mode END) as order_payment_mode'),
                    'orders.amount',
                    'order_payments.pos_amount',
                    'order_payments.cash',
                    'orders.vat_rate',
                    'orders.delivery_charge',
                    DB::raw('COALESCE(
                        (SELECT notes.note FROM notes WHERE notes.order_id = orders.id AND notes.id IN (select MAX(last_note.id) from notes as last_note join orders as u2 on u2.id = last_note.order_id group by u2.id)),
                        (SELECT order_pending_reasons.reason FROM order_pending_reasons WHERE order_pending_reasons.id = order_logs.reason_id)
                    ) as note'));
    }

    public function fileName(): string
    {
        return $this->name;
    }

    public function uniqueId()
    {
        return $this->unique_id;
    }

    public function generateName(): string
    {
        return Uuid::uuid4()->toString();
    }
}
