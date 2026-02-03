<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RespiteAuditLog extends Model
{
    use HasFactory;

    // No soft deletes - audit logs are permanent
    public $timestamps = true;
    const UPDATED_AT = null; // Only track created_at

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'action',
        'action_category',
        'user_id',
        'user_name',
        'user_role',
        'old_values',
        'new_values',
        'reason',
        'ip_address',
        'user_agent',
        'session_id',
        'break_glass_access',
        'break_glass_justification',
        'break_glass_approved_by',
        'evidence_refs',
        'idempotency_key',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'evidence_refs' => 'array',
        'break_glass_access' => 'boolean',
    ];

    // Common action types
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_STATUS_CHANGED = 'status_changed';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_ASSIGNED = 'assigned';
    public const ACTION_ESCALATED = 'escalated';
    public const ACTION_VIEWED = 'viewed';
    public const ACTION_EXPORTED = 'exported';
    public const ACTION_BREAK_GLASS = 'break_glass_access';

    // Action categories
    public const CATEGORY_REFERRAL = 'referral';
    public const CATEGORY_BOOKING = 'booking';
    public const CATEGORY_STAY = 'stay';
    public const CATEGORY_PROCEDURE = 'procedure';
    public const CATEGORY_TASK = 'task';
    public const CATEGORY_HANDOVER = 'handover';
    public const CATEGORY_EVIDENCE = 'evidence';
    public const CATEGORY_ADMIN = 'admin';
    public const CATEGORY_ACCESS = 'access';

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function breakGlassApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'break_glass_approved_by');
    }

    // Scopes
    public function scopeForEntity($query, string $type, int $id)
    {
        return $query->where('auditable_type', $type)->where('auditable_id', $id);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('action_category', $category);
    }

    public function scopeBreakGlassAccess($query)
    {
        return $query->where('break_glass_access', true);
    }

    public function scopeInDateRange($query, $start, $end)
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }

    // Static factory method for creating audit logs
    public static function log(
        Model $auditable,
        string $action,
        ?int $userId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        ?string $category = null,
        ?array $evidenceRefs = null,
        bool $breakGlassAccess = false,
        ?string $breakGlassJustification = null,
        ?int $breakGlassApprovedBy = null,
    ): self {
        $user = $userId ? User::find($userId) : auth()->user();

        return self::create([
            'auditable_type' => get_class($auditable),
            'auditable_id' => $auditable->id,
            'action' => $action,
            'action_category' => $category,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_role' => $user?->role,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'session_id' => session()->getId(),
            'break_glass_access' => $breakGlassAccess,
            'break_glass_justification' => $breakGlassJustification,
            'break_glass_approved_by' => $breakGlassApprovedBy,
            'evidence_refs' => $evidenceRefs,
            'idempotency_key' => self::generateIdempotencyKey($auditable, $action),
        ]);
    }

    protected static function generateIdempotencyKey(Model $auditable, string $action): string
    {
        return md5(
            get_class($auditable) . ':' .
            $auditable->id . ':' .
            $action . ':' .
            now()->timestamp . ':' .
            uniqid('', true)
        );
    }

    // Get a summary for display
    public function getSummary(): string
    {
        $entityName = class_basename($this->auditable_type);
        return "{$this->user_name} {$this->action} {$entityName} #{$this->auditable_id}";
    }

    // Get changes as a human-readable array
    public function getChanges(): array
    {
        $changes = [];
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];

        $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($allKeys as $key) {
            $oldValue = $old[$key] ?? null;
            $newValue = $new[$key] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }
}
