<?php

namespace App\Http\Controllers\Api;

use App\Models\Incident;
use App\Services\AlertEngine;
use App\Services\Metrics;
use App\Services\MonitorSettings;
use App\Services\PlatformHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord : etat actuel de la plateforme (vu de l'exterieur et par l'agent), disponibilite,
 * utilisation, erreurs, incidents avec leur cause probable. Rafraichi automatiquement par l'interface.
 */
class OverviewController extends ConsoleController
{
    public function __invoke(Request $request): JsonResponse
    {
        [$from, $to, $step] = $this->period($request);

        // Sans cron, la verification est aussi lancee a l'ouverture de la console (au plus chaque minute).
        $last = Cache::get('monitor:last-check');
        if (! $last || Carbon::parse($last)->lt(now()->subMinutes(2))) {
            try {
                AlertEngine::evaluate();
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $health = Cache::get('monitor:last-health') ?? PlatformHealth::run();

        $length = $from->diffInSeconds($to, true);
        $prevFrom = $from->copy()->subSeconds((int) $length);
        $incidents = Incident::where('status', '!=', 'resolved')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 ELSE 1 END")->orderByDesc('last_seen_at')->limit(8)->get();

        // Mesures de la plateforme : si sa base est illisible, le tableau de bord reste utilisable.
        $readable = (bool) ($health['checks']['app_db']['ok'] ?? false);
        $m = fn (callable $fn, $fallback) => $readable ? rescue($fn, $fallback) : $fallback;
        $emptyReq = ['hits' => 0, 'errors' => 0, 'client_errors' => 0, 'denied' => 0, 'throttled' => 0, 'error_rate' => null, 'avg_ms' => null,
            'p95_ms' => null, 'max_ms' => null, 'slow' => 0, 'avg_queries' => null, 'avg_db_ms' => null];
        $emptyUsers = ['total' => 0, 'active_status' => 0, 'blocked' => 0, 'today' => 0, 'last_7_days' => 0, 'last_30_days' => 0,
            'seen_30_days' => 0, 'in_period' => 0, 'new_accounts' => 0, 'logins' => 0, 'failed_codes' => 0];

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'collected_since' => MonitorSettings::installedAt(),
            'last_check' => Cache::get('monitor:last-check'),
            'metrics_readable' => $readable,
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'step' => $step],
            'health' => $health,
            'uptime' => [
                'up_since' => Metrics::upSince(),
                'server_seconds' => $health['server_uptime_s'] ?? null,
                'deployed_at' => $health['deployed_at'] ?? null,
                'availability' => [
                    '24h' => Metrics::availability(now()->subDay(), now()),
                    '7d' => Metrics::availability(now()->subDays(7), now()),
                    '30d' => Metrics::availability(now()->subDays(30), now()),
                ],
                'response_series' => $this->responseSeries(),
            ],
            'incidents' => [
                'open' => Incident::where('status', '!=', 'resolved')->count(),
                'critical' => Incident::where('status', '!=', 'resolved')->where('severity', 'critical')->count(),
                'list' => $incidents->map(fn (Incident $i) => [
                    'id' => $i->id, 'title' => $i->title, 'summary' => $i->summary, 'severity' => $i->severity, 'status' => $i->status,
                    'cause' => $i->investigation['cause'] ?? null, 'confidence' => $i->investigation['confidence'] ?? null,
                    'first_seen_at' => $this->iso($i->first_seen_at), 'last_seen_at' => $this->iso($i->last_seen_at),
                ])->values(),
            ],
            'requests' => $m(fn () => Metrics::requests($from, $to), $emptyReq),
            'requests_previous' => $m(fn () => Metrics::requests($prevFrom, $from), $emptyReq),
            'series' => $m(fn () => Metrics::requestSeries($from, $to, $step), []),
            'by_service' => $m(fn () => Metrics::byService($from, $to), []),
            'by_action' => $m(fn () => Metrics::byAction($from, $to), []),
            'users' => $m(fn () => Metrics::users($from, $to), $emptyUsers),
            'user_series' => $m(fn () => Metrics::userSeries($step === 'hour' ? now()->subDays(14)->startOfDay() : $from->copy()->startOfDay(), $to), []),
            'top_errors' => $m(fn () => Metrics::topErrors($from, $to, 6), []),
            'recent_events' => $m(fn () => Metrics::app()->table('monitor_events')->whereIn('level', ['error', 'critical', 'alert', 'emergency'])
                ->orderByDesc('id')->limit(8)->get(['id', 'created_at', 'level', 'service', 'type', 'message'])
                ->map(fn ($e) => ['id' => $e->id, 'created_at' => $this->iso($e->created_at), 'level' => $e->level, 'service' => $e->service, 'type' => $e->type, 'message' => $e->message])->all(), []),
            'integrations' => $m(fn () => Metrics::integrations($from, $to), []),
            'jobs' => $m(fn () => Metrics::jobs($from, $to), []),
        ]);
    }

    /** Temps de reponse de la plateforme vu de l'exterieur, 60 dernieres mesures. @return array<int, array<string, mixed>> */
    private function responseSeries(): array
    {
        return DB::table('health_samples')->orderByDesc('id')->limit(60)->get(['created_at', 'status', 'response_ms'])
            ->reverse()->map(fn ($r) => ['at' => $this->iso($r->created_at), 'label' => Carbon::parse($r->created_at)->format('H:i'),
                'status' => $r->status, 'ms' => $r->response_ms])->values()->all();
    }
}
