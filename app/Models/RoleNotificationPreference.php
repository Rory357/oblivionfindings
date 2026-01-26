<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleNotificationPreference extends Model
{
    protected $fillable = [
        'role_id',
        'key',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
