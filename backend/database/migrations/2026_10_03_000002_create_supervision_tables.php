<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Donnees propres a la console : etat releve chaque minute (disponibilite vue de l'exterieur),
 * incidents avec leur enquete automatique, rapports et reglages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->nullable();
            $table->string('status', 10);                      // ok | degraded | down
            $table->unsignedInteger('response_ms')->nullable(); // temps de reponse de /api/health
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('checks');

            $table->index('created_at');
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 160);                        // regle + objet (anti-doublon)
            $table->string('rule', 40);
            $table->string('severity', 10);                    // warning | critical
            $table->string('status', 14);                      // open | acknowledged | resolved
            $table->string('title', 200);
            $table->text('summary')->nullable();
            $table->json('details')->nullable();
            $table->json('investigation')->nullable();         // cause probable, preuves, actions recommandees
            $table->json('timeline')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('auto_action_at')->nullable();   // derniere action automatique
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable(); // null + resolu = retour a la normale constate
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['key', 'status']);
            $table->index(['status', 'last_seen_at']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('period', 10);                      // daily | weekly | monthly | custom
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->json('data');
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('delivery')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['period', 'period_start']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->json('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // Anti-doublon des envois et des taches periodiques (une cle = une seule execution).
        Schema::create('dispatches', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->timestamp('sent_at')->nullable();
        });

        DB::table('settings')->insert([
            'key' => 'installed_at',
            'value' => json_encode(now()->toIso8601String()),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (['dispatches', 'settings', 'reports', 'incidents', 'health_samples'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
