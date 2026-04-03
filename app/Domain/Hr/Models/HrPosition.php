<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrPosition extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Hr\HrPositionFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'title',
        'code',
        'department',
        'team',
        'description',
        'requirements',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* Scopes */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

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
        if ($this->headcount_budget === 0) return 0;
        return round(($this->current_headcount / $this->headcount_budget) * 100, 1);
    }
}
