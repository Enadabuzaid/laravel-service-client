<?php

namespace Enadstack\ServiceClient;

use Enadstack\ServiceClient\Http\VerifyServiceWebhook;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class ServiceClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/service-client.php', 'service-client');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/service-client.php' => config_path('service-client.php')], 'service-client-config');

        $this->app->make(Router::class)->aliasMiddleware('service-webhook', VerifyServiceWebhook::class);
    }
}
