<?php

namespace App\Domain\SecurityDevices\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use UnexpectedValueException;

class DeviceDocument extends Model
{
    public const DISK = 'private';

    public const STATE_UPLOAD_STAGED = 'upload_staged';

    public const STATE_ACTIVE = 'active';

    public const STATE_REMOVAL_PENDING = 'removal_pending';

    public const STATE_REMOVED = 'removed';

    private const GOVERNED_FIELDS = [
        'storage_disk',
        'storage_path',
        'content_sha256',
        'lifecycle_state',
        'upload_operation_uuid',
        'upload_requested_by_user_id',
        'staged_storage_path',
        'storage_verified_at',
        'lifecycle_error_code',
        'removal_operation_uuid',
        'removal_requested_at',
        'removal_requested_by_user_id',
        'removal_request_reason',
        'quarantine_storage_path',
        'removed_at',
        'removed_by_user_id',
        'removal_reason',
        'storage_deleted_at',
    ];

    private static int $governedMutationDepth = 0;

    protected $table = 'device_documents';

    protected $attributes = [
        'storage_disk' => self::DISK,
        'lifecycle_state' => self::STATE_ACTIVE,
    ];

    protected $hidden = [
        'storage_disk',
        'storage_path',
        'content_sha256',
        'upload_operation_uuid',
        'upload_requested_by_user_id',
        'staged_storage_path',
        'removal_operation_uuid',
        'quarantine_storage_path',
        'lifecycle_error_code',
    ];

    protected $fillable = [
        'device_id',
        'uploaded_by_user_id',
        'title',
        'category',
        'version',
        'effective_date',
        'expiry_date',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'content_sha256',
        'lifecycle_state',
        'upload_operation_uuid',
        'upload_requested_by_user_id',
        'staged_storage_path',
        'storage_verified_at',
        'lifecycle_error_code',
        'removal_operation_uuid',
        'removal_requested_at',
        'removal_requested_by_user_id',
        'removal_request_reason',
        'quarantine_storage_path',
        'notes',
        'removed_at',
        'removed_by_user_id',
        'removal_reason',
        'storage_deleted_at',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'removed_at' => 'immutable_datetime',
        'storage_deleted_at' => 'immutable_datetime',
        'storage_verified_at' => 'immutable_datetime',
        'removal_requested_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $document): void {
            if (! in_array($document->lifecycle_state, [self::STATE_ACTIVE, self::STATE_UPLOAD_STAGED], true)
                || $document->removed_at !== null
                || $document->removed_by_user_id !== null
                || $document->removal_reason !== null
                || $document->storage_deleted_at !== null
                || $document->removal_requested_at !== null
                || $document->removal_requested_by_user_id !== null
                || $document->removal_request_reason !== null) {
                throw new UnexpectedValueException(
                    'New Device documents must begin as active legacy evidence or a governed staged upload.',
                );
            }
        });
        self::deleting(function (): never {
            throw new UnexpectedValueException('Device document metadata is retained as lifecycle evidence.');
        });
        self::updating(function (self $document): void {
            if (self::$governedMutationDepth > 0) {
                return;
            }

            $governedDirty = array_intersect(array_keys($document->getDirty()), self::GOVERNED_FIELDS);
            if ($governedDirty !== []) {
                throw new UnexpectedValueException(
                    'Device document storage and lifecycle evidence can only change through the governed recovery service.',
                );
            }

            if (in_array($document->getRawOriginal('lifecycle_state'), [
                self::STATE_REMOVAL_PENDING,
                self::STATE_REMOVED,
            ], true)) {
                throw new UnexpectedValueException('Pending and removed Device document evidence is immutable.');
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    public function removalRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removal_requested_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->available()
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays($days))
            ->where('expiry_date', '>=', now());
    }

    public function scopeExpired($query)
    {
        return $query->available()
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now());
    }

    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('lifecycle_state', self::STATE_ACTIVE)
            ->whereNull('removed_at');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->active()
            ->where('storage_disk', self::DISK)
            ->whereNotNull('content_sha256')
            ->whereNotNull('storage_verified_at')
            ->whereNull('lifecycle_error_code');
    }

    public function scopeRemoved(Builder $query): Builder
    {
        return $query->where('lifecycle_state', self::STATE_REMOVED)
            ->whereNotNull('removed_at');
    }

    public function scopeHistory(Builder $query): Builder
    {
        return $query->where(function (Builder $history): void {
            $history->where('lifecycle_state', '!=', self::STATE_ACTIVE)
                ->orWhere('storage_disk', '!=', self::DISK)
                ->orWhereNull('content_sha256')
                ->orWhereNull('storage_verified_at')
                ->orWhereNotNull('lifecycle_error_code');
        });
    }

    public function isRemoved(): bool
    {
        return $this->lifecycle_state === self::STATE_REMOVED || $this->removed_at !== null;
    }

    public function isDownloadable(): bool
    {
        return $this->lifecycle_state === self::STATE_ACTIVE
            && $this->removed_at === null
            && $this->storage_disk === self::DISK
            && is_string($this->content_sha256)
            && strlen($this->content_sha256) === 64
            && $this->storage_verified_at !== null
            && $this->lifecycle_error_code === null;
    }

    public function activateVerifiedStorage(string $sha256, \DateTimeInterface $verifiedAt): void
    {
        $this->assertState(self::STATE_UPLOAD_STAGED);
        if (! hash_equals((string) $this->content_sha256, $sha256)) {
            throw new LogicException('The staged Device document hash does not match its governed upload evidence.');
        }

        $this->governedUpdate([
            'storage_disk' => self::DISK,
            'content_sha256' => $sha256,
            'lifecycle_state' => self::STATE_ACTIVE,
            'staged_storage_path' => null,
            'storage_verified_at' => $verifiedAt,
            'lifecycle_error_code' => null,
        ]);
    }

    public function adoptVerifiedLegacyStorage(string $sha256, \DateTimeInterface $verifiedAt): void
    {
        $this->assertState(self::STATE_ACTIVE);
        if (is_string($this->content_sha256) && ! hash_equals($this->content_sha256, $sha256)) {
            throw new LogicException('The retained Device document hash does not match private storage.');
        }

        $this->governedUpdate([
            'storage_disk' => self::DISK,
            'content_sha256' => $sha256,
            'storage_verified_at' => $verifiedAt,
            'lifecycle_error_code' => null,
        ]);
    }

    public function requestGovernedRemoval(
        string $operationUuid,
        int $actorId,
        string $reason,
        string $quarantinePath,
        \DateTimeInterface $requestedAt,
    ): void {
        $this->assertState(self::STATE_ACTIVE);
        if (! $this->isDownloadable()) {
            throw new LogicException('Only a verified active Device document can enter removal.');
        }

        $this->governedUpdate([
            'lifecycle_state' => self::STATE_REMOVAL_PENDING,
            'removal_operation_uuid' => $operationUuid,
            'removal_requested_at' => $requestedAt,
            'removal_requested_by_user_id' => $actorId,
            'removal_request_reason' => trim($reason),
            'quarantine_storage_path' => $quarantinePath,
            'lifecycle_error_code' => null,
        ]);
    }

    public function completeGovernedRemoval(\DateTimeInterface $removedAt): void
    {
        $this->assertState(self::STATE_REMOVAL_PENDING);
        if ($this->removal_requested_by_user_id === null || $this->removal_request_reason === null) {
            throw new LogicException('The durable Device document removal intent is incomplete.');
        }

        $this->governedUpdate([
            'lifecycle_state' => self::STATE_REMOVED,
            'removed_at' => $removedAt,
            'removed_by_user_id' => $this->removal_requested_by_user_id,
            'removal_reason' => $this->removal_request_reason,
            'lifecycle_error_code' => null,
        ]);
    }

    public function completeQuarantineDeletion(\DateTimeInterface $deletedAt): void
    {
        $this->assertState(self::STATE_REMOVED);
        $this->governedUpdate([
            'storage_deleted_at' => $deletedAt,
            'quarantine_storage_path' => null,
            'lifecycle_error_code' => null,
        ]);
    }

    public function recordLifecycleError(string $code): void
    {
        if (! in_array($this->lifecycle_state, [
            self::STATE_UPLOAD_STAGED,
            self::STATE_ACTIVE,
            self::STATE_REMOVAL_PENDING,
            self::STATE_REMOVED,
        ], true)) {
            throw new LogicException('Unknown Device document lifecycle state.');
        }

        $this->governedUpdate(['lifecycle_error_code' => substr($code, 0, 64)]);
    }

    /** @param array<string, mixed> $attributes */
    private function governedUpdate(array $attributes): void
    {
        self::$governedMutationDepth++;
        try {
            $this->forceFill($attributes)->save();
        } finally {
            self::$governedMutationDepth--;
        }
    }

    private function assertState(string $expected): void
    {
        if ($this->lifecycle_state !== $expected) {
            throw new LogicException("Expected Device document state [{$expected}].");
        }
    }
}
