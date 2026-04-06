<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        Passport::tokensCan([
            'client-create' => 'Create Client',
            'sales-leads-create' => 'Create Sales Leads',
        ]);

        Gate::define('viewShifts', function ($user) {
            return $user->role->name === 'operation' && $user->can('shift-logging');
        });

        Gate::define('superAdmin', function ($user) {
            return $user->isSuperAdmin();
        });
    }
}
