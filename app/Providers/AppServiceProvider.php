<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
