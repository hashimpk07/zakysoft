<?php

namespace App\Services\Reports\ClientReports;

use App\Interfaces\Reports\ClientReports\SalesReportInterface;
use Illuminate\Http\Request;

class SalesReportService 
{
    public function __construct(private readonly SalesReportInterface $repository) {}

    public function getSalesReport(Request $request)
    {
        return $this->repository->getSalesReport(perPage: $request->get('per_page', 20));
    }
}