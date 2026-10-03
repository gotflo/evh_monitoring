<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Reglages de la supervision modifiables depuis la console (seuils d'alerte, conservation,
 * rapports, canaux). Valeurs par defaut : config/monitoring.php et la liste des regles.
 */
class MonitorSettings
{
    /** Regles de detection : libelle, explication, parametres (valeur par defaut, bornes, unite). */
    public const RULES = [
        'platform_down' => [
            'label' => 'Plateforme indisponible',
            'help' => 'La plateforme ne répond plus, répond en erreur, ou sa base de données ou son cache sont en panne : les membres ne peuvent plus utiliser l\'application.',
            'severity' => 'critical',
            'params' => [
                'confirmations' => ['label' => 'Échecs consécutifs avant alerte', 'unit' => '', 'default' => 2, 'min' => 1, 'max' => 10],
            ],
        ],
        'platform_slow' => [
            'label' => 'Plateforme lente à répondre',
            'help' => 'La page de santé de la plateforme met trop de temps à répondre, vue depuis la console.',
            'severity' => 'warning',
            'params' => [
                'ms' => ['label' => 'Délai de réponse', 'unit' => 'ms', 'default' => 3000, 'min' => 500, 'max' => 30000],
                'confirmations' => ['label' => 'Mesures lentes consécutives', 'unit' => '', 'default' => 3, 'min' => 1, 'max' => 30],
            ],
        ],
        'agent_link' => [
            'label' => 'Liaison avec la plateforme',
            'help' => 'L\'API signée de la plateforme ou la lecture de sa base échoue : mesures ou actions indisponibles depuis la console.',
            'severity' => 'warning',
            'params' => [],
        ],
        'api_errors' => [
            'label' => 'Taux d\'erreurs serveur élevé',
            'help' => 'Part des requêtes de l\'API terminées par une erreur serveur (5xx) sur la période.',
            'severity' => 'warning',
            'params' => [
                'threshold_percent' => ['label' => 'Seuil', 'unit' => '%', 'default' => 5, 'min' => 1, 'max' => 100],
                'window_minutes' => ['label' => 'Période observée', 'unit' => 'min', 'default' => 15, 'min' => 5, 'max' => 240],
                'min_requests' => ['label' => 'Requêtes minimum', 'unit' => '', 'default' => 20, 'min' => 1, 'max' => 10000],
            ],
        ],
        'repeated_error' => [
            'label' => 'Erreur répétée',
            'help' => 'Une même erreur (serveur, navigateur ou intégration) se reproduit plusieurs fois sur la période.',
            'severity' => 'warning',
            'params' => [
                'count' => ['label' => 'Occurrences', 'unit' => '', 'default' => 5, 'min' => 2, 'max' => 1000],
                'window_minutes' => ['label' => 'Période observée', 'unit' => 'min', 'default' => 15, 'min' => 5, 'max' => 240],
            ],
        ],
        'failure_spike' => [
            'label' => 'Forte hausse des échecs',
            'help' => 'Les échecs (erreurs serveur et requêtes refusées pour surcharge) dépassent nettement leur niveau habituel des 24 dernières heures.',
            'severity' => 'warning',
            'params' => [
                'factor' => ['label' => 'Multiplicateur', 'unit' => 'fois', 'default' => 3, 'min' => 2, 'max' => 50],
                'min_failures' => ['label' => 'Échecs minimum', 'unit' => '', 'default' => 10, 'min' => 1, 'max' => 10000],
                'window_minutes' => ['label' => 'Période observée', 'unit' => 'min', 'default' => 15, 'min' => 5, 'max' => 240],
            ],
        ],
        'slow_responses' => [
            'label' => 'Ralentissement',
            'help' => '95 % des requêtes devraient répondre sous ce délai (estimation par tranches : 100 ms, 300 ms, 1 s, 3 s).',
            'severity' => 'warning',
            'params' => [
                'p95_ms' => ['label' => 'Délai (95e centile)', 'unit' => 'ms', 'default' => 1000, 'min' => 100, 'max' => 30000],
                'window_minutes' => ['label' => 'Période observée', 'unit' => 'min', 'default' => 15, 'min' => 5, 'max' => 240],
                'min_requests' => ['label' => 'Requêtes minimum', 'unit' => '', 'default' => 20, 'min' => 1, 'max' => 10000],
            ],
        ],
        'automation' => [
            'label' => 'Automatismes en retard ou en échec',
            'help' => 'Rappels, anniversaires, FISS : la tâche planifiée n\'est pas passée récemment ou une étape échoue.',
            'severity' => 'warning',
            'auto_action' => 'run_automation',
            'params' => [
                'max_minutes' => ['label' => 'Retard toléré', 'unit' => 'min', 'default' => 20, 'min' => 10, 'max' => 1440],
            ],
        ],
        'push' => [
            'label' => 'Notifications push bloquées',
            'help' => 'Envois en attente depuis plus de 5 minutes, ou clés de chiffrement inutilisables.',
            'severity' => 'warning',
            'auto_action' => 'flush_push',
            'params' => [],
        ],
        'sms' => [
            'label' => 'Échecs d\'envoi des codes SMS',
            'help' => 'Twilio Verify refuse ou n\'est pas joignable : les membres ne peuvent plus se connecter.',
            'severity' => 'critical',
            'params' => [
                'failures' => ['label' => 'Échecs', 'unit' => '', 'default' => 3, 'min' => 1, 'max' => 1000],
                'window_minutes' => ['label' => 'Période observée', 'unit' => 'min', 'default' => 15, 'min' => 5, 'max' => 240],
            ],
        ],
        'browser_errors' => [
            'label' => 'Erreurs d\'affichage',
            'help' => 'Erreurs JavaScript remontées par les navigateurs des membres.',
            'severity' => 'warning',
            'params' => [
                'count' => ['label' => 'Erreurs', 'unit' => '', 'default' => 10, 'min' => 1, 'max' => 10000],
                'window_minutes' => ['label' => 'Période observée', 'unit' => 'min', 'default' => 15, 'min' => 5, 'max' => 240],
            ],
        ],
        'disk' => [
            'label' => 'Espace disque ou stockage',
            'help' => 'Espace libre insuffisant, ou dossiers de stockage non accessibles en écriture.',
            'severity' => 'warning',
            'params' => [
                'min_free_percent' => ['label' => 'Espace libre minimum', 'unit' => '%', 'default' => 10, 'min' => 1, 'max' => 90],
            ],
        ],
        'login_abuse' => [
            'label' => 'Tentatives de connexion suspectes',
            'help' => 'Nombreux codes erronés ou demandes de codes répétées depuis une même adresse IP (force brute, envoi abusif de SMS).',
            'severity' => 'warning',
            'params' => [
                'otp_failures' => ['label' => 'Codes erronés (même IP)', 'unit' => '', 'default' => 10, 'min' => 2, 'max' => 1000],
                'otp_requests_hour' => ['label' => 'Demandes de code par heure (même IP)', 'unit' => '', 'default' => 15, 'min' => 2, 'max' => 1000],
                'window_minutes' => ['label' => 'Période (codes erronés)', 'unit' => 'min', 'default' => 15, 'min' => 5, 'max' => 240],
            ],
        ],
        'foreign_login' => [
            'label' => 'Connexion depuis un pays inhabituel',
            'help' => 'Un membre se connecte depuis un pays hors des pays habituels (MONITOR_HOME_COUNTRIES) : voyage, ou compte utilisé par quelqu\'un d\'autre.',
            'severity' => 'warning',
            'params' => [
                'window_minutes' => ['label' => 'Connexions des dernières', 'unit' => 'min', 'default' => 60, 'min' => 10, 'max' => 1440],
            ],
        ],
        'console_denied' => [
            'label' => 'Accès refusés à la console',
            'help' => 'Tentatives de connexion à la console par des numéros non autorisés, ou actions refusées.',
            'severity' => 'warning',
            'params' => [
                'count' => ['label' => 'Refus', 'unit' => '', 'default' => 3, 'min' => 1, 'max' => 1000],
                'window_minutes' => ['label' => 'Période observée', 'unit' => 'min', 'default' => 15, 'min' => 5, 'max' => 240],
            ],
        ],
    ];

    public const RETENTION_BOUNDS = [
        'events_days' => [7, 365],
        'metrics_days' => [7, 730],
        'audit_days' => [90, 3650],
    ];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        if (self::$cache === null) {
            try {
                self::$cache = DB::table('settings')->pluck('value', 'key')
                    ->map(fn ($v) => json_decode((string) $v, true))->all();
            } catch (\Throwable) {
                self::$cache = [];
            }
        }

        return self::$cache;
    }

    public static function get(string $key): mixed
    {
        return self::stored()[$key] ?? null;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** Actions automatiques possibles : libelle affiche. */
    public const AUTO_ACTIONS = [
        'run_automation' => 'Relancer les automatismes',
        'flush_push' => 'Renvoyer les notifications en attente',
    ];

    /** @return array<string, array<string, mixed>> regle => [enabled, auto, parametres...] */
    public static function rules(): array
    {
        $stored = self::stored()['rules'] ?? [];
        $rules = [];
        foreach (self::RULES as $key => $def) {
            $values = ['enabled' => (bool) ($stored[$key]['enabled'] ?? true)];
            if (isset($def['auto_action'])) {
                $values['auto'] = (bool) ($stored[$key]['auto'] ?? true);
            }
            foreach ($def['params'] as $param => $spec) {
                $v = $stored[$key][$param] ?? $spec['default'];
                $values[$param] = is_numeric($v) ? max($spec['min'], min($spec['max'], (int) $v)) : $spec['default'];
            }
            $rules[$key] = $values;
        }

        return $rules;
    }

    /** @return array{events_days: int, metrics_days: int, audit_days: int} */
    public static function retention(): array
    {
        $stored = self::stored()['retention'] ?? [];
        $out = [];
        foreach (self::RETENTION_BOUNDS as $key => [$min, $max]) {
            $v = $stored[$key] ?? config('monitoring.retention.'.$key);
            $out[$key] = max($min, min($max, (int) $v));
        }

        return $out;
    }

    /** @return array{daily: bool, weekly: bool, monthly: bool} */
    public static function reports(): array
    {
        $stored = self::stored()['reports'] ?? [];

        return [
            'daily' => (bool) ($stored['daily'] ?? false),
            'weekly' => (bool) ($stored['weekly'] ?? true),
            'monthly' => (bool) ($stored['monthly'] ?? true),
        ];
    }

    /** @return array{app: bool, email: bool, webhook: bool} canaux actives par la console */
    public static function channels(): array
    {
        $stored = self::stored()['channels'] ?? [];

        return [
            'app' => (bool) ($stored['app'] ?? true),
            'email' => (bool) ($stored['email'] ?? true),
            'webhook' => (bool) ($stored['webhook'] ?? true),
        ];
    }

    public static function installedAt(): ?string
    {
        $v = self::stored()['installed_at'] ?? null;

        return is_string($v) ? $v : null;
    }

    public static function set(string $key, mixed $value, ?int $operatorId = null): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], [
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
            'updated_by' => $operatorId,
            'updated_at' => now(),
        ]);
        self::forget();
    }
}
