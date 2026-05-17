<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class Client extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'organization_id',
        'nhi_number',
        'site_id',
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
        'funding_notes',
        'openai_vector_store_id',
        'profile_photo_path',
        'transport_needs',
        'transport_notes',
        'ethnicity',
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
        'sleep_preferences',
        'service_start_date',
        'key_worker_id',
        'risk_level',
        'safeguarding_flag',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'transport_needs' => 'array',
        'languages' => 'array',
        'service_start_date' => 'date',
        'safeguarding_flag' => 'boolean',
        'phone' => 'encrypted',
        'email' => 'encrypted',
        'nhi_number' => 'encrypted',
    ];

    protected $appends = ['profile_photo_url', 'avatar', 'full_name'];

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
        return $this->first_name . ' ' . $this->last_name;
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

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function keyWorker()
    {
        return $this->belongsTo(\App\Models\User::class, 'key_worker_id');
    }

    public function supportWorkers()
    {
        return $this->belongsToMany(\App\Models\User::class)->withTimestamps();
    }

    public function notes()
    {
        return $this->hasMany(ClientNote::class);
    }

    public function portalUsers()
    {
        return $this->belongsToMany(\App\Models\User::class, 'client_portal_users')
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
        return $this->hasOne(\App\Models\ClientMedicalProfile::class);
    }

    public function medications()
    {
        return $this->hasMany(\App\Models\ClientMedication::class);
    }

    public function medicationAdministrations()
    {
        return $this->hasMany(\App\Models\ClientMedicationAdministration::class);
    }

    public function breakGlassAccesses()
    {
        return $this->hasMany(\App\Models\ClientBreakGlassAccess::class);
    }

    public function emergencyContacts()
    {
        return $this->hasMany(\App\Models\ClientEmergencyContact::class);
    }

    public function conditions()
    {
        return $this->hasMany(\App\Models\ClientCondition::class);
    }

    public function controlledDrugDiscrepancies()
    {
        return $this->hasMany(\App\Models\ClientControlledDrugDiscrepancy::class);
    }

    public function medicationAllergies()
    {
        return $this->hasMany(\App\Models\MedicationAllergy::class);
    }

    public function documents()
    {
        return $this->hasMany(\App\Models\ClientDocument::class);
    }

    public function documentFolders()
    {
        return $this->hasMany(\App\Models\ClientDocumentFolder::class);
    }

    public function familyPortalSetting()
    {
        return $this->hasOne(\App\Models\FamilyPortalSetting::class);
    }

    public function supportPlan()
    {
        return $this->hasOne(\App\Models\ClientSupportPlan::class);
    }

    public function assessments()
    {
        return $this->hasMany(\App\Models\ClientAssessment::class);
    }

    public function incidents()
    {
        return $this->hasMany(\App\Models\ClientIncident::class);
    }

    public function respiteBookings()
    {
        return $this->hasMany(\App\Models\RespiteBooking::class);
    }

    public function respiteBookingRequests()
    {
        return $this->hasMany(\App\Models\RespiteBookingRequest::class);
    }

    public function risks()
    {
        return $this->hasMany(\App\Models\ClientRisk::class);
    }

    public function onboardingOverrides()
    {
        return $this->hasMany(\App\Models\ClientOnboardingOverride::class);
    }

    /**
     * Scope: Find by NHI number
     */
    public function scopeByNhi($query, string $nhi)
    {
        return $query->where('nhi_number', strtoupper($nhi));
    }

    /**
     * Validate NHI number format (3 letters + 4 numbers)
     */
    public static function validateNhi(string $nhi): bool
    {
        return preg_match('/^[A-Z]{3}\d{4}$/i', $nhi) === 1;
    }

    /**
     * Validation rules for NHI number.
     */
    public static function nhiValidationRules(?int $ignoreClientId = null): array
    {
        $unique = Rule::unique('clients', 'nhi_number');

        if ($ignoreClientId !== null) {
            $unique = $unique->ignore($ignoreClientId);
        }

        return [
            'nullable',
            'string',
            'max:10',
            'regex:/^[A-Z]{3}\d{4}$/i',
            $unique,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Client $client) {
            $raw = $client->nhi_number;
            if ($raw) {
                $upper = strtoupper($raw);
                if ($upper !== $raw) {
                    $client->nhi_number = $upper;
                }
            }
        });
    }

    public function onboardingWorkflow()
    {
        return $this->hasOne(\App\Models\ClientOnboardingWorkflow::class, 'client_id')->latest();
    }

    public function onboardingWorkflows()
    {
        return $this->hasMany(\App\Models\ClientOnboardingWorkflow::class, 'client_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function personalAssets()
    {
        return $this->hasMany(\App\Models\ClientPersonalAsset::class);
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
}
