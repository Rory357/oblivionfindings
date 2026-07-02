<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FirstAidRecord extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;
    use Concerns\HasReferenceNumber;

    public const REFERENCE_PREFIX = 'FA';

    protected $fillable = [
        'reference_number',
        'site_id',
        'treated_person_id',
        'client_id',
        'treated_person_name',
        'treated_person_type',
        'treatment_date',
        'injury_illness_type',
        'injury_illness_description',
        'body_part',
        'treatment_given',
        'treatment_outcome',
        'ambulance_called',
        'first_aider_id',
        'first_aider_notes',
        'incident_reported',
        'related_incident_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'treatment_date' => 'datetime',
        'ambulance_called' => 'boolean',
        'incident_reported' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function treatedPerson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'treated_person_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function firstAider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_aider_id');
    }

    public function relatedIncident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'related_incident_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FirstAidAttachment::class, 'first_aid_record_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(FirstAidFollowup::class, 'first_aid_record_id');
    }
}
