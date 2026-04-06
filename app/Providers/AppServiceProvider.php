<?php

namespace App\Providers;

use App\User;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Laravel\Passport\Passport;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use App\Order;
use App\Captain;
use App\ShiftStatus;
use App\Observers\OrderObserver;
use App\Observers\CaptainObserver;
use App\Observers\ShiftStatusObserver;
use App\Observers\CaptainLocationLogObserver;
use App\CaptainLocationLog;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
      
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Builder::macro('whereLike', function ($attributes, string $searchTerm) {
            $this->where(function (Builder $query) use ($attributes, $searchTerm) {
                foreach (Arr::wrap($attributes) as $attribute) {
                    $query->when(
                        str_contains($attribute, '.'),
                        function (Builder $query) use ($attribute, $searchTerm) {
                            $buffer = explode('.', $attribute);
                            $attributeField = array_pop($buffer);
                            $relationPath = implode('.', $buffer);
                            $query->orWhereHas($relationPath, function (Builder $query) use ($attributeField, $searchTerm) {
                                $query->where($attributeField, 'LIKE', "%{$searchTerm}%");
                            });
                        },
                        function (Builder $query) use ($attribute, $searchTerm) {
                            $query->orWhere($attribute, 'LIKE', "%{$searchTerm}%");
                        },
                    );
                }
            });
            return $this;
        });

        Gate::define('viewPulse', function (User $user) {
            return $user->email === 'sayed@4ulogistic.com';
        });

        // Paginator::useBootstrap();
        Model::unguard();
        Passport::tokensExpireIn(now()->addYears(100));
        Passport::refreshTokensExpireIn(now()->addYears(100));
        Passport::personalAccessTokensExpireIn(now()->addYear(100));
        Paginator::useBootstrapFour();

        Order::observe(OrderObserver::class);
        Captain::observe(CaptainObserver::class);
        ShiftStatus::observe(ShiftStatusObserver::class);
        CaptainLocationLog::observe(CaptainLocationLogObserver::class);
    }
}
