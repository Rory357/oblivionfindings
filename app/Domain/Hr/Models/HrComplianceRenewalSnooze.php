<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrComplianceRenewalSnooze extends Model
{
    use AuditableChanges;

    public const TYPE_COMPLIANCE = 'compliance';

    public const TYPE_VETTING = 'vetting';

    public const TYPE_DRIVER = 'driver';

    public const ENTITY_TYPES = [
        self::TYPE_COMPLIANCE,
        self::TYPE_VETTING,
        self::TYPE_DRIVER,
    ];

    protected $fillable = [
        'entity_type',
        'entity_id',
        'snoozed_until',
        'snoozed_by',
    ];

    protected $casts = [
        'snoozed_until' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('snoozed_until', '>', now());
    }

    public function scopeForEntity(Builder $query, string $entityType, int $entityId): Builder
    {
        return $query
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    public function scopeForEntityType(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    public function snoozedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'snoozed_by');
    }
}
