<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Conservation : donnees de la console et mesures de la plateforme (une fois par jour, ou a la demande du proprietaire). */
class Retention
{
    /** @return array<string, int> lignes supprimees par table */
    public static function purge(): array
    {
        $r = MonitorSettings::retention();
        $events = now()->subDays($r['events_days']);
        $metrics = now()->subDays($r['metrics_days']);
        $audit = now()->subDays($r['audit_days']);

        $app = Metrics::app();
        $deleted = [
            // Mesures ecrites par l'agent dans la base de la plateforme.
            'monitor_events' => $app->table('monitor_events')->where('created_at', '<', $events)->delete(),
            'monitor_requests' => $app->table('monitor_requests')->where('created_at', '<', $events)->delete(),
            'monitor_request_stats' => $app->table('monitor_request_stats')->where('bucket', '<', $metrics->format('Y-m-d H:i:s'))->delete(),
            'monitor_integration_stats' => $app->table('monitor_integration_stats')->where('bucket', '<', $metrics->format('Y-m-d H:i:s'))->delete(),
            'monitor_job_runs' => $app->table('monitor_job_runs')->where('started_at', '<', $metrics)->delete(),
            'monitor_user_days' => $app->table('monitor_user_days')->where('day', '<', $metrics->toDateString())->delete(),
            // Donnees de la console.
            'health_samples' => DB::table('health_samples')->where('created_at', '<', $metrics)->delete(),
            'incidents' => DB::table('incidents')->where('status', 'resolved')->where('resolved_at', '<', $metrics)->delete(),
            'reports' => DB::table('reports')->where('created_at', '<', $audit)->delete(),
            'audit_logs' => DB::table('audit_logs')->where('created_at', '<', $audit)->delete(),
        ];
        OtpService::prune();
        \App\Support\Once::prune();

        return array_filter($deleted);
    }
}
