<?php

namespace App\Repositories\Reports\ClientReports;

use App\Interfaces\Reports\ClientReports\SalesReportInterface;
use App\SalesReport;
use Illuminate\Pagination\LengthAwarePaginator;

class SalesReportRepository implements SalesReportInterface
{
    public function getSalesReport(int $perPage = 20): LengthAwarePaginator
    {
        $sales_report = new SalesReport();
        return $sales_report->query()->paginate($perPage)->withQueryString();
    }
}
