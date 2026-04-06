<?php

namespace App\Jobs;

use App\CaptainSalaryPayment;
use App\Exports\QueueExport;
use App\GeneralExport;
use Illuminate\Support\Facades\Log;


class CommissionSalaryPaymentExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected int $totalData = 0;
    protected string $file_name = 'salary_payments';
    public $tries = 3;
    public $timeout = 900;
    public $retryAfter = 60;

    public function __construct(GeneralExport $export)
    {
        parent::__construct($export);
    }

    /**
     * Prepare data for Excel export.
     */
    public function data(): array
    {
        try {
            return $this->getReport();

        } catch (\Throwable $e) {

            Log::channel('commission')->error('CommissionSalaryPaymentExportJob failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get zone report data with filters.
     */
    private function getReport(): array
    {
        try {
            $filters = $this->export->filters ?? [];

            $limit = $this->chunk;
            $offset = $this->export->page_done * $limit;

            $payment = CaptainSalaryPayment::query()
                ->with('captain:id,firstname,iqama_number,region_id', 'paidBy:id,name', 'paymentMode:id,name', 'captain.regions:id,quadrant_id', 'captain.regions.quadrant:id,name')
                ->when($filters['from_date'] ?? null, fn($q, $v) => $q->where('created_at', '>=', now()->parse($v)->format('Y-m-d') . ' 06:00:00'))
                ->when($filters['to_date'] ?? null, fn($q, $v) => $q->where('created_at', '<=', now()->parse($v)->addDay()->format('Y-m-d') . ' 05:59:59'))
                ->when($filters['captain'] ?? null, fn($q, $v) => $q->where('captain_id', $v))
                ->when($filters['paid_by'] ?? null, fn($q, $v) => $q->where('paid_by', $v))
                ->when($filters['region'] ?? null, fn($q, $v) => $q->whereHas('captain.regions.quadrant', fn($q) => $q->where('quadrants.id', $v)))
                ->when($filters['payment_type'] ?? null, fn($q, $v) => $q->where('payment_mode_id', $v))
                ->when($filters['invoice_number'] ?? null, fn($q, $v) => $q->where('id', $v))
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->offset($offset)
                ->get()
                ->map(function ($item) {
                    return [
                        $item->formatted_created_at,
                        $item->paidBy->name ?? null,
                        $item->captain->firstname ?? null,
                        $item->captain->iqama_number ?? null,
                        $item->captain->regions
                        ? $item->captain->regions->pluck('quadrant.name')->unique()->join(', ')
                        : null,
                        $item->formatted_date_range,
                        $item->worked_days,
                        $item->salary_per_day,
                        $item->id,
                        $item->total_salary_paid,
                        $item->paymentMode->name ?? null,
                    ];
                })->toArray();

            $this->totalData = count($payment);

            return $payment;


        } catch (\Throwable $e) {

            Log::channel('commission')->error('ZoneReportExportJob::getReport failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Excel headers.
     */
    public function headers(): array
    {
        return [
            'Paid Date',
            'Paid By',
            'Paid To (Captain Name)',
            'Iqama No',
            'Working Region',
            'Date from to',
            'Days Count',
            'Daily Salary',
            'Invoice Number',
            'Paid Amount',
            'Payment Type'
        ];
    }

    /**
     * Total count.
     */
    public function count(): int
    {
        return $this->totalData;
    }
}
