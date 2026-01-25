<?php

namespace App\Models;

use App\Enums\ServiceType;
use App\Models\Concerns\AuditableChanges;
use App\Models\AppSetting;
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
        if (!$id) {
            return null;
        }

        return self::query()->whereKey($id)->where('is_active', true)->exists() ? $id : null;
    }

}
