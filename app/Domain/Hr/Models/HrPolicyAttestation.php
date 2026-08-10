<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPolicyAttestation extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    public $timestamps = false;

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'policy_id',
        'policy_version_id',
        'attested_at',
        'ip_address',
        'user_agent',
        'attestation_method',
    ];

    protected $casts = [
        'attested_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(HrPolicy::class, 'policy_id');
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(HrPolicyVersion::class, 'policy_version_id');
    }
}
