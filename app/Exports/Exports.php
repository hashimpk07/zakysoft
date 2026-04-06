<?php

namespace App\Exports;

use App\Export;
use App\Jobs\ExportToCsv;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromQuery;

class Exports
{
    protected $query;

    protected $headers;

    protected $footer;

    protected $filename;

    protected $appending = false;

    protected $batching = false;

    protected $chunkSize = 1000;

    public function header(array $headers = [])
    {
        $this->headers = [$headers];

        return $this;
    }

    public function query(FromQuery $query)
    {
        $this->query = $query;

        return $this;
    }

    public function export($offset = null, $limit = null)
    {
        $query = $this->query->query();

        if (! is_null($offset) && ! is_null($limit)) {
            $query->offset($offset);
        }

        if (! is_null($limit)) {
            $query->limit($limit);
        }

        return $query->get()->toArray();
    }

    public function footer(array $footer = [])
    {
        if (is_array($footer) && isset($footer[0]) && is_array($footer[0])) {
            $footer = $footer;
        } else {
            $footer = [$footer];
        }

        $this->footer = $footer;

        return $this;
    }

    public function append()
    {
        $this->appending = true;

        return $this;
    }

    public function path()
    {
        return Storage::disk('public')->path('exports/'.$this->query->uniqueId().'.csv');
    }

    public function batching($chunkSize = 1000)
    {
        $this->batching = true;
        $this->chunkSize = $chunkSize;

        return $this;
    }

    public function toCsv()
    {
        $batching = [];
        $getCount = $this->query->query()->getCountForPagination();

        if ($this->headers) {
            $batching[] = new ExportToCsv($this->headers, $this->path());
        }

        if ($this->batching) {
            $chunks = ceil($getCount / $this->chunkSize);
            $offset = 0;
            for ($i = 0; $i < $chunks; $i++) {
                $limit = (($i + 1) * $this->chunkSize) > $getCount ? (($i + 1) * $this->chunkSize) - $getCount : $this->chunkSize;
                $batching[] = new ExportToCsv($this, $this->path(), $offset, $limit);
                $offset += $this->chunkSize;
            }
        } else {
            $batching[] = new ExportToCsv($this, 0, $getCount);
        }

        if ($this->footer) {
            $batching[] = new ExportToCsv($this->footer, $this->path());
        }

        $batch = Bus::batch($batching)->dispatch();

        return Export::create([
            'export_type' => $this->query::class,
            'batch_id' => $batch->id,
            'path' => $this->path(),
            'filename' => pathinfo($this->query->fileName(), PATHINFO_EXTENSION) ? $this->query->fileName() : $this->query->fileName().'.csv',
            'notify' => true,
            'notified_at' => null,
            'downloaded_at' => null,
            'purge_after_download' => 1,
            'created_by' => auth()->user()->id,
        ]);
    }
}
