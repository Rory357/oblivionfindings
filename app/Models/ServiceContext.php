<?php

namespace App\Models;

use App\Enums\ServiceType;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ServiceContext represents the service delivery setting for work and care.
 *
 * This is a foundation object used to classify activity (e.g. shift work and,
 * later, medication administration) by service type (residential, home support,
 * respite) without forcing any UI/workflow changes.
 */
class ServiceContext extends Model
{
    use AuditableChanges;
    use HasFactory;

    public const PROFILE_LIMIT = 12;

    protected $fillable = [
        'type',
        'name',
        'description',
        'site_id',
        'is_active',
    ];

    protected $casts = [
        'type' => ServiceType::class,
        'is_active' => 'bool',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function scopeAvailableToSite(Builder $query, ?int $siteId): Builder
    {
        return $this->scopeAvailableToSites(
            $query,
            $siteId === null ? [] : [$siteId],
        );
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    public function scopeAvailableToSites(Builder $query, array $siteIds): Builder
    {
        $siteIds = collect($siteIds)
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->filter(fn (int $siteId): bool => $siteId > 0)
            ->unique()
            ->values()
            ->all();

        return $query->where(function (Builder $query) use ($siteIds): void {
            $query->whereNull('site_id');
            if ($siteIds !== []) {
                $query->orWhereIn('site_id', $siteIds);
            }
        });
    }

    /**
     * Returns the configured default service context id (if any).
     *
     * Only returns an ID if it refers to an active ServiceContext. This prevents
     * silently inheriting an inactive/retired context.
     */
    public static function defaultId(): ?int
    {
        $raw = AppSetting::query()->where('key', 'service_context.default_id')->value('value');

        $id = is_numeric($raw) ? (int) $raw : null;
        if (! $id) {
            return null;
        }

        return self::query()->whereKey($id)->where('is_active', true)->exists() ? $id : null;
    }

    /**
     * Returns the active configured default only when it is available to the
     * selected Site. A null Site can use only an application-wide context.
     */
    public static function defaultIdForSite(?int $siteId): ?int
    {
        $id = self::defaultId();
        if ($id === null) {
            return null;
        }

        return self::query()
            ->availableToSite($siteId)
            ->whereKey($id)
            ->where('is_active', true)
            ->exists() ? $id : null;
    }
}
