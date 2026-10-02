<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something that happened to a medication alert: sent, opened, dealt with, … (P11 B2). */
class MedicationAlertEvent extends Model
{
    public const SENT = 'sent';

    public const DEALT_WITH = 'dealt_with';

    public const NOT_TOLD_CONTROLLED = 'not_told_controlled';

    /** Nobody in its groups at the house: medication settings managers were told instead. */
    public const FALLBACK = 'fallback_to_settings_managers';

    /** "Nobody could be told", with the reason. */
    public const NOBODY_TOLD = 'nobody_told';

    protected $fillable = [
        'medication_alert_id',
        'event',
        'user_id',
        'detail',
        'occurred_at',
    ];

    protected $casts = [
        'detail' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(MedicationAlert::class, 'medication_alert_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
