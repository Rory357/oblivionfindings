<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NextOfKin extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'next_of_kins';

    protected $fillable = [
        'user_id',
        'client_id',
        'relationship',
        'is_primary_contact',
        'is_emergency_contact',
        'phone',
        'alternate_phone',
        'address',
        'can_view_medical',
        'can_view_medications',
        'can_view_incidents',
        'can_receive_updates',
        'notes',
    ];

    protected $casts = [
        'is_primary_contact' => 'boolean',
        'is_emergency_contact' => 'boolean',
        'can_view_medical' => 'boolean',
        'can_view_medications' => 'boolean',
        'can_view_incidents' => 'boolean',
        'can_receive_updates' => 'boolean',
    ];

    /**
     * The user account for portal access
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The client this NOK is related to
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get name from user
     */
    public function getNameAttribute(): ?string
    {
        return $this->user?->name;
    }

    /**
     * Get email from user
     */
    public function getEmailAttribute(): ?string
    {
        return $this->user?->email;
    }

    /**
     * Scope: Primary contacts only
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary_contact', true);
    }

    /**
     * Scope: Emergency contacts only
     */
    public function scopeEmergency($query)
    {
        return $query->where('is_emergency_contact', true);
    }

    /**
     * Scope: For a specific client
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Check if this NOK has portal access
     */
    public function hasPortalAccess(): bool
    {
        return $this->user !== null && $this->user->approved_at !== null;
    }
}
