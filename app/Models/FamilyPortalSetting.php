<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyPortalSetting extends Model
{
    protected $table = 'family_portal_settings';

    protected $fillable = [
        'organization_id',
        'client_id',
        'show_shift_schedule',
        'show_care_notes',
        'show_care_plans',
        'show_medication_status',
        'show_incidents',
        'notify_shift_arrival',
        'notify_shift_completion',
        'notify_incident',
    ];

    protected $casts = [
        'show_shift_schedule' => 'boolean',
        'show_care_notes' => 'boolean',
        'show_care_plans' => 'boolean',
        'show_medication_status' => 'boolean',
        'show_incidents' => 'boolean',
        'notify_shift_arrival' => 'boolean',
        'notify_shift_completion' => 'boolean',
        'notify_incident' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
