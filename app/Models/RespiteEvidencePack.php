<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteEvidencePack extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'stay_id',
        'booking_id',
        'status',
        'pack_type',
        'summary',
        'items',
        'included_documents',
        'included_incidents',
        'included_medications',
        'included_daily_notes',
        'included_handovers',
        'coordinator_notes',
        'family_feedback',
        'sealed_at',
        'sealed_by_user_id',
        'exported',
        'exported_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'items' => 'array',
        'included_documents' => 'array',
        'included_incidents' => 'array',
        'included_medications' => 'array',
        'included_daily_notes' => 'array',
        'included_handovers' => 'array',
        'sealed_at' => 'datetime',
        'exported' => 'boolean',
        'exported_at' => 'datetime',
    ];

    public function stay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'stay_id');
    }

    public function sealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sealed_by_user_id');
    }
}
