<?php

namespace App\Domain\SecurityDevices\Models;

use App\Domain\SecurityDevices\Enums\RelationshipType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceRelationship extends Model
{
    protected $table = 'device_relationships';

    protected $fillable = [
        'parent_device_id',
        'child_device_id',
        'relationship_type',
        'port',
        'notes',
        'created_by_user_id',
        'unlinked_at',
        'unlinked_by_user_id',
        'unlink_reason',
    ];

    protected $casts = [
        'relationship_type' => RelationshipType::class,
        'unlinked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $relationship): void {
            if ($relationship->created_by_user_id === null) {
                throw new \UnexpectedValueException('New Device relationships require creation actor evidence.');
            }

            if ((int) $relationship->parent_device_id === (int) $relationship->child_device_id) {
                throw new \UnexpectedValueException('A Device relationship must connect two different Devices.');
            }

            if ($relationship->unlinked_at !== null
                || $relationship->unlinked_by_user_id !== null
                || filled($relationship->unlink_reason)) {
                throw new \UnexpectedValueException('New Device relationships must begin as active evidence.');
            }
        });

        static::updating(function (self $relationship): void {
            if ($relationship->getRawOriginal('unlinked_at') !== null && $relationship->isDirty()) {
                throw new \UnexpectedValueException('Unlinked Device relationship history is immutable.');
            }

            $lifecycleFields = ['unlinked_at', 'unlinked_by_user_id', 'unlink_reason'];
            $unexpectedFields = array_diff(
                array_keys($relationship->getDirty()),
                [...$lifecycleFields, 'updated_at'],
            );
            if ($unexpectedFields !== []) {
                throw new \UnexpectedValueException('Active Device relationship evidence is immutable; remove and recreate the relationship instead.');
            }

            $completeTransition = collect($lifecycleFields)
                ->every(fn (string $field): bool => $relationship->isDirty($field));
            if (! $completeTransition
                || $relationship->unlinked_at === null
                || $relationship->unlinked_by_user_id === null
                || blank($relationship->unlink_reason)) {
                throw new \UnexpectedValueException('Unlinked Device relationships require actor and reason evidence.');
            }
        });

        static::deleting(function (): void {
            throw new \UnexpectedValueException('Device relationship history cannot be deleted.');
        });
    }

    // ── Relationships ─────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'parent_device_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'child_device_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function unlinkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlinked_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('unlinked_at');
    }

    public function scopeUnlinked($query)
    {
        return $query->whereNotNull('unlinked_at');
    }
}
