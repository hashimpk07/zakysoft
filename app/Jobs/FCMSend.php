<?php

namespace App\Jobs;

use App\Services\Firebase\CloudMessage;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
class FCMSend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $payload = [];
    protected $fb_token = null;
    protected $method_to_run = [];
    protected $before_to_run = null;
    protected $version = 'v1';
    
    public function __construct($payload = [], $fb_token, $method_to_run = [], $version = 'v1', $before_to_run = null)
    {
        $this->payload = $payload;
        $this->fb_token = $fb_token;
        $this->method_to_run = $method_to_run;
        $this->before_to_run = $before_to_run;
        $this->version = $version;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try{
            if($this->before_to_run && !empty($this->before_to_run)) {
                $class = $this->before_to_run['class'];
                $method = $this->before_to_run['method'];
                $argument = $this->before_to_run['argument'];
                if(($class)::$method(...$argument)) {
                    return;
                }
            }

            (new CloudMessage($this->version))->send([
                'to' => $this->fb_token,
                'notification' => $this->payload,
                'data' => $this->payload
            ]);
            
            if($this->method_to_run && !empty($this->method_to_run)) {
                Log::channel('auto_assigning')->debug('FCM Notification: runAfter Method', [
                    'token' => $this->fb_token,
                    'argument' => $this->method_to_run['argument']
                ]);

                $class = $this->method_to_run['class'];
                $method = $this->method_to_run['method'];
                $argument = $this->method_to_run['argument'];
                ($class)::$method(...$argument);
            }
        } catch (\Exception $e) {
            Log::channel('auto_assigning')->error('FCM Notification: Error', [
                'token' => $this->fb_token,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
