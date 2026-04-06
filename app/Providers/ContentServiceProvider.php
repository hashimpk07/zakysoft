<?php

namespace App\Providers;

use App\OrderStatus;
use Illuminate\Support\ServiceProvider;

class ContentServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {

        app()->singleton('content', function() {
            return new \App\Content();
        });
    }
}
