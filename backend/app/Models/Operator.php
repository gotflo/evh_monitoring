<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

/**
 * Personne autorisee a la console de supervision. Identite propre a la console : un role de
 * l'eglise (meme super admin) ne donne jamais acces a la console. Le numero sert aussi a lui
 * envoyer les alertes dans l'application, si un compte membre porte le meme numero.
 */
class Operator extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens;

    /** Rang de chaque role : un role donne aussi les droits des rangs inferieurs. */
    public const ROLES = ['viewer' => 1, 'admin' => 2, 'owner' => 3];

    public const ROLE_LABELS = ['owner' => 'Propriétaire', 'admin' => 'Administrateur', 'viewer' => 'Lecture seule'];

    protected $fillable = ['phone', 'name', 'role', 'is_primary', 'status', 'alerts_enabled', 'invited_by', 'activated_at', 'last_login_at', 'revoked_at'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'alerts_enabled' => 'boolean',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Pas de « se souvenir de moi » : aucune colonne dediee. */
    public function getRememberTokenName()
    {
        return '';
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by');
    }

    public function isAllowed(): bool
    {
        return in_array($this->status, ['invited', 'active'], true);
    }

    /** A-t-il au moins ce role ? (owner > admin > viewer) */
    public function atLeast(string $role): bool
    {
        return $this->isAllowed() && (self::ROLES[$this->role] ?? 0) >= (self::ROLES[$role] ?? PHP_INT_MAX);
    }

    /** Libelle lisible : nom, sinon numero masque. */
    public function label(): string
    {
        return $this->name ?: self::maskPhone($this->phone);
    }

    public static function maskPhone(?string $phone): string
    {
        if (! $phone) {
            return '-';
        }

        return mb_substr($phone, 0, 2).' *** '.mb_substr($phone, -4);
    }

    /** Ce que l'interface peut proposer (le serveur reverifie chaque action). */
    public function abilities(): array
    {
        return [
            'view' => $this->atLeast('viewer'),
            'operate' => $this->atLeast('admin'),
            'manage_users' => $this->atLeast('admin'),
            'manage_access' => $this->atLeast('owner'),
            'manage_retention' => $this->atLeast('owner'),
        ];
    }
}
