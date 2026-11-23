<?php

namespace App\Providers;

use App\Admin\GoogleGroupMembership;
use App\Admin\GroupMembership;
use App\Catalogue\CatalogueSource;
use App\Catalogue\LwtvListShowsSource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CatalogueSource::class, LwtvListShowsSource::class);

        $this->app->bind(GroupMembership::class, fn () => new GoogleGroupMembership(
            config('admin.group'),
            config('admin.service_account_key'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
