<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrJobPosting extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'position_id',
        'title',
        'department',
        'location',
        'employment_type',
        'description',
        'requirements',
        'salary_range_min',
        'salary_range_max',
        'show_salary',
        'status',
        'published_at',
        'closes_at',
        'applications_count',
        'created_by',
    ];

    protected $casts = [
        'salary_range_min' => 'decimal:2',
        'salary_range_max' => 'decimal:2',
        'show_salary' => 'boolean',
        'published_at' => 'datetime',
        'closes_at' => 'date',
        'applications_count' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('closes_at')
                    ->orWhere('closes_at', '>=', now()->toDateString());
            });
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors                                                          */
    /* ------------------------------------------------------------------ */

    public function getSalaryRangeAttribute(): ?string
    {
        if (! $this->show_salary) {
            return null;
        }

        if ($this->salary_range_min && $this->salary_range_max) {
            return '$' . number_format($this->salary_range_min) . ' - $' . number_format($this->salary_range_max);
        }

        if ($this->salary_range_min) {
            return 'From $' . number_format($this->salary_range_min);
        }

        if ($this->salary_range_max) {
            return 'Up to $' . number_format($this->salary_range_max);
        }

        return null;
    }
}
