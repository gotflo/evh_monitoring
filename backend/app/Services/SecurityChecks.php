<?php

namespace App\Services;

use Composer\InstalledVersions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Controles de securite de la plateforme (configuration transmise par l'agent, indicateurs
 * seulement) et de la console elle-meme, et vulnerabilites connues des paquets PHP des deux
 * projets (base publique de Packagist, interrogee a la demande). Les limites sont indiquees.
 */
class SecurityChecks
{
    /** Fin du support de securite des versions de PHP (php.net/supported-versions). */
    private const PHP_EOL = ['8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31'];

    /**
     * @param  array<string, mixed>|null  $health  etat de la plateforme (PlatformHealth)
     * @return array<int, array<string, string>>
     */
    public static function configuration(?array $health): array
    {
        $checks = [];
        $add = function (string $scope, string $key, string $label, string $status, string $detail, ?string $fix = null) use (&$checks) {
            $checks[] = array_filter(compact('scope', 'key', 'label', 'status', 'detail', 'fix'), fn ($v) => $v !== null);
        };

        $c = $health['config'] ?? null;
        if (! $c) {
            $add('platform', 'unavailable', 'Configuration de la plateforme', 'info', 'Non disponible : la liaison avec l\'agent de la plateforme ne répond pas.',
                'Voir l\'état « Liaison avec la plateforme » dans le tableau de bord.');
        } else {
            $prod = (bool) $c['production'];
            $add('platform', 'environment', 'Environnement', $prod ? 'ok' : 'info', 'APP_ENV = '.$c['environment'].'.', $prod ? null : 'En production : APP_ENV=production.');
            $add('platform', 'debug', 'Mode débogage', $c['debug'] ? ($prod ? 'critical' : 'info') : 'ok',
                $c['debug'] ? 'APP_DEBUG est activé : les erreurs détaillées peuvent être affichées.' : 'APP_DEBUG est désactivé.', $c['debug'] ? 'APP_DEBUG=false.' : null);
            $add('platform', 'app_key', 'Clé de chiffrement', $c['app_key'] ? 'ok' : 'critical', $c['app_key'] ? 'APP_KEY est définie.' : 'APP_KEY est vide.', $c['app_key'] ? null : 'php artisan key:generate');
            $add('platform', 'https', 'Adresse sécurisée (HTTPS)', $c['https'] ? 'ok' : ($prod ? 'warning' : 'info'), 'APP_URL = '.$c['app_url'].'.', $c['https'] ? null : 'APP_URL en https:// en production.');
            $add('platform', 'expose_otp', 'Code de connexion affiché à l\'écran', $c['expose_otp'] ? ($prod ? 'critical' : 'warning') : 'ok',
                $c['expose_otp'] ? 'EXPOSE_OTP est activé : n\'importe qui peut se connecter avec un numéro connu.' : 'EXPOSE_OTP est désactivé.', $c['expose_otp'] ? 'EXPOSE_OTP=false.' : null);
            $add('platform', 'sms', 'Envoi des codes par SMS', $c['sms_driver'] === 'twilio_verify' ? 'ok' : ($prod ? 'warning' : 'info'), 'SMS_DRIVER = '.$c['sms_driver'].'.',
                $c['sms_driver'] === 'twilio_verify' ? null : 'SMS_DRIVER=twilio_verify en production.');
            $days = (int) $c['member_session_days'];
            $add('platform', 'member_sessions', 'Durée des sessions des membres', $days && $days <= 90 ? 'ok' : 'warning', $days ? $days.' jours.' : 'Sans expiration.',
                $days && $days <= 90 ? null : 'SANCTUM_EXPIRATION au plus 129600 (90 jours).');
            $add('platform', 'log_level', 'Niveau des journaux', $prod && $c['log_level'] === 'debug' ? 'warning' : 'ok', 'LOG_LEVEL = '.$c['log_level'].'.',
                $prod && $c['log_level'] === 'debug' ? 'LOG_LEVEL=info en production.' : null);
            if ($c['env_world_readable'] !== null) {
                $add('platform', 'env_file', 'Fichier .env', $c['env_world_readable'] ? 'warning' : 'ok', $c['env_world_readable'] ? 'Lisible par tous les comptes du serveur.' : 'Droits restreints.',
                    $c['env_world_readable'] ? 'chmod 640 .env' : null);
            }
            $add('platform', 'monitoring', 'Collecte des mesures', $c['monitoring_enabled'] ? 'ok' : 'warning', $c['monitoring_enabled'] ? 'Activée.' : 'Désactivée (MONITOR_ENABLED=false) : la console n\'a plus de données.');
            self::php($add, 'platform', (string) ($health['php'] ?? ''));
            $cron = (bool) ($health['checks']['automation']['cron_seen'] ?? false);
            $add('platform', 'scheduler', 'Tâches planifiées (cron)', $cron ? 'ok' : 'warning',
                $cron ? 'Le cron de l\'hébergeur lance les tâches de la plateforme.' : 'Aucun passage du cron depuis 30 minutes sur la plateforme.',
                $cron ? null : 'Cron chaque minute : php artisan schedule:run (dossier de la plateforme).');
        }

        // La console elle-meme.
        $prod = app()->environment('production');
        $add('console', 'debug', 'Mode débogage de la console', config('app.debug') ? ($prod ? 'critical' : 'info') : 'ok', config('app.debug') ? 'APP_DEBUG est activé.' : 'APP_DEBUG est désactivé.');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $add('console', 'https', 'Adresse sécurisée de la console', $https ? 'ok' : ($prod ? 'warning' : 'info'), 'APP_URL = '.config('app.url').'.');
        $sms = (string) config('services.sms.driver');
        $add('console', 'sms', 'Codes de connexion de la console', $sms === 'twilio_verify' ? 'ok' : ($prod ? 'critical' : 'info'),
            $sms === 'twilio_verify' ? 'Envoyés par Twilio Verify.' : 'Mode « log » : en production, personne ne pourrait recevoir son code.', $sms === 'twilio_verify' ? null : 'SMS_DRIVER=twilio_verify.');
        $hours = (int) config('monitoring.console.session_hours');
        $add('console', 'sessions', 'Durée des sessions de la console', $hours <= 24 ? 'ok' : 'warning', $hours.' heures (CONSOLE_SESSION_HOURS).');
        $secret = strlen((string) config('monitoring.platform.agent_secret'));
        $add('console', 'agent_secret', 'Secret partagé avec la plateforme', $secret >= 32 ? 'ok' : 'warning', $secret >= 32 ? 'Configuré ('.$secret.' caractères).' : 'Absent ou trop court : actions impossibles.');
        $platformHttps = str_starts_with((string) config('monitoring.platform.url'), 'https://');
        $add('console', 'platform_url', 'Liaison chiffrée avec la plateforme', $platformHttps ? 'ok' : ($prod ? 'warning' : 'info'), 'PLATFORM_URL = '.config('monitoring.platform.url').'.');
        self::php($add, 'console', PHP_VERSION);

        $add('console', 'rate_limit', 'Limitation des tentatives', 'ok', 'Demandes et vérifications de code limitées par adresse IP ; 5 essais au plus par code.');
        $add('console', 'backups', 'Sauvegardes', 'info', 'Les sauvegardes des bases sont gérées par l\'hébergeur : la console ne peut pas les vérifier.',
            'Vérifier dans hPanel que les sauvegardes quotidiennes sont actives pour les deux bases.');

        return $checks;
    }

    private static function php(callable $add, string $scope, string $version): void
    {
        if ($version === '') {
            return;
        }
        $minor = implode('.', array_slice(explode('.', $version), 0, 2));
        $eol = self::PHP_EOL[$minor] ?? null;
        $status = ! $eol ? 'info' : (now()->gt($eol) ? 'critical' : (now()->addMonths(6)->gt($eol) ? 'warning' : 'ok'));
        $add($scope, 'php', 'Version de PHP', $status, 'PHP '.$version.($eol ? ', correctifs de sécurité jusqu\'au '.Carbon::parse($eol)->format('d/m/Y') : '').'.',
            in_array($status, ['ok', 'info'], true) ? null : 'Choisir une version plus récente dans le panneau de l\'hébergeur.');
    }

    /** @return array<string, string> paquets PHP de la console */
    public static function localPackages(): array
    {
        $installed = [];
        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $version = InstalledVersions::getPrettyVersion($name);
            if ($version && str_contains($name, '/') && InstalledVersions::isInstalled($name, false)) {
                $installed[$name] = ltrim($version, 'vV');
            }
        }
        unset($installed['gotflo/evh-monitoring']);

        return $installed;
    }

    /**
     * Vulnerabilites connues des paquets PHP de la plateforme et de la console (Packagist).
     * Seuls les noms des paquets sont envoyes ; les versions sont comparees ici.
     *
     * @return array<string, mixed>
     */
    public static function vulnerabilities(): array
    {
        abort_unless(config('monitoring.vulnerability_check'), 422, 'La vérification est désactivée (MONITOR_VULNERABILITY_CHECK=false).');

        $sets = ['console' => self::localPackages()];
        try {
            $sets['platform'] = (array) (app(AgentClient::class)->get('packages')['packages'] ?? []);
        } catch (AgentException $e) {
            $platformError = $e->getMessage();
        }
        $names = array_values(array_unique(array_merge(...array_map('array_keys', array_values($sets)))));
        $names = array_values(array_filter($names, fn ($n) => ! str_starts_with((string) ($sets['platform'][$n] ?? $sets['console'][$n] ?? ''), 'dev-')));

        $response = Http::timeout(20)->connectTimeout(5)->acceptJson()->asForm()
            ->post('https://packagist.org/api/security-advisories/', ['packages' => $names]);
        if (! $response->successful()) {
            throw new \RuntimeException('Packagist a répondu HTTP '.$response->status().'.');
        }

        $found = [];
        foreach ((array) $response->json('advisories', []) as $package => $advisories) {
            foreach ((array) $advisories as $a) {
                $constraint = (string) ($a['affectedVersions'] ?? '');
                foreach ($sets as $scope => $packages) {
                    if (! isset($packages[$package])) {
                        continue;
                    }
                    $match = self::matches($packages[$package], $constraint);
                    if ($match === false) {
                        continue;
                    }
                    $found[] = [
                        'scope' => $scope, 'package' => $package, 'installed' => $packages[$package],
                        'title' => mb_substr((string) ($a['title'] ?? ''), 0, 200), 'cve' => $a['cve'] ?? null,
                        'severity' => $a['severity'] ?? null, 'affected' => mb_substr($constraint, 0, 200),
                        'link' => $a['link'] ?? null, 'reported_at' => $a['reportedAt'] ?? null, 'certain' => $match === true,
                    ];
                }
            }
        }

        $result = [
            'checked_at' => now()->toIso8601String(),
            'packages' => ['console' => count($sets['console']), 'platform' => isset($sets['platform']) ? count($sets['platform']) : null],
            'platform_error' => $platformError ?? null,
            'advisories' => $found,
            'source' => 'Packagist (base publique des avis de sécurité PHP)',
        ];
        MonitorSettings::set('vulnerabilities', $result);

        return $result;
    }

    /** La version est-elle dans l'intervalle ? true / false / null (format non reconnu : a verifier). */
    public static function matches(string $version, string $constraint): ?bool
    {
        if ($version === '' || trim($constraint) === '') {
            return null;
        }
        $version = preg_replace('/^v/i', '', $version);
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $range) {
            $ok = true;
            $parsed = false;
            foreach (preg_split('/\s*,\s*|\s+/', trim($range)) ?: [] as $part) {
                if ($part === '') {
                    continue;
                }
                if (! preg_match('/^(>=|<=|>|<|==|=|!=)?\s*v?([0-9][0-9A-Za-z.\-+]*)$/', $part, $m)) {
                    return null;
                }
                $parsed = true;
                $op = $m[1] ?: '==';
                if (! version_compare($version, $m[2], $op === '=' ? '==' : $op)) {
                    $ok = false;
                }
            }
            if ($parsed && $ok) {
                return true;
            }
        }

        return false;
    }
}
