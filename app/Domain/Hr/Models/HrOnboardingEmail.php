<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrOnboardingEmail extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $table = 'hr_onboarding_emails';

    protected $fillable = [
        'tenant_id',
        'template_name',
        'subject',
        'body',
        'send_days_before_start',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'send_days_before_start' => 'integer',
        'is_active' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(HrOnboardingEmailLog::class, 'onboarding_email_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
