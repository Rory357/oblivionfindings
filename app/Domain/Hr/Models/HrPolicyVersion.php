<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPolicyVersion extends Model
{
    use HasFactory, AuditableChanges;

    public $timestamps = false;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'policy_id',
        'version_number',
        'content_summary',
        'document_path',
        'effective_from',
        'is_current',
        'published_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'is_current' => 'boolean',
        'version_number' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function policy(): BelongsTo
    {
        return $this->belongsTo(HrPolicy::class, 'policy_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(HrPolicyAttestation::class, 'policy_version_id');
    }
}
