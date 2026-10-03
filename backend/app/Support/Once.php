<?php

namespace App\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Anti-doublon durable : une cle n'est acceptee qu'une fois (rapports periodiques, nettoyage
 * quotidien, alertes). En cas d'echec du traitement, la cle est liberee pour un nouvel essai.
 */
class Once
{
    public static function take(string $key): bool
    {
        try {
            DB::table('dispatches')->insert(['key' => mb_substr($key, 0, 191), 'sent_at' => now()]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public static function release(string $key): void
    {
        DB::table('dispatches')->where('key', mb_substr($key, 0, 191))->delete();
    }

    public static function prune(int $days = 120): void
    {
        DB::table('dispatches')->where('sent_at', '<', now()->subDays($days))->delete();
    }
}
