<?php

namespace App\Services;

use App\Models\Operator;
use App\Models\Report;
use App\Support\Once;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rapports periodiques de supervision : disponibilite, utilisation, erreurs, incidents et
 * tendances par rapport a la periode precedente. Les chiffres sont figes a la generation.
 */
class ReportBuilder
{
    public const PERIOD_LABELS = ['daily' => 'Rapport quotidien', 'weekly' => 'Rapport hebdomadaire', 'monthly' => 'Rapport mensuel', 'custom' => 'Rapport à la demande'];

    /** @return array<string, mixed> */
    public static function build(Carbon $from, Carbon $to): array
    {
        $length = $from->diffInSeconds($to, true);
        $prevFrom = $from->copy()->subSeconds((int) $length);

        $requests = Metrics::requests($from, $to);
        $previous = Metrics::requests($prevFrom, $from);
        $users = Metrics::users($from, $to);
        $prevUsers = Metrics::users($prevFrom, $from);
        $incidents = DB::table('incidents')->where('first_seen_at', '>=', $from)->where('first_seen_at', '<', $to);

        $trend = fn ($now, $before) => $now === null || $before === null || (float) $before === 0.0 ? null : round(($now - $before) / $before * 100, 1);
        $days = max(1, (int) ceil($length / 86400));

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'days' => $days],
            'collected_since' => MonitorSettings::installedAt(),
            'availability' => Metrics::availability($from, $to),
            'requests' => $requests,
            'requests_previous' => $previous,
            'users' => [
                'active' => $users['in_period'],
                'logins' => $users['logins'],
                'new_accounts' => $users['new_accounts'],
                'failed_codes' => $users['failed_codes'],
                'total' => $users['total'],
                'blocked' => $users['blocked'],
            ],
            'trends' => [
                'requests' => $trend($requests['hits'], $previous['hits']),
                'errors' => $trend($requests['errors'], $previous['errors']),
                'avg_ms' => $trend($requests['avg_ms'], $previous['avg_ms']),
                'active_users' => $trend($users['in_period'], $prevUsers['in_period']),
                'logins' => $trend($users['logins'], $prevUsers['logins']),
            ],
            'by_service' => array_slice(Metrics::byService($from, $to), 0, 8),
            'top_errors' => Metrics::topErrors($from, $to, 5),
            'slow_routes' => Metrics::slowRoutes($from, $to, 5),
            'integrations' => Metrics::integrations($from, $to),
            'jobs' => Metrics::jobs($from, $to),
            'incidents' => [
                'opened' => (clone $incidents)->count(),
                'critical' => (clone $incidents)->where('severity', 'critical')->count(),
                'resolved' => DB::table('incidents')->where('resolved_at', '>=', $from)->where('resolved_at', '<', $to)->count(),
                'still_open' => DB::table('incidents')->where('status', '!=', 'resolved')->count(),
                'list' => (clone $incidents)->orderByDesc('id')->limit(10)->get(['id', 'title', 'severity', 'status', 'first_seen_at', 'resolved_at'])
                    ->map(fn ($i) => (array) $i)->all(),
            ],
            'events' => [
                'errors' => Metrics::app()->table('monitor_events')->whereIn('level', ['error', 'critical', 'alert', 'emergency'])->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
                'warnings' => Metrics::app()->table('monitor_events')->where('level', 'warning')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
                'browser' => Metrics::app()->table('monitor_events')->where('service', 'browser')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            ],
            'security' => [
                'denied' => $requests['denied'],
                'throttled' => $requests['throttled'],
                'console_denied' => DB::table('audit_logs')->whereIn('action', ['console.login_denied', 'console.denied'])->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            ],
        ];
    }

    public static function generate(string $period, Carbon $from, Carbon $to, ?Operator $by = null, bool $send = true): Report
    {
        $report = Report::create([
            'period' => $period, 'period_start' => $from, 'period_end' => $to,
            'data' => self::build($from, $to), 'generated_by' => $by?->id,
        ]);

        if ($send) {
            $d = $report->data;
            $avail = $d['availability']['availability'];
            $lines = [
                'Période : du '.$from->format('d/m/Y H\hi').' au '.$to->format('d/m/Y H\hi'),
                'Disponibilité mesurée : '.($avail === null ? 'non mesurée' : $avail.' %').' (couverture '.$d['availability']['coverage'].' %)',
                'Requêtes : '.$d['requests']['hits'].' - erreurs serveur : '.$d['requests']['errors'].($d['requests']['error_rate'] !== null ? ' ('.$d['requests']['error_rate'].' %)' : ''),
                'Membres actifs : '.$d['users']['active'].' - connexions : '.$d['users']['logins'].' - nouveaux comptes : '.$d['users']['new_accounts'],
                'Incidents : '.$d['incidents']['opened'].' ouverts, '.$d['incidents']['resolved'].' résolus, '.$d['incidents']['still_open'].' en cours',
            ];
            $results = AlertNotifier::send(self::PERIOD_LABELS[$period].' de supervision', $lines, '/rapports?id='.$report->id, 'info');
            $report->update(['sent_at' => $results ? now() : null, 'delivery' => $results]);
        }

        return $report;
    }

    /** Rapports planifies dus (appele par la verification periodique). Retourne le nombre genere. */
    public static function runScheduled(): int
    {
        $now = now();
        $settings = MonitorSettings::reports();
        $made = 0;
        $due = [];
        if ($settings['daily'] && $now->hour >= 7) {
            $due[] = ['daily', $now->copy()->subDay()->startOfDay(), $now->copy()->startOfDay()];
        }
        if ($settings['weekly'] && $now->isMonday() && $now->hour >= 8) {
            $due[] = ['weekly', $now->copy()->subWeek()->startOfWeek(Carbon::MONDAY), $now->copy()->startOfWeek(Carbon::MONDAY)];
        }
        if ($settings['monthly'] && $now->day === 1 && $now->hour >= 8) {
            $due[] = ['monthly', $now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->startOfMonth()];
        }
        foreach ($due as [$period, $from, $to]) {
            $key = 'monitor-report:'.$period.':'.$from->toDateString();
            if (Once::take($key)) {
                try {
                    self::generate($period, $from, $to);
                    $made++;
                } catch (\Throwable $e) {
                    Once::release($key);
                    report($e);
                }
            }
        }

        return $made;
    }
}
