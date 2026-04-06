<?php

namespace App\Filter\Client;

use App\OrderStatus;

class ClientReportFilter
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function apply($query)
    {
        return $query
            ->when($this->filters['client_order_id'] ?? null, fn($q, $v) => $q->where('orders.client_order_id', 'like', "%$v%"))
            ->when($this->filters['client'] ?? null, fn($q, $v) => $q->where('orders.client_id', $v))
            ->when($this->filters['captain'] ?? null, fn($q, $v) => $q->whereHas('captain', fn($q2) => $q2->whereIn('captains.id', $v)))
            ->when($this->filters['third_party_company'] ?? null, fn($q, $v) => $q->whereIn('third_party_logistic_companies.id', $v))
            ->when($this->filters['search'] ?? null, fn($q, $v) => $q->where('orders.client_order_id', 'like', "%$v%"))
            ->when(
                $this->filters['assigned_by'] ?? null,
                fn($q, $v) => $q->whereRaw(
                    "
                (SELECT CASE WHEN captains.id IS NULL THEN users.id ELSE NULL END
                 FROM order_logs
                 LEFT JOIN users ON users.id = order_logs.created_by
                 LEFT JOIN captains ON captains.user_id = users.id
                 WHERE order_logs.status_id = ? AND order_logs.order_id = orders.id
                 ORDER BY order_logs.id DESC LIMIT 1) = ?
            ",
                    [OrderStatus::ACCEPT, $v],
                ),
            )
            ->when($this->filters['status_id'] ?? null, fn($q, $v) => $q->where('orders.status_id', $v))
            ->when(isset($this->filters['from_date'], $this->filters['to_date']), fn($q) => $q->whereBetween('orders.delivery_date', [$this->filters['from_date'], $this->filters['to_date']]));
    }
}
