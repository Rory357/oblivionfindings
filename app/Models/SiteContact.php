<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteContact extends Model
{
    use AuditableChanges, WritesLegacyStorageContext;

    public const TYPES = [
        'site_contact',
        'site_lead',
        'team_lead',
        'emergency',
        'manager',
        'clinical',
        'family',
        'next_of_kin',
        'maintenance',
        'other',
    ];

    protected $fillable = [
        'tenant_id',
        'site_id',
        'type',
        'name',
        'role',
        'phone',
        'email',
        'is_primary',
        'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
