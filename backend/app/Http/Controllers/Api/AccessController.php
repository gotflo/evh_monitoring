<?php

namespace App\Http\Controllers\Api;

use App\Models\Operator;
use App\Support\Audit;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Personnes autorisees a la console. Le proprietaire invite ou retire des numeros et choisit
 * leur role ; le proprietaire principal ne peut etre ni retire ni retrograde depuis la console,
 * et il reste toujours au moins un proprietaire actif.
 */
class AccessController extends ConsoleController
{
    public function index(Request $request): JsonResponse
    {
        $operators = Operator::with('inviter:id,name,phone')->orderByDesc('is_primary')
            ->orderByRaw("CASE status WHEN 'revoked' THEN 1 ELSE 0 END")->orderBy('created_at')->get();
        // Compte membre portant le meme numero (alertes dans l'application) ; inconnu si la base est illisible.
        $members = rescue(function () use ($operators) {
            $found = \App\Services\Metrics::app()->table('users')->whereIn('phone', $operators->pluck('phone'))->pluck('phone')->all();

            return $operators->pluck('phone')->mapWithKeys(fn ($p) => [$p => in_array($p, $found, true)])->all();
        }, [], false);
        $sessions = \Illuminate\Support\Facades\DB::table('personal_access_tokens')->where('tokenable_type', Operator::class)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->groupBy('tokenable_id')->selectRaw('tokenable_id, COUNT(*) as n, MAX(last_used_at) as last_used_at')->get()->keyBy('tokenable_id');

        return response()->json([
            'operators' => $operators->map(fn (Operator $o) => [
                'id' => $o->id, 'name' => $o->name, 'phone' => $o->phone, 'role' => $o->role,
                'role_label' => Operator::ROLE_LABELS[$o->role] ?? $o->role, 'is_primary' => $o->is_primary,
                'status' => $o->status, 'alerts_enabled' => $o->alerts_enabled,
                'invited_by' => $o->inviter?->label(), 'created_at' => $this->iso($o->created_at),
                'activated_at' => $this->iso($o->activated_at), 'last_login_at' => $this->iso($o->last_login_at),
                'revoked_at' => $this->iso($o->revoked_at),
                'sessions' => (int) ($sessions[$o->id]->n ?? 0),
                'last_activity_at' => $this->iso($sessions[$o->id]->last_used_at ?? null),
                'has_member_account' => $members[$o->phone] ?? null,
                'is_me' => $o->id === $this->operator($request)->id,
            ])->values(),
            'roles' => collect(Operator::ROLE_LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'help' => match ($key) {
                'owner' => 'Tout, y compris la gestion des accès et la durée de conservation.',
                'admin' => 'Consultation, actions sur les comptes membres, incidents, dépannage et seuils d\'alerte.',
                default => 'Consultation uniquement : aucun changement possible.',
            }])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'size:2'],
            'name' => ['nullable', 'string', 'max:80'],
            'role' => ['required', Rule::in(array_keys(Operator::ROLES))],
            'alerts_enabled' => ['nullable', 'boolean'],
        ]);
        $phone = Phone::normalize($data['phone'], $data['country'] ?? 'CA');
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Ce numéro de téléphone n\'est pas valide.']);
        }
        $operator = $this->operator($request);
        $existing = Operator::where('phone', $phone)->first();
        if ($existing && $existing->isAllowed()) {
            throw ValidationException::withMessages(['phone' => 'Ce numéro a déjà accès à la console.']);
        }

        if ($existing) {
            $existing->fill(['status' => 'invited', 'role' => $data['role'], 'name' => $data['name'] ?? $existing->name,
                'alerts_enabled' => $data['alerts_enabled'] ?? true, 'invited_by' => $operator->id, 'revoked_at' => null])->save();
            $target = $existing;
            $action = 'access.restored';
        } else {
            $target = Operator::create(['phone' => $phone, 'name' => $data['name'] ?? null, 'role' => $data['role'], 'status' => 'invited',
                'alerts_enabled' => $data['alerts_enabled'] ?? true, 'invited_by' => $operator->id]);
            $action = 'access.invited';
        }
        Audit::log($operator, $action, 'success', 'operator', $target->id, $target->label(), ['role' => $target->role, 'phone' => Operator::maskPhone($phone)]);

        return response()->json(['message' => 'Accès ajouté : cette personne peut se connecter à la console avec son numéro et un code reçu par SMS.']);
    }

    public function update(Request $request, Operator $target): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'role' => ['nullable', Rule::in(array_keys(Operator::ROLES))],
            'alerts_enabled' => ['nullable', 'boolean'],
        ]);
        $operator = $this->operator($request);
        abort_unless($target->isAllowed(), 422, 'Cet accès a été retiré : ajoutez de nouveau le numéro pour le rétablir.');
        if (isset($data['role']) && $data['role'] !== $target->role) {
            $this->protectOwners($operator, $target, 'access.updated', $data['role']);
        }

        $before = $target->only(['name', 'role', 'alerts_enabled']);
        $target->fill(array_filter($data, fn ($v) => $v !== null))->save();
        [$old, $new] = \App\Support\Audit::diff($before, $target->only(['name', 'role', 'alerts_enabled']));
        if ($new) {
            Audit::log($operator, 'access.updated', 'success', 'operator', $target->id, $target->label(), ['before' => $old, 'after' => $new]);
        }

        return response()->json(['message' => 'Accès mis à jour.']);
    }

    public function destroy(Request $request, Operator $target): JsonResponse
    {
        $operator = $this->operator($request);
        abort_unless($target->isAllowed(), 422, 'Cet accès est déjà retiré.');
        $this->protectOwners($operator, $target, 'access.revoked', null);

        $target->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $sessions = $target->tokens()->delete();
        Audit::log($operator, 'access.revoked', 'success', 'operator', $target->id, $target->label(), ['sessions_closed' => $sessions]);

        return response()->json(['message' => 'Accès retiré : les sessions ouvertes de cette personne sont fermées.']);
    }

    public function revokeSessions(Request $request, Operator $target): JsonResponse
    {
        $operator = $this->operator($request);
        $query = $target->tokens();
        // Ses propres sessions : on garde celle en cours.
        if ($target->id === $operator->id && $operator->currentAccessToken()) {
            $query->where('id', '!=', $operator->currentAccessToken()->id);
        }
        $n = $query->delete();
        Audit::log($operator, 'access.sessions_revoked', 'success', 'operator', $target->id, $target->label(), ['sessions' => $n]);

        return response()->json(['message' => $n ? $n.' session(s) fermée(s).' : 'Aucune autre session ouverte.']);
    }

    /** Le proprietaire principal est intouchable ; il reste toujours au moins un proprietaire. */
    private function protectOwners(Operator $operator, Operator $target, string $action, ?string $newRole): void
    {
        $reason = null;
        if ($target->is_primary) {
            $reason = 'Le propriétaire principal ne peut être ni retiré ni rétrogradé depuis la console.';
        } elseif ($target->role === 'owner' && ($newRole === null || $newRole !== 'owner')
            && Operator::where('role', 'owner')->whereIn('status', ['invited', 'active'])->where('id', '!=', $target->id)->count() === 0) {
            $reason = 'Il doit toujours rester au moins un propriétaire.';
        }
        if ($reason) {
            Audit::log($operator, $action, 'denied', 'operator', $target->id, $target->label(), ['reason' => $reason]);
            throw ValidationException::withMessages(['role' => $reason]);
        }
    }
}
