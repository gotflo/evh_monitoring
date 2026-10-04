<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Operator;
use App\Services\AlertEngine;
use App\Services\MonitorSettings;
use App\Services\SecurityChecks;
use App\Support\Redactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\PlatformSchema;
use Tests\TestCase;

/**
 * Console de supervision (projet separe) : acces par numero autorise + code, roles verifies cote
 * serveur, lecture des mesures de la plateforme, actions par l'API signee de l'agent, detection,
 * enquete automatique, actions automatiques, alertes sans doublon, audit.
 */
class ConsoleTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '+14187181876';

    private const SECRET = 'secret-de-test-partage-entre-console-et-plateforme';

    /** @var array<string, mixed> reponse de /api/agent/health */
    private array $agentHealth;

    private bool $platformUp = true;

    private int $agentStatus = 200;

    /** @var array<int, array{method: string, path: string, body: array<string, mixed>, actor: string}> */
    private array $agentCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSchema::create();
        config(['app.expose_otp' => true]);
        MonitorSettings::forget();
        Cache::flush();
        $this->agentHealth = $this->healthyAgent();
        $this->fakePlatform();
    }

    /** @return array<string, mixed> */
    private function healthyAgent(): array
    {
        return [
            'status' => 'ok', 'essential' => true,
            'checks' => [
                'database' => ['ok' => true, 'ms' => 2, 'driver' => 'mysql'],
                'cache' => ['ok' => true],
                'storage' => ['ok' => true],
                'disk' => ['ok' => true, 'free_percent' => 42.0, 'free_gb' => 8.4],
                'push' => ['ok' => true, 'devices' => 3, 'waiting' => 0],
                'automation' => ['ok' => true, 'last_run_minutes' => 2, 'failed_steps' => 0, 'steps' => [], 'trigger' => 'cron', 'cron_seen' => true, 'auto_tick' => true],
                'sms' => ['ok' => true, 'driver' => 'twilio_verify', 'configured' => true, 'calls_24h' => 4, 'failed_24h' => 0],
                'mail' => ['ok' => true, 'configured' => false, 'driver' => 'log'],
                'scheduler' => ['ok' => true, 'cron_seen' => true],
            ],
            'php' => '8.3.30', 'laravel' => '13.25.0', 'environment' => 'production', 'app_name' => 'My vasesdhonneur',
            'time' => now()->toIso8601String(), 'deployed_at' => now()->subDays(3)->toIso8601String(), 'server_uptime_s' => 86400,
            'config' => [
                'environment' => 'production', 'production' => true, 'debug' => false, 'app_key' => true, 'https' => true,
                'app_url' => 'https://plateforme.test', 'expose_otp' => false, 'sms_driver' => 'twilio_verify', 'member_session_days' => 60,
                'log_level' => 'info', 'env_world_readable' => false, 'monitoring_enabled' => true, 'slow_request_ms' => 1500, 'slow_query_ms' => 500,
            ],
        ];
    }

    /** Plateforme simulee : sante publique et API de l'agent, signature verifiee comme la plateforme le fait. */
    private function fakePlatform(): void
    {
        Http::fake(function (Request $request) {
            $url = parse_url($request->url());
            $path = $url['path'] ?? '';
            if (($url['host'] ?? '') === 'ipwho.is') {
                $ip = trim($url['path'] ?? '', '/');

                return Http::response(match ($ip) {
                    '24.48.1.10' => ['success' => true, 'country' => 'Canada', 'country_code' => 'CA', 'region' => 'Québec', 'city' => 'Saguenay'],
                    '90.12.34.56' => ['success' => true, 'country' => 'France', 'country_code' => 'FR', 'region' => 'Île-de-France', 'city' => 'Paris'],
                    default => ['success' => false, 'message' => 'Invalid IP address'],
                }, 200);
            }
            if (($url['host'] ?? '') !== 'plateforme.test') {
                return Http::response(['ok' => true], 200);
            }
            if ($path === '/api/health') {
                if (! $this->platformUp) {
                    throw new ConnectionException('Connection refused');
                }

                return Http::response(['status' => 'ok', 'checks' => array_intersect_key($this->agentHealth['checks'], array_flip(['database', 'cache', 'storage', 'disk', 'push', 'automation']))], 200);
            }
            if (! $this->platformUp) {
                throw new ConnectionException('Connection refused');
            }
            // Verification de la signature, a l'identique de VerifyAgentSignature (plateforme).
            parse_str($url['query'] ?? '', $query);
            ksort($query);
            $canonical = implode("\n", [$request->header('X-Agent-Timestamp')[0] ?? '', $request->method(), $path,
                http_build_query($query, '', '&', PHP_QUERY_RFC3986), $request->header('X-Agent-Actor')[0] ?? '', hash('sha256', $request->body())]);
            if (! hash_equals(hash_hmac('sha256', $canonical, self::SECRET), $request->header('X-Agent-Signature')[0] ?? '')) {
                return Http::response(['message' => 'Signature invalide.'], 401);
            }
            if ($this->agentStatus !== 200) {
                return Http::response(['message' => 'Refusé par la plateforme.'], $this->agentStatus);
            }
            $this->agentCalls[] = ['method' => $request->method(), 'path' => substr($path, strlen('/api/agent/')),
                'body' => json_decode($request->body() ?: '[]', true) ?: [], 'actor' => rawurldecode($request->header('X-Agent-Actor')[0] ?? '')];

            return match (true) {
                $path === '/api/agent/health' => Http::response($this->agentHealth, 200),
                $path === '/api/agent/packages' => Http::response(['packages' => ['laravel/framework' => '13.25.0']], 200),
                $path === '/api/agent/notify' => Http::response(['message' => '1 destinataire(s).', 'recipients' => 1], 200),
                (bool) preg_match('#/api/agent/users/\d+/impact#', $path) => Http::response(['deleted' => [['label' => 'Profil (identité, appartenance)', 'count' => 1]], 'kept' => [], 'released' => [], 'warnings' => []], 200),
                (bool) preg_match('#/api/agent/actions/(\w+)#', $path, $m) => Http::response(['message' => 'Action '.$m[1].' faite.', 'details' => ['ok' => true]], 200),
                default => Http::response(['message' => 'Fait.', 'impact' => ['deleted' => [['label' => 'Profil', 'count' => 1]]]], 200),
            };
        });
    }

    private function calls(string $path): array
    {
        return array_values(array_filter($this->agentCalls, fn ($c) => $c['path'] === $path));
    }

    private function login(string $phone): string
    {
        $code = $this->postJson('/api/auth/request-code', ['phone' => $phone])->assertOk()->json('dev_code');
        $this->assertNotNull($code, 'un numero autorise recoit un code');
        $token = $this->postJson('/api/auth/verify', ['phone' => $phone, 'code' => $code])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function operator(string $phone, string $role): string
    {
        Operator::create(['phone' => $phone, 'role' => $role, 'status' => 'invited']);

        return $this->login($phone);
    }

    private function member(string $phone, string $first = 'Marie', ?string $role = null): int
    {
        $app = DB::connection('app');
        $id = $app->table('users')->insertGetId(['phone' => $phone, 'last_login_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $app->table('profiles')->insert(['user_id' => $id, 'first_name' => $first, 'last_name' => 'Test', 'is_completed' => true, 'created_at' => now()]);
        if ($role) {
            $roleId = $app->table('roles')->insertGetId(['key' => $role, 'name' => $role, 'rank' => 100]);
            $app->table('role_user')->insert(['user_id' => $id, 'role_id' => $roleId]);
        }

        return $id;
    }

    private function stats(int $ok, int $errors, string $route = 'api/admin/members'): void
    {
        $bucket = now()->setTime(now()->hour, intdiv(now()->minute, 5) * 5)->format('Y-m-d H:i:s');
        foreach ([[200, $ok], [500, $errors]] as [$status, $hits]) {
            if ($hits) {
                DB::connection('app')->table('monitor_request_stats')->insert(['bucket' => $bucket, 'service' => 'membres', 'route' => $route, 'method' => 'GET',
                    'status' => $status, 'hits' => $hits, 'total_ms' => $hits * 100, 'max_ms' => 200, 'h_100' => $hits]);
            }
        }
    }

    /** Seule cette regle est active (les memes donnees en declenchent parfois d'autres). */
    private function only(string $rule, array $extra = []): void
    {
        MonitorSettings::set('rules', collect(MonitorSettings::RULES)->map(fn ($r, $k) => ['enabled' => $k === $rule] + ($k === $rule ? $extra : []))->all());
    }

    // ------------------------------------------------------------------ Acces

    public function test_only_the_primary_owner_is_authorised_and_a_phone_alone_never_opens_the_console(): void
    {
        $owner = Operator::firstOrFail();
        $this->assertSame([self::OWNER, 'owner', true], [$owner->phone, $owner->role, $owner->is_primary]);

        $r = $this->postJson('/api/auth/request-code', ['phone' => '+14185550199'])->assertOk();
        $this->assertArrayNotHasKey('dev_code', $r->json());
        $this->assertSame(0, DB::table('otp_codes')->where('phone', '+14185550199')->count());
        $this->assertTrue(AuditLog::where('action', 'console.login_denied')->exists());
        $this->postJson('/api/auth/verify', ['phone' => '+14185550199', 'code' => '123456'])->assertStatus(422);

        $this->postJson('/api/auth/request-code', ['phone' => self::OWNER])->assertOk();
        $this->postJson('/api/auth/verify', ['phone' => self::OWNER, 'code' => '000001'])->assertStatus(422);
        $this->assertTrue(AuditLog::where('action', 'console.login_failed')->exists());
        $this->getJson('/api/overview')->assertStatus(401);

        Cache::flush();
        $this->travel(1)->minutes();
        $token = $this->login(self::OWNER);
        $this->as($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('operator.role', 'owner')->assertJsonPath('abilities.manage_access', true);
        $this->assertSame('active', $owner->fresh()->status);
    }

    public function test_roles_are_enforced_server_side(): void
    {
        $member = $this->member('+14185550101');
        $viewer = $this->operator('+14185550102', 'viewer');
        $admin = $this->operator('+14185550103', 'admin');

        $this->as($viewer)->getJson('/api/overview')->assertOk();
        $this->as($viewer)->getJson('/api/users/'.$member)->assertOk();
        $this->as($viewer)->postJson('/api/users/'.$member.'/block', ['reason' => 'test'])->assertStatus(403);
        $this->as($viewer)->postJson('/api/actions/health_check', ['confirm' => true])->assertStatus(403);
        $this->as($viewer)->getJson('/api/access')->assertStatus(403);
        $this->assertTrue(AuditLog::where('action', 'console.denied')->where('outcome', 'denied')->exists());
        $this->assertSame([], $this->calls('users/'.$member.'/block'), 'aucune action envoyee a la plateforme');

        $this->as($admin)->postJson('/api/access', ['phone' => '+14185550104', 'role' => 'viewer'])->assertStatus(403);
        $this->as($admin)->putJson('/api/settings/retention', ['events_days' => 30, 'metrics_days' => 90, 'audit_days' => 365])->assertStatus(403);
        $this->as($admin)->postJson('/api/actions/clear_config', ['confirm' => true])->assertStatus(403);
        $this->as($admin)->postJson('/api/actions/health_check', [])->assertStatus(422);
        $this->as($admin)->postJson('/api/actions/health_check', ['confirm' => true])->assertOk();
    }

    public function test_owner_manages_access_and_the_primary_owner_is_protected(): void
    {
        $owner = $this->login(self::OWNER);
        $this->as($owner)->postJson('/api/access', ['phone' => '418 555 0110', 'country' => 'CA', 'role' => 'admin', 'name' => 'Paul'])->assertOk();
        $paul = Operator::where('phone', '+14185550110')->firstOrFail();
        $paulToken = $this->login('+14185550110');
        $this->as($owner)->patchJson('/api/access/'.$paul->id, ['role' => 'viewer'])->assertOk();
        $this->as($paulToken)->postJson('/api/actions/health_check', ['confirm' => true])->assertStatus(403);

        $primary = Operator::where('is_primary', true)->first();
        $this->as($owner)->deleteJson('/api/access/'.$primary->id)->assertStatus(422);
        $this->as($owner)->patchJson('/api/access/'.$primary->id, ['role' => 'admin'])->assertStatus(422);

        $this->as($owner)->deleteJson('/api/access/'.$paul->id)->assertOk();
        $this->as($paulToken)->getJson('/api/overview')->assertStatus(401);
        foreach (['access.invited', 'access.updated', 'access.revoked'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->where('outcome', 'success')->exists(), $action);
        }
    }

    // ------------------------------------------------------------------ Actions par l'agent

    public function test_user_actions_go_through_the_signed_agent_api_and_are_audited(): void
    {
        $member = $this->member('+14185550120', 'Jean');
        $admin = $this->operator('+14185550121', 'admin');

        $this->as($admin)->postJson('/api/users/'.$member.'/block', ['reason' => ''])->assertStatus(422);
        $this->as($admin)->postJson('/api/users/'.$member.'/block', ['reason' => 'Usurpation signalée'])->assertOk();
        $call = $this->calls('users/'.$member.'/block')[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('Usurpation signalée', $call['body']['reason']);
        $this->assertSame('+1 *** 0121', $call['actor'], 'la plateforme sait qui a decide');
        $log = AuditLog::where('action', 'user.blocked')->firstOrFail();
        $this->assertSame('success', $log->outcome);
        $this->assertStringContainsString('Jean Test', $log->target_label);
        $this->assertStringNotContainsString('5550120', $log->target_label);

        // Plateforme qui refuse : erreur claire, echec consigne.
        $this->agentStatus = 503;
        $this->as($admin)->postJson('/api/users/'.$member.'/unblock')->assertStatus(503);
        $this->assertTrue(AuditLog::where('action', 'user.unblocked')->where('outcome', 'failure')->exists());
    }

    public function test_deletion_needs_confirmation_and_super_admins_need_an_owner(): void
    {
        $member = $this->member('+14185550130');
        $pastor = $this->member('+14185550131', 'Pasteur', 'super_admin');
        $admin = $this->operator('+14185550132', 'admin');

        $impact = $this->as($admin)->getJson('/api/users/'.$member.'/impact')->assertOk();
        $this->assertSame('Profil (identité, appartenance)', $impact->json('deleted.0.label'));
        $this->as($admin)->deleteJson('/api/users/'.$member, ['confirmation' => 'oui'])->assertStatus(422);
        $this->as($admin)->deleteJson('/api/users/'.$pastor, ['confirmation' => 'SUPPRIMER'])->assertStatus(403);
        $this->assertSame([], $this->calls('users/'.$pastor));
        $this->as($admin)->deleteJson('/api/users/'.$member, ['confirmation' => 'supprimer'])->assertOk();
        $this->assertSame('DELETE', $this->calls('users/'.$member)[0]['method']);
        $this->assertTrue(AuditLog::where('action', 'user.deleted')->where('outcome', 'success')->exists());
    }

    // ------------------------------------------------------------------ Detection, enquete, actions automatiques

    public function test_server_errors_open_one_investigated_incident_notify_once_and_resolve_alone(): void
    {
        Operator::firstOrFail()->update(['alerts_enabled' => true]);
        $this->only('api_errors');
        $this->stats(60, 40);
        $e = DB::connection('app')->table('monitor_events')->insertGetId(['created_at' => now(), 'level' => 'error', 'service' => 'api', 'type' => 'exception',
            'message' => 'QueryException : table manquante', 'fingerprint' => sha1('x'), 'context' => json_encode(['file' => 'app/Http/Controllers/Api/Admin/MemberController.php:42'])]);
        DB::connection('app')->table('monitor_requests')->insert(['created_at' => now(), 'request_id' => 'abc', 'reason' => 'error', 'method' => 'GET',
            'route' => 'api/admin/members', 'service' => 'membres', 'status' => 500, 'duration_ms' => 80]);

        AlertEngine::evaluate(true);
        $incident = Incident::where('rule', 'api_errors')->firstOrFail();
        $this->assertSame(['open', 'critical'], [$incident->status, $incident->severity]);
        $this->assertStringContainsString('MemberController.php:42', $incident->investigation['cause']);
        $this->assertSame('élevée', $incident->investigation['confidence']);
        $this->assertNotEmpty(collect($incident->investigation['evidence'])->firstWhere('label', 'Route en échec'));
        $this->assertCount(1, $this->calls('notify'));
        $this->assertStringContainsString('Cause probable', $this->calls('notify')[0]['body']['body']);

        AlertEngine::evaluate(true);
        $this->assertSame(1, Incident::where('rule', 'api_errors')->count());
        $this->assertSame(2, $incident->fresh()->occurrences);
        $this->assertCount(1, $this->calls('notify'), 'pas de nouvelle alerte pour le meme probleme');

        DB::connection('app')->table('monitor_request_stats')->where('status', 500)->delete();
        AlertEngine::evaluate(true);
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertNull($incident->fresh()->resolved_by);
        $this->assertCount(2, $this->calls('notify'), 'information de resolution');
        unset($e);
    }

    public function test_a_scheduler_blocked_by_disabled_proc_open_is_recognised(): void
    {
        $this->only('repeated_error');
        foreach (range(1, 5) as $i) {
            DB::connection('app')->table('monitor_events')->insert(['created_at' => now()->subMinutes($i), 'level' => 'error', 'service' => 'automation', 'type' => 'exception',
                'message' => 'LogicException : The Process class relies on proc_open, which is not available on your PHP installation.',
                'fingerprint' => sha1('proc_open'), 'context' => json_encode(['file' => 'vendor/symfony/process/Process.php:163'])]);
        }

        AlertEngine::evaluate(true);
        $incident = Incident::where('rule', 'repeated_error')->firstOrFail();
        $this->assertStringContainsString('proc_open', $incident->investigation['cause']);
        $this->assertSame('élevée', $incident->investigation['confidence']);
        $this->assertStringContainsString('app:push-outbox', $incident->investigation['recommendations'][0]['text']);
        $this->assertStringContainsString('app:tick', $incident->investigation['recommendations'][0]['text']);
    }

    public function test_late_automation_is_fixed_automatically_once_per_quarter_hour(): void
    {
        $this->only('automation', ['auto' => true]);
        $this->agentHealth['checks']['automation'] = ['ok' => false, 'last_run_minutes' => 95, 'failed_steps' => 0, 'steps' => [], 'trigger' => 'application', 'cron_seen' => false, 'auto_tick' => true];
        $this->agentHealth['checks']['scheduler'] = ['ok' => true, 'cron_seen' => false];

        AlertEngine::evaluate(true);
        $incident = Incident::where('rule', 'automation')->firstOrFail();
        $this->assertStringContainsString('cron', $incident->investigation['cause']);
        $this->assertCount(1, $this->calls('actions/run_automation'));
        $this->assertSame('Agent automatique', $this->calls('actions/run_automation')[0]['actor']);
        $this->assertTrue(collect($incident->fresh()->timeline)->contains(fn ($t) => $t['kind'] === 'auto_action'));
        $this->assertTrue(AuditLog::where('action', 'auto.run_automation')->where('operator_label', 'Agent automatique')->exists());

        AlertEngine::evaluate(true);
        $this->assertCount(1, $this->calls('actions/run_automation'), 'pas de nouvelle action avant 15 minutes');

        $this->travel(16)->minutes();
        $this->agentHealth['time'] = now()->toIso8601String();
        AlertEngine::evaluate(true);
        $this->assertCount(2, $this->calls('actions/run_automation'));

        // Desactivee dans les reglages : plus aucune action automatique.
        $this->only('automation', ['auto' => false]);
        $this->travel(16)->minutes();
        $this->agentHealth['time'] = now()->toIso8601String();
        AlertEngine::evaluate(true);
        $this->assertCount(2, $this->calls('actions/run_automation'));
    }

    public function test_a_stopped_platform_is_detected_from_outside_after_confirmation(): void
    {
        config(['monitoring.alerts.webhook_url' => 'https://hooks.example.test/x', 'monitoring.alerts.webhook_format' => 'slack']);
        $this->only('platform_down', ['confirmations' => 2]);
        $this->platformUp = false;

        AlertEngine::evaluate(true);
        $this->assertSame(0, Incident::count(), 'une seule mesure en echec ne suffit pas');
        $this->travel(1)->minutes();
        AlertEngine::evaluate(true);
        $incident = Incident::where('rule', 'platform_down')->firstOrFail();
        $this->assertSame('critical', $incident->severity);
        $this->assertStringContainsString('serveur web', $incident->investigation['cause']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'hooks.example.test') && str_contains((string) $r['text'], '[CRITIQUE]'));

        $this->platformUp = true;
        $this->travel(1)->minutes();
        $this->agentHealth['time'] = now()->toIso8601String();
        AlertEngine::evaluate(true);
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertSame(2, DB::table('health_samples')->where('status', 'down')->count());
    }

    public function test_broken_link_with_the_platform_is_explained(): void
    {
        $this->only('agent_link');
        $this->agentStatus = 401;
        AlertEngine::evaluate(true);
        $incident = Incident::where('rule', 'agent_link')->firstOrFail();
        $this->assertStringContainsString('signature', $incident->investigation['cause']);
    }

    public function test_sms_failures_are_explained_with_the_twilio_code(): void
    {
        $this->only('sms');
        foreach (range(1, 3) as $i) {
            DB::connection('app')->table('monitor_events')->insert(['created_at' => now(), 'level' => 'error', 'service' => 'sms', 'type' => 'log',
                'message' => 'OTP : envoi Twilio Verify impossible', 'context' => json_encode(['twilio_code' => 21608, 'http' => 400])]);
        }
        AlertEngine::evaluate(true);
        $incident = Incident::where('rule', 'sms')->firstOrFail();
        $this->assertStringContainsString('Trust Hub', $incident->investigation['cause']);
        $this->assertSame('sms_check', collect($incident->investigation['recommendations'])->firstWhere('action', 'sms_check')['action']);
    }

    public function test_login_localities_are_shown_and_an_unusual_country_is_investigated(): void
    {
        $this->only('foreign_login');
        $member = $this->member('+14185550190', 'Paul');
        $app = DB::connection('app');
        foreach ([['24.48.1.10', 3], ['90.12.34.56', 0]] as [$ip, $daysAgo]) {
            $app->table('monitor_events')->insert(['created_at' => now()->subDays($daysAgo)->subMinutes(5), 'level' => 'info', 'service' => 'auth',
                'type' => 'auth.login', 'outcome' => 'success', 'message' => 'Connexion', 'user_id' => $member, 'ip' => $ip]);
        }
        $app->table('monitor_events')->insert(['created_at' => now(), 'level' => 'info', 'service' => 'auth', 'type' => 'auth.login',
            'outcome' => 'success', 'message' => 'Connexion', 'user_id' => $member, 'ip' => '192.168.1.20']);

        AlertEngine::evaluate(true);
        $incident = Incident::where('rule', 'foreign_login')->firstOrFail();
        $this->assertStringContainsString('France', $incident->title);
        $this->assertStringContainsString('Paul Test', $incident->title);
        $this->assertStringContainsString('Première connexion', $incident->investigation['cause']);
        $this->assertNotEmpty(collect($incident->investigation['evidence'])->firstWhere('value', 'Saguenay, Québec, Canada (1 connexion(s))'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '192.168.1.20'));

        $owner = $this->login(self::OWNER);
        $detail = $this->as($owner)->getJson('/api/users/'.$member)->assertOk();
        $this->assertSame('Réseau local', $detail->json('logins.0.location'));
        $this->assertContains('Paris, Île-de-France, France', collect($detail->json('localities'))->pluck('label')->all());
        $security = $this->as($owner)->getJson('/api/security?period=7d')->assertOk();
        $this->assertTrue(collect($security->json('localities'))->firstWhere('label', 'Paris, Île-de-France, France')['unusual']);

        // Localisation desactivee : aucune adresse envoyee, rien de suppose.
        config(['monitoring.geo.provider' => 'none']);
        DB::table('ip_locations')->delete();
        $this->as($owner)->getJson('/api/users/'.$member)->assertJsonPath('logins.1.location', 'Localisation désactivée');
    }

    // ------------------------------------------------------------------ Ecrans et reglages

    public function test_screens_answer_with_real_platform_data(): void
    {
        $this->member('+14185550160');
        DB::connection('app')->table('monitor_user_days')->insert(['day' => now()->toDateString(), 'user_id' => 1]);
        $this->stats(10, 0);
        $owner = $this->login(self::OWNER);
        foreach (['/api/overview?period=7d', '/api/activity', '/api/users?q=Marie', '/api/logs', '/api/logs/errors', '/api/logs/requests',
            '/api/logs/performance', '/api/incidents?status=all', '/api/reports', '/api/security', '/api/settings', '/api/audit', '/api/actions',
            '/api/access', '/api/logs/files?source=console'] as $url) {
            $this->as($owner)->getJson($url)->assertOk();
        }
        $this->as($owner)->getJson('/api/users?q=Marie')->assertJsonPath('total', 1);
        $overview = $this->as($owner)->getJson('/api/overview?period=24h')->assertOk();
        $overview->assertJsonPath('requests.hits', 10)->assertJsonPath('users.today', 1)->assertJsonPath('health.checks.agent.ok', true);
        $this->as($owner)->getJson('/api/activity?service=console')->assertJsonFragment(['type' => 'console.login']);
        $security = $this->as($owner)->getJson('/api/security')->assertOk();
        $this->assertNotEmpty(collect($security->json('checks'))->where('scope', 'platform'));

        $report = $this->as($owner)->postJson('/api/reports', ['from' => now()->subDays(7)->toDateString(), 'to' => now()->toDateString()])->assertOk();
        $this->as($owner)->getJson('/api/reports/'.$report->json('id'))->assertJsonStructure(['report' => ['data' => ['availability', 'requests', 'users', 'incidents', 'trends']]]);
    }

    public function test_thresholds_auto_actions_and_retention_are_configurable(): void
    {
        $admin = $this->operator('+14185550150', 'admin');
        $this->as($admin)->putJson('/api/settings/rules', ['rules' => ['api_errors' => ['threshold_percent' => 0]]])->assertStatus(422);
        $this->as($admin)->putJson('/api/settings/rules', ['rules' => ['api_errors' => ['threshold_percent' => 50], 'push' => ['auto' => false]]])->assertOk();
        MonitorSettings::forget();
        $this->assertSame(50, MonitorSettings::rules()['api_errors']['threshold_percent']);
        $this->assertFalse(MonitorSettings::rules()['push']['auto']);

        $owner = $this->login(self::OWNER);
        $this->as($owner)->putJson('/api/settings/retention', ['events_days' => 2, 'metrics_days' => 90, 'audit_days' => 365])->assertStatus(422);
        $this->as($owner)->putJson('/api/settings/retention', ['events_days' => 14, 'metrics_days' => 60, 'audit_days' => 400])->assertOk();
        DB::connection('app')->table('monitor_events')->insert(['created_at' => now()->subDays(20), 'level' => 'error', 'service' => 'api', 'type' => 'log', 'message' => 'ancien']);
        $this->as($owner)->postJson('/api/actions/purge', ['confirm' => true])->assertOk();
        $this->assertSame(0, DB::connection('app')->table('monitor_events')->where('message', 'ancien')->count());
    }

    public function test_rate_limits_are_separate(): void
    {
        $owner = $this->login(self::OWNER);
        for ($i = 0; $i < 10; $i++) {
            $this->as($owner)->postJson('/api/actions/test_alert', ['confirm' => true])->assertOk();
        }
        $this->as($owner)->postJson('/api/actions/test_alert', ['confirm' => true])->assertStatus(429);
        $this->as($owner)->getJson('/api/overview')->assertOk();
    }

    public function test_redaction_and_vulnerability_matching(): void
    {
        $t = Redactor::text("SQL: select * from users where phone = '+14185551234' and code = 482913 Bearer 12|abcdefghijklmnopqrstuvwxyz0123 https://x.org/a?token=zz");
        foreach (['5551234', '482913', 'abcdefghijklmnop', 'token=zz'] as $secret) {
            $this->assertStringNotContainsString($secret, $t);
        }
        $this->assertTrue(SecurityChecks::matches('2.1.0', '>=2.0.0,<2.1.5'));
        $this->assertFalse(SecurityChecks::matches('2.1.5', '>=2.0.0,<2.1.5'));
        $this->assertNull(SecurityChecks::matches('1.0.0', '~1.0'));
    }
}
