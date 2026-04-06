<?php

namespace App\Traits;

use App\Mail\ExportReportMail;
use Exception;
use Illuminate\Support\Facades\Mail;

trait QueueExportable {

  public function failed(Exception $exception)
  {
      $this->export->update([
          'status' => 'error',
          'status_message' => 'Error',
          'is_ready_for_download' => 0,
          'notify' => 1,
          'page_done' => $this->page
      ]);
      Mail::to($this->export->email_id)->send(new ExportReportMail($this->export));

      throw $exception;
  }
}