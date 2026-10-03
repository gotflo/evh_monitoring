<?php

namespace App\Console\Commands;

use App\Models\Operator;
use App\Services\AgentClient;
use App\Services\AgentException;
use App\Services\Sms\TwilioVerifyClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Verification complete de l'installation, sans rien modifier et sans envoyer de SMS :
 *   php artisan console:diagnostic
 * Base de la console, base de la plateforme, page de sante, liaison signee avec l'agent,
 * codes de connexion (Twilio Verify), proprietaire principal, cron. Aucun secret affiche.
 */
class ConsoleDiagnostic extends Command
{
    protected $signature = 'console:diagnostic';

    protected $description = 'Vérifie l\'installation de la console et sa liaison avec la plateforme.';

    private int $failures = 0;

    public function handle(): int
    {
        $this->line('Console : '.config('app.url').' (environnement '.app()->environment().')');
        $this->check('Mode débogage désactivé', ! config('app.debug') || ! app()->environment('production'), 'APP_DEBUG=false dans le .env de la console.');
        $this->check('Clé de chiffrement', (bool) config('app.key'), 'php artisan key:generate --force');

        // Base de la console.
        try {
            DB::select('select 1');
            $owner = Operator::where('is_primary', true)->first();
            $this->check('Base de la console', Schema::hasTable('operators'), 'php artisan migrate --force');
            $this->check('Propriétaire principal', (bool) $owner, 'php artisan migrate --force', $owner ? $owner->phone.' ('.$owner->status.')' : null);
        } catch (\Throwable $e) {
            $this->check('Base de la console', false, 'Vérifier DB_* dans le .env de la console : '.mb_substr($e->getMessage(), 0, 150));
        }

        // Base de la plateforme (lecture).
        try {
            $app = DB::connection('app');
            $users = $app->table('users')->count();
            $this->check('Lecture de la base de la plateforme', true, null, $users.' compte(s)');
            $this->check('Tables de mesures de la plateforme (agent migré)', $app->getSchemaBuilder()->hasTable('monitor_events'),
                'Sur la plateforme : php artisan migrate --force');
        } catch (\Throwable $e) {
            $this->check('Lecture de la base de la plateforme', false, 'Vérifier APP_DB_* (mêmes valeurs que DB_* de la plateforme) : '.mb_substr($e->getMessage(), 0, 150));
        }

        // Page de sante publique.
        $url = config('monitoring.platform.url');
        try {
            $r = Http::acceptJson()->timeout(10)->get($url.'/api/health');
            $this->check('Plateforme joignable ('.$url.')', $r->successful(), 'Vérifier PLATFORM_URL', 'HTTP '.$r->status().', état '.($r->json('status') ?? '?'));
        } catch (\Throwable $e) {
            $this->check('Plateforme joignable ('.$url.')', false, 'Vérifier PLATFORM_URL : '.mb_substr($e->getMessage(), 0, 150));
        }

        // Liaison signee avec l'agent.
        $agent = app(AgentClient::class);
        if (! $agent->configured()) {
            $this->check('Liaison signée avec l\'agent', false, 'MONITOR_AGENT_SECRET absent ou trop court dans le .env de la console.');
        } else {
            try {
                $h = $agent->get('health', [], 'Diagnostic');
                $this->check('Liaison signée avec l\'agent', true, null, 'plateforme '.($h['app_name'] ?? '').', PHP '.($h['php'] ?? '?'));
            } catch (AgentException $e) {
                $this->check('Liaison signée avec l\'agent', false, match ($e->httpStatus) {
                    401 => 'Secrets différents : copier le même MONITOR_AGENT_SECRET dans les deux .env, puis config:cache des deux côtés.',
                    503 => 'MONITOR_AGENT_SECRET absent du .env de la plateforme (puis config:cache sur la plateforme).',
                    404 => 'L\'agent n\'est pas installé sur la plateforme (mettre la plateforme à jour, route:cache).',
                    default => $e->getMessage(),
                });
            }
        }

        // Codes de connexion a la console.
        $driver = (string) config('services.sms.driver');
        if ($driver === 'twilio_verify') {
            try {
                $service = app(TwilioVerifyClient::class)->service();
                $this->check('Codes de connexion par SMS (Twilio Verify)', true, null, 'service « '.($service['friendly_name'] ?? '?').' »');
            } catch (\Throwable $e) {
                $this->check('Codes de connexion par SMS (Twilio Verify)', false, 'Vérifier TWILIO_* dans le .env de la console : '.mb_substr($e->getMessage(), 0, 150));
            }
        } else {
            $this->check('Codes de connexion par SMS', ! app()->environment('production'),
                'SMS_DRIVER=twilio_verify et identifiants TWILIO_* dans le .env de la console (sinon aucun code n\'arrive).', 'mode '.$driver);
        }

        $cron = Cache::get('monitor:last-cron');
        $this->check('Cron de la console (monitor:check chaque minute)', $cron && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($cron), true) <= 3,
            'Ajouter dans hPanel la tâche cron « php artisan schedule:run » chaque minute (peut prendre 1 à 2 minutes après l\'ajout).',
            $cron ? 'dernier passage '.$cron : 'aucun passage pour l\'instant');

        $this->newLine();
        if ($this->failures === 0) {
            $this->info('Tout est prêt.');

            return self::SUCCESS;
        }
        $this->warn($this->failures.' point(s) à corriger (voir ci-dessus).');

        return self::FAILURE;
    }

    private function check(string $label, bool $ok, ?string $fix = null, ?string $detail = null): void
    {
        if (! $ok) {
            $this->failures++;
        }
        $this->line(($ok ? '  [OK]     ' : '  [ÉCHEC]  ').$label.($detail ? ' : '.$detail : ''));
        if (! $ok && $fix) {
            $this->line('            À faire : '.$fix);
        }
    }
}
