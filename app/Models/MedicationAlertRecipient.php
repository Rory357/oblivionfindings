<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person told about a medication alert, at one step, and how (P11 B2). */
class MedicationAlertRecipient extends Model
{
    protected $fillable = [
        'medication_alert_id',
        'user_id',
        'reason',
        'step',
        'channels',
        'told_at',
        'notification_id',
        'held_until',
    ];

    protected $casts = [
        'channels' => 'array',
        'step' => 'integer',
        'told_at' => 'datetime',
        'held_until' => 'datetime',
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
