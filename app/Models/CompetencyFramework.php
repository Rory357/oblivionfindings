<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetencyFramework extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'name',
        'role',
        'description',
        'version',
        'effective_from',
        'active',
        'created_by',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'active' => 'boolean',
    ];

    /**
     * Competency items in this framework.
     */
    public function items(): HasMany
    {
        return $this->hasMany(CompetencyItem::class, 'framework_id');
    }

    /**
     * Staff competency assessments using this framework.
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(StaffCompetencyAssessment::class);
    }

    /**
     * User who created the record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope: Active frameworks.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope: By role.
     */
    public function scopeForRole($query, string $role)
    {
        return $query->where('role', $role);
    }
}
