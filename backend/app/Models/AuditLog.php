<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Journal d'audit de la console : jamais modifie, supprime seulement apres la duree de conservation. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['operator_id', 'operator_label', 'action', 'target_type', 'target_id', 'target_label', 'outcome', 'details', 'ip'];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'operator_id');
    }
}
