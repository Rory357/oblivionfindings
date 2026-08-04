<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Model;

/**
 * The application-wide break-glass policy. {@see current()} always returns a
 * usable instance: the stored record, or an unsaved record carrying the
 * canonical {@see ClientBreakGlassAccess} constant defaults.
 */
class BreakGlassPolicy extends Model
{
    use WritesLegacyOrganizationStorageContext;

    protected $fillable = [
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
     * The effective application policy: the stored record, or an unsaved
     * instance carrying the constant defaults so callers always read usable values.
     */
    public static function current(): self
    {
        $policy = self::query()->oldest('id')->first();

        return $policy ?? new self(self::defaults());
    }

    /**
     * Persist the one application policy without consulting compatibility storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function updateApplicationPolicy(array $attributes): self
    {
        $policy = self::query()->oldest('id')->first() ?? new self;
        $policy->fill($attributes)->save();

        return $policy;
    }
}
