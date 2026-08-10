<?php

namespace App\Domain\SecurityDevices\Management\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use UnexpectedValueException;

final class DeviceConfigurationProfile extends Model
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_RETIRED = 'retired';

    protected $fillable = [
        'uuid', 'profile_key', 'version', 'name', 'description', 'provider', 'device_domain',
        'target_category', 'encrypted_payload', 'payload_hash', 'verification_sections', 'status',
        'is_system', 'created_by_user_id', 'supersedes_profile_id',
    ];

    protected $hidden = ['encrypted_payload'];

    protected $casts = [
        'version' => 'integer',
        'encrypted_payload' => 'encrypted:array',
        'verification_sections' => 'array',
        'is_system' => 'boolean',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $profile): void {
            $profile->uuid ??= (string) Str::orderedUuid();
            $payload = Arr::sortRecursive((array) $profile->encrypted_payload);
            $profile->encrypted_payload = $payload;
            $profile->payload_hash = self::hashPayload($payload);
        });
        self::updating(function (self $profile): void {
            $dirty = array_keys($profile->getDirty());
            $onlyRetirement = collect($dirty)->every(fn (string $field): bool => in_array($field, ['status', 'updated_at'], true))
                && $profile->getRawOriginal('status') === self::STATUS_ACTIVE
                && $profile->status === self::STATUS_RETIRED;
            if (! $onlyRetirement) {
                throw new UnexpectedValueException('Device configuration profile versions are immutable. Create a new version instead.');
            }
        });
        self::deleting(function (): never {
            throw new UnexpectedValueException('Device configuration profile versions are retained as governed audit evidence.');
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_profile_id');
    }

    public function retire(): void
    {
        if ($this->status === self::STATUS_RETIRED) {
            return;
        }
        $this->status = self::STATUS_RETIRED;
        $this->save();
    }

    /** @return array<string, array<string, mixed>> */
    public function sectionPayloads(): array
    {
        return collect((array) $this->encrypted_payload)
            ->filter(fn (mixed $section): bool => is_array($section))
            ->all();
    }

    /** @param array<string, mixed> $payload */
    public static function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode(
            Arr::sortRecursive($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }
}
