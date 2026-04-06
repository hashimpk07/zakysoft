<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * The path to the "home" route for your application.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        //

        parent::boot();

        if (app()->environment('production', 'staging')) {
            \URL::forceScheme('https');
        }
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();

        $this->mapWebRoutes();

        $this->mapApiV2Routes();

        $this->mapPublicApis();

        $this->mapClientApiRoutes();

        $this->map3plApiRoutes();

        $this->mapMadarFleetRoutes();

        $this->mapGeneralApiRoutes();

        $this->mapReportRoutes();

    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        Route::middleware('web')->namespace($this->namespace)->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::prefix('api')->middleware('api')->namespace($this->namespace)->group(base_path('routes/api.php'));
    }

    protected function mapApiV2Routes()
    {
        Route::prefix('api/v2')->middleware('api')->namespace($this->namespace)->group(base_path('routes/api_v2.php'));
    }

    /**
     * Define the "public api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */

    protected function mapPublicApis()
    {
        Route::prefix('api/public')->middleware('api')->group(base_path('routes/public_api.php'));
    }

     /**
     * Define the "client api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */

    protected function mapClientApiRoutes()
    {
        Route::prefix('api/client')->middleware('api')->group(base_path('routes/client_api.php'));
    }
     /**
     * Define the "3pl api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function map3plApiRoutes()
    {
        Route::prefix('api/3pl')->middleware('api')->group(base_path('routes/3pl_api.php'));
    }
     /**
     * Define the "3pl api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapGeneralApiRoutes()
    {
        Route::prefix('api/general')->middleware('api')->group(base_path('routes/general_api.php'));
    }

    /**
     * Define the "madar fleet" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapMadarFleetRoutes()
    {
        Route::prefix('api')->middleware('api')->group(base_path('routes/madar_fleet.php'));
    }

     /**
     * Define the "madar fleet" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapReportRoutes()
    {
        Route::prefix('api/reports')->middleware('api')->group(base_path('routes/reports_api.php'));
    }

    
}
