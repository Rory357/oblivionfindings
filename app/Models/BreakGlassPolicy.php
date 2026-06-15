<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-organisation break-glass policy. Resolve with {@see forOrganization()},
 * which always returns a usable instance — the stored row, or an unsaved one
 * carrying the canonical {@see ClientBreakGlassAccess} constant defaults.
 */
class BreakGlassPolicy extends Model
{
    protected $fillable = [
        'organization_id',
        'default_minutes',
        'max_minutes',
        'extend_minutes',
        'reason_required',
        'repeat_threshold_count',
        'repeat_window_days',
    ];

    protected $casts = [
        'default_minutes' => 'integer',
        'max_minutes' => 'integer',
        'extend_minutes' => 'integer',
        'reason_required' => 'boolean',
        'repeat_threshold_count' => 'integer',
        'repeat_window_days' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Canonical defaults (single source of truth = the access-model constants). */
    public static function defaults(): array
    {
        return [
            'default_minutes' => ClientBreakGlassAccess::DEFAULT_MINUTES,
            'max_minutes' => ClientBreakGlassAccess::MAX_MINUTES,
            'extend_minutes' => ClientBreakGlassAccess::EXTEND_MINUTES,
            'reason_required' => true,
            'repeat_threshold_count' => 4,
            'repeat_window_days' => 7,
        ];
    }

    /**
     * The effective policy for an organisation: the stored row, or an unsaved
     * instance carrying the constant defaults so callers always read usable values.
     */
    public static function forOrganization(?int $organizationId): self
    {
        if (! $organizationId) {
            return new self(self::defaults());
        }

        return self::firstOrNew(['organization_id' => $organizationId], self::defaults());
    }
}
