<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrEmployeeProfile extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'employee_number',
        'date_of_birth',
        'gender',
        'ethnicity',
        'personal_email',
        'personal_phone',
        'home_address',
        'work_email',
        'work_phone',
        'position_title',
        'position_role',
        'employment_type',
        'contract_type',
        'hours_per_week',
        'hourly_rate',
        'annual_salary',
        'pay_frequency',
        'start_date',
        'end_date',
        'probation_end_date',
        'termination_reason',
        'is_active',
        'primary_site_id',
        'secondary_site_ids',
        'emergency_contacts',
        'bank_account',
        'ird_number',
        'tax_code',
        'kiwisaver_rate',
        'can_drive_clients',
        'driver_eligibility_reviewed_at',
        'is_first_aider',
        'is_fire_warden',
        'position_id',
        'manager_user_id',
        'department',
        'team',
        'reporting_level',
        'offer_id',
        'candidate_id',
        'notes',
        'restricted_notes',
        'profile_photo_path',
        'bio',
        'preferred_name',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date_of_birth' => 'encrypted',
        'start_date' => 'date',
        'end_date' => 'date',
        'probation_end_date' => 'date',
        'is_active' => 'boolean',
        'secondary_site_ids' => 'array',
        'emergency_contacts' => 'array',
        'can_drive_clients' => 'boolean',
        'is_first_aider' => 'boolean',
        'is_fire_warden' => 'boolean',
        'hours_per_week' => 'decimal:2',
        'kiwisaver_rate' => 'decimal:2',
        'driver_eligibility_reviewed_at' => 'datetime',
        'home_address' => 'encrypted',
        'bank_account' => 'encrypted',
        'ird_number' => 'encrypted',
        'hourly_rate' => 'encrypted',
        'annual_salary' => 'encrypted',
        'reporting_level' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primarySite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'primary_site_id');
    }

    public function departmentRelation(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(HrEmployeeProfileVersion::class, 'employee_profile_id');
    }

    public function onboardingChecklists(): HasMany
    {
        return $this->hasMany(HrOnboardingChecklist::class, 'employee_profile_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(HrLeaveRequest::class, 'user_id', 'user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HrDocument::class, 'employee_profile_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(HrOffer::class, 'offer_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_user_id', 'user_id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(HrEmployeeStatusChange::class, 'employee_profile_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAtSite($query, int $siteId)
    {
        return $query->where(function ($q) use ($siteId) {
            $q->where('primary_site_id', $siteId)
              ->orWhereJsonContains('secondary_site_ids', $siteId);
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors                                                          */
    /* ------------------------------------------------------------------ */

    public function getFullNameAttribute(): string
    {
        return $this->user?->name ?? 'Unknown';
    }
}
