<?php

namespace App\Console\Commands;

use App\GeneralExport;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ClearExportedData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'export:clear-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'to clear exported data & files from server';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        GeneralExport::whereDate( 'created_at', '<=', now()->subMonths(2))->delete();
        $path = storage_path('app/public/general_exports');
        $files = File::files($path);

        $now = now();

        foreach ($files as $file) {
            $fileTime = File::lastModified($file);
            $fileTime = Carbon::parse($fileTime);
            $fileAge = $now->diffInDays($fileTime);
            if ($fileAge > 5 ) {
                File::delete($file);
            }
        }
    }
}
