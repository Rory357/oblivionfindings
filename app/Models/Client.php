<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

class Client extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
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
    ];

    protected $appends = ['profile_photo_url', 'avatar'];

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

    public function documents()
    {
        return $this->hasMany(\App\Models\ClientDocument::class);
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

    public function onboardingWorkflow()
    {
        return $this->hasOne(\App\Models\ClientOnboardingWorkflow::class, 'client_id')->latest();
    }

    public function onboardingWorkflows()
    {
        return $this->hasMany(\App\Models\ClientOnboardingWorkflow::class, 'client_id');
    }
}
