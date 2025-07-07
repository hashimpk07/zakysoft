<?php

namespace App\Jobs;

use App\Models\StockMovement;
use App\Models\StockLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;

class LogStockMovementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $stockMovement;

    /**
     * Create a new job instance.
     */
    public function __construct(StockMovement $stockMovement)
    {
        $this->stockMovement = $stockMovement;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        StockLog::create([
            'stock_movement_id' => $this->stockMovement->id,
            'log_data' => json_encode($this->stockMovement->toArray()),
        ]);
    }
}
