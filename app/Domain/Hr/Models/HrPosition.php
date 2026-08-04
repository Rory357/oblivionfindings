<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Database\Factories\Hr\HrPositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrPosition extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrPositionFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'title',
        'code',
        'department',
        'team',
        'description',
        'requirements',
        'summary',
        'responsibilities',
        'employment_type',
        'fte',
        'headcount_budget',
        'current_headcount',
        'reports_to_position_id',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'fte' => 'decimal:2',
        'headcount_budget' => 'integer',
        'current_headcount' => 'integer',
        'is_active' => 'boolean',
    ];

    /* Relationships */

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_position_id');
    }

    public function directReportPositions(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to_position_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(HrEmployeeProfile::class, 'position_id');
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(HrJobRequisition::class, 'position_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* Scopes */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInDepartment($query, string $department)
    {
        return $query->where('department', $department);
    }

    /* Accessors */

    public function getVacanciesAttribute(): int
    {
        return max(0, $this->headcount_budget - $this->current_headcount);
    }

    public function getFteUtilizationAttribute(): float
    {
        if ($this->headcount_budget === 0) {
            return 0;
        }

        return round(($this->current_headcount / $this->headcount_budget) * 100, 1);
    }

    /**
     * Openings already being recruited for via linked, non-closed requisitions —
     * so a seat that's actively in recruitment isn't double-counted as a gap.
     */
    public function getOpenRequisitionOpeningsAttribute(): int
    {
        if ($this->relationLoaded('requisitions')) {
            return (int) $this->requisitions
                ->whereNotIn('status', ['closed'])
                ->sum('openings');
        }

        return (int) $this->requisitions()
            ->whereNotIn('status', ['closed'])
            ->sum('openings');
    }

    /** Vacancies that still need action: budget − filled − openings already in recruitment. */
    public function getActionableVacanciesAttribute(): int
    {
        return max(
            0,
            $this->headcount_budget - $this->current_headcount - $this->open_requisition_openings,
        );
    }

    public function getIsUnderstaffedAttribute(): bool
    {
        return $this->actionable_vacancies > 0;
    }
}
