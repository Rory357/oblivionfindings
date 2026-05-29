<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientEmergencyContact extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'name',
        'relationship',
        'phone',
        'alternate_phone',
        'email',
        'address',
        'notes',
        'contact_order',
        'is_primary_contact',
        'preferred_method',
        'availability',
        'authorised_health_info',
        'can_view_medical',
        'can_view_medications',
        'can_view_incidents',
        'can_receive_updates',
    ];

    protected $casts = [
        'is_primary_contact' => 'boolean',
        'authorised_health_info' => 'boolean',
        'can_view_medical' => 'boolean',
        'can_view_medications' => 'boolean',
        'can_view_incidents' => 'boolean',
        'can_receive_updates' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
