<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personnes autorisees a la console et journal d'audit de la console.
 * A la mise en service, seul le proprietaire principal (CONSOLE_OWNER_PHONE) est autorise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();             // format international E.164
            $table->string('name', 80)->nullable();
            $table->string('role', 12);                        // owner | admin | viewer
            $table->boolean('is_primary')->default(false);     // proprietaire principal : jamais retire depuis la console
            $table->string('status', 12)->default('invited');  // invited | active | revoked
            $table->boolean('alerts_enabled')->default(true);
            $table->foreignId('invited_by')->nullable()->constrained('operators')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->nullable()->constrained('operators')->nullOnDelete();
            $table->string('operator_label', 120)->nullable(); // nom fige ; « Agent automatique » pour les actions automatiques
            $table->string('action', 60);
            $table->string('target_type', 40)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label', 160)->nullable();
            $table->string('outcome', 12);                     // success | failure | denied
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['action', 'created_at']);
            $table->index(['operator_id', 'created_at']);
            $table->index(['outcome', 'created_at']);
        });

        $now = now();
        DB::table('operators')->insert([
            'phone' => (string) config('monitoring.console.owner_phone', '+14187181876'),
            'name' => 'Propriétaire principal',
            'role' => 'owner',
            'is_primary' => true,
            'status' => 'invited',
            'alerts_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('operators');
    }
};
