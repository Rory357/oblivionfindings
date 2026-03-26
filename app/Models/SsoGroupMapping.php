<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsoGroupMapping extends Model
{
    protected $fillable = [
        'organization_id',
        'provider',
        'external_group_id',
        'external_group_name',
        'role_id',
        'auto_assign',
        'auto_remove',
        'last_synced_at',
    ];

    protected $casts = [
        'auto_assign' => 'boolean',
        'auto_remove' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
