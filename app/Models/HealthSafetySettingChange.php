<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable organisational policy provenance and accepted command receipts. */
final class HealthSafetySettingChange extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'revision' => 'integer', 'schema_version' => 'integer', 'actor_id' => 'integer',
            'audit_id' => 'integer', 'before_value' => 'json', 'after_value' => 'json',
            'actor_snapshot' => 'json', 'accepted_result' => 'json', 'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Health and Safety policy history cannot be updated.'));
        self::deleting(fn () => throw new LogicException('Health and Safety policy history cannot be deleted.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class, 'audit_id');
    }
}
