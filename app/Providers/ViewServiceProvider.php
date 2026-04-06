<?php

namespace App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Global view composer to share shift and break status
        View::composer('*', function ($view) {
            $shiftStatus = 'off';
            $breakStatus = 'off';

            if (Auth::check()) {
                $user = Auth::user()->loadMissing('presenceStatus');
                $presence = $user->presenceStatus;

                if ($presence) {
                    $shiftStatus = in_array($presence->status, ['on_duty', 'on_break', 'in_call']) ? 'on' : 'off';
                    $breakStatus = $presence->status === 'on_break' ? 'on' : 'off';
                }
            }

            $view->with(compact('shiftStatus', 'breakStatus'));
        });
    }
}
