<?php

namespace App\Models;

use App\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TrainingCourse extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'name',
        'code',
        'category',
        'description',
        'learning_outcomes',
        'duration_minutes',
        'requires_renewal',
        'validity_period_months',
        'renewal_reminder_months',
        'mandatory_for_all',
        'mandatory_for_roles',
        'prerequisites',
        'requires_assessment',
        'pass_mark_percentage',
        'provider',
        'provider_reference',
        'delivery_method',
        'cost_per_person',
        'active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'mandatory_for_roles' => 'array',
        'prerequisites' => 'array',
        'requires_renewal' => 'boolean',
        'mandatory_for_all' => 'boolean',
        'requires_assessment' => 'boolean',
        'active' => 'boolean',
    ];

    /**
     * Training records for this course.
     */
    public function trainingRecords(): HasMany
    {
        return $this->hasMany(StaffTrainingRecord::class);
    }

    /**
     * User who created the record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who last updated the record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope: Active courses.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope: Mandatory courses.
     */
    public function scopeMandatory($query)
    {
        return $query->where('mandatory_for_all', true);
    }

    /**
     * Scope: By category.
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Check if course is mandatory for a given role.
     */
    public function isMandatoryForRole(string $role): bool
    {
        return $this->mandatory_for_all
            || (is_array($this->mandatory_for_roles) && in_array($role, $this->mandatory_for_roles));
    }

    /**
     * Check if course requires renewal.
     */
    public function requiresRenewal(): bool
    {
        return $this->requires_renewal;
    }
}
