<?php

namespace App\Services\General\Reports\ClientReports;

use App\Interfaces\General\ClientReports\ClientSaleInterface;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


final class ClientSaleService
{
    public function __construct(protected readonly ClientSaleInterface $interface) {}

    public function getSales(array $data, int $perPage)
    {
        $dateFrom = $data['from_date'] ?? now()->startOfMonth()->format('Y-m-d');
        $dateTo = $data['to_date'] ?? now()->format('Y-m-d');

        $from = Carbon::parse(
            $dateFrom.' '.($data['order_time_from'] ?? '06:00:00')
        );

        $to = Carbon::parse(
            $dateTo.' '.($data['order_time_to'] ?? '05:59:59')
        )->addDay();

        $filters = [
            'client' => $data['client'] ?? null,
            'shopname' => $data['shopname'] ?? null,
            'captain' => $data['captain'] ?? null,
            'orderID' => $data['orderID'] ?? null,
            'from' => $from,
            'to' => $to,
            'per_page' => $data['per_page'] ?? 100
        ];

        return $this->interface->getSales($filters,$perPage);
    }
}