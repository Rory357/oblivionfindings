<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MonitoringCoverageExpectation extends Model
{
    protected $fillable = [
        'site_id',
        'device_domain',
        'device_category',
        'capability',
        'identity_key',
        'monitor_kind',
        'minimum_count',
        'support_status',
        'support_evidence',
        'is_active',
        'version',
        'created_by_user_id',
        'updated_by_user_id',
        'deactivated_at',
        'deactivated_by_user_id',
        'deactivation_reason',
    ];

    protected $casts = [
        'monitor_kind' => MonitorKind::class,
        'minimum_count' => 'integer',
        'support_evidence' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
        'deactivated_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $expectation): void {
            $expectation->identity_key = self::identityFor(
                $expectation->site_id === null ? null : (int) $expectation->site_id,
                (string) $expectation->device_domain,
                $expectation->device_category === null ? null : (string) $expectation->device_category,
                (string) $expectation->capability,
            );
        });
    }

    public static function identityFor(?int $siteId, string $domain, ?string $category, string $capability): string
    {
        return hash('sha256', implode('|', [
            $siteId === null ? '*' : (string) $siteId,
            strtolower(trim($domain)),
            $category === null ? '*' : strtolower(trim($category)),
            strtolower(trim($capability)),
        ]));
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_user_id');
    }
}
