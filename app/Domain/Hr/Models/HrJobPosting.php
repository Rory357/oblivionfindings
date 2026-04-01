<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class HrJobPosting extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'position_id',
        'title',
        'slug',
        'department',
        'location',
        'employment_type',
        'is_remote',
        'is_internal',
        'description',
        'summary',
        'requirements',
        'responsibilities',
        'salary_range_min',
        'salary_range_max',
        'show_salary',
        'status',
        'published_at',
        'closes_at',
        'closing_soon_notified_at',
        'applications_count',
        'views_count',
        'screening_questions',
        'notification_emails',
        'hiring_manager_id',
        'requires_approval',
        'approved_by',
        'approved_at',
        'created_by',
    ];

    protected $casts = [
        'salary_range_min' => 'decimal:2',
        'salary_range_max' => 'decimal:2',
        'show_salary' => 'boolean',
        'is_remote' => 'boolean',
        'is_internal' => 'boolean',
        'requires_approval' => 'boolean',
        'published_at' => 'datetime',
        'approved_at' => 'datetime',
        'closing_soon_notified_at' => 'datetime',
        'closes_at' => 'date',
        'applications_count' => 'integer',
        'views_count' => 'integer',
        'notification_emails' => 'array',
        'screening_questions' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Boot                                                               */
    /* ------------------------------------------------------------------ */

    protected static function booted(): void
    {
        static::creating(function (self $posting) {
            if (empty($posting->slug)) {
                $posting->slug = self::generateUniqueSlug($posting->title, $posting->tenant_id);
            }
        });
    }

    public static function generateUniqueSlug(string $title, ?int $tenantId): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'posting';
        }
        $slug = $base;
        $i = 1;

        while (self::where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        // The unique DB index (tenant_id, slug) provides final safety against race conditions
        return $slug;
    }

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

    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hiring_manager_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(HrApplication::class, 'job_posting_id');
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

    public function scopePublishedBySlug($query, string $slug)
    {
        return $query->where('slug', $slug)->where('status', 'published');
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
