<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InitiativeSiteScopeSite extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $table = 'roadmap_initiative_site_scope_sites';

    protected $fillable = [
        'tenant_id',
        'initiative_site_scope_id',
        'site_id',
        'wave_no',
        'status',
        'readiness_status',
        'readiness_checklist',
        'planned_start',
        'planned_end',
        'actual_start',
        'actual_end',
        'blocked_reason',
        'owner_user_id',
    ];

    protected $casts = [
        'readiness_checklist' => 'array',
        'planned_start' => 'date',
        'planned_end' => 'date',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
    ];

    public function initiativeScope(): BelongsTo
    {
        return $this->belongsTo(InitiativeSiteScope::class, 'initiative_site_scope_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
