<?php

namespace App\Models;

use App\Domain\Clinical\Models\ClientMealRestriction;
use App\Domain\Clinical\Models\ClientMealRestrictionDiscrepancy;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Referrals\Services\ReferralActivationGuard;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use App\Services\AuditLogger;
use App\Services\Clients\ClientIdentityWrites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class Client extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;
    use WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'user_id',
        'nhi_number',
        'nhi_hash',
        'site_id',
        'room_id',
        'service_context_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'preferred_name',
        'gender',
        'status',
        'phone',
        'email',
        'address_line_1',
        'address_line_2',
        'suburb',
        'city',
        'postcode',
        'funding_type',
        'care_level',
        'funding_notes',
        'openai_vector_store_id',
        'profile_photo_path',
        'transport_needs',
        'transport_notes',
        'ethnicity',
        'iwi',
        'hapu',
        'marae',
        'languages',
        'preferred_pronouns',
        'religion',
        'interests_hobbies',
        'strengths_abilities',
        'life_story',
        'education_level',
        'employment_status',
        'mobility_needs',
        'sensory_needs',
        'cognitive_needs',
        'dietary_requirements',
        'cultural_dietary_needs',
        'sleep_preferences',
        'sleep_target_hours',
        'service_start_date',
        'key_worker_id',
        'risk_level',
        'safeguarding_flag',
        'house_geofence_id',
        'fluid_intake_min_ml',
        'fluid_intake_max_ml',
        'seizure_duration_escalation_seconds',
        'suppress_med_admin_alerts',
        'med_alerts_suppressed_reason',
        'med_alerts_suppressed_by',
        'med_alerts_suppressed_at',
        'chart_review_interval_months',
        'next_chart_review_date',
        'meal_iddsi_level',
        'meal_iddsi_label',
        'meal_fluids_label',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'transport_needs' => 'array',
        'languages' => 'array',
        'service_start_date' => 'date',
        'sleep_target_hours' => 'decimal:1',
        'safeguarding_flag' => 'boolean',
        'suppress_med_admin_alerts' => 'boolean',
        'med_alerts_suppressed_at' => 'datetime',
        'chart_review_interval_months' => 'integer',
        'medication_review_interval_months' => 'integer',
        'next_chart_review_date' => 'date',
        'phone' => 'encrypted',
        'email' => 'encrypted',
        'contact_preferences' => 'encrypted:array',
        'nhi_number' => 'encrypted',
    ];

    protected $appends = ['profile_photo_url', 'avatar', 'full_name'];

    // Keep contact values out of generic plaintext snapshots; retain changed-field audit events below.
    protected $auditExcludedAttributes = ['phone', 'email', 'contact_preferences'];

    protected $hidden = ['contact_preferences'];

    public function save(array $options = [])
    {
        if ($this->isDirty('status') && strtolower(trim((string) $this->status)) === 'active') {
            $this->status = 'active';
        }
        if (! $this->exists) {
            // Omitted status keeps the established default unless the organisation requires a
            // commencement review for everyone. Referral conversion sets onboarding explicitly.
            $strict = ReferralActivationGuard::requiredForEveryone();
            $this->status ??= $strict ? 'onboarding' : 'active';
            if ($strict && ($this->status === 'active' || $this->service_start_date !== null)) {
                throw ValidationException::withMessages([
                    'status' => 'Create this person in onboarding, then use the separate service-commencement review to start support.',
                    ...($this->service_start_date !== null ? ['service_start_date' => 'The actual start date must be recorded by the service-commencement review.'] : []),
                ]);
            }
        }
        $serviceFields = ['status', 'service_context_id', 'site_id', 'service_start_date'];
        if ($this->exists && $this->isDirty($serviceFields)) {
            // If identity also changes, take its mutex before the person row, as conversion does.
            return $this->saveIdentity($options, fn () => DB::transaction(function () use ($options, $serviceFields) {
                $current = static::query()->lockForUpdate()->findOrFail($this->id);
                foreach ($serviceFields as $field) {
                    $latest = $field === 'service_start_date' ? $current->getOriginal($field)?->toDateString() : $current->getRawOriginal($field);
                    $original = $field === 'service_start_date' ? $this->getOriginal($field)?->toDateString() : $this->getRawOriginal($field);
                    if ((string) $latest !== (string) $original) {
                        throw ValidationException::withMessages([
                            'status' => 'This person’s service details changed while you were editing. Refresh the profile and review the current status, site and service before saving.',
                        ]);
                    }
                }
                // Only a NEW activation (into active) or recording a start date is a commencement
                // decision. Moving an already-active person between sites or services is not.
                $activating = $this->status === 'active' && strtolower(trim((string) $current->getRawOriginal('status'))) !== 'active';
                $dating = $this->isDirty('service_start_date');
                $guard = app(ReferralActivationGuard::class);
                if (($activating || $dating) && $guard->governsActivation($current)) {
                    if ($dating) {
                        $guard->assertStartDatePermitted($this);
                    }
                    $guard->assertPermitted($this);
                }

                return parent::save($options);
            }));
        }

        return $this->saveIdentity($options);
    }

    private function saveIdentity(array $options, ?callable $write = null)
    {
        $write ??= fn () => parent::save($options);
        if (! $this->exists || $this->isDirty(['first_name', 'last_name', 'date_of_birth', 'nhi_number', 'nhi_hash'])) {
            return app(ClientIdentityWrites::class)->save($this, $write);
        }

        return $write();
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        return $this->profile_photo_path
            ? Storage::disk('public')->url($this->profile_photo_path)
            : url('/images/avatar-placeholder.svg');
    }

    public function getAvatarAttribute(): ?string
    {
        return $this->profile_photo_url;
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    /**
     * The user account associated with this client (for portal access)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function room()
    {
        return $this->belongsTo(SiteHouseRoom::class, 'room_id');
    }

    public function houseGeofence()
    {
        return $this->belongsTo(AssetGeofence::class, 'house_geofence_id');
    }

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function keyWorker()
    {
        return $this->belongsTo(User::class, 'key_worker_id');
    }

    public function supportWorkers()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function notes()
    {
        return $this->hasMany(ClientNote::class);
    }

    public function portalUsers()
    {
        return $this->belongsToMany(User::class, 'client_portal_users')
            ->withPivot('relation')
            ->withTimestamps();
    }

    /**
     * Next of kin relationships
     */
    public function nextOfKins()
    {
        return $this->hasMany(NextOfKin::class);
    }

    public function medicalProfile()
    {
        return $this->hasOne(ClientMedicalProfile::class);
    }

    public function medications()
    {
        return $this->hasMany(ClientMedication::class);
    }

    public function medicationAdministrations()
    {
        return $this->hasMany(ClientMedicationAdministration::class);
    }

    public function mealLogs()
    {
        return $this->hasMany(ClientMealLog::class);
    }

    public function sleepEntries()
    {
        return $this->hasMany(ClientSleepEntry::class);
    }

    public function respiteAllocations()
    {
        return $this->hasMany(ClientRespiteAllocation::class);
    }

    public function medicationAlerts()
    {
        return $this->hasMany(ClientMedicationAlert::class);
    }

    public function inrRecords()
    {
        return $this->hasMany(ClientInrRecord::class);
    }

    public function syringeDrivers()
    {
        return $this->hasMany(MedicationSyringeDriver::class);
    }

    public function breakGlassAccesses()
    {
        return $this->hasMany(ClientBreakGlassAccess::class);
    }

    public function emergencyContacts()
    {
        return $this->hasMany(ClientEmergencyContact::class);
    }

    public function conditions()
    {
        return $this->hasMany(ClientCondition::class);
    }

    public function controlledDrugDiscrepancies()
    {
        return $this->hasMany(ClientControlledDrugDiscrepancy::class);
    }

    public function medicationAllergies()
    {
        return $this->hasMany(MedicationAllergy::class);
    }

    public function safeguardingAlerts()
    {
        return $this->morphMany(SafeguardingAlert::class, 'alertable');
    }

    public function documents()
    {
        return $this->hasMany(ClientDocument::class);
    }

    public function documentFolders()
    {
        return $this->hasMany(ClientDocumentFolder::class);
    }

    public function familyPortalSetting()
    {
        return $this->hasOne(FamilyPortalSetting::class);
    }

    public function supportPlan()
    {
        return $this->hasOne(ClientSupportPlan::class);
    }

    public function carePlans()
    {
        return $this->hasMany(CarePlan::class);
    }

    public function assessments()
    {
        return $this->hasMany(ClientAssessment::class);
    }

    public function incidents()
    {
        return $this->hasMany(ClientIncident::class);
    }

    /**
     * Privacy Act 2020 access/correction requests made about this client.
     */
    public function dataSubjectRequests()
    {
        return $this->hasMany(DataSubjectRequest::class);
    }

    public function respiteBookings()
    {
        return $this->hasMany(RespiteBooking::class);
    }

    public function respiteBookingRequests()
    {
        return $this->hasMany(RespiteBookingRequest::class);
    }

    public function risks()
    {
        return $this->hasMany(ClientRisk::class);
    }

    public function onboardingOverrides()
    {
        return $this->hasMany(ClientOnboardingOverride::class);
    }

    /**
     * Scope: Find by NHI number
     */
    public function scopeByNhi($query, string $nhi)
    {
        return $query->where('nhi_hash', self::nhiHash($nhi));
    }

    /**
     * Validate NHI number format (3 letters + 4 numbers)
     */
    public static function validateNhi(string $nhi): bool
    {
        return preg_match('/^[A-Z]{3}\d{4}$/i', $nhi) === 1;
    }

    public static function normaliseNhi(?string $nhi): ?string
    {
        $normalised = strtoupper(preg_replace('/\s+/', '', (string) $nhi));

        return $normalised !== '' ? $normalised : null;
    }

    public static function nhiHash(?string $nhi): ?string
    {
        $normalised = self::normaliseNhi($nhi);

        return $normalised ? hash('sha256', $normalised) : null;
    }

    /**
     * Validation rules for NHI number.
     */
    public static function nhiValidationRules(?int $ignoreClientId = null): array
    {
        return [
            'nullable',
            'string',
            'max:10',
            'regex:/^[A-Z]{3}\d{4}$/i',
            function (string $attribute, mixed $value, \Closure $fail) use ($ignoreClientId): void {
                $hash = self::nhiHash((string) $value);

                if (! $hash) {
                    return;
                }

                $exists = self::query()
                    ->where('nhi_hash', $hash)
                    ->when($ignoreClientId !== null, fn ($query) => $query->whereKeyNot($ignoreClientId))
                    ->exists();

                if ($exists) {
                    $fail('The NHI number is already attached to another client.');
                }
            },
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (Client $client) {
            $fields = array_values(array_intersect(['phone', 'email', 'contact_preferences'], array_keys($client->getChanges())));
            if ($fields !== []) {
                AuditLogger::log('client.contact_fields_changed', $client, ['fields' => $fields]);
            }
        });
        static::saving(function (Client $client) {
            $raw = $client->nhi_number;
            if ($raw) {
                $upper = self::normaliseNhi($raw);
                if ($upper !== $raw) {
                    $client->nhi_number = $upper;
                }
                $client->nhi_hash = self::nhiHash($upper);
            } else {
                $client->nhi_hash = null;
            }
        });
    }

    public function onboardingWorkflow()
    {
        return $this->hasOne(ClientOnboardingWorkflow::class, 'client_id')->where('scope', 'person')->latest();
    }

    public function onboardingWorkflows()
    {
        return $this->hasMany(ClientOnboardingWorkflow::class, 'client_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Live finance invoices (FinInvoice). The legacy `invoices()` relation above
     * points at the orphaned App\Models\Invoice table that nothing writes to.
     */
    public function finInvoices()
    {
        return $this->hasMany(FinInvoice::class);
    }

    public function personalAssets()
    {
        return $this->hasMany(ClientPersonalAsset::class);
    }

    // ── Fleet relationships ──────────────────────────────────────────────────

    public function fleetTransports()
    {
        return $this->hasMany(FleetResidentTransport::class, 'resident_id');
    }

    public function fleetOutings()
    {
        return $this->belongsToMany(FleetOuting::class, 'fleet_outing_residents', 'client_id', 'outing_id')
            ->withPivot(['pre_check_completed', 'medication_packed', 'notes'])
            ->withTimestamps();
    }

    public function fleetOutingResidents()
    {
        return $this->hasMany(FleetOutingResident::class);
    }

    public function fleetMedicationTransitLogs()
    {
        return $this->hasMany(FleetMedicationTransitLog::class);
    }

    public function mealDietaryTags()
    {
        return $this->belongsToMany(MealDietaryTag::class, 'client_meal_dietary_tag', 'client_id', 'tag_id')
            ->withPivot('notes')
            ->withTimestamps();
    }

    public function mealDislikes()
    {
        return $this->hasMany(ClientMealDislike::class);
    }

    public function mealRestrictions()
    {
        return $this->hasMany(ClientMealRestriction::class);
    }

    public function mealRestrictionDiscrepancies()
    {
        return $this->hasMany(ClientMealRestrictionDiscrepancy::class);
    }
}
