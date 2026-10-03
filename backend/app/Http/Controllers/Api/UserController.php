<?php

namespace App\Http\Controllers\Api;

use App\Models\Operator;
use App\Services\AgentClient;
use App\Services\AgentException;
use App\Services\Metrics;
use App\Support\Audit;
use App\Support\Like;
use App\Support\ServiceMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Comptes membres de la plateforme vus depuis la console : recherche, etat, elements lies
 * (nombres seulement, jamais le contenu des FISS, journaux ou demandes). Les actions (bloquer,
 * debloquer, fermer les sessions, supprimer) sont executees par la plateforme elle-meme via son
 * API signee : ses contraintes et son journal d'audit s'appliquent ; la console trace aussi.
 */
class UserController extends ConsoleController
{
    private const PER_PAGE = 30;

    public function __construct(private AgentClient $agent) {}

    private function db(): \Illuminate\Database\Connection
    {
        return Metrics::app();
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:active,inactive,blocked,never'],
            'role' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', 'in:recent,last_seen,name'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $query = $this->db()->table('users')->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')
            ->select('users.id', 'users.phone', 'users.created_at', 'users.last_seen_at', 'users.last_login_at', 'users.blocked_at',
                'users.activity_status', 'users.activity_override', 'profiles.first_name', 'profiles.last_name', 'profiles.matricule', 'profiles.tribe_id');
        if (! empty($f['q'])) {
            $text = trim($f['q']);
            $digits = preg_replace('/\D/', '', $text);
            $query->where(function ($w) use ($text, $digits) {
                Like::contains($w, 'profiles.first_name', $text);
                Like::contains($w, 'profiles.last_name', $text, 'or');
                Like::contains($w, 'profiles.matricule', $text, 'or');
                // « Marie Dupont » : chaque mot dans le prenom ou le nom (portable MySQL / SQLite).
                $words = array_slice(preg_split('/\s+/', $text) ?: [], 0, 4);
                if (count($words) > 1) {
                    $w->orWhere(function ($all) use ($words) {
                        foreach ($words as $word) {
                            $all->where(function ($one) use ($word) {
                                Like::contains($one, 'profiles.first_name', $word);
                                Like::contains($one, 'profiles.last_name', $word, 'or');
                            });
                        }
                    });
                }
                if (strlen($digits) >= 3) {
                    Like::contains($w, 'users.phone', $digits, 'or');
                }
                if (ctype_digit($text)) {
                    $w->orWhere('users.id', (int) $text);
                }
            });
        }
        match ($f['status'] ?? null) {
            'blocked' => $query->whereNotNull('users.blocked_at'),
            'never' => $query->whereNull('users.last_login_at'),
            'inactive' => $query->whereNull('users.blocked_at')->where(fn ($w) => $w->where('users.activity_override', 'inactive')
                ->orWhere(fn ($w) => $w->whereNull('users.activity_override')->where('users.activity_status', 'inactive'))),
            'active' => $query->whereNull('users.blocked_at')->where(fn ($w) => $w->where('users.activity_override', 'active')
                ->orWhere(fn ($w) => $w->whereNull('users.activity_override')->where(fn ($w) => $w->whereNull('users.activity_status')->orWhere('users.activity_status', 'active')))),
            default => null,
        };
        if (! empty($f['role'])) {
            $query->whereExists(fn ($q) => $q->from('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->whereColumn('role_user.user_id', 'users.id')->where('roles.key', $f['role']));
        }
        match ($f['sort'] ?? 'last_seen') {
            'recent' => $query->orderByDesc('users.created_at'),
            'name' => $query->orderBy('profiles.last_name')->orderBy('profiles.first_name'),
            default => $query->orderByRaw('users.last_seen_at IS NULL')->orderByDesc('users.last_seen_at'),
        };
        $query->orderByDesc('users.id');

        $total = (clone $query)->count('users.id');
        $page = (int) ($f['page'] ?? 1);
        $users = $query->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();
        $roles = $this->db()->table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->whereIn('role_user.user_id', $users->pluck('id'))
            ->get(['role_user.user_id', 'roles.name'])->groupBy('user_id');
        $tribes = $this->db()->table('tribes')->pluck('name', 'id');

        return response()->json([
            'total' => $total,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'users' => $users->map(fn ($u) => [
                'id' => $u->id,
                'name' => trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: null,
                'phone' => $u->phone,
                'matricule' => $u->matricule,
                'tribe' => $u->tribe_id ? ($tribes[$u->tribe_id] ?? null) : null,
                'roles' => ($roles[$u->id] ?? collect())->pluck('name')->unique()->values(),
                'status' => $u->blocked_at ? 'blocked' : self::activity($u),
                'last_seen_at' => $this->iso($u->last_seen_at),
                'last_login_at' => $this->iso($u->last_login_at),
                'created_at' => $this->iso($u->created_at),
            ])->values(),
            'roles' => $this->db()->table('roles')->orderByDesc('rank')->get(['key', 'name']),
        ]);
    }

    public function show(int $user): JsonResponse
    {
        $db = $this->db();
        $u = $db->table('users')->find($user);
        abort_unless($u, 404, 'Compte introuvable (il a peut-être été supprimé).');
        $p = $db->table('profiles')->where('user_id', $user)->first();

        $tokens = $db->table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->where('tokenable_id', $user);
        $devices = $db->table('push_subscriptions')->where('user_id', $user)->orderByDesc('last_used_at')
            ->get(['id', 'user_agent', 'last_used_at', 'last_received_at', 'last_error', 'created_at']);
        $audit = $db->table('audit_logs')->where(fn ($w) => $w->where('user_id', $user)->orWhere('member_user_id', $user))
            ->orderByDesc('id')->limit(15)->get(['id', 'action', 'user_id', 'context', 'created_at']);
        $events = $db->table('monitor_events')->where('user_id', $user)->orderByDesc('id')->limit(15)
            ->get(['id', 'created_at', 'level', 'service', 'type', 'outcome', 'message', 'request_id']);
        $logins = $db->table('monitor_events')->where('type', 'auth.login')->where('user_id', $user)->whereNotNull('ip')
            ->orderByDesc('id')->limit(200)->get(['id', 'created_at', 'ip']);
        $places = \App\Services\GeoLocator::lookup($logins->pluck('ip')->all());
        $requests = $db->table('monitor_requests')->where('user_id', $user)->orderByDesc('id')->limit(10)
            ->get(['id', 'created_at', 'reason', 'method', 'route', 'status', 'duration_ms', 'request_id']);

        // Elements lies : calcules par la plateforme (memes regles que la suppression).
        try {
            $related = $this->agent->get('users/'.$user.'/impact')['deleted'] ?? [];
            $relatedError = null;
        } catch (AgentException $e) {
            $related = [];
            $relatedError = 'Détail indisponible : '.$e->getMessage();
        }

        return response()->json([
            'user' => [
                'id' => $u->id,
                'phone' => $u->phone,
                'name' => $p ? trim(($p->first_name ?? '').' '.($p->last_name ?? '')) ?: null : null,
                'matricule' => $p->matricule ?? null,
                'created_at' => $this->iso($u->created_at),
                'phone_verified_at' => $this->iso($u->phone_verified_at ?? null),
                'last_login_at' => $this->iso($u->last_login_at),
                'last_seen_at' => $this->iso($u->last_seen_at),
                'activity_status' => self::activity($u),
                'activity_override' => $u->activity_override,
                'blocked_at' => $this->iso($u->blocked_at),
                'blocked_reason' => $u->blocked_reason,
                'profile_completed' => (bool) ($p->is_completed ?? false),
                'is_console_operator' => Operator::where('phone', $u->phone)->exists(),
            ],
            'belonging' => [
                'tribe' => $p && $p->tribe_id ? $db->table('tribes')->where('id', $p->tribe_id)->value('name') : null,
                'gem' => $p && ($p->gem_id ?? null) ? $db->table('gems')->where('id', $p->gem_id)->value('name') : null,
                'departments' => $p ? $db->table('department_profile')->join('departments', 'departments.id', '=', 'department_profile.department_id')
                    ->where('department_profile.profile_id', $p->id)->pluck('departments.name')->values() : [],
                'led_departments' => $db->table('department_leaders')->join('departments', 'departments.id', '=', 'department_leaders.department_id')
                    ->where('department_leaders.user_id', $user)->pluck('departments.name')->values(),
                'roles' => $db->table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->where('role_user.user_id', $user)
                    ->get(['roles.key', 'roles.name', 'role_user.scope_kind'])->map(fn ($r) => ['key' => $r->key, 'name' => $r->name, 'scope_kind' => $r->scope_kind])->values(),
            ],
            'sessions' => [
                'count' => (clone $tokens)->count(),
                'last_used_at' => $this->iso((clone $tokens)->max('last_used_at')),
                'oldest_at' => $this->iso((clone $tokens)->min('created_at')),
            ],
            'devices' => $devices->map(fn ($d) => [
                'id' => $d->id, 'device' => self::device($d->user_agent), 'last_used_at' => $this->iso($d->last_used_at),
                'last_received_at' => $this->iso($d->last_received_at ?? null), 'error' => ($d->last_error ?? null) ? mb_substr($d->last_error, 0, 120) : null,
                'created_at' => $this->iso($d->created_at),
            ])->values(),
            'usage' => ['active_days_30' => $db->table('monitor_user_days')->where('user_id', $user)->where('day', '>', now()->subDays(30)->toDateString())->count()],
            'related' => $related,
            'related_error' => $relatedError,
            'audit' => $audit->map(fn ($a) => [
                'id' => $a->id, 'label' => ActivityController::auditLabel($a->action), 'action' => $a->action,
                'as' => (int) $a->user_id === $user ? 'auteur' : 'concerné', 'created_at' => $this->iso($a->created_at),
            ])->values(),
            'events' => $events->map(fn ($e) => [
                'id' => $e->id, 'created_at' => $this->iso($e->created_at), 'level' => $e->level, 'service' => $e->service,
                'type' => $e->type, 'label' => ServiceMap::EVENT_LABELS[$e->type] ?? $e->type, 'outcome' => $e->outcome,
                'message' => $e->message, 'request_id' => $e->request_id,
            ])->values(),
            'requests' => $requests->map(fn ($r) => (array) $r + ['created_at_iso' => $this->iso($r->created_at)])->values(),
            'logins' => $logins->take(15)->map(fn ($l) => [
                'id' => $l->id, 'created_at' => $this->iso($l->created_at), 'ip' => $l->ip,
                'location' => $places[$l->ip]['label'] ?? null, 'country_code' => $places[$l->ip]['country_code'] ?? null,
            ])->values(),
            'localities' => $logins->groupBy(fn ($l) => $places[$l->ip]['label'] ?? 'Localité inconnue')
                ->map(fn ($g, $label) => ['label' => $label, 'count' => $g->count(), 'last_at' => $this->iso($g->max('created_at')),
                    'country_code' => $places[$g->first()->ip]['country_code'] ?? null])
                ->sortByDesc('count')->values(),
            'home_countries' => \App\Services\GeoLocator::homeCountries(),
            'geo_enabled' => \App\Services\GeoLocator::enabled(),
        ]);
    }

    public function impact(int $user): JsonResponse
    {
        $u = $this->target($user);

        return response()->json($this->call(fn () => $this->agent->get('users/'.$user.'/impact')) + ['name' => $u['name'], 'phone' => $u['phone']]);
    }

    public function block(Request $request, int $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $u = $this->guardTarget($request, $user, 'user.blocked');

        return $this->act($request, 'user.blocked', $u, fn ($actor) => $this->agent->post('users/'.$user.'/block', ['reason' => $data['reason']], $actor), ['reason' => $data['reason']]);
    }

    public function unblock(Request $request, int $user): JsonResponse
    {
        $u = $this->target($user);

        return $this->act($request, 'user.unblocked', $u, fn ($actor) => $this->agent->post('users/'.$user.'/unblock', [], $actor));
    }

    public function revokeSessions(Request $request, int $user): JsonResponse
    {
        $u = $this->target($user);

        return $this->act($request, 'user.sessions_revoked', $u, fn ($actor) => $this->agent->post('users/'.$user.'/revoke-sessions', [], $actor));
    }

    public function destroy(Request $request, int $user): JsonResponse
    {
        $request->validate(['confirmation' => ['required', 'string']]);
        if ($request->string('confirmation')->trim()->upper()->toString() !== 'SUPPRIMER') {
            throw ValidationException::withMessages(['confirmation' => 'Tapez SUPPRIMER pour confirmer la suppression définitive.']);
        }
        $u = $this->guardTarget($request, $user, 'user.deleted');

        return $this->act($request, 'user.deleted', $u, fn ($actor) => $this->agent->delete('users/'.$user, $actor));
    }

    /**
     * Execute une action par l'agent et la trace dans l'audit de la console, resultat compris.
     *
     * @param  array{id: int, name: string|null, phone: string, label: string}  $u
     * @param  array<string, mixed>  $details
     */
    private function act(Request $request, string $action, array $u, callable $call, array $details = []): JsonResponse
    {
        $operator = $this->operator($request);
        try {
            $result = $call($operator->label());
        } catch (AgentException $e) {
            Audit::log($operator, $action, 'failure', 'user', $u['id'], $u['label'], $details + ['error' => $e->getMessage()]);
            abort($e->status(), $e->getMessage());
        }
        $extra = $action === 'user.deleted' && isset($result['impact']['deleted'])
            ? ['deleted' => collect($result['impact']['deleted'])->mapWithKeys(fn ($i) => [$i['label'] => $i['count']])->all()] : [];
        Audit::log($operator, $action, 'success', 'user', $u['id'], $u['label'], $details + $extra);

        return response()->json(['message' => $result['message'] ?? 'Fait.']);
    }

    /** @return array{id: int, name: string|null, phone: string, label: string} */
    private function target(int $user): array
    {
        $row = $this->db()->table('users')->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')->where('users.id', $user)
            ->first(['users.id', 'users.phone', 'profiles.first_name', 'profiles.last_name']);
        abort_unless($row, 404, 'Compte introuvable (il a peut-être été supprimé).');
        $name = trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: null;

        return ['id' => (int) $row->id, 'name' => $name, 'phone' => $row->phone, 'label' => ($name ?: 'Compte #'.$row->id).' ('.Operator::maskPhone($row->phone).')'];
    }

    /** Garde-fous : pas sur son propre compte membre ; un super admin de l'eglise : proprietaire seulement. */
    private function guardTarget(Request $request, int $user, string $action): array
    {
        $u = $this->target($user);
        $operator = $this->operator($request);
        $superAdmin = $this->db()->table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user)->where('roles.key', 'super_admin')->exists();
        $reason = match (true) {
            $u['phone'] === $operator->phone => 'Vous ne pouvez pas faire cette action sur votre propre compte.',
            $superAdmin && ! $operator->atLeast('owner') => 'Seul un propriétaire de la console peut agir sur un super administrateur de l\'église.',
            default => null,
        };
        if ($reason) {
            Audit::log($operator, $action, 'denied', 'user', $u['id'], $u['label'], ['reason' => $reason]);
            abort(403, $reason);
        }

        return $u;
    }

    private function call(callable $fn): array
    {
        try {
            return $fn();
        } catch (AgentException $e) {
            abort($e->status(), $e->getMessage());
        }
    }

    private static function activity(object $u): string
    {
        return in_array($u->activity_override, ['active', 'inactive'], true) ? $u->activity_override : ($u->activity_status ?: 'active');
    }

    /** Appareil lisible a partir du navigateur declare (sans conserver la chaine complete). */
    public static function device(?string $ua): string
    {
        if (! $ua) {
            return 'Appareil inconnu';
        }
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux', default => 'Autre',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'Firefox') => 'Firefox', str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Chrome') => 'Chrome', str_contains($ua, 'Safari') => 'Safari', default => 'navigateur',
        };

        return $os.' - '.$browser;
    }
}
