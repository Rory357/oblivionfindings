<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrCompetency extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $table = 'hr_competencies';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'category',
        'proficiency_levels',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'proficiency_levels' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function assessments(): HasMany
    {
        return $this->hasMany(HrCompetencyAssessment::class, 'competency_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
