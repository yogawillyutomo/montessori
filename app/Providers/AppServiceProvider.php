<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
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
        $database = config('database.connections.sqlite.database');

        if (config('database.default') === 'sqlite' && is_string($database) && $database !== ':memory:' && ! File::exists($database)) {
            File::ensureDirectoryExists(dirname($database));
            File::put($database, '');
        }
    }
}
