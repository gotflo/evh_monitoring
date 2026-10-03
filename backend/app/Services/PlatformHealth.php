<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Etat de la plateforme vu par la console, depuis un autre sous-domaine :
 *  - /api/health public : la plateforme repond-elle, et en combien de temps (vraie disponibilite,
 *    y compris quand le serveur de la plateforme est completement arrete) ;
 *  - API de l'agent : detail des services, integrations, configuration ;
 *  - base de la plateforme : les mesures sont-elles lisibles.
 */
class PlatformHealth
{
    /** @return array<string, mixed> */
    public static function run(): array
    {
        $url = config('monitoring.platform.url');
        $checks = [];

        // 1. Disponibilite publique.
        $t = microtime(true);
        $public = null;
        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(max(5, (int) config('monitoring.platform.timeout', 10)))->get($url.'/api/health');
            $ms = (int) round((microtime(true) - $t) * 1000);
            $public = $response->json();
            $checks['platform'] = [
                'ok' => $response->successful() && in_array($public['status'] ?? null, ['ok', 'degraded'], true),
                'http_status' => $response->status(), 'ms' => $ms, 'status' => $public['status'] ?? null,
                'slow' => $ms >= (int) config('monitoring.platform.slow_health_ms', 3000),
            ];
        } catch (\Throwable $e) {
            $checks['platform'] = ['ok' => false, 'http_status' => null, 'ms' => null, 'status' => null,
                'error' => 'Aucune réponse : '.mb_substr($e->getMessage(), 0, 160)];
        }

        // 2. Detail par l'agent (signe).
        $agent = app(AgentClient::class);
        $detail = null;
        $t = microtime(true);
        if (! $agent->configured()) {
            $checks['agent'] = ['ok' => false, 'configured' => false, 'error' => 'MONITOR_AGENT_SECRET absent ou trop court : actions et détail indisponibles.'];
        } else {
            try {
                $detail = $agent->get('health');
                $skew = isset($detail['time']) ? abs(Carbon::parse($detail['time'])->diffInSeconds(now(), false)) : null;
                $checks['agent'] = ['ok' => true, 'configured' => true, 'ms' => (int) round((microtime(true) - $t) * 1000), 'clock_skew_s' => $skew !== null ? (int) $skew : null];
                if ($skew !== null && $skew > 120) {
                    $checks['agent']['ok'] = false;
                    $checks['agent']['error'] = 'Horloges décalées de '.(int) $skew.' s : les requêtes signées risquent d\'être refusées.';
                }
            } catch (AgentException $e) {
                $checks['agent'] = ['ok' => false, 'configured' => true, 'http_status' => $e->httpStatus, 'error' => $e->getMessage()];
            }
        }

        // 3. Lecture de la base de la plateforme.
        $t = microtime(true);
        try {
            DB::connection('app')->select('select 1');
            $checks['app_db'] = ['ok' => true, 'ms' => (int) round((microtime(true) - $t) * 1000)];
        } catch (\Throwable $e) {
            $checks['app_db'] = ['ok' => false, 'error' => 'Lecture impossible : '.mb_substr(\App\Support\Redactor::text($e->getMessage()), 0, 200)];
        }

        // Services de la plateforme : detail de l'agent, sinon version publique.
        $source = $detail['checks'] ?? ($public['checks'] ?? []);
        foreach (['database', 'cache', 'storage', 'disk', 'automation', 'push', 'sms', 'mail', 'scheduler'] as $name) {
            if (isset($source[$name]) && is_array($source[$name])) {
                $checks[$name] = $source[$name];
            }
        }

        $down = ! $checks['platform']['ok'] && (($checks['platform']['http_status'] ?? null) === null || ($checks['platform']['http_status'] ?? 0) >= 500);
        $relevant = array_intersect_key($checks, array_flip(['platform', 'agent', 'app_db', 'database', 'cache', 'storage', 'disk', 'automation', 'push']));
        $status = $down ? 'down' : (collect($relevant)->every(fn ($c) => (bool) ($c['ok'] ?? false)) && ! ($checks['platform']['slow'] ?? false) ? 'ok' : 'degraded');

        return [
            'status' => $status,
            'checks' => $checks,
            'config' => $detail['config'] ?? null,
            'php' => $detail['php'] ?? null,
            'laravel' => $detail['laravel'] ?? null,
            'environment' => $detail['environment'] ?? null,
            'app_name' => $detail['app_name'] ?? null,
            'deployed_at' => $detail['deployed_at'] ?? null,
            'server_uptime_s' => $detail['server_uptime_s'] ?? null,
            'platform_url' => $url,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
