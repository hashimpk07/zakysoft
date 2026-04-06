<?php

namespace App\Jobs;

use App\CaptainCommissionPayment;
use App\Exports\QueueExport;
use App\GeneralExport;
use Illuminate\Support\Facades\Log;

class CommissionPaymentExportJob  extends QueueExport
{
    protected int $chunk = 1000;
    protected int $totalData = 0;
    protected string $file_name = 'commission_payments';
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

            Log::channel('commission')->error('CommissionPaymentExportJob failed: ' . $e->getMessage(), [
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

            $data = CaptainCommissionPayment::query()
            ->select([
                'id',
                'captain_id',
                'settled_by',
                'payment_mode_id',
                'settled_at',
                'amount_paid',
                'order_count',
                'from_date',
                'to_date',
            ])
            ->with([
                'settledBy:id,name',
                'paymentMode:id,name',
                'captain:id,iqama_number,user_id,region_id',
                'captain.user:id,name',
                'captain.regions:id,quadrant_id',
                'captain.regions.quadrant:id,name',
            ])
            ->whereDoesntHave('captain.captainThirdParty')
            ->when(
                $filters['from_date'] ?? null,
                fn($q, $v) =>
                $q->where('settled_at', '>=', now()->parse($v)->format('Y-m-d') . ' 06:00:00')
            )
            ->when(
                $filters['to_date'] ?? null,
                fn($q, $v) =>
                $q->where('settled_at', '<=', now()->parse($v)->addDay()->format('Y-m-d') . ' 05:59:59')
            )
            ->when(
                $filters['captain'] ?? null,
                fn($q, $v) =>
                $q->where('captain_id', $v)
            )
            ->when(
                $filters['paid_by'] ?? null,
                fn($q, $v) =>
                $q->where('settled_by', $v)
            )
            ->when(
                $filters['region'] ?? null,
                fn($q, $v) =>
                $q->whereHas(
                    'captain.regions.quadrant',
                    fn($q) =>
                    $q->where('quadrants.id', $v)
                )
            )
            ->when(
                $filters['payment_type'] ?? null,
                fn($q, $v) =>
                $q->where('payment_mode_id', $v)
            )
            ->when(
                $filters['invoice_number'] ?? null,
                fn($q, $v) =>
                $q->where('id', $v)
            )
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(function ($payment) {
                return [
                    $payment->formatted_settled_at,
                    $payment->settledBy?->name,
                    $payment->captain?->user?->name ?? 'N/A',
                    $payment->captain?->iqama_number ?? 'N/A',
                    $payment->formatted_date_range,
                    $payment->order_count,
                    $payment->captain?->regions
                        ? $payment->captain->regions
                            ->pluck('quadrant.name')
                            ->filter() // avoids null values
                            ->unique()
                            ->join(', ')
                        : 'N/A',
                    $payment->id,
                    $payment->amount_paid,
                    $payment->paymentMode?->name,
                ];
            })
            ->toArray();

            // Set total data count for pagination

            $this->totalData = count($data);

            return $data;

        
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
            'Date from to',
            'Order Count',
            'Working Region',
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
