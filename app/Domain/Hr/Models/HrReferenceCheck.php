<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrReferenceCheck extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'application_id',
        'referee_name',
        'referee_email',
        'referee_phone',
        'referee_relationship',
        'status',
        'requested_at',
        'received_at',
        'verified_at',
        'reference_notes',
        'verified_by',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'received_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function application(): BelongsTo
    {
        return $this->belongsTo(HrApplication::class, 'application_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
