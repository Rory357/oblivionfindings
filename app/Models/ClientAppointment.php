<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAppointment extends Model
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
}
