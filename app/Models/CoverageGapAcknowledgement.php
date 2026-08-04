<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoverageGapAcknowledgement extends Model
{
    use HasFactory, WritesLegacyOrganizationStorageContext;

    public const STATE_ACKED = 'acked';

    public const STATE_DISMISSED = 'dismissed';

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'coverage_requirement_id',
        'coverage_window_key',
        'window_starts_at',
        'window_ends_at',
        'state',
        'reason',
        'actor_user_id',
        'created_at',
        'cleared_at',
    ];

    protected $casts = [
        'window_starts_at' => 'datetime',
        'window_ends_at' => 'datetime',
        'created_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SiteCoverageRequirement::class, 'coverage_requirement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
