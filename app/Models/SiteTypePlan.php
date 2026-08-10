<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteTypePlan extends Model
{
    use HasFactory, SoftDeletes, WritesLegacyStorageContext;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'tenant_id',
        'site_id',
        'site_type',
        'status',
        'current_slot',
        'version',
        'layout',
        'notes',
        'published_at',
        'published_by_user_id',
        'created_by_user_id',
        'archived_at',
    ];

    protected $casts = [
        'layout' => 'array',
        'version' => 'integer',
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function pins(): HasMany
    {
        return $this->hasMany(SiteTypePlanPin::class);
    }

    public function scopeForSite($query, Site|int $site)
    {
        $siteId = $site instanceof Site ? $site->id : $site;

        return $query->where('site_id', $siteId);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public static function currentDraft(Site $site): ?self
    {
        return self::query()
            ->forSite($site)
            ->draft()
            ->latest('id')
            ->first();
    }

    public static function currentPublished(Site $site): ?self
    {
        return self::query()
            ->forSite($site)
            ->published()
            ->latest('version')
            ->latest('id')
            ->first();
    }
}
