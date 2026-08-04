<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Database\Factories\HrComplianceRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HrComplianceRequirement extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrComplianceRequirementFactory::new();
    }

    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'check_type',
        'reference_id',
        'validity_months',
        'renewal_reminder_days',
        'hard_stop',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'validity_months' => 'integer',
        'renewal_reminder_days' => 'integer',
        'hard_stop' => 'boolean',
        'is_active' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function matrixEntries(): HasMany
    {
        return $this->hasMany(HrComplianceMatrix::class, 'requirement_id');
    }

    public function staffStatuses(): HasMany
    {
        return $this->hasMany(HrStaffComplianceStatus::class, 'requirement_id');
    }

    /**
     * The canonical catalog course this requirement is satisfied by, if any.
     * HrCourse.compliance_requirement_id is the source-of-truth back-link used by
     * the training-completion → compliance bridge and the eligibility readers.
     */
    public function hrCourse(): HasOne
    {
        return $this->hasOne(HrCourse::class, 'compliance_requirement_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeHardStop($query)
    {
        return $query->where('hard_stop', true);
    }
}
