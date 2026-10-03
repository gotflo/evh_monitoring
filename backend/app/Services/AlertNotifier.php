<?php

namespace App\Services;

use App\Models\Operator;
use App\Support\Redactor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Envoi des alertes et rapports par les canaux disponibles :
 *  - application : notification dans la cloche + push de la plateforme (mecanisme existant), via
 *    l'agent, aux personnes de la console qui ont un compte membre avec le meme numero ;
 *  - courriel : si un envoi de courriel est configure (MAIL_MAILER) et MONITOR_ALERT_EMAILS renseigne ;
 *  - webhook : si MONITOR_ALERT_WEBHOOK_URL est renseigne (Slack, Discord, Teams, n8n...).
 * Un canal non configure n'est jamais presente comme actif. Aucun canal ne depend de la
 * plateforme sauf « application » : si elle est en panne, courriel et webhook partent quand meme.
 */
class AlertNotifier
{
    /** @return array<int, array<string, mixed>> */
    public static function channels(): array
    {
        $enabled = MonitorSettings::channels();
        $phones = self::phones();
        $agent = app(AgentClient::class)->configured();
        $emails = self::emails();
        $mailer = (string) config('mail.default');
        $mailReady = ! in_array($mailer, ['log', 'array'], true) && $emails;
        $webhook = (string) config('monitoring.alerts.webhook_url');

        return [
            [
                'key' => 'app', 'label' => 'Application (notification et push de la plateforme)', 'enabled' => $enabled['app'],
                'configured' => $agent && count($phones) > 0,
                'active' => $enabled['app'] && $agent && count($phones) > 0,
                'detail' => ! $agent ? 'Liaison avec la plateforme non configurée (MONITOR_AGENT_SECRET).'
                    : (count($phones) ? count($phones).' personne(s) de la console avec les alertes activées ; elles les reçoivent si un compte membre porte le même numéro.'
                        : 'Aucune personne de la console n\'a les alertes activées.'),
            ],
            [
                'key' => 'email', 'label' => 'Courriel', 'enabled' => $enabled['email'],
                'configured' => (bool) $mailReady,
                'active' => $enabled['email'] && $mailReady,
                'detail' => $mailReady ? count($emails).' adresse(s), envoi par « '.$mailer.' ».'
                    : (! $emails ? 'MONITOR_ALERT_EMAILS n\'est pas renseigné.' : 'Aucun envoi de courriel configuré (MAIL_MAILER = '.$mailer.').'),
            ],
            [
                'key' => 'webhook', 'label' => 'Webhook (Slack, Discord, Teams...)', 'enabled' => $enabled['webhook'],
                'configured' => $webhook !== '',
                'active' => $enabled['webhook'] && $webhook !== '',
                'detail' => $webhook !== '' ? 'Adresse configurée (format « '.config('monitoring.alerts.webhook_format', 'json').' »).'
                    : 'MONITOR_ALERT_WEBHOOK_URL n\'est pas renseigné.',
            ],
        ];
    }

    /** @return array<int, string> */
    private static function phones(): array
    {
        try {
            return Operator::whereIn('status', ['invited', 'active'])->where('alerts_enabled', true)->pluck('phone')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, string> */
    private static function emails(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('monitoring.alerts.emails'))),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<string, array{ok: bool, detail: string}>
     */
    public static function send(string $title, array $lines, string $path, string $severity = 'warning'): array
    {
        $results = [];
        $url = rtrim((string) config('app.url'), '/').$path;
        foreach (self::channels() as $channel) {
            if (! $channel['active']) {
                continue;
            }
            try {
                $results[$channel['key']] = match ($channel['key']) {
                    'app' => self::viaApp($title, $lines, $severity),
                    'email' => self::viaEmail($title, $lines, $url),
                    'webhook' => self::viaWebhook($title, $lines, $url, $severity),
                };
            } catch (\Throwable $e) {
                // Le message d'erreur peut contenir l'adresse secrete du webhook : retiree.
                $message = str_replace((string) config('monitoring.alerts.webhook_url') ?: "\0", '[adresse du webhook]', $e->getMessage());
                $results[$channel['key']] = ['ok' => false, 'detail' => Redactor::text($message, 160)];
            }
        }

        return $results;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{ok: bool, detail: string}
     */
    private static function viaApp(string $title, array $lines, string $severity): array
    {
        $body = implode(' ', array_filter($lines)).' Détails dans la console de supervision.';
        $r = app(AgentClient::class)->post('notify', [
            'phones' => self::phones(), 'title' => mb_substr('Supervision : '.$title, 0, 200),
            'body' => mb_substr($body, 0, 400), 'urgency' => $severity === 'critical' ? 'high' : 'normal',
        ], \App\Support\Audit::AGENT);
        $n = (int) ($r['recipients'] ?? 0);

        return ['ok' => $n > 0, 'detail' => $n.' destinataire(s)'];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{ok: bool, detail: string}
     */
    private static function viaEmail(string $title, array $lines, string $url): array
    {
        $emails = self::emails();
        $text = $title."\n\n".implode("\n", $lines)."\n\nOuvrir la console : ".$url."\n";
        Mail::raw($text, fn ($m) => $m->to($emails)->subject('['.config('app.name').'] '.$title));

        return ['ok' => true, 'detail' => count($emails).' adresse(s)'];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{ok: bool, detail: string}
     */
    private static function viaWebhook(string $title, array $lines, string $url, string $severity): array
    {
        $level = ['critical' => '[CRITIQUE]', 'warning' => '[ATTENTION]', 'info' => '[INFO]'][$severity] ?? '[INFO]';
        $text = $level.' '.$title."\n".implode("\n", $lines)."\n".$url;
        $payload = match (config('monitoring.alerts.webhook_format', 'json')) {
            'slack' => ['text' => $text],
            'discord' => ['content' => mb_substr($text, 0, 1900)],
            default => ['source' => config('app.name'), 'severity' => $severity, 'title' => $title, 'lines' => $lines, 'url' => $url, 'at' => now()->toIso8601String()],
        };
        $response = Http::timeout(8)->connectTimeout(4)->acceptJson()->post((string) config('monitoring.alerts.webhook_url'), $payload);

        return ['ok' => $response->successful(), 'detail' => 'HTTP '.$response->status()];
    }

    /** @param array<string, array{ok: bool, detail: string}> $results */
    public static function describe(array $results): string
    {
        if (! $results) {
            return 'Aucun canal d\'alerte actif : alerte visible uniquement dans la console.';
        }
        $labels = ['app' => 'application', 'email' => 'courriel', 'webhook' => 'webhook'];

        return 'Notifié : '.implode(', ', array_map(fn ($k, $r) => ($labels[$k] ?? $k).' '.($r['ok'] ? 'réussi' : 'échec').' ('.$r['detail'].')', array_keys($results), $results)).'.';
    }
}
