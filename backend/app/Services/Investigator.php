<?php

namespace App\Services;

use App\Models\Incident;
use App\Support\ServiceMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enquete automatique d'un incident : rassemble les preuves dans les mesures (requetes, erreurs,
 * pile d'appels, base de donnees, integrations, taches, mise en ligne recente), en deduit la cause
 * la plus probable avec un niveau de confiance, et propose les actions a faire (certaines
 * executables depuis la console, d'autres a faire sur l'hebergement).
 *
 * Resultat : ['cause' => texte, 'confidence' => elevee|moyenne|faible, 'evidence' => [[label, value]],
 *             'recommendations' => [[text, action?, link?]], 'investigated_at' => date]
 */
class Investigator
{
    /** Codes d'erreur Twilio les plus courants : explication et marche a suivre. */
    public const TWILIO_CODES = [
        20003 => ['Identifiants Twilio refusés', 'Vérifier TWILIO_API_KEY_SID / TWILIO_API_KEY_SECRET sur la plateforme (clé révoquée ou mal copiée).'],
        20404 => ['Vérification introuvable', 'Code expiré ou déjà utilisé : aucun problème de service.'],
        20429 => ['Trop de requêtes simultanées chez Twilio', 'Passager ; si cela dure, réduire les demandes de code (abus possible).'],
        21211 => ['Numéro de téléphone invalide', 'Numéro mal saisi par le membre : aucun problème de service.'],
        21608 => ['Numéro non vérifié ou profil Trust Hub non approuvé', 'Vérifier dans la console Twilio que le profil Trust Hub est approuvé et le compte hors période d\'essai.'],
        21610 => ['Le destinataire a refusé les SMS (STOP)', 'Le membre doit répondre START au numéro Twilio.'],
        60200 => ['Paramètre invalide envoyé à Twilio', 'Vérifier TWILIO_VERIFY_SERVICE_SID et TWILIO_VERIFY_TEMPLATE_SID.'],
        60203 => ['Nombre maximal d\'envois atteint pour ce numéro', 'Attendre 10 minutes ; possible abus si cela se répète.'],
        60410 => ['Envoi bloqué par la protection anti-fraude de Twilio', 'Examiner les demandes de code récentes (Sécurité) et, si besoin, ajuster la protection dans la console Twilio.'],
    ];

    /** @param array<string, mixed>|null $health */
    public static function run(Incident $incident, ?array $health = null): array
    {
        $since = ($incident->first_seen_at ?? now())->copy()->subMinutes(15);
        try {
            $result = match ($incident->rule) {
                'platform_down' => self::platformDown($health),
                'platform_slow', 'slow_responses' => self::slow($since, $health),
                'agent_link' => self::agentLink($health),
                'api_errors', 'failure_spike' => self::errors($since, $health),
                'repeated_error', 'browser_errors' => self::repeated($incident, $since),
                'automation' => self::automation($health),
                'push' => self::push($health),
                'sms' => self::sms($since, $health),
                'disk' => self::disk($health),
                'login_abuse' => self::loginAbuse($incident, $since),
                'console_denied' => self::consoleDenied($since),
                'foreign_login' => self::foreignLogin($incident),
                default => self::generic($incident),
            };
        } catch (\Throwable $e) {
            $result = [
                'cause' => 'Enquête incomplète : les mesures de la plateforme ne sont pas lisibles pour le moment.',
                'confidence' => 'faible',
                'evidence' => [['label' => 'Erreur de lecture', 'value' => mb_substr(\App\Support\Redactor::text($e->getMessage()), 0, 200)]],
                'recommendations' => [['text' => 'Vérifier la liaison avec la plateforme (Réglages, état « Liaison »).']],
            ];
        }

        // Mise en ligne recente : souvent la cause d'un probleme qui vient d'apparaitre.
        $deployed = $health['deployed_at'] ?? null;
        if ($deployed && Carbon::parse($deployed)->gt(($incident->first_seen_at ?? now())->copy()->subHours(3))
            && in_array($incident->rule, ['api_errors', 'failure_spike', 'repeated_error', 'browser_errors', 'platform_down', 'slow_responses', 'platform_slow'], true)) {
            $result['evidence'][] = ['label' => 'Mise en ligne récente', 'value' => 'Version déployée le '.Carbon::parse($deployed)->format('d/m/Y à H:i').', peu avant le début du problème.'];
            $result['recommendations'][] = ['text' => 'Le problème est apparu juste après une mise en ligne : vérifier les fichiers envoyés et les migrations ; si besoin, revenir à la version précédente (procédure de retour arrière).'];
            if ($result['confidence'] === 'faible') {
                $result['confidence'] = 'moyenne';
            }
        }

        return $result + ['investigated_at' => now()->toIso8601String()];
    }

    private static function app(): \Illuminate\Database\Connection
    {
        return DB::connection('app');
    }

    /** @return array<string, mixed> */
    private static function platformDown(?array $h): array
    {
        $c = $h['checks'] ?? [];
        $p = $c['platform'] ?? [];
        $status = $p['http_status'] ?? null;
        $dbOk = $c['database']['ok'] ?? null;
        $appDb = $c['app_db']['ok'] ?? null;
        $evidence = [
            ['label' => 'Page de santé (/api/health)', 'value' => $status ? 'HTTP '.$status.($p['ms'] ? ' en '.$p['ms'].' ms' : '') : ($p['error'] ?? 'aucune réponse')],
            ['label' => 'Base de la plateforme lue par la console', 'value' => $appDb === null ? 'non testée' : ($appDb ? 'accessible' : 'inaccessible')],
            ['label' => 'API de l\'agent', 'value' => ($c['agent']['ok'] ?? false) ? 'répond' : ($c['agent']['error'] ?? 'ne répond pas')],
        ];
        $recent = self::app()->table('monitor_events')->whereIn('level', ['error', 'critical', 'alert', 'emergency'])
            ->where('created_at', '>=', now()->subMinutes(30))->orderByDesc('id')->limit(3)->pluck('message')->all();
        foreach ($recent as $m) {
            $evidence[] = ['label' => 'Erreur récente', 'value' => $m];
        }

        [$cause, $confidence, $reco] = match (true) {
            $status === null && $appDb === false => ['L\'hébergement entier semble arrêté (serveur web et base de données injoignables).', 'élevée',
                ['Vérifier l\'état du service sur le panneau Hostinger (hPanel) et la page d\'état de l\'hébergeur.', 'Contacter le support Hostinger si l\'arrêt dure plus de quelques minutes.']],
            $status === null => ['Le serveur web de la plateforme ne répond pas (arrêt de PHP ou du serveur, nom de domaine ou certificat HTTPS), alors que sa base de données reste accessible.', 'élevée',
                ['Ouvrir l\'adresse de la plateforme dans un navigateur pour confirmer.', 'Vérifier dans hPanel le domaine, le certificat SSL et les ressources (processus PHP saturés).']],
            $status === 503 && $dbOk === false => ['La base de données de la plateforme est indisponible (MySQL arrêté ou trop de connexions).', 'élevée',
                ['Vérifier MySQL dans hPanel (base, utilisateur, limite de connexions).', 'Vérifier DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD dans le .env de la plateforme.']],
            $status === 503 => ['La plateforme se déclare indisponible (cache ou stockage en panne, ou maintenance).', 'moyenne',
                ['Vérifier si un mode maintenance est actif (php artisan up).', 'Vérifier les droits d\'écriture de storage/ et bootstrap/cache/.']],
            $status !== null && $status >= 500 => ['L\'application de la plateforme plante à chaque requête (configuration, fichiers manquants, mise en ligne incomplète).', 'élevée',
                ['Lire les dernières erreurs du serveur (Journaux, fichiers du serveur).', 'Vérifier le .env de la plateforme et relancer php artisan config:cache.']],
            default => ['La plateforme répond de façon inattendue.', 'faible', ['Ouvrir la page de santé de la plateforme et lire les journaux.']],
        };

        return ['cause' => $cause, 'confidence' => $confidence, 'evidence' => $evidence,
            'recommendations' => array_merge(array_map(fn ($t) => ['text' => $t], $reco), [['text' => 'Voir les fichiers journaux du serveur', 'link' => '/journaux?tab=files']])];
    }

    /** @return array<string, mixed> */
    private static function slow(Carbon $since, ?array $h): array
    {
        $now = now()->addMinute();
        $m = Metrics::requests($since, $now);
        $base = Metrics::requests(now()->subDay(), $since);
        $routes = Metrics::slowRoutes($since, $now, 5);
        $slowQueries = self::app()->table('monitor_events')->where('type', 'db.slow_query')->where('created_at', '>=', $since)->count();
        $dbShare = $m['avg_ms'] ? round(($m['avg_db_ms'] ?? 0) / max(1, $m['avg_ms']) * 100) : null;
        $perMinute = $m['hits'] / max(1, $since->diffInMinutes($now, true));
        $basePerMinute = $base['hits'] / max(1, now()->subDay()->diffInMinutes($since, true));

        $evidence = [
            ['label' => 'Temps de réponse', 'value' => 'moyenne '.($m['avg_ms'] ?? '-').' ms, 95 % sous environ '.($m['p95_ms'] ?? '-').' ms (habituellement '.($base['avg_ms'] ?? '-').' ms en moyenne)'],
            ['label' => 'Part du temps passée en base de données', 'value' => $dbShare === null ? 'inconnue' : $dbShare.' %'],
            ['label' => 'Requêtes SQL par appel', 'value' => (string) ($m['avg_queries'] ?? '-')],
            ['label' => 'Requêtes SQL lentes', 'value' => (string) $slowQueries],
            ['label' => 'Volume', 'value' => round($perMinute, 1).' requêtes/min (habituellement '.round($basePerMinute, 1).')'],
        ];
        if (isset($h['checks']['platform']['ms'])) {
            $evidence[] = ['label' => 'Page de santé vue de l\'extérieur', 'value' => $h['checks']['platform']['ms'].' ms'];
        }
        foreach ($routes as $r) {
            $evidence[] = ['label' => 'Route lente', 'value' => $r['method'].' '.$r['route'].' : '.$r['avg_ms'].' ms en moyenne sur '.$r['hits'].' appels, '.$r['avg_queries'].' requêtes SQL'];
        }

        [$cause, $confidence, $reco] = match (true) {
            $m['hits'] === 0 && isset($h['checks']['platform']['ms']) => ['Le serveur de la plateforme répond lentement alors qu\'il y a peu d\'activité : hébergement chargé ou ralenti.', 'moyenne',
                ['Vérifier l\'utilisation des ressources (CPU, processus) dans hPanel.']],
            $dbShare !== null && $dbShare >= 60 => ['La base de données ralentit les réponses ('.$dbShare.' % du temps y est passé)'.($slowQueries ? ', avec '.$slowQueries.' requête(s) SQL lente(s).' : '.'), 'élevée',
                ['Examiner les requêtes SQL lentes (Journaux, onglet Performances).', 'Vérifier la charge MySQL dans hPanel ; un index manquant est la cause la plus fréquente.']],
            $basePerMinute > 0 && $perMinute >= 3 * $basePerMinute => ['Pic d\'activité : '.round($perMinute).' requêtes/min contre '.round($basePerMinute, 1).' d\'habitude ; le serveur sature.', 'moyenne',
                ['Vérifier que le pic est légitime (culte, annonce envoyée à tous) ou s\'il vient d\'une même adresse (Sécurité).', 'Si les pics se répètent, envisager une offre d\'hébergement plus puissante.']],
            ($m['avg_queries'] ?? 0) >= 40 => ['Trop de requêtes SQL par appel ('.$m['avg_queries'].') : un écran charge ses données de façon inefficace.', 'moyenne',
                ['Identifier la route concernée ci-dessus et optimiser son chargement (requêtes groupées).']],
            default => ['Lenteur côté serveur (PHP) sans cause unique dans la base de données.', 'faible',
                ['Comparer avec l\'activité de l\'hébergement (hPanel) ; vérifier les routes lentes ci-dessus.']],
        };

        return ['cause' => $cause, 'confidence' => $confidence, 'evidence' => $evidence,
            'recommendations' => array_merge(array_map(fn ($t) => ['text' => $t], $reco), [['text' => 'Voir les performances détaillées', 'link' => '/journaux?tab=performance']])];
    }

    /** @return array<string, mixed> */
    private static function agentLink(?array $h): array
    {
        $agent = $h['checks']['agent'] ?? [];
        $db = $h['checks']['app_db'] ?? [];
        $evidence = [
            ['label' => 'API de l\'agent', 'value' => ($agent['ok'] ?? false) ? 'répond' : (($agent['http_status'] ?? null) ? 'HTTP '.$agent['http_status'].' : ' : '').($agent['error'] ?? 'ne répond pas')],
            ['label' => 'Base de la plateforme', 'value' => ($db['ok'] ?? false) ? 'lisible' : ($db['error'] ?? 'non lisible')],
            ['label' => 'Adresse configurée', 'value' => (string) config('monitoring.platform.url')],
        ];
        $status = $agent['http_status'] ?? null;
        [$cause, $reco] = match (true) {
            ! ($agent['configured'] ?? true) => ['Le secret partagé n\'est pas configuré dans la console.', ['Renseigner MONITOR_AGENT_SECRET (32 caractères ou plus) dans le .env de la console, identique à celui de la plateforme.']],
            $status === 503 => ['Le secret partagé n\'est pas configuré sur la plateforme.', ['Renseigner MONITOR_AGENT_SECRET dans le .env de la plateforme, puis php artisan config:cache.']],
            $status === 401 => ['La plateforme refuse la signature : secrets différents ou horloges décalées.', ['Copier exactement le même MONITOR_AGENT_SECRET dans les deux .env, puis php artisan config:cache des deux côtés.']],
            $status === 403 => ['L\'adresse IP de la console n\'est pas autorisée par la plateforme.', ['Ajouter l\'IP de la console dans MONITOR_AGENT_ALLOWED_IPS (ou vider cette variable).']],
            $status === 404 => ['L\'API de l\'agent n\'existe pas sur la plateforme (version trop ancienne).', ['Mettre à jour la plateforme (agent de supervision) puis php artisan route:cache.']],
            ! ($db['ok'] ?? true) => ['La console ne peut pas lire la base de la plateforme.', ['Vérifier APP_DB_HOST, APP_DB_DATABASE, APP_DB_USERNAME, APP_DB_PASSWORD dans le .env de la console.']],
            default => ['La plateforme est injoignable depuis la console.', ['Vérifier PLATFORM_URL dans le .env de la console et que la plateforme est en ligne.']],
        };

        return ['cause' => $cause, 'confidence' => 'élevée', 'evidence' => $evidence, 'recommendations' => array_map(fn ($t) => ['text' => $t], $reco)];
    }

    /** @return array<string, mixed> */
    private static function errors(Carbon $since, ?array $h): array
    {
        $now = now()->addMinute();
        $m = Metrics::requests($since, $now);
        $routes = self::app()->table('monitor_requests')->whereIn('reason', ['error', 'throttled'])->where('created_at', '>=', $since)
            ->groupBy('route', 'method', 'status')->selectRaw('route, method, status, COUNT(*) as n, COUNT(DISTINCT user_id) as users')
            ->orderByDesc('n')->limit(5)->get();
        $top = Metrics::topErrors($since, $now, 3);
        $evidence = [['label' => 'Requêtes', 'value' => $m['hits'].' dont '.$m['errors'].' erreurs serveur et '.$m['throttled'].' limitées (429)']];
        foreach ($routes as $r) {
            $evidence[] = ['label' => 'Route en échec', 'value' => $r->method.' '.$r->route.' : HTTP '.$r->status.', '.$r->n.' fois, '.$r->users.' membre(s)'];
        }
        $location = null;
        foreach ($top as $e) {
            $ctx = json_decode((string) self::app()->table('monitor_events')->where('id', $e['last_id'])->value('context'), true) ?: [];
            $where = $ctx['file'] ?? null;
            $location ??= $where;
            $evidence[] = ['label' => 'Erreur ('.$e['count'].' fois)', 'value' => $e['message'].($where ? ' - '.$where : '')];
        }
        $totalErrors = max(1, array_sum(array_column($top, 'count')));
        $dominant = $top[0] ?? null;

        if ($m['throttled'] > $m['errors']) {
            $ips = self::app()->table('monitor_requests')->where('reason', 'throttled')->where('created_at', '>=', $since)
                ->groupBy('ip')->selectRaw('ip, COUNT(*) as n')->orderByDesc('n')->limit(3)->get();
            foreach ($ips as $ip) {
                $evidence[] = ['label' => 'Adresse limitée', 'value' => $ip->ip.' : '.$ip->n.' requêtes refusées'];
            }
            $cause = 'La majorité des échecs sont des limites de requêtes (429) : afflux important ou programme automatisé.';
            $confidence = 'moyenne';
            $reco = ['Si une seule adresse domine, la bloquer dans le pare-feu de l\'hébergeur (hPanel, Sécurité).', 'Si l\'afflux est légitime (culte), rien à faire : les limites protègent le serveur.'];
        } elseif ($dominant && $dominant['count'] / $totalErrors >= 0.6) {
            $dbLike = ($dominant['service'] ?? '') === 'database';
            $cause = $dbLike
                ? 'Une erreur de base de données provoque la plupart des échecs : '.$dominant['message']
                : 'Une erreur précise du code provoque la plupart des échecs'.($location ? ' ('.$location.')' : '').' : '.$dominant['message'];
            $confidence = 'élevée';
            $reco = [$dbLike ? 'Vérifier MySQL (hPanel) et les migrations : une table ou une colonne manque peut-être (php artisan migrate --force).'
                : 'Corriger le code à l\'emplacement indiqué ; la pile d\'appels est dans le détail de l\'erreur.'];
        } elseif (($h['checks']['database']['ok'] ?? true) === false) {
            $cause = 'La base de données de la plateforme est en panne : les requêtes échouent.';
            $confidence = 'élevée';
            $reco = ['Vérifier MySQL dans hPanel.'];
        } else {
            $cause = 'Plusieurs erreurs différentes en même temps : problème général (ressources de l\'hébergement, configuration) plutôt qu\'un défaut de code unique.';
            $confidence = 'faible';
            $reco = ['Lire les erreurs regroupées et les fichiers journaux du serveur.'];
        }

        $recommendations = array_map(fn ($t) => ['text' => $t], $reco);
        if ($dominant) {
            $recommendations[] = ['text' => 'Voir toutes les occurrences de l\'erreur principale', 'link' => '/journaux?fingerprint='.$dominant['fingerprint'].'&period=7d'];
        }

        return ['cause' => $cause, 'confidence' => $confidence, 'evidence' => $evidence, 'recommendations' => $recommendations];
    }

    /** @return array<string, mixed> */
    private static function repeated(Incident $incident, Carbon $since): array
    {
        $fingerprint = $incident->details['fingerprint'] ?? null;
        $q = self::app()->table('monitor_events')->where('created_at', '>=', $since);
        $fingerprint ? $q->where('fingerprint', $fingerprint) : $q->where('service', 'browser');
        $events = (clone $q)->orderByDesc('id')->limit(200)->get(['id', 'message', 'context', 'route', 'user_id', 'service', 'type']);
        if ($events->isEmpty()) {
            return self::generic($incident);
        }
        $last = $events->first();
        $ctx = json_decode((string) $last->context, true) ?: [];
        $byRoute = $events->countBy(fn ($e) => $e->route ?: 'inconnue')->sortDesc()->take(3);
        $users = $events->pluck('user_id')->filter()->unique()->count();
        $browsers = $events->map(fn ($e) => (json_decode((string) $e->context, true) ?: [])['browser'] ?? null)->filter()->countBy()->sortDesc()->take(3);

        $evidence = [
            ['label' => 'Occurrences analysées', 'value' => $events->count().' depuis le '.$since->format('d/m H:i')],
            ['label' => 'Message', 'value' => $last->message],
            ['label' => 'Membres touchés', 'value' => (string) $users],
        ];
        foreach ($byRoute as $route => $n) {
            $evidence[] = ['label' => $last->service === 'browser' ? 'Page' : 'Route', 'value' => $route.' ('.$n.' fois)'];
        }
        foreach ($browsers as $b => $n) {
            $evidence[] = ['label' => 'Appareil', 'value' => $b.' ('.$n.' fois)'];
        }
        if (! empty($ctx['file'])) {
            $evidence[] = ['label' => 'Emplacement', 'value' => $ctx['file']];
        }
        if (! empty($ctx['trace'][0]) || ! empty($ctx['stack'][1])) {
            $evidence[] = ['label' => 'Première ligne de la pile', 'value' => (string) ($ctx['trace'][0] ?? $ctx['stack'][1])];
        }

        // Causes connues, reconnues a leur message : explication et remede precis.
        if (str_contains((string) $last->message, 'proc_open')) {
            return [
                'cause' => 'Le cron de la plateforme lance « schedule:run », mais l\'hébergeur a désactivé la fonction PHP proc_open dont le planificateur Laravel a besoin pour démarrer chaque tâche : '
                    .'aucune tâche planifiée ne passe par le cron (les automatismes ne tournent qu\'avec l\'activité des membres, et les notifications en attente ne sont plus rattrapées chaque minute).',
                'confidence' => 'élevée',
                'evidence' => $evidence,
                'recommendations' => [
                    ['text' => 'Dans hPanel (Avancé, Tâches Cron), remplacer la tâche « schedule:run » de la plateforme par deux tâches qui lancent les commandes directement, dans le dossier de la plateforme : '
                        .'« php artisan app:push-outbox » chaque minute et « php artisan app:tick » toutes les 5 minutes.'],
                    ['text' => 'Vérifier ensuite qu\'aucune nouvelle occurrence n\'apparaît (l\'incident se résout seul).', 'link' => $fingerprint ? '/journaux?fingerprint='.$fingerprint.'&period=7d' : '/journaux'],
                ],
            ];
        }

        $browser = $last->service === 'browser';
        $onePage = $byRoute->count() === 1;
        $cause = $browser
            ? 'Erreur d\'affichage dans le navigateur'.($onePage ? ' sur la page '.$byRoute->keys()->first() : '').($browsers->count() === 1 ? ', seulement sur '.$browsers->keys()->first() : '').'.'
            : 'Erreur du serveur qui se répète'.(! empty($ctx['file']) ? ' dans '.$ctx['file'] : '').'.';

        return [
            'cause' => $cause,
            'confidence' => $onePage || ! empty($ctx['file']) ? 'élevée' : 'moyenne',
            'evidence' => $evidence,
            'recommendations' => array_values(array_filter([
                ['text' => $browser ? 'Reproduire sur la page indiquée avec le même appareil, puis corriger le composant concerné (pile ci-dessus).' : 'Corriger le code à l\'emplacement indiqué ; la pile d\'appels complète est dans le détail.'],
                $browser && $browsers->count() === 1 ? ['text' => 'L\'erreur ne touche qu\'un type d\'appareil : vérifier la compatibilité de ce navigateur.'] : null,
                ['text' => 'Voir toutes les occurrences', 'link' => $fingerprint ? '/journaux?fingerprint='.$fingerprint.'&period=7d' : '/journaux?service=browser'],
            ])),
        ];
    }

    /** @return array<string, mixed> */
    private static function automation(?array $h): array
    {
        $a = $h['checks']['automation'] ?? [];
        $cron = (bool) ($a['cron_seen'] ?? false);
        $failed = array_filter($a['steps'] ?? [], fn ($s) => ! ($s['ok'] ?? true));
        $evidence = [
            ['label' => 'Dernier passage', 'value' => isset($a['last_run_minutes']) ? 'il y a '.$a['last_run_minutes'].' min'.(! empty($a['trigger']) ? ' (déclenché par : '.$a['trigger'].')' : '') : 'jamais enregistré'],
            ['label' => 'Cron de l\'hébergeur', 'value' => $cron ? 'actif' : 'non détecté depuis 30 min'],
            ['label' => 'Secours par l\'activité des membres', 'value' => ($a['auto_tick'] ?? false) ? 'activé (AUTO_TICK)' : 'désactivé'],
        ];
        foreach ($failed as $step => $s) {
            $evidence[] = ['label' => 'Étape en échec : '.$step, 'value' => (string) ($s['error'] ?? '')];
        }
        $lastRun = self::app()->table('monitor_job_runs')->where('command', 'app:tick')->orderByDesc('id')->first();
        if ($lastRun) {
            $evidence[] = ['label' => 'Dernier passage enregistré', 'value' => Carbon::parse($lastRun->started_at)->format('d/m H:i').', '.$lastRun->duration_ms.' ms, résultat '.$lastRun->status];
        }

        if ($failed) {
            return ['cause' => 'Les automatismes tournent mais '.count($failed).' étape(s) échoue(nt) : '.implode(', ', array_keys($failed)).'.', 'confidence' => 'élevée', 'evidence' => $evidence,
                'recommendations' => [['text' => 'Lire l\'erreur de l\'étape (ci-dessus) et les journaux du serveur.', 'link' => '/journaux?service=automation'], ['text' => 'Relancer les automatismes après correction.', 'action' => 'run_automation']]];
        }

        return ['cause' => $cron ? 'Le cron tourne mais les automatismes ne sont pas passés récemment (processus interrompu ou verrou resté en place).'
            : 'Le cron de l\'hébergeur ne lance pas les tâches : les automatismes ne passent qu\'avec l\'activité des membres (la nuit, plus rien).',
            'confidence' => $cron ? 'moyenne' : 'élevée', 'evidence' => $evidence,
            'recommendations' => array_values(array_filter([
                $cron ? null : ['text' => 'Configurer dans hPanel (Avancé, Tâches Cron) deux tâches dans le dossier de la plateforme : « php artisan app:push-outbox » chaque minute et « php artisan app:tick » toutes les 5 minutes (commandes lancées directement : elles fonctionnent même si l\'hébergeur désactive proc_open, contrairement à schedule:run).'],
                ['text' => 'Relancer les automatismes maintenant.', 'action' => 'run_automation'],
            ]))];
    }

    /** @return array<string, mixed> */
    private static function push(?array $h): array
    {
        $p = $h['checks']['push'] ?? [];
        $evidence = [
            ['label' => 'Envois en attente', 'value' => (string) ($p['waiting'] ?? 0)],
            ['label' => 'Appareils abonnés', 'value' => (string) ($p['devices'] ?? 0)],
            ['label' => 'Dernière réception par un appareil', 'value' => isset($p['last_delivery_hours']) ? 'il y a '.$p['last_delivery_hours'].' h' : 'inconnue'],
        ];
        if (! empty($p['error'])) {
            $evidence[] = ['label' => 'Erreur', 'value' => (string) $p['error']];
        }
        $stats = Metrics::integrations(now()->subHours(6), now())['webpush'] ?? null;
        if ($stats) {
            $evidence[] = ['label' => 'Envois sur 6 h', 'value' => $stats['ok'].' réussis, '.$stats['failed'].' en échec'];
        }

        return ! empty($p['error'])
            ? ['cause' => 'Le chiffrement des notifications est impossible : '.$p['error'].'.', 'confidence' => 'élevée', 'evidence' => $evidence,
                'recommendations' => [['text' => 'Vérifier VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY (ou storage/app/webpush-vapid.json) et l\'extension OpenSSL de PHP sur la plateforme.']]]
            : ['cause' => 'Des envois restent en attente : l\'envoi après la réponse a été interrompu ou le service push des navigateurs est injoignable.', 'confidence' => 'moyenne', 'evidence' => $evidence,
                'recommendations' => [['text' => 'Renvoyer les notifications en attente.', 'action' => 'flush_push'], ['text' => 'Vérifier que le cron lance app:push-outbox chaque minute.']]];
    }

    /** @return array<string, mixed> */
    private static function sms(Carbon $since, ?array $h): array
    {
        $events = self::app()->table('monitor_events')->where('service', 'sms')->where('created_at', '>=', $since)
            ->whereIn('level', ['warning', 'error', 'critical'])->orderByDesc('id')->limit(100)->get(['message', 'context']);
        $codes = [];
        foreach ($events as $e) {
            $text = $e->message.' '.$e->context;
            if (preg_match_all('/(?:twilio_code"?\s*:\s*|Twilio code )(\d{5})/i', $text, $m)) {
                foreach ($m[1] as $code) {
                    $codes[(int) $code] = ($codes[(int) $code] ?? 0) + 1;
                }
            }
        }
        arsort($codes);
        $s = $h['checks']['sms'] ?? [];
        $evidence = [
            ['label' => 'Mode', 'value' => (string) ($s['driver'] ?? 'inconnu').(($s['configured'] ?? false) ? ', identifiants complets' : ', identifiants incomplets')],
            ['label' => 'Échecs analysés', 'value' => (string) $events->count()],
        ];
        foreach ($codes as $code => $n) {
            $evidence[] = ['label' => 'Code Twilio '.$code.' ('.$n.' fois)', 'value' => self::TWILIO_CODES[$code][0] ?? 'code non répertorié'];
        }
        $main = array_key_first($codes);

        return [
            'cause' => ! ($s['configured'] ?? true) ? 'Twilio Verify est choisi mais ses identifiants sont incomplets sur la plateforme.'
                : ($main ? self::TWILIO_CODES[$main][0] ?? 'Twilio refuse les envois (code '.$main.').' : 'Twilio Verify ne répond pas ou refuse les envois.'),
            'confidence' => $main || ! ($s['configured'] ?? true) ? 'élevée' : 'moyenne',
            'evidence' => $evidence,
            'recommendations' => array_values(array_filter([
                $main && isset(self::TWILIO_CODES[$main]) ? ['text' => self::TWILIO_CODES[$main][1]] : null,
                ['text' => 'Lancer le diagnostic SMS (sans envoi).', 'action' => 'sms_check'],
                ['text' => 'Consulter la page d\'état de Twilio (status.twilio.com).'],
            ])),
        ];
    }

    /** @return array<string, mixed> */
    private static function disk(?array $h): array
    {
        $d = $h['checks']['disk'] ?? [];
        $evidence = [
            ['label' => 'Espace libre', 'value' => isset($d['free_percent']) ? $d['free_percent'].' % ('.($d['free_gb'] ?? '?').' Go)' : 'inconnu'],
            ['label' => 'Stockage accessible en écriture', 'value' => ($h['checks']['storage']['ok'] ?? false) ? 'oui' : 'non'],
        ];
        try {
            $files = app(AgentClient::class)->get('logs/files')['files'] ?? [];
            $size = array_sum(array_column($files, 'size'));
            $evidence[] = ['label' => 'Fichiers journaux', 'value' => count($files).' fichier(s), '.round($size / 1048576, 1).' Mo'];
        } catch (\Throwable) {
            // detail facultatif
        }

        return ['cause' => ($h['checks']['storage']['ok'] ?? true) ? 'L\'espace disque de l\'hébergement est presque plein.' : 'Les dossiers de stockage ne sont pas accessibles en écriture (droits).',
            'confidence' => 'élevée', 'evidence' => $evidence,
            'recommendations' => [
                ['text' => 'Supprimer les anciennes sauvegardes et fichiers inutiles dans le gestionnaire de fichiers hPanel.'],
                ['text' => 'Garder LOG_STACK=daily et LOG_DAILY_DAYS=14 sur la plateforme.'],
                ['text' => 'Si les droits sont en cause : dossiers storage/ et bootstrap/cache/ en 775.'],
            ]];
    }

    /** @return array<string, mixed> */
    private static function loginAbuse(Incident $incident, Carbon $since): array
    {
        $ip = $incident->details['ip'] ?? null;
        $events = self::app()->table('monitor_events')->whereIn('type', ['auth.otp_failed', 'auth.otp_requested'])->where('ip', $ip)
            ->where('created_at', '>=', $since->copy()->subHour())->get(['type', 'context', 'created_at']);
        $phones = $events->map(fn ($e) => (json_decode((string) $e->context, true) ?: [])['phone'] ?? null)->filter()->unique();
        $failed = $events->where('type', 'auth.otp_failed')->count();
        $requested = $events->where('type', 'auth.otp_requested')->count();
        $logins = self::app()->table('monitor_events')->where('type', 'auth.login')->where('ip', $ip)->where('created_at', '>=', $since)->count();

        $evidence = [
            ['label' => 'Adresse IP', 'value' => (string) $ip],
            ['label' => 'Codes demandés', 'value' => (string) $requested],
            ['label' => 'Codes erronés', 'value' => (string) $failed],
            ['label' => 'Numéros visés', 'value' => $phones->count().' ('.$phones->take(5)->implode(', ').')'],
            ['label' => 'Connexions réussies depuis cette adresse', 'value' => (string) $logins],
        ];
        $pumping = $requested > $failed * 2 && $phones->count() > 3;

        return [
            'cause' => $pumping ? 'Envoi abusif de SMS (« SMS pumping ») : beaucoup de numéros différents depuis une même adresse, pour faire payer des SMS.'
                : ($phones->count() <= 2 ? 'Tentatives répétées de deviner le code d\'un ou deux comptes (force brute).' : 'Activité de connexion anormale depuis une même adresse.'),
            'confidence' => $logins > 0 && ! $pumping ? 'faible' : 'moyenne',
            'evidence' => $evidence,
            'recommendations' => array_values(array_filter([
                ['text' => 'Bloquer l\'adresse '.$ip.' dans le pare-feu de l\'hébergeur (hPanel, Sécurité, Gestionnaire d\'IP) si elle n\'est pas celle de l\'église.'],
                $pumping ? ['text' => 'Activer la protection anti-fraude « Fraud Guard » de Twilio Verify et limiter les pays autorisés.'] : null,
                $logins > 0 ? ['text' => 'Des connexions ont réussi depuis cette adresse : vérifier les comptes concernés (Activité) et fermer leurs sessions si besoin.', 'link' => '/activite?service=connexion'] : null,
            ])),
        ];
    }

    /** @return array<string, mixed> */
    private static function consoleDenied(Carbon $since): array
    {
        $rows = DB::table('audit_logs')->whereIn('action', ['console.login_denied', 'console.denied', 'console.login_failed'])
            ->where('created_at', '>=', $since)->orderByDesc('id')->limit(20)->get(['action', 'operator_label', 'target_label', 'ip', 'created_at']);
        $byIp = $rows->countBy('ip')->sortDesc();
        $evidence = [['label' => 'Tentatives', 'value' => (string) $rows->count()]];
        foreach ($byIp->take(3) as $ip => $n) {
            $evidence[] = ['label' => 'Adresse', 'value' => $ip.' ('.$n.' fois)'];
        }
        foreach ($rows->take(5) as $r) {
            $evidence[] = ['label' => Carbon::parse($r->created_at)->format('d/m H:i'), 'value' => (\App\Support\Audit::LABELS[$r->action] ?? $r->action).' : '.($r->operator_label ?: $r->target_label)];
        }
        $insiders = $rows->where('action', 'console.denied')->count();

        return ['cause' => $insiders ? 'Une personne autorisée a tenté des actions au-delà de son rôle.' : 'Des numéros non autorisés essaient de se connecter à la console.',
            'confidence' => 'élevée', 'evidence' => $evidence,
            'recommendations' => [
                ['text' => $insiders ? 'Vérifier si le rôle de cette personne doit être élargi (Accès) ou s\'il s\'agit d\'un usage anormal.' : 'Si les tentatives persistent depuis une même adresse, la bloquer dans le pare-feu de l\'hébergeur.'],
                ['text' => 'Voir le journal d\'audit', 'link' => '/audit?outcome=denied'],
            ]];
    }

    /** @return array<string, mixed> */
    private static function foreignLogin(Incident $incident): array
    {
        $userId = (int) ($incident->details['user_id'] ?? 0);
        $logins = self::app()->table('monitor_events')->where('type', 'auth.login')->where('user_id', $userId)
            ->whereNotNull('ip')->orderByDesc('id')->limit(50)->get(['ip', 'created_at']);
        $places = GeoLocator::lookup($logins->pluck('ip')->all());
        $usual = $logins->map(fn ($l) => $places[$l->ip]['label'] ?? null)->filter()->countBy()->sortDesc();
        $failed = self::app()->table('monitor_events')->where('type', 'auth.otp_failed')->where('ip', $incident->details['ip'] ?? '')->count();
        $sessions = self::app()->table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->where('tokenable_id', $userId)->count();

        $evidence = [
            ['label' => 'Localité de la connexion', 'value' => (string) ($incident->details['location'] ?? '')],
            ['label' => 'Adresse IP', 'value' => (string) ($incident->details['ip'] ?? '')],
            ['label' => 'Codes erronés depuis cette adresse', 'value' => (string) $failed],
            ['label' => 'Sessions ouvertes du membre', 'value' => (string) $sessions],
        ];
        foreach ($usual->take(4) as $place => $n) {
            $evidence[] = ['label' => 'Localité habituelle', 'value' => $place.' ('.$n.' connexion(s))'];
        }
        $firstTime = ! $usual->has($incident->details['location'] ?? "\0") || $usual[$incident->details['location']] <= 1;

        return [
            'cause' => $firstTime
                ? 'Première connexion de ce membre depuis cette localité : voyage, réseau privé virtuel (VPN), ou compte utilisé par quelqu\'un d\'autre.'
                : 'Ce membre s\'est déjà connecté depuis cette localité : probablement un séjour ou un réseau privé virtuel.',
            'confidence' => $failed > 0 ? 'moyenne' : 'faible',
            'evidence' => $evidence,
            'recommendations' => [
                ['text' => 'Vérifier auprès de la personne qu\'elle est bien à l\'origine de cette connexion.'],
                ['text' => 'Si ce n\'est pas elle : fermer ses sessions, puis bloquer le compte le temps de vérifier.', 'link' => '/utilisateurs/'.$userId],
                ['text' => 'Pays fréquents pour l\'église (voyages, famille) : les ajouter à MONITOR_HOME_COUNTRIES.'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function generic(Incident $incident): array
    {
        return ['cause' => $incident->summary ?? 'Problème détecté.', 'confidence' => 'faible',
            'evidence' => collect($incident->details ?? [])->filter(fn ($v) => is_scalar($v))->map(fn ($v, $k) => ['label' => (string) $k, 'value' => (string) $v])->values()->all(),
            'recommendations' => [['text' => 'Consulter le journal central sur la période.', 'link' => '/journaux']]];
    }

    /** Libelle lisible d'un service du journal central. */
    public static function source(?string $service): string
    {
        return ServiceMap::SOURCES[$service] ?? ($service ?? '');
    }
}
