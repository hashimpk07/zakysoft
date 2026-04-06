<?php

namespace App\Interfaces\Reports\CaptainReports;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

interface CaptainPerformanceReportInterface
{
    public function getPerformanceReports(array $filters, array $dateRange): LengthAwarePaginator;

    public function getConsolidatedReports(array $filters, string $date): LengthAwarePaginator;

    public function getLowPerformanceReports(Request $request): LengthAwarePaginator;

    public function getShiftReports(array $filters, string $date, int $perPage): LengthAwarePaginator;
}
