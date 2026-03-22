<?php

namespace App\Domain\Hr\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrOnboardingEmailLog extends Model
{
    use HasFactory;

    protected $table = 'hr_onboarding_email_log';

    public $timestamps = false;

    protected $fillable = [
        'onboarding_email_id',
        'employee_profile_id',
        'sent_at',
        'status',
        'error',
        'created_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function onboardingEmail(): BelongsTo
    {
        return $this->belongsTo(HrOnboardingEmail::class, 'onboarding_email_id');
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }
}
