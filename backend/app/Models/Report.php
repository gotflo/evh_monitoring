<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Rapport periodique de supervision (chiffres figes au moment de la generation). */
class Report extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['period', 'period_start', 'period_end', 'data', 'generated_by', 'sent_at', 'delivery'];

    protected $casts = [
        'data' => 'array',
        'delivery' => 'array',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
