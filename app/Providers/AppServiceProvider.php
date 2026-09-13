<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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

        if ($this->app->environment('production')) {
            User::saving(function (User $user): void {
                $email = strtolower(trim((string) $user->email));

                // Demo seed accounts use the reserved .test namespace. Blocking them at
                // the model boundary prevents an accidental production db:seed from
                // creating or resetting predictable privileged credentials.
                if (str_ends_with($email, '@montessori.test')) {
                    throw new RuntimeException('Demo Montessori accounts are disabled in production.');
                }
            });
        }
    }
}
