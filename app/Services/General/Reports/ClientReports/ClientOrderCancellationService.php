<?php

namespace App\Services\General\Reports\ClientReports;

use App\Interfaces\General\ClientReports\ClientOrderCancellationInterface;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


final class ClientOrderCancellationService
{
   
    public function __construct(protected readonly ClientOrderCancellationInterface $interface) {}

    public function getClientOrderCancellationReport(array $filters, int $perPage)
    {
        $from_date = $filters['from_date'] ?? now()->startOfMonth()->format('Y-m-d');
        $to_date = $filters['to_date'] ?? now()->addDay()->format('Y-m-d');

        $filters['from_date'] = Carbon::parse($from_date)->setTime(6,0,0);
        $filters['to_date'] = Carbon::parse($to_date)->addDay()->setTime(5,59,59);

        return $this->interface->getClientOrderCancellations($filters, $perPage );
        
    }

}