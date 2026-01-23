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
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
