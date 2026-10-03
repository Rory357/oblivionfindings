<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationFollowup extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'due_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'context' => 'array',
        'revision' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id')->withTrashed();
    }

    public function administration(): BelongsTo
    {
        return $this->belongsTo(ClientMedicationAdministration::class, 'administration_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function originalOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_owner_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MedicationFollowupEvent::class)->orderBy('id');
    }
}
