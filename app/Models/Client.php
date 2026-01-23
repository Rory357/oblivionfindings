<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Client extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'site_id',
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
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
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

    public function emergencyContacts()
    {
        return $this->hasMany(\App\Models\ClientEmergencyContact::class);
    }

    public function conditions()
    {
        return $this->hasMany(\App\Models\ClientCondition::class);
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
}
