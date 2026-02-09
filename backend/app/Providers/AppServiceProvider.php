<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\WordValidator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WordValidator::class, function () {
            return new WordValidator();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
