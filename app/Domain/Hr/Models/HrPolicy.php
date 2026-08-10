<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Database\Factories\Hr\HrPolicyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HrPolicy extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrPolicyFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'title',
        'slug',
        'category',
        'is_active',
        'requires_attestation',
        'attestation_frequency_months',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_attestation' => 'boolean',
        'attestation_frequency_months' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function versions(): HasMany
    {
        return $this->hasMany(HrPolicyVersion::class, 'policy_id');
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(HrPolicyAttestation::class, 'policy_id');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(HrPolicyVersion::class, 'policy_id')
            ->where('is_current', true);
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRequiringAttestation(Builder $query): Builder
    {
        return $query->where('requires_attestation', true);
    }
}
