<?php

namespace App\Console\Commands;

use App\Models\Operator;
use App\Support\Audit;
use App\Support\Phone;
use Illuminate\Console\Command;

/**
 * Proprietaire principal de la console, en ligne de commande sur le serveur uniquement
 * (aucun mecanisme de secours dans l'application) : utile si le numero du proprietaire change.
 *   php artisan console:owner             affiche les personnes autorisees
 *   php artisan console:owner +14181234567  designe ce numero comme proprietaire principal
 */
class ConsoleOwner extends Command
{
    protected $signature = 'console:owner {telephone? : numero au format international (+1...)}';

    protected $description = 'Affiche les accès à la console ou désigne le propriétaire principal.';

    public function handle(): int
    {
        $phone = $this->argument('telephone');
        if (! $phone) {
            $this->table(['Numéro', 'Nom', 'Rôle', 'Principal', 'Statut'], Operator::orderBy('id')->get()
                ->map(fn ($o) => [$o->phone, $o->name, Operator::ROLE_LABELS[$o->role] ?? $o->role, $o->is_primary ? 'oui' : '', $o->status])->all());

            return self::SUCCESS;
        }

        $normalized = Phone::normalize((string) $phone);
        if (! $normalized) {
            $this->error('Numéro invalide : utilisez le format international, par exemple +14181234567.');

            return self::FAILURE;
        }

        $operator = Operator::firstOrNew(['phone' => $normalized]);
        Operator::where('is_primary', true)->where('phone', '!=', $normalized)->update(['is_primary' => false]);
        $operator->fill(['role' => 'owner', 'is_primary' => true, 'status' => $operator->status === 'active' ? 'active' : 'invited', 'revoked_at' => null]);
        $operator->name ??= 'Propriétaire principal';
        $operator->save();
        Audit::log(null, 'access.updated', 'success', 'operator', $operator->id, $operator->label(), ['primary_owner' => true, 'via' => 'ligne de commande']);

        $this->info('Propriétaire principal : '.$normalized.'. Il peut se connecter à la console avec un code reçu par SMS.');

        return self::SUCCESS;
    }
}
