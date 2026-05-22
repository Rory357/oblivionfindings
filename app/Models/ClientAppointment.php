<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAppointment extends Model implements EmitsToTimeline
{
    protected $fillable = [
        'client_id',
        'title',
        'description',
        'appointment_type',
        'starts_at',
        'ends_at',
        'location',
        'provider_name',
        'status',
        'is_recurring',
        'recurrence_rule',
        'share_with_family',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_recurring' => 'boolean',
        'share_with_family' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeInRange($query, $start, $end)
    {
        return $query->where('starts_at', '<=', $end)->where(function ($q) use ($start) {
            $q->where('ends_at', '>=', $start)->orWhereNull('ends_at');
        });
    }

    public function scopeSharedWithFamily($query)
    {
        return $query->where('share_with_family', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineEvent(): ?array
    {
        $this->loadMissing('client');

        return [
            'type' => 'appointment',
            'occurred_at' => $this->starts_at ?? $this->created_at ?? now(),
            'actor_user_id' => $this->created_by,
            'client_id' => $this->client_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'Appointment: '.$this->title,
            'body' => $this->description,
            'meta' => array_filter([
                'appointment_type' => $this->appointment_type,
                'status' => $this->status,
                'location' => $this->location,
                'provider_name' => $this->provider_name,
                'ends_at' => $this->ends_at?->toISOString(),
                'share_with_family' => $this->share_with_family,
            ], fn ($value) => $value !== null && $value !== ''),
            'visibility' => $this->share_with_family ? 'portal' : 'internal',
            'is_pinned' => false,
            'created_by' => $this->created_by,
        ];
    }
}
