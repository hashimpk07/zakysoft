<?php
namespace App\Jobs;

use App\Captain;
use App\Exports\QueueExport;
use Illuminate\Support\Facades\Log;
use App\Files_and_remainders;
use DateTime;

class NotificationReportExportJob extends QueueExport
{
    protected int $chunk = 1000;
    protected int $totalData = 0;
    protected string $file_name = 'notification_report';
    public $tries = 3;
    public $timeout = 900;
    public $retryAfter = 60;

    protected ?string $fromDate;
    protected ?string $toDate;

    /**
     * Prepare data for Excel export.
     */
    public function data(): array
    {
        try {
            $captainReports = $this->getReport();
            // Format data for Excel export
            $data = [];
            foreach ($captainReports as $report) {
                $data[] = [
                    $report['id'] ?? '',
                    $report['name'] ?? '',
                    $report['position'] ?? '',
                    $report['expire_date'] ?? '',
                    $report['status'] ?? ''
                ];
            }
            return $data;
        } catch (\Throwable $e) {
            Log::channel('commission')->error('CaptainReportExcelJob failed: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Get captain report data with filters.
     */
    private function getReport(): array
    {
        try {
            $request  = $this->export->filters ?? [];

            $query = Files_and_remainders::orderBy('id', 'desc');

            // Apply "from_date" filter (m/d/Y format)
            if (!empty($request['from_date'])) {
                $fromDate = DateTime::createFromFormat('m/d/Y', $request['from_date']);
                if ($fromDate) {
                    $query->whereDate('date', '>=', $fromDate->format('Y-m-d'));
                }
            }

            // Apply "to_date" filter (m/d/Y format)
            if (!empty($request['to_date'])) {
                $toDate = DateTime::createFromFormat('m/d/Y', $request['to_date']);
                if ($toDate) {
                    $query->whereDate('date', '<=', $toDate->format('Y-m-d'));
                }
            }

            $notifications = $query
                ->limit($this->chunk)
                ->offset(($this->chunk * ($this->export->page_done ?? 0)))
                ->get()
                ->map(function ($notification) {
                    return [
                        'id'          => $notification->id,
                        'name'        => $notification->name,
                        'position'    => $notification->type,
                        'expire_date' => $notification->date,
                        'status'      => 'Expired',
                    ];
                })
                ->toArray();

            $this->totalData = count($notifications);
            return $notifications;
        } catch (\Throwable $e) {
            Log::channel('commission')->error('CaptainReportExcelJobs::getReport failed: ' . $e->getMessage(), [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return []; // return empty array on failure
        }
    }
    /**
     * Excel file headers.
     */
    public function headers(): array
    {
        return [
            'ID',
            'Name',
            'Position',
            'Expire Date',
            'Status',
        ];
    }

    /**
     * Total count of data exported.
     */
    public function count(): int
    {
        return $this->totalData;
          
    }
}



