<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Limites dediees, chacune avec son propre compteur (une limite « throttle:N,1 » partagerait
        // le compteur de l'utilisateur ou de l'adresse IP entre toutes les routes).
        RateLimiter::for('console', fn (Request $r) => Limit::perMinute(180)->by('console:'.($r->user('console')?->getAuthIdentifier() ?: $r->ip())));
        RateLimiter::for('console-actions', fn (Request $r) => Limit::perMinute(10)->by('console-actions:'.($r->user('console')?->getAuthIdentifier() ?: $r->ip())));
        RateLimiter::for('console-vulnerabilities', fn (Request $r) => Limit::perMinute(3)->by('console-vuln:'.($r->user('console')?->getAuthIdentifier() ?: $r->ip())));
        RateLimiter::for('console-code', fn (Request $r) => Limit::perMinute(6)->by('console-code:'.$r->ip()));
        RateLimiter::for('console-verify', fn (Request $r) => Limit::perMinute(10)->by('console-verify:'.$r->ip()));

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
