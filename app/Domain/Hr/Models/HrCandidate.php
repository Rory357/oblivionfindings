<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Database\Factories\Hr\HrCandidateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrCandidate extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrCandidateFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'first_name',
        'last_name',
        'preferred_name',
        'personal_email',
        'personal_phone',
        'source',
        'source_detail',
        'status',
        'current_stage_entered_at',
        'privacy_consent_given_at',
        'privacy_consent_ip',
        'notes',
        'tags',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'current_stage_entered_at' => 'datetime',
        'privacy_consent_given_at' => 'datetime',
        'tags' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function applications(): HasMany
    {
        return $this->hasMany(HrApplication::class, 'candidate_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HrCandidateDocument::class, 'candidate_id');
    }

    public function talentPoolMembership(): HasOne
    {
        return $this->hasOne(HrTalentPool::class, 'candidate_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function interviews(): HasManyThrough
    {
        return $this->hasManyThrough(
            HrInterview::class,
            HrApplication::class,
            'candidate_id',  // FK on hr_applications
            'application_id' // FK on hr_interviews
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['withdrawn', 'rejected']);
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors */
    /* ------------------------------------------------------------------ */

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
