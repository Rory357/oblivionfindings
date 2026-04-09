<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * H&S training requirement — a compliance bridge between HR training
 * records and H&S shift eligibility enforcement.
 *
 * This model does NOT own training data. HR owns courses, enrollments,
 * and compliance statuses. This model declares that a specific HR
 * compliance requirement is H&S-relevant and defines enforcement rules.
 */
class HsTrainingRequirement extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $table = 'hs_training_requirements';

    /* ------------------------------------------------------------------ */
    /*  Constants                                                          */
    /* ------------------------------------------------------------------ */

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_ROLE = 'role';
    public const SCOPE_SITE = 'site';
    public const SCOPE_CLIENT = 'client';

    public const ENFORCEMENT_WARN = 'warn';
    public const ENFORCEMENT_BLOCK = 'block';

    /* ------------------------------------------------------------------ */
    /*  Fillable / Casts                                                   */
    /* ------------------------------------------------------------------ */

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'hr_compliance_requirement_id',
        'scope_type',
        'scope_roles',
        'scope_site_ids',
        'scope_client_ids',
        'enforcement_mode',
        'validity_months',
        'grace_period_days',
        'regulatory_reference',
        'rationale',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'scope_roles' => 'array',
        'scope_site_ids' => 'array',
        'scope_client_ids' => 'array',
        'validity_months' => 'integer',
        'grace_period_days' => 'integer',
        'is_active' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForScope($query, string $scopeType)
    {
        return $query->where('scope_type', $scopeType);
    }

    /* ------------------------------------------------------------------ */
    /*  Applicability checks                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Check if this requirement applies to a given shift/user context.
     */
    public function appliesTo(?string $userRole, ?int $siteId, ?int $clientId): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($this->scope_type) {
            self::SCOPE_GLOBAL => true,
            self::SCOPE_ROLE => $userRole && in_array($userRole, $this->scope_roles ?? [], true),
            self::SCOPE_SITE => $siteId && in_array($siteId, $this->scope_site_ids ?? [], true),
            self::SCOPE_CLIENT => $clientId && in_array($clientId, $this->scope_client_ids ?? [], true),
            default => false,
        };
    }

    /**
     * Whether this requirement blocks shift assignment (vs just warns).
     */
    public function isBlocking(): bool
    {
        return $this->enforcement_mode === self::ENFORCEMENT_BLOCK;
    }
}
