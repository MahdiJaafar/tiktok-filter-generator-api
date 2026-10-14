<?php

namespace App\Providers;

use App\Models\Collection;
use App\Models\Filter;
use App\Policies\CollectionPolicy;
use App\Policies\FilterPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Collection::class, CollectionPolicy::class);
        Gate::policy(Filter::class, FilterPolicy::class);
    }
}
