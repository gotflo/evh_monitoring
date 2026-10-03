<?php

namespace App\Services;

use App\Support\ServiceMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lecture des mesures pour le tableau de bord, les alertes, les enquetes et les rapports.
 * Mesures de la plateforme : base « app » (tables monitor_* ecrites par l'agent) ; disponibilite :
 * releves de la console. Uniquement des chiffres reellement collectes.
 */
class Metrics
{
    public static function app(): \Illuminate\Database\Connection
    {
        return DB::connection('app');
    }

    /** Bornes hautes des tranches de duree enregistrees. */
    private const HIST = ['h_100' => 100, 'h_300' => 300, 'h_1000' => 1000, 'h_3000' => 3000];

    private static function fmt(Carbon $d): string
    {
        return $d->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    public static function requests(Carbon $from, Carbon $to): array
    {
        $row = self::app()->table('monitor_request_stats')->where('bucket', '>=', self::fmt($from))->where('bucket', '<', self::fmt($to))
            ->selectRaw('COALESCE(SUM(hits),0) as hits, COALESCE(SUM(total_ms),0) as total_ms, COALESCE(MAX(max_ms),0) as max_ms,
                COALESCE(SUM(slow_hits),0) as slow_hits, COALESCE(SUM(h_100),0) as h_100, COALESCE(SUM(h_300),0) as h_300,
                COALESCE(SUM(h_1000),0) as h_1000, COALESCE(SUM(h_3000),0) as h_3000,
                COALESCE(SUM(CASE WHEN status >= 500 THEN hits ELSE 0 END),0) as errors,
                COALESCE(SUM(CASE WHEN status >= 400 AND status < 500 THEN hits ELSE 0 END),0) as client_errors,
                COALESCE(SUM(CASE WHEN status IN (401, 403, 419) THEN hits ELSE 0 END),0) as denied,
                COALESCE(SUM(CASE WHEN status = 429 THEN hits ELSE 0 END),0) as throttled,
                COALESCE(SUM(queries),0) as queries, COALESCE(SUM(db_ms),0) as db_ms')
            ->first();
        $hits = (int) $row->hits;

        return [
            'hits' => $hits,
            'errors' => (int) $row->errors,
            'client_errors' => (int) $row->client_errors,
            'denied' => (int) $row->denied,
            'throttled' => (int) $row->throttled,
            'error_rate' => $hits ? round($row->errors / $hits * 100, 2) : null,
            'avg_ms' => $hits ? (int) round($row->total_ms / $hits) : null,
            'p95_ms' => $hits ? self::percentile($row, 0.95) : null,
            'max_ms' => $hits ? (int) $row->max_ms : null,
            'slow' => (int) $row->slow_hits,
            'avg_queries' => $hits ? round($row->queries / $hits, 1) : null,
            'avg_db_ms' => $hits ? (int) round($row->db_ms / $hits) : null,
        ];
    }

    /** Centile estime a partir des tranches (interpolation lineaire dans la tranche). */
    public static function percentile(object $row, float $p): ?int
    {
        $hits = (int) $row->hits;
        if ($hits === 0) {
            return null;
        }
        $target = $p * $hits;
        $cumulative = 0;
        $lower = 0;
        foreach (self::HIST as $col => $upper) {
            $count = (int) $row->{$col};
            if ($count > 0 && $cumulative + $count >= $target) {
                return (int) round($lower + ($upper - $lower) * (($target - $cumulative) / $count));
            }
            $cumulative += $count;
            $lower = $upper;
        }
        $rest = max(1, $hits - $cumulative);
        $max = max(3000, (int) $row->max_ms);

        return (int) round(3000 + ($max - 3000) * min(1, ($target - $cumulative) / $rest));
    }

    /**
     * Serie temporelle : requetes, erreurs serveur, duree moyenne, par heure ou par jour.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function requestSeries(Carbon $from, Carbon $to, string $step): array
    {
        $rows = self::app()->table('monitor_request_stats')->where('bucket', '>=', self::fmt($from))->where('bucket', '<', self::fmt($to))
            ->groupBy('bucket')
            ->selectRaw('bucket, SUM(hits) as hits, SUM(CASE WHEN status >= 500 THEN hits ELSE 0 END) as errors,
                SUM(CASE WHEN status >= 400 AND status < 500 THEN hits ELSE 0 END) as client_errors, SUM(total_ms) as total_ms, SUM(slow_hits) as slow')
            ->get();

        $len = $step === 'hour' ? 13 : 10;
        $acc = [];
        foreach ($rows as $r) {
            $k = substr((string) $r->bucket, 0, $len);
            $acc[$k] ??= ['hits' => 0, 'errors' => 0, 'client_errors' => 0, 'total_ms' => 0, 'slow' => 0];
            foreach (['hits', 'errors', 'client_errors', 'total_ms', 'slow'] as $f) {
                $acc[$k][$f] += (int) $r->{$f};
            }
        }

        $out = [];
        foreach (self::steps($from, $to, $step) as $key => $label) {
            $a = $acc[$key] ?? ['hits' => 0, 'errors' => 0, 'client_errors' => 0, 'total_ms' => 0, 'slow' => 0];
            $out[] = ['key' => $key, 'label' => $label, 'hits' => $a['hits'], 'errors' => $a['errors'], 'client_errors' => $a['client_errors'],
                'slow' => $a['slow'], 'avg_ms' => $a['hits'] ? (int) round($a['total_ms'] / $a['hits']) : null];
        }

        return $out;
    }

    /** @return array<string, string> cle (Y-m-d H ou Y-m-d) => libelle court */
    public static function steps(Carbon $from, Carbon $to, string $step): array
    {
        $out = [];
        $cursor = $step === 'hour' ? $from->copy()->startOfHour() : $from->copy()->startOfDay();
        $guard = 0;
        while ($cursor->lt($to) && $guard++ < 800) {
            if ($step === 'hour') {
                $out[$cursor->format('Y-m-d H')] = $cursor->format('H\h');
                $cursor->addHour();
            } else {
                $out[$cursor->format('Y-m-d')] = $cursor->format('d/m');
                $cursor->addDay();
            }
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function byService(Carbon $from, Carbon $to): array
    {
        return self::app()->table('monitor_request_stats')->where('bucket', '>=', self::fmt($from))->where('bucket', '<', self::fmt($to))
            ->groupBy('service')
            ->selectRaw('service, SUM(hits) as hits, SUM(CASE WHEN status >= 500 THEN hits ELSE 0 END) as errors, SUM(total_ms) as total_ms, SUM(slow_hits) as slow')
            ->orderByDesc('hits')->get()
            ->map(fn ($r) => [
                'service' => $r->service, 'label' => ServiceMap::label($r->service), 'hits' => (int) $r->hits,
                'errors' => (int) $r->errors, 'slow' => (int) $r->slow,
                'avg_ms' => $r->hits ? (int) round($r->total_ms / $r->hits) : null,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public static function byAction(Carbon $from, Carbon $to): array
    {
        $acc = [];
        $rows = self::app()->table('monitor_request_stats')->where('bucket', '>=', self::fmt($from))->where('bucket', '<', self::fmt($to))
            ->groupBy('method')->selectRaw('method, SUM(hits) as hits, SUM(CASE WHEN status >= 400 THEN hits ELSE 0 END) as failed')->get();
        foreach ($rows as $r) {
            $k = ServiceMap::action($r->method);
            $acc[$k] ??= ['action' => $k, 'label' => ServiceMap::ACTION_LABELS[$k], 'hits' => 0, 'failed' => 0];
            $acc[$k]['hits'] += (int) $r->hits;
            $acc[$k]['failed'] += (int) $r->failed;
        }

        return array_values($acc);
    }

    /** Routes les plus lentes (duree moyenne), avec leur volume. @return array<int, array<string, mixed>> */
    public static function slowRoutes(Carbon $from, Carbon $to, int $limit = 10): array
    {
        return self::app()->table('monitor_request_stats')->where('bucket', '>=', self::fmt($from))->where('bucket', '<', self::fmt($to))
            ->groupBy('route', 'method', 'service')
            ->selectRaw('route, method, service, SUM(hits) as hits, SUM(total_ms) as total_ms, MAX(max_ms) as max_ms, SUM(slow_hits) as slow,
                SUM(h_100) as h_100, SUM(h_300) as h_300, SUM(h_1000) as h_1000, SUM(h_3000) as h_3000,
                SUM(CASE WHEN status >= 500 THEN hits ELSE 0 END) as errors, SUM(queries) as queries')
            ->get()
            ->map(fn ($r) => [
                'route' => $r->route, 'method' => $r->method, 'service' => ServiceMap::label($r->service), 'hits' => (int) $r->hits,
                'avg_ms' => $r->hits ? (int) round($r->total_ms / $r->hits) : null, 'p95_ms' => self::percentile($r, 0.95),
                'max_ms' => (int) $r->max_ms, 'slow' => (int) $r->slow, 'errors' => (int) $r->errors,
                'avg_queries' => $r->hits ? round($r->queries / $r->hits, 1) : null,
            ])
            ->sortByDesc('avg_ms')->take($limit)->values()->all();
    }

    /** @return array<string, mixed> */
    public static function users(Carbon $from, Carbon $to): array
    {
        $today = now()->toDateString();

        return [
            'total' => self::app()->table('users')->count(),
            'active_status' => self::app()->table('users')->where(fn ($q) => $q->where('activity_override', 'active')
                ->orWhere(fn ($q) => $q->whereNull('activity_override')->where(fn ($q) => $q->whereNull('activity_status')->orWhere('activity_status', 'active'))))->count(),
            'blocked' => self::app()->table('users')->whereNotNull('blocked_at')->count(),
            'today' => self::app()->table('monitor_user_days')->where('day', $today)->count(),
            'last_7_days' => self::app()->table('monitor_user_days')->where('day', '>', now()->subDays(7)->toDateString())->distinct()->count('user_id'),
            'last_30_days' => self::app()->table('monitor_user_days')->where('day', '>', now()->subDays(30)->toDateString())->distinct()->count('user_id'),
            'seen_30_days' => self::app()->table('users')->where('last_seen_at', '>=', now()->subDays(30))->count(),
            'in_period' => self::app()->table('monitor_user_days')->where('day', '>=', $from->toDateString())->where('day', '<=', $to->toDateString())->distinct()->count('user_id'),
            'new_accounts' => self::app()->table('users')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            'logins' => self::app()->table('monitor_events')->where('type', 'auth.login')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            'failed_codes' => self::app()->table('monitor_events')->where('type', 'auth.otp_failed')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
        ];
    }

    /** Membres actifs, connexions et nouveaux comptes par jour. @return array<int, array<string, mixed>> */
    public static function userSeries(Carbon $from, Carbon $to): array
    {
        $active = self::app()->table('monitor_user_days')->where('day', '>=', $from->toDateString())->where('day', '<=', $to->toDateString())
            ->groupBy('day')->selectRaw('day, COUNT(*) as n')->pluck('n', 'day')->mapWithKeys(fn ($n, $d) => [substr((string) $d, 0, 10) => $n]);
        $logins = self::perDay(self::app()->table('monitor_events')->where('type', 'auth.login'), $from, $to);
        $created = self::perDay(self::app()->table('users'), $from, $to);

        $out = [];
        foreach (self::steps($from, $to, 'day') as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, 'active' => (int) ($active[$key] ?? 0), 'logins' => (int) ($logins[$key] ?? 0), 'new_accounts' => (int) ($created[$key] ?? 0)];
        }

        return $out;
    }

    /** Erreurs regroupees par empreinte. @return array<int, array<string, mixed>> */
    public static function topErrors(Carbon $from, Carbon $to, int $limit = 8, ?string $service = null): array
    {
        $q = self::app()->table('monitor_events')->whereIn('level', ['error', 'critical', 'alert', 'emergency'])
            ->whereNotNull('fingerprint')->where('created_at', '>=', $from)->where('created_at', '<', $to);
        if ($service) {
            $q->where('service', $service);
        }
        $groups = $q->groupBy('fingerprint')
            ->selectRaw('fingerprint, COUNT(*) as n, MIN(created_at) as first_at, MAX(created_at) as last_at, MAX(id) as last_id, COUNT(DISTINCT user_id) as users')
            ->orderByDesc('n')->limit($limit)->get();
        $samples = self::app()->table('monitor_events')->whereIn('id', $groups->pluck('last_id'))->get(['id', 'service', 'type', 'level', 'message'])->keyBy('id');

        return $groups->map(fn ($g) => [
            'fingerprint' => $g->fingerprint, 'count' => (int) $g->n, 'users' => (int) $g->users,
            'first_at' => Carbon::parse($g->first_at)->toIso8601String(), 'last_at' => Carbon::parse($g->last_at)->toIso8601String(),
            'last_id' => (int) $g->last_id, 'service' => $samples[$g->last_id]->service ?? null,
            'type' => $samples[$g->last_id]->type ?? null, 'level' => $samples[$g->last_id]->level ?? null,
            'message' => $samples[$g->last_id]->message ?? '',
        ])->values()->all();
    }

    /** @return array<string, array<string, mixed>> */
    public static function integrations(Carbon $from, Carbon $to): array
    {
        $out = [];
        foreach (self::app()->table('monitor_integration_stats')->where('bucket', '>=', self::fmt($from->copy()->startOfHour()))->where('bucket', '<', self::fmt($to))
            ->groupBy('integration')->selectRaw('integration, SUM(ok) as ok, SUM(failed) as failed, SUM(total_ms) as total_ms')->get() as $r) {
            $calls = (int) $r->ok + (int) $r->failed;
            $out[$r->integration] = ['ok' => (int) $r->ok, 'failed' => (int) $r->failed, 'avg_ms' => $calls ? (int) round($r->total_ms / $calls) : null];
        }

        return $out;
    }

    /** Disponibilite mesuree par les verifications internes. @return array<string, mixed> */
    public static function availability(Carbon $from, Carbon $to): array
    {
        $row = DB::table('health_samples')->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw("COUNT(*) as n, SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok,
                SUM(CASE WHEN status = 'degraded' THEN 1 ELSE 0 END) as degraded, SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down")
            ->first();
        $n = (int) $row->n;
        $expected = max(1, (int) floor($from->diffInMinutes(min($to, now()), true)));

        return [
            'samples' => $n,
            'ok' => (int) $row->ok,
            'degraded' => (int) $row->degraded,
            'down' => (int) $row->down,
            // Part du temps couverte par une mesure (verification chaque minute).
            'coverage' => $n ? min(100, round($n / $expected * 100, 1)) : 0,
            'availability' => $n ? round(($n - (int) $row->down) / $n * 100, 2) : null,
            'fully_ok' => $n ? round((int) $row->ok / $n * 100, 2) : null,
        ];
    }

    /** Depuis quand la plateforme repond sans interruption constatee. */
    public static function upSince(): ?string
    {
        $lastDown = DB::table('health_samples')->where('status', 'down')->max('created_at');
        $first = $lastDown
            ? DB::table('health_samples')->where('created_at', '>', $lastDown)->min('created_at')
            : DB::table('health_samples')->min('created_at');

        return $first ? Carbon::parse($first)->toIso8601String() : null;
    }

    /** @return array<string, mixed> */
    public static function jobs(Carbon $from, Carbon $to): array
    {
        $rows = self::app()->table('monitor_job_runs')->where('started_at', '>=', $from)->where('started_at', '<', $to)
            ->groupBy('command')->selectRaw("command, COUNT(*) as runs, SUM(CASE WHEN status <> 'ok' THEN 1 ELSE 0 END) as failed, AVG(duration_ms) as avg_ms, MAX(started_at) as last_at")
            ->get();

        return $rows->mapWithKeys(fn ($r) => [$r->command => [
            'runs' => (int) $r->runs, 'failed' => (int) $r->failed, 'avg_ms' => (int) round((float) $r->avg_ms),
            'last_at' => $r->last_at ? Carbon::parse($r->last_at)->toIso8601String() : null,
        ]])->all();
    }

    /** Evenements par jour et par service (journal central). @return array<int, array<string, mixed>> */
    public static function eventSeries(Carbon $from, Carbon $to): array
    {
        $rows = self::app()->table('monitor_events')->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereIn('level', ['warning', 'error', 'critical', 'alert', 'emergency'])
            ->selectRaw("DATE(created_at) as d, CASE WHEN level = 'warning' THEN 'warnings' ELSE 'errors' END as kind, COUNT(*) as n")
            ->groupBy('d', 'kind')->get();
        $acc = [];
        foreach ($rows as $r) {
            $k = substr((string) $r->d, 0, 10);
            $acc[$k] ??= ['errors' => 0, 'warnings' => 0];
            $acc[$k][$r->kind] += (int) $r->n;
        }
        $out = [];
        foreach (self::steps($from, $to, 'day') as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label] + ($acc[$key] ?? ['errors' => 0, 'warnings' => 0]);
        }

        return $out;
    }

    /** Nombre de lignes par jour (colonne created_at). @return \Illuminate\Support\Collection<string, int> */
    private static function perDay(\Illuminate\Database\Query\Builder $q, Carbon $from, Carbon $to)
    {
        return $q->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as n')->groupBy('d')->pluck('n', 'd')
            ->mapWithKeys(fn ($n, $d) => [substr((string) $d, 0, 10) => (int) $n]);
    }
}
