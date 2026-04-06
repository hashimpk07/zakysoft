<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use App\Mail\ExportReportMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Traits\HasFileUpload;
use App\GeneralExport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Exception;
use App\Captain;

class CaptainShiftRuleReportExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HasFileUpload;
    /**
     * Create a new job instance.
     */
    private $export, $exportFileName, $page, $request;
     /**
     * Create a new job instance.
     */
    public function __construct(GeneralExport $export,string $exportFileName,int $page = 1,$request) {
        $this->onQueue('reports');
        $this->export = $export;
        $this->exportFileName = $exportFileName;
        $this->page = $page;
        $this->request = $request;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::channel('commission')->info("CaptainShiftRuleReportExportJob started", [
                'export_id' => $this->export->id,
                'page' => $this->page,
            ]);

            $path = 'public/general_exports/';
            $fileName = $this->exportFileName . '_' . now()->format('Ymd_His') . '.csv';
            $fullPath = $path . $fileName;

            $captains = $this->getOrders();

            $columns = $this->getColumns();
            $rows = [];


            foreach ($captains as $captain) {
                $rows[] = [
                    $captain->code ?? '-',
                    $captain->user->name ?? '-',
                    optional($captain->employmentType)->name ?? '-',
                    $captain->iqama_number ?? '-',
                    optional($captain->nationality)->name ?? '-',
                    $captain->regions->map(fn($r) => optional($r->quadrant)->name)->unique()->filter()->join(', ') ?? '-',
                    $captain->regions->pluck('name')->filter()->join(', ') ?? '-',
                    optional($captain->shiftRule)->created_at?->format('d/m/Y h:i:s A')  ?? 'N/A',
                    optional($captain->shiftRule->creator)->name ?? '-',
                    optional($captain->shiftRule)->name ?? '-',
                    ucfirst($captain->status ?? '-'),
                    optional($captain->orderReports->sortByDesc('final_status_at')->first())->final_status_at?->format('d/m/Y h:i:s A') ?? 'N/A' ,

                ];
            }

            // Convert to CSV manually
            $csvData = implode(',', $columns) . "\n";
            foreach ($rows as $row) {
                $csvData .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $row)) . "\n";
            }

            Storage::put($path.'/'.$fileName, $csvData);
            // Update export status
            $this->export->update([
                'status' => 'completed',
                'status_message' => 'Report generated successfully.',
                'is_ready_for_download' => 1,
                'notify' => 1,
                'page_done' => $this->page,
                'file' => $fullPath,
            ]);

            Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));

            Log::channel('commission')->info("CaptainShiftRuleReportExportJob completed", [
                'file' => $fullPath,
                'export_id' => $this->export->id,
            ]);
        } catch (Exception $e) {
            Log::channel('commission')->error("Export failed", ['error' => $e->getMessage()]);

            $this->export->update([
                'status' => 'error',
                'status_message' => $e->getMessage(),
                'is_ready_for_download' => 0,
                'notify' => 1,
                'page_done' => $this->page,
            ]);

            Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));
        }
    }
    public function getOrders()
    {
        $ruleId = isset($this->request['shift_rule_id'])
            ? $this->request['shift_rule_id']
            : null;

        $query = Captain::with([
            'user',
            'employmentType',
            'shiftRule.createdBy',
            'latestOrderReport',
            'nationality',
            'regions.quadrant',
        ]);

        if ($ruleId) {
            $query->where('shift_rule_id', $ruleId);
        }

        $captains = $query->get();

        Log::channel('commission')->info("Fetched captains count", ['captains' => $captains]);
        Log::channel('commission')->info("Fetched captains count", ['count' => $captains->count()]);

        return $captains;
        
        $captains = Captain::with('user', 'employmentType','shiftRule.createdBy','latestOrderReport','nationality', 'regions.quadrant') ->where('shift_rule_id', $ruleId)->paginate(10);
        Log::channel('commission')->info("Reached getOrders", [$this->request]);
        
    }

    public function getColumns()
    {

        return $columns = [
            'Emp ID',
            'Captain Name',
            'Job Type',
            'Iqama Number',
            'Nationality',
            'Work Region',
            'Work Area',
            'Rule Assigned On',	
            'Rule Assigned By',	
            'Shift Rule Name',
            'Job Status',
            'Last Delivery Date',
        ];
    }

    public function failed(Exception $exception)
    {
        $this->export->update([
            'status' => 'error',
            'status_message' => 'Error',
            'is_ready_for_download' => 0,
            'notify' => 1,
            'page_done' => $this->page,
        ]);
        Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));
    }
}