<?php 
namespace App\Exports;

use App\GeneralExport;
use App\Mail\ExportReportMail;
use Error;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

abstract class QueueExport implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
  protected int $chunk = 2000;

  protected string $folder = 'public/general_exports/';

  protected string $file_name;

  protected string $file_path;

  public function __construct(public GeneralExport $export)
  {
    $this->onQueue('reports');
  }

  public function handle()
  {
    $file = null;

    if($this->export->initialized()) {
      $this->initialize();

      $file = $this->openFile();

      $this->writeHeaders($file);
    }

    if(!$file) {
      $file = $this->openFile('a');
    }

    $data = $this->data();

    if(empty($data)) {
      $this->completed();
      fclose($file);
      return;
    }

    foreach($data as $row) {
      fputcsv($file, $row);
    }

    fclose($file);
    $this->export->update(['page_done' => $this->export->page_done + 1]);
    
     dispatch(new static($this->export));
  }


  public function initialize()
  {
    $file = $this->initializeFile();
    $this->export->update([
      'file' => $file,
      'status' => GeneralExport::STATUS['processing'],
      'page_done' => 0,
      'page_count' => ceil($this->count() / $this->chunk),
    ]);
  }

  public function initializeFile(): string 
  {
      return $this->folder . now()->timestamp . '-' . $this->getFileName() . '-' . $this->export->created_by . '.csv';
  }

  public function getFileName() : string
  {
      return $this->file_name ?? class_basename($this);
  }

  public function writeHeaders( $file)
  {
    fputcsv($file, $this->headers());
  }

  public function openFile($mode = 'w')
  {
      return fopen(storage_path('app/' . $this->export->file), $mode);
  }

  public function completed()
  {
      $this->export->update([
          'status' => 'processed',
          'status_message' => 'Completed',
          'is_ready_for_download' => 1,
          'notify' => 1
      ]);
      
      Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));
  }

  public function failed(Exception | Error $exception)
  {
    Log::error($exception->getMessage() . ' at line ' . $exception->getLine(). ' in ' . $exception->getFile());
    $this->export->update([
      'status' => 'error',
      'status_message' => $exception->getMessage() . ' at line ' . $exception->getLine(). ' in ' . $exception->getFile(),
      'is_ready_for_download' => 0,
      'notify' => 1,
    ]);

    Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));

    throw $exception;
  }

  abstract public function count(): int;
  abstract public function headers(): array;
  abstract public function data(): array;
}