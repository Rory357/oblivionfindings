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
        'email',
        'notes',
        'contact_order',
        'preferred_method',
        'availability',
        'authorised_health_info',
    ];

    protected $casts = [
        'authorised_health_info' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
