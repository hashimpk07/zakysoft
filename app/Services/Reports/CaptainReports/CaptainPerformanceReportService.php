<?php
namespace App\Services\Reports\CaptainReports;

use App\Interfaces\Reports\CaptainReports\CaptainPerformanceReportInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class CaptainPerformanceReportService
{
    public function __construct(
        protected CaptainPerformanceReportInterface $repository
    ) {}

    public function getKPICommissionReport(Request $request): LengthAwarePaginator
    {
        $filters = $request->only([
            'from_date',
            'to_date',
            'captain',
            'client',
            'areas_id',
            'regions',
            'employment_type',
            'companies',
            'status',
            'q',
            'sort_by',
            'sort_order',
            'per_page',
        ]);
        $dateRange = $this->resolveDateRange($filters);

        return $this->repository->getPerformanceReports($filters, $dateRange);
    }

    public function getConsolidatedCommissionReport(Request $request)
    {
        $filters = $request->only([
                'date',
                'captain',
                'commission_type',
                'code',
                'region_id',
                'quadrant_id',
                'search',
                'page',
                'per_page',
            ]);
       $date = $this->resolveConsolidatedDate($filters['date'] ?? null);
 
        return $this->repository->getConsolidatedReports($filters, $date);
    }

    public function getLowPerformanceReports(Request $request){
        return $this->repository->getLowPerformanceReports($request);
    }

    private function resolveDateRange(array $filters): array
    {
        $fromRaw = $filters['from_date'] ?? now()->subDays(6)->format('Y-m-d');
        $toRaw   = $filters['to_date'] ?? now()->subDay()->format('Y-m-d');

        $fromDate = Carbon::parse($fromRaw)->setTime(6, 0, 0);
        $toDate   = Carbon::parse($toRaw)->addDay()->setTime(5, 59, 59);

        return [$fromDate, $toDate, $fromRaw, $toRaw];
    }

    private function resolveConsolidatedDate(?string $rawDate): string
    {
        if ($rawDate) {
            return Carbon::createFromFormat('m/d/Y', $rawDate)->format('Y-m-d');
        }
 
        return Carbon::yesterday()->format('Y-m-d');
    }
}
