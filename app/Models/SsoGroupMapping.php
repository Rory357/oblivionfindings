<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsoGroupMapping extends Model
{
    use WritesLegacyOrganizationStorageContext;

    protected $appends = ['version'];

    /** Fingerprint of editable intent; directory evidence timestamps do not conflict. */
    public function getVersionAttribute(): string
    {
        return hash('sha256', json_encode($this->only(['id', 'provider', 'external_group_id', 'external_group_name', 'role_id', 'auto_assign', 'auto_remove']), JSON_THROW_ON_ERROR));
    }

    protected $fillable = [
        'provider',
        'external_group_id',
        'external_group_name',
        'role_id',
        'auto_assign',
        'auto_remove',
        'last_synced_at',
    ];

    protected $casts = [
        'role_id' => 'integer',
        'auto_assign' => 'boolean',
        'auto_remove' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
