<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MedicationEmergencyAccessReview extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['access_id', 'user_id', 'outcome', 'notes', 'incident_report_id', 'medication_error_id', 'corrects_review_id', 'correction_reason', 'legacy', 'created_at'];

    protected $casts = ['created_at' => 'datetime', 'legacy' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Emergency access reviews are append-only.'));
        static::deleting(fn () => throw new LogicException('Emergency access reviews are append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
