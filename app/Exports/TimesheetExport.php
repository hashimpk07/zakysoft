<?php
namespace App\Exports;

use App\Models\Shift;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Support\Facades\Log;

class TimesheetExport implements FromCollection, WithHeadings, WithMapping
{
    protected $from;
    protected $to;
    protected $userId;

    public function __construct($from, $to, $userId = null)
    {
        $this->from = $from;
        $this->to = $to;
        $this->userId = $userId;
    }

    public function collection()
    {
        $q = Shift::with('breaks')->whereBetween('start_time', [$this->from, $this->to]);
        if ($this->userId) { $q->where('user_id', $this->userId); }
        return $q->get();
    }

    public function headings(): array
    {
        return ['Date','User ID','Shift Start','Shift End','Total Work Seconds','Breaks Count','Total Break Seconds'];
    }

    public function map($shift): array
    {
        $breakSeconds = $shift->breaks->sum(function($b){ return $b->duration_seconds ?? 0; });
        return [
            $shift->start_time?->toDateString(),
            $shift->user_id,
            $shift->start_time?->toDateTimeString(),
            $shift->end_time?->toDateTimeString(),
            $shift->total_working_seconds ?? '',
            $shift->breaks->count(),
            $breakSeconds,
        ];
    }
}
