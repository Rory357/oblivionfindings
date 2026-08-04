<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InitiativeSiteScope extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $table = 'roadmap_initiative_site_scopes';

    protected $fillable = [
        'initiative_id',
        'scope_type',
        'rollout_mode',
        'wave_count',
        'constraints',
    ];

    protected $casts = [
        'constraints' => 'array',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(InitiativeSiteScopeSite::class, 'initiative_site_scope_id');
    }
}
