<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Signal extends Model
{
    protected $table = 'control_room_signals';

    protected $fillable = [
        'signal_source_id',
        'signal_type_id',
        'signal_type_code',
        'received_at',
        'idempotency_key',
        'site_id',
        'client_id',
        'asset_id',
        'device_id',
        'external_ref',
        'severity_hint',
        'occurred_at',
        'payload',
        'normalized_data',
        'status',
        'alert_id',
        'correlated_alert_id',
        'processing_notes',
        'processed_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
        'payload' => 'array',
        'normalized_data' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $signal): void {
            // Ensure required signal_type_code exists when only signal_type_id/code is provided.
            if (empty($signal->signal_type_code) && !empty($signal->signal_type_id)) {
                $signalType = SignalType::find($signal->signal_type_id);
                if ($signalType) {
                    $signal->signal_type_code = $signalType->code;
                }
            }

            if (empty($signal->occurred_at)) {
                $signal->occurred_at = now();
            }
        });
    }

    public function setReceivedAtAttribute($value): void
    {
        $this->attributes['occurred_at'] = $value;
    }

    public function signalSource(): BelongsTo
    {
        return $this->belongsTo(SignalSource::class, 'signal_source_id');
    }

    public function signalType(): BelongsTo
    {
        return $this->belongsTo(SignalType::class, 'signal_type_id');
    }

    /**
     * The CR device projection this signal originated from.
     * For canonical device identity, use: $signal->device?->canonicalDevice
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'alert_id');
    }

    public function correlatedAlert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'correlated_alert_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }

    public function scopeRecent($query, int $minutes = 60)
    {
        return $query->where('occurred_at', '>=', now()->subMinutes($minutes));
    }

    public function markProcessed(?ControlRoomAlert $alert = null, ?string $notes = null): void
    {
        $this->update([
            'status' => 'processed',
            'alert_id' => $alert?->id,
            'processed_at' => now(),
            'processing_notes' => $notes,
        ]);
    }

    public function markSuppressed(string $reason): void
    {
        $this->update([
            'status' => 'suppressed',
            'processed_at' => now(),
            'processing_notes' => $reason,
        ]);
    }

    public function markCorrelated(ControlRoomAlert $existingAlert): void
    {
        $this->update([
            'status' => 'processed',
            'correlated_alert_id' => $existingAlert->id,
            'processed_at' => now(),
            'processing_notes' => 'Correlated with existing alert',
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'processed_at' => now(),
            'processing_notes' => $reason,
        ]);
    }

    public static function generateIdempotencyKey(array $data): string
    {
        $keyParts = [
            $data['signal_source_id'] ?? '',
            $data['signal_type_code'] ?? '',
            $data['device_id'] ?? $data['asset_id'] ?? '',
            $data['external_ref'] ?? '',
            isset($data['occurred_at']) ? (is_string($data['occurred_at']) ? $data['occurred_at'] : $data['occurred_at']->format('Y-m-d H:i')) : '',
        ];

        return hash('sha256', implode('|', $keyParts));
    }
}
