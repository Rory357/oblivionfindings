<?php

namespace App\Models\Integration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use UnexpectedValueException;

final class IntegrationSecretReference extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'reference_uuid',
        'provider_connection_id',
        'site_secret_id',
        'provider',
        'purpose',
        'secret_manager_reference',
        'secret_manager_reference_hash',
        'secret_manager_version',
        'secret_manager_fingerprint',
        'status',
        'cutover_at',
        'rolled_back_at',
        'revoked_at',
        'cleanup_pending_at',
        'cleanup_last_attempt_at',
        'cleanup_attempts',
    ];

    protected $hidden = [
        'reference_uuid',
        'secret_manager_reference',
        'secret_manager_reference_hash',
        'secret_manager_fingerprint',
    ];

    protected $casts = [
        'secret_manager_reference' => 'encrypted',
        'secret_manager_version' => 'integer',
        'cutover_at' => 'immutable_datetime',
        'rolled_back_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
        'cleanup_pending_at' => 'immutable_datetime',
        'cleanup_last_attempt_at' => 'immutable_datetime',
        'cleanup_attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $reference): void {
            $hasProviderConnection = $reference->provider_connection_id !== null;
            $hasSiteSecret = $reference->site_secret_id !== null;
            $isOwnerlessCleanup = ! $hasProviderConnection
                && ! $hasSiteSecret
                && $reference->status === self::STATUS_REVOKED
                && $reference->cleanup_pending_at !== null;
            if ($hasProviderConnection && $hasSiteSecret
                || (! $hasProviderConnection && ! $hasSiteSecret && ! $isOwnerlessCleanup)) {
                throw new UnexpectedValueException('A provider secret reference must have exactly one owner.');
            }
        });

        self::creating(function (self $reference): void {
            $reference->reference_uuid ??= (string) Str::orderedUuid();
        });
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationProviderConnection::class, 'provider_connection_id');
    }

    public function siteSecret(): BelongsTo
    {
        return $this->belongsTo(IntegrationSiteSecret::class, 'site_secret_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
