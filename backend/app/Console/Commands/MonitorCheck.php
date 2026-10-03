<?php

namespace App\Console\Commands;

use App\Services\AlertEngine;
use Illuminate\Console\Command;

/**
 * Supervision (cron chaque minute) : etat de sante, detection des incidents et alertes,
 * rapports periodiques dus, conservation des journaux (une fois par jour).
 *   php artisan monitor:check          verification normale
 *   php artisan monitor:check --force  ignore l'ecart minimal entre deux verifications
 */
class MonitorCheck extends Command
{
    protected $signature = 'monitor:check {--force : verifier meme si une verification vient d\'avoir lieu}';

    protected $description = 'Supervision : santé, incidents, alertes, rapports et conservation.';

    public function handle(): int
    {
        try {
            $result = AlertEngine::evaluate((bool) $this->option('force'));
        } catch (\Throwable $e) {
            report($e);
            $this->error('Vérification impossible : '.$e->getMessage());

            return self::FAILURE;
        }
        if (! $result['ran']) {
            $this->line('Vérification déjà faite il y a moins d\'une minute.');

            return self::SUCCESS;
        }
        \Illuminate\Support\Facades\Cache::forever('monitor:last-cron', now()->toIso8601String());
        $this->info(sprintf('État : %s, détections : %d, ouverts : %d, résolus : %d, actions automatiques : %d.', $result['status'] ?? '?',
            $result['findings'] ?? 0, $result['opened'] ?? 0, $result['resolved'] ?? 0, $result['auto_actions'] ?? 0));

        return self::SUCCESS;
    }
}
