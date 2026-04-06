<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanExpiredOtps extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'otp:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete OTPs older than 5 minutes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        $count = DB::table('email_otps')
            ->where('created_at', '<', now()->subMinutes(5))
            ->delete();

        $this->info("Deleted $count expired OTP(s).");
    }
}
