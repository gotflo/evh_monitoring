<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Operator;
use App\Support\Audit;
use App\Support\Once;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * L'agent de supervision, a chaque verification (chaque minute) :
 *  1. releve l'etat de la plateforme vu de l'exterieur (disponibilite mesuree) ;
 *  2. evalue chaque regle active (seuils reglables) ;
 *  3. ouvre ou met a jour l'incident (une cle par probleme : jamais de doublon), lance l'enquete
 *     automatique (cause probable, preuves, actions recommandees) et previent par les canaux actifs ;
 *  4. execute les actions automatiques sures prevues pour la regle (au plus une fois par quart
 *     d'heure et par incident), puis verifie de nouveau au passage suivant ;
 *  5. resout automatiquement les incidents dont la condition a disparu ;
 *  6. produit les rapports periodiques et applique la conservation.
 */
class AlertEngine
{
    public const MIN_INTERVAL_SECONDS = 50;

    /** Delai minimal entre deux actions automatiques sur un meme incident. */
    public const AUTO_ACTION_EVERY_MINUTES = 15;

    /** L'enquete d'un incident ouvert est rafraichie a cet intervalle. */
    public const REINVESTIGATE_MINUTES = 10;

    /** @return array<string, mixed> */
    public static function evaluate(bool $force = false): array
    {
        if (! $force && ! Cache::add('monitor:check-lock', 1, self::MIN_INTERVAL_SECONDS)) {
            return ['ran' => false];
        }
        Cache::forever('monitor:last-check', now()->toIso8601String());

        $health = PlatformHealth::run();
        Cache::forever('monitor:last-health', $health);
        self::sample($health);

        // Localite des connexions recentes (nombre borne a chaque passage).
        try {
            GeoLocator::resolvePending(30);
        } catch (\Throwable $e) {
            report($e);
        }

        $rules = MonitorSettings::rules();
        $findings = [];
        $evaluated = [];
        foreach ($rules as $rule => $params) {
            if (! $params['enabled']) {
                continue;
            }
            try {
                foreach (self::detect($rule, $params, $health) as $f) {
                    $findings[$f['key']] = $f + ['rule' => $rule];
                }
                $evaluated[] = $rule;
            } catch (\Throwable $e) {
                // Mesures illisibles (base de la plateforme injoignable) : la regle n'est pas evaluee,
                // ses incidents restent ouverts (jamais resolus a tort).
                report($e);
            }
        }

        $stats = ['opened' => 0, 'updated' => 0, 'resolved' => 0, 'auto_actions' => 0];
        foreach ($findings as $finding) {
            [$what, $incident] = self::raise($finding, $health);
            $stats[$what]++;
            if (self::autoAct($incident, $rules[$incident->rule] ?? [])) {
                $stats['auto_actions']++;
            }
        }

        foreach (Incident::where('status', '!=', 'resolved')->get() as $incident) {
            $ruleOff = ! ($rules[$incident->rule]['enabled'] ?? false);
            if (isset($findings[$incident->key]) || (! $ruleOff && ! in_array($incident->rule, $evaluated, true))) {
                continue;
            }
            self::resolve($incident, $ruleOff ? 'Règle de détection désactivée.' : 'Retour à la normale constaté par la vérification automatique.');
            $stats['resolved']++;
        }

        try {
            $stats['reports'] = ReportBuilder::runScheduled();
            if (Once::take('purge:'.now()->toDateString())) {
                Retention::purge();
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return ['ran' => true, 'status' => $health['status'], 'findings' => count($findings)] + $stats;
    }

    /** Releve d'etat (disponibilite mesuree de l'exterieur), au plus un par minute. */
    private static function sample(array $health): void
    {
        $last = DB::table('health_samples')->max('created_at');
        if ($last && now()->diffInSeconds(\Illuminate\Support\Carbon::parse($last), true) < 50) {
            return;
        }
        DB::table('health_samples')->insert([
            'created_at' => now(),
            'status' => $health['status'],
            'response_ms' => $health['checks']['platform']['ms'] ?? null,
            'http_status' => $health['checks']['platform']['http_status'] ?? null,
            'checks' => json_encode(collect($health['checks'])->map(fn ($c) => (bool) ($c['ok'] ?? false))->all()),
        ]);
    }

    /** Les N derniers releves verifient-ils la condition ? (anti fausse alerte) */
    private static function consecutive(int $n, callable $test): bool
    {
        $rows = DB::table('health_samples')->orderByDesc('id')->limit($n)->get();

        return $rows->count() >= $n && $rows->every($test);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $health
     * @return array<int, array<string, mixed>>
     */
    private static function detect(string $rule, array $p, array $health): array
    {
        $now = now();
        $since = fn (int $minutes) => $now->copy()->subMinutes($minutes);
        $checks = $health['checks'];
        $label = MonitorSettings::RULES[$rule]['label'];
        $app = DB::connection('app');

        switch ($rule) {
            case 'platform_down':
                $essentialDown = isset($checks['database']) && (! ($checks['database']['ok'] ?? true) || ! ($checks['cache']['ok'] ?? true));
                if ($health['status'] !== 'down' && ! $essentialDown) {
                    return [];
                }
                if (! self::consecutive($p['confirmations'], fn ($r) => $r->status === 'down') && ! $essentialDown) {
                    return [];
                }
                $pl = $checks['platform'];

                return [[
                    'key' => 'platform_down', 'severity' => 'critical', 'title' => $label,
                    'summary' => $pl['http_status'] ? 'La plateforme répond HTTP '.$pl['http_status'].'.' : 'La plateforme ne répond plus ('.($pl['error'] ?? 'aucune réponse').').',
                    'details' => ['platform' => $pl, 'database' => $checks['database'] ?? null],
                ]];

            case 'platform_slow':
                $ms = $checks['platform']['ms'] ?? null;
                if ($ms === null || $ms < $p['ms'] || ! self::consecutive($p['confirmations'], fn ($r) => $r->response_ms !== null && $r->response_ms >= $p['ms'])) {
                    return [];
                }

                return [[
                    'key' => 'platform_slow', 'severity' => 'warning', 'title' => $label,
                    'summary' => 'La page de santé répond en '.$ms.' ms (seuil '.$p['ms'].' ms) depuis '.$p['confirmations'].' mesures.',
                    'details' => ['ms' => $ms],
                ]];

            case 'agent_link':
                $agentOk = $checks['agent']['ok'] ?? false;
                $dbOk = $checks['app_db']['ok'] ?? false;
                // Plateforme entierement arretee : c'est l'incident « indisponible », pas la liaison.
                if (($agentOk && $dbOk) || $health['status'] === 'down') {
                    return [];
                }

                return [[
                    'key' => 'agent_link', 'severity' => 'warning', 'title' => $label,
                    'summary' => ! $dbOk ? 'La console ne peut pas lire la base de la plateforme.' : 'L\'API de la plateforme refuse ou ne répond pas : '.($checks['agent']['error'] ?? '').'.',
                    'details' => ['agent' => $checks['agent'], 'app_db' => $checks['app_db']],
                ]];

            case 'api_errors':
                $m = Metrics::requests($since($p['window_minutes']), $now->copy()->addMinute());
                if ($m['hits'] < $p['min_requests'] || ($m['error_rate'] ?? 0) < $p['threshold_percent']) {
                    return [];
                }

                return [[
                    'key' => 'api_errors', 'severity' => $m['error_rate'] >= max(25, $p['threshold_percent'] * 3) ? 'critical' : 'warning',
                    'title' => $label,
                    'summary' => $m['error_rate'].' % des requêtes en erreur serveur ('.$m['errors'].' sur '.$m['hits'].') ces '.$p['window_minutes'].' dernières minutes.',
                    'details' => ['requests' => $m, 'threshold_percent' => $p['threshold_percent']],
                ]];

            case 'repeated_error':
                $out = [];
                foreach (Metrics::topErrors($since($p['window_minutes']), $now->copy()->addMinute(), 20) as $err) {
                    if ($err['count'] >= $p['count']) {
                        $out[] = [
                            'key' => 'repeated_error:'.$err['fingerprint'], 'severity' => 'warning',
                            'title' => 'Erreur répétée : '.mb_substr($err['message'], 0, 90),
                            'summary' => $err['count'].' occurrences en '.$p['window_minutes'].' minutes ('.Investigator::source($err['service']).').',
                            'details' => $err,
                        ];
                    }
                }

                return $out;

            case 'failure_spike':
                $current = Metrics::requests($since($p['window_minutes']), $now->copy()->addMinute());
                $failures = $current['errors'] + $current['throttled'];
                $base = Metrics::requests($since(1440 + $p['window_minutes']), $since($p['window_minutes']));
                $baseline = ($base['errors'] + $base['throttled']) / max(1, 1440 / $p['window_minutes']);
                if ($failures < $p['min_failures'] || $failures < $p['factor'] * max(1, $baseline)) {
                    return [];
                }

                return [[
                    'key' => 'failure_spike', 'severity' => 'warning', 'title' => $label,
                    'summary' => $failures.' échecs ces '.$p['window_minutes'].' minutes, contre '.round($baseline, 1).' en moyenne sur 24 h.',
                    'details' => ['failures' => $failures, 'baseline' => round($baseline, 2), 'errors' => $current['errors'], 'throttled' => $current['throttled']],
                ]];

            case 'slow_responses':
                $m = Metrics::requests($since($p['window_minutes']), $now->copy()->addMinute());
                if ($m['hits'] < $p['min_requests'] || ($m['p95_ms'] ?? 0) < $p['p95_ms']) {
                    return [];
                }

                return [[
                    'key' => 'slow_responses', 'severity' => 'warning', 'title' => $label,
                    'summary' => '95 % des requêtes répondent en environ '.$m['p95_ms'].' ms ou moins (seuil '.$p['p95_ms'].' ms), moyenne '.$m['avg_ms'].' ms.',
                    'details' => ['requests' => $m],
                ]];

            case 'automation':
                $a = $checks['automation'] ?? null;
                if (! $a) {
                    return [];
                }
                $late = ($a['last_run_minutes'] ?? null) === null || $a['last_run_minutes'] > $p['max_minutes'];
                if (! $late && ! ($a['failed_steps'] ?? 0)) {
                    return [];
                }
                $failedSteps = array_keys(array_filter($a['steps'] ?? [], fn ($s) => ! ($s['ok'] ?? true)));

                return [[
                    'key' => 'automation', 'severity' => 'warning', 'title' => $label,
                    'summary' => ($a['last_run_minutes'] ?? null) === null ? 'Aucun passage des automatismes n\'a été enregistré.'
                        : ($late ? 'Dernier passage il y a '.$a['last_run_minutes'].' minutes.' : 'Étape(s) en échec : '.implode(', ', $failedSteps).'.'),
                    'details' => array_intersect_key($a, array_flip(['last_run_minutes', 'failed_steps', 'trigger', 'cron_seen'])),
                ]];

            case 'push':
                $push = $checks['push'] ?? null;
                if (! $push || $push['ok'] || str_contains((string) ($push['error'] ?? ''), 'desactiv')) {
                    return [];
                }

                return [[
                    'key' => 'push', 'severity' => 'warning', 'title' => $label,
                    'summary' => ($push['waiting'] ?? 0) ? $push['waiting'].' envoi(s) en attente depuis plus de 5 minutes.' : 'Envoi impossible : '.($push['error'] ?? 'cause inconnue').'.',
                    'details' => $push,
                ]];

            case 'sms':
                $failed = $app->table('monitor_events')->where('service', 'sms')->whereIn('level', ['error', 'critical', 'alert', 'emergency'])
                    ->where('created_at', '>=', $since($p['window_minutes']))->count();
                $misconfigured = ($checks['sms']['driver'] ?? null) === 'twilio_verify' && ! ($checks['sms']['configured'] ?? true);
                if ($failed < $p['failures'] && ! $misconfigured) {
                    return [];
                }

                return [[
                    'key' => 'sms', 'severity' => 'critical', 'title' => $label,
                    'summary' => $misconfigured ? 'Twilio Verify est choisi (SMS_DRIVER) mais ses identifiants sont incomplets.'
                        : $failed.' échec(s) d\'envoi ou de vérification des codes ces '.$p['window_minutes'].' minutes.',
                    'details' => ['failed' => $failed],
                ]];

            case 'browser_errors':
                $n = $app->table('monitor_events')->where('service', 'browser')->where('created_at', '>=', $since($p['window_minutes']))->count();

                return $n < $p['count'] ? [] : [[
                    'key' => 'browser_errors', 'severity' => 'warning', 'title' => $label,
                    'summary' => $n.' erreur(s) d\'affichage remontée(s) par les navigateurs ces '.$p['window_minutes'].' minutes.',
                    'details' => ['count' => $n],
                ]];

            case 'disk':
                if (! isset($checks['disk'])) {
                    return [];
                }
                $free = $checks['disk']['free_percent'] ?? null;
                $storageOk = $checks['storage']['ok'] ?? true;
                if ($storageOk && ($free === null || $free >= $p['min_free_percent'])) {
                    return [];
                }

                return [[
                    'key' => 'disk', 'severity' => $storageOk ? 'warning' : 'critical', 'title' => $label,
                    'summary' => ! $storageOk ? 'Les dossiers de stockage de la plateforme ne sont pas accessibles en écriture.'
                        : 'Espace disque libre : '.$free.' % (minimum '.$p['min_free_percent'].' %).',
                    'details' => ['disk' => $checks['disk']],
                ]];

            case 'login_abuse':
                $out = [];
                foreach ($app->table('monitor_events')->where('type', 'auth.otp_failed')->whereNotNull('ip')
                    ->where('created_at', '>=', $since($p['window_minutes']))->groupBy('ip')->selectRaw('ip, COUNT(*) as n')
                    ->havingRaw('COUNT(*) >= ?', [$p['otp_failures']])->get() as $r) {
                    $out[] = ['key' => 'login_abuse:fail:'.$r->ip, 'severity' => 'warning', 'title' => 'Codes de connexion erronés répétés',
                        'summary' => $r->n.' codes erronés depuis l\'adresse '.$r->ip.' en '.$p['window_minutes'].' minutes.', 'details' => ['ip' => $r->ip, 'count' => (int) $r->n]];
                }
                foreach ($app->table('monitor_events')->where('type', 'auth.otp_requested')->whereNotNull('ip')
                    ->where('created_at', '>=', $since(60))->groupBy('ip')->selectRaw('ip, COUNT(*) as n')
                    ->havingRaw('COUNT(*) >= ?', [$p['otp_requests_hour']])->get() as $r) {
                    $out[] = ['key' => 'login_abuse:req:'.$r->ip, 'severity' => 'warning', 'title' => 'Demandes de codes SMS en rafale',
                        'summary' => $r->n.' demandes de code depuis l\'adresse '.$r->ip.' en une heure (coût SMS, possible abus).', 'details' => ['ip' => $r->ip, 'count' => (int) $r->n]];
                }

                return $out;

            case 'foreign_login':
                if (! GeoLocator::enabled()) {
                    return [];
                }
                $home = GeoLocator::homeCountries();
                $logins = $app->table('monitor_events')->where('type', 'auth.login')->whereNotNull('ip')->whereNotNull('user_id')
                    ->where('created_at', '>=', $since($p['window_minutes']))->get(['user_id', 'ip', 'created_at']);
                $places = GeoLocator::lookup($logins->pluck('ip')->all());
                $out = [];
                foreach ($logins as $l) {
                    $place = $places[$l->ip] ?? null;
                    $cc = $place['country_code'] ?? null;
                    if (! $cc || ($place['status'] ?? '') !== 'ok' || in_array($cc, $home, true)) {
                        continue;
                    }
                    $profile = $app->table('profiles')->where('user_id', $l->user_id)->first(['first_name', 'last_name']);
                    $name = $profile ? trim(($profile->first_name ?? '').' '.($profile->last_name ?? '')) : null;
                    $out['foreign_login:'.$l->user_id.':'.$cc] = [
                        'key' => 'foreign_login:'.$l->user_id.':'.$cc, 'severity' => 'warning',
                        'title' => 'Connexion depuis '.($place['country'] ?? $cc).' : '.($name ?: 'compte #'.$l->user_id),
                        'summary' => 'Connexion depuis '.$place['label'].' (adresse '.$l->ip.'), hors des pays habituels ('.implode(', ', $home).').',
                        'details' => ['user_id' => (int) $l->user_id, 'ip' => $l->ip, 'country_code' => $cc, 'location' => $place['label']],
                    ];
                }

                return array_values($out);

            case 'console_denied':
                $n = DB::table('audit_logs')->whereIn('action', ['console.login_denied', 'console.denied'])
                    ->where('created_at', '>=', $since($p['window_minutes']))->count();

                return $n < $p['count'] ? [] : [[
                    'key' => 'console_denied', 'severity' => 'warning', 'title' => $label,
                    'summary' => $n.' accès refusé(s) à la console ces '.$p['window_minutes'].' minutes.', 'details' => ['count' => $n],
                ]];
        }

        return [];
    }

    /**
     * Ouvre, rouvre ou met a jour l'incident d'une detection, et tient son enquete a jour.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $health
     * @return array{0: string, 1: Incident}
     */
    private static function raise(array $f, array $health): array
    {
        $now = now();
        $incident = Incident::where('key', $f['key'])->where('status', '!=', 'resolved')->latest('id')->first();
        $reopened = false;
        if (! $incident) {
            $incident = Incident::where('key', $f['key'])->where('status', 'resolved')
                ->where('resolved_at', '>=', $now->copy()->subMinutes(30))->latest('id')->first();
            if ($incident) {
                $reopened = true;
                $incident->fill(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null]);
                $incident->addTimeline('reopened', 'Le problème est revenu peu après sa résolution : incident rouvert.');
            }
        }

        if ($incident) {
            $escalated = $incident->severity === 'warning' && $f['severity'] === 'critical';
            $incident->fill([
                'severity' => $escalated ? 'critical' : $incident->severity,
                'title' => $f['title'], 'summary' => $f['summary'], 'details' => $f['details'] ?? null,
                'last_seen_at' => $now, 'occurrences' => $incident->occurrences + 1,
            ]);
            if ($escalated) {
                $incident->addTimeline('escalated', 'Aggravation : le problème est passé en niveau critique.');
            }
            $stale = ! isset($incident->investigation['investigated_at'])
                || \Illuminate\Support\Carbon::parse($incident->investigation['investigated_at'])->lt($now->copy()->subMinutes(self::REINVESTIGATE_MINUTES));
            if ($escalated || $reopened || $stale) {
                $incident->investigation = Investigator::run($incident, $health);
            }
            if ($escalated || ($reopened && (! $incident->notified_at || $incident->notified_at->lt($now->copy()->subMinutes(30))))) {
                self::notify($incident, $escalated ? 'Aggravation' : 'Retour du problème');
            }
            $incident->save();

            return ['updated', $incident];
        }

        $incident = new Incident([
            'key' => $f['key'], 'rule' => $f['rule'], 'severity' => $f['severity'], 'status' => 'open',
            'title' => $f['title'], 'summary' => $f['summary'], 'details' => $f['details'] ?? null,
            'occurrences' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now,
        ]);
        $incident->addTimeline('opened', 'Détecté automatiquement : '.$f['summary']);
        $incident->save();
        $incident->investigation = Investigator::run($incident, $health);
        $incident->addTimeline('investigated', 'Enquête automatique : '.$incident->investigation['cause']);
        self::notify($incident, null);
        $incident->save();

        return ['opened', $incident];
    }

    /** Action automatique sure prevue pour la regle (activee dans les reglages), avec garde-fous. */
    private static function autoAct(Incident $incident, array $ruleSettings): bool
    {
        $action = MonitorSettings::RULES[$incident->rule]['auto_action'] ?? null;
        if (! $action || ! ($ruleSettings['auto'] ?? false) || $incident->status === 'resolved') {
            return false;
        }
        if ($incident->auto_action_at && $incident->auto_action_at->gt(now()->subMinutes(self::AUTO_ACTION_EVERY_MINUTES))) {
            return false;
        }
        $agent = app(AgentClient::class);
        if (! $agent->configured()) {
            return false;
        }

        $label = MonitorSettings::AUTO_ACTIONS[$action];
        try {
            $result = $agent->post('actions/'.$action, [], Audit::AGENT);
            $incident->addTimeline('auto_action', 'Action automatique : '.$label.'. Résultat : '.($result['message'] ?? 'ok'), null, Audit::AGENT);
            Audit::log(null, 'auto.'.$action, 'success', 'incident', $incident->id, $incident->title, ['result' => $result['message'] ?? null], Audit::AGENT);
        } catch (AgentException $e) {
            $incident->addTimeline('auto_action', 'Action automatique : '.$label.'. Échec : '.$e->getMessage(), null, Audit::AGENT);
            Audit::log(null, 'auto.'.$action, 'failure', 'incident', $incident->id, $incident->title, ['error' => $e->getMessage()], Audit::AGENT);
        }
        $incident->auto_action_at = now();
        $incident->save();

        return true;
    }

    public static function resolve(Incident $incident, string $note, ?Operator $by = null): void
    {
        $wasNotified = (bool) $incident->notified_at;
        $incident->fill(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $by?->id, 'resolution_note' => $note]);
        $incident->addTimeline('resolved', $note, $by);
        if ($wasNotified && ! $by) {
            $duration = $incident->first_seen_at?->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, false, 2);
            $results = AlertNotifier::send('Résolu : '.$incident->title, [$note, 'Durée : '.$duration.'.'], '/incidents?id='.$incident->id, 'info');
            $incident->addTimeline('notified', AlertNotifier::describe($results));
        }
        $incident->save();
    }

    private static function notify(Incident $incident, ?string $prefix): void
    {
        if (! Once::take('alert:'.$incident->key.':'.now()->format('Y-m-d H:i'))) {
            return;
        }
        $lines = [$incident->summary ?? ''];
        if (! empty($incident->investigation['cause'])) {
            $lines[] = 'Cause probable : '.$incident->investigation['cause'];
        }
        $results = AlertNotifier::send(($prefix ? $prefix.' : ' : '').$incident->title, $lines, '/incidents?id='.$incident->id, $incident->severity);
        $incident->notified_at = now();
        $incident->addTimeline('notified', AlertNotifier::describe($results));
    }
}
