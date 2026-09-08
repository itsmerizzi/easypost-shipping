<?php

namespace App\Providers;

use App\Services\EasyPost\EasyPostClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EasyPostClient::class, fn () => new EasyPostClient(
            apiKey: (string) config('services.easypost.key', ''),
            baseUrl: (string) config('services.easypost.base_url'),
            timeout: (int) config('services.easypost.timeout'),
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
