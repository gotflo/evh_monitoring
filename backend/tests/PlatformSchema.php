<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema minimal de la base de la plateforme (connexion « app »), pour les tests de la console :
 * comptes, appartenance, roles, journal d'audit de l'eglise et tables de mesures de l'agent.
 * Reprend les colonnes reellement lues par la console.
 */
class PlatformSchema
{
    public static function create(): void
    {
        $s = Schema::connection('app');

        $s->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('phone')->unique();
            $t->timestamp('phone_verified_at')->nullable();
            $t->string('activity_status')->nullable();
            $t->string('activity_override')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->timestamp('blocked_at')->nullable();
            $t->string('blocked_reason')->nullable();
            $t->timestamps();
        });
        $s->create('tribes', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        $s->create('gems', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        $s->create('departments', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        $s->create('profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('matricule')->nullable();
            $t->unsignedBigInteger('tribe_id')->nullable();
            $t->unsignedBigInteger('gem_id')->nullable();
            $t->boolean('is_completed')->default(false);
            $t->timestamps();
        });
        $s->create('department_profile', fn (Blueprint $t) => [$t->unsignedBigInteger('department_id'), $t->unsignedBigInteger('profile_id')]);
        $s->create('department_leaders', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('department_id'), $t->unsignedBigInteger('user_id'), $t->timestamps()]);
        $s->create('roles', fn (Blueprint $t) => [$t->id(), $t->string('key'), $t->string('name'), $t->unsignedInteger('rank')->default(0), $t->timestamps()]);
        $s->create('role_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('role_id');
            $t->string('scope_kind')->nullable();
            $t->unsignedBigInteger('scope_id')->nullable();
            $t->timestamps();
        });
        $s->create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('member_user_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->json('context')->nullable();
            $t->string('ip')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        $s->create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('tokenable_type');
            $t->unsignedBigInteger('tokenable_id');
            $t->string('name');
            $t->string('token', 64);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        $s->create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('user_agent')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('last_received_at')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
        });

        // Tables de mesures ecrites par l'agent de la plateforme.
        $s->create('monitor_request_stats', function (Blueprint $t) {
            $t->id();
            $t->dateTime('bucket');
            $t->string('service', 40);
            $t->string('route', 160);
            $t->string('method', 8);
            $t->unsignedSmallInteger('status');
            foreach (['hits', 'max_ms', 'slow_hits', 'h_100', 'h_300', 'h_1000', 'h_3000'] as $c) {
                $t->unsignedInteger($c)->default(0);
            }
            foreach (['total_ms', 'queries', 'db_ms'] as $c) {
                $t->unsignedBigInteger($c)->default(0);
            }
            $t->unique(['bucket', 'route', 'method', 'status']);
        });
        $s->create('monitor_requests', function (Blueprint $t) {
            $t->id();
            $t->timestamp('created_at')->nullable();
            $t->string('request_id', 32);
            $t->string('reason', 12);
            $t->string('method', 8);
            $t->string('route', 160);
            $t->string('service', 40);
            $t->unsignedSmallInteger('status');
            $t->unsignedInteger('duration_ms');
            $t->unsignedInteger('queries')->default(0);
            $t->unsignedInteger('db_ms')->default(0);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('ip', 45)->nullable();
        });
        $s->create('monitor_events', function (Blueprint $t) {
            $t->id();
            $t->timestamp('created_at')->nullable();
            $t->string('level', 10);
            $t->string('service', 30);
            $t->string('type', 50);
            $t->string('outcome', 12)->nullable();
            $t->string('message', 500);
            $t->json('context')->nullable();
            $t->string('fingerprint', 40)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('request_id', 32)->nullable();
            $t->string('route', 160)->nullable();
            $t->string('ip', 45)->nullable();
        });
        $s->create('monitor_user_days', fn (Blueprint $t) => [$t->date('day'), $t->unsignedBigInteger('user_id'), $t->primary(['day', 'user_id'])]);
        $s->create('monitor_integration_stats', function (Blueprint $t) {
            $t->id();
            $t->dateTime('bucket');
            $t->string('integration', 30);
            $t->string('operation', 40);
            $t->unsignedInteger('ok')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->unsignedBigInteger('total_ms')->default(0);
        });
        $s->create('monitor_job_runs', function (Blueprint $t) {
            $t->id();
            $t->string('command', 40);
            $t->string('trigger', 12);
            $t->timestamp('started_at')->nullable();
            $t->unsignedInteger('duration_ms')->default(0);
            $t->string('status', 10);
            $t->json('summary')->nullable();
        });
    }
}
