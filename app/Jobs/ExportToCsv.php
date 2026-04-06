<?php

namespace App\Jobs;

use App\Exports\Exports;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExportToCsv implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    protected $export;

    protected $offset;

    protected $limit;

    protected $path;

    public function __construct(Exports|array $export, string $path, int $offset = null, int $limit = null)
    {
        $this->export = $export;
        $this->offset = $offset;
        $this->limit = $limit;
        $this->path = $path;
    }

    public function handle()
    {
        if (is_array($this->export)) {
            $data = $this->export;
        } else {
            $data = $this->export->export($this->offset, $this->limit);
        }

        if (is_null($data) || ! is_iterable($data)) {
            return;
        }

        $handle = fopen($this->path, 'a+');
        foreach ($data as $row) {
            fputcsv($handle, (array) $row);
        }
        fclose($handle);
    }
}
