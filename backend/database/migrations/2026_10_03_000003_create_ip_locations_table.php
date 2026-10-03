<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localite approximative des adresses IP de connexion (ville, region, pays), mise en cache
 * pour ne pas interroger le service de localisation a chaque affichage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_locations', function (Blueprint $table) {
            $table->string('ip', 45)->primary();
            $table->string('status', 10);                 // ok | private | unknown | error
            $table->string('city', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('source', 20)->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->index('country_code');
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_locations');
    }
};
