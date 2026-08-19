<?php

namespace App\Providers;

use App\Contracts\MonzoClient;
use App\Services\Monzo\HttpMonzoClient;
use Illuminate\Support\ServiceProvider;

class MonzoServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(MonzoClient::class, function (): HttpMonzoClient {
            return new HttpMonzoClient(
                clientId: (string) config('services.monzo.client_id'),
                clientSecret: (string) config('services.monzo.client_secret'),
                redirectUri: (string) config('services.monzo.redirect'),
                authUrl: rtrim((string) config('services.monzo.auth_url'), '/'),
                apiUrl: rtrim((string) config('services.monzo.api_url'), '/'),
            );
        });
    }
}
