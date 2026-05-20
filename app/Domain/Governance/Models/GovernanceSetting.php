<?php

namespace App\Domain\Governance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key-value configuration table for governance — escalation paths, spend
 * approval thresholds, etc. Replaces hard-coded values previously baked into
 * ComplianceEngineService and elsewhere.
 *
 * Use the static helpers `get()` / `set()` / `getJson()` to read/write.
 */
class GovernanceSetting extends Model
{
    use HasFactory;

    protected $table = 'governance_settings';

    protected $fillable = [
        'key', 'value', 'category', 'description', 'metadata', 'updated_by',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public const CATEGORY_ESCALATION = 'escalation';
    public const CATEGORY_SPEND_APPROVAL = 'spend_approval';
    public const CATEGORY_REMINDER = 'reminder';
    public const CATEGORY_GENERAL = 'general';

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("governance_setting:{$key}", now()->addMinutes(5), function () use ($key, $default) {
            $row = static::where('key', $key)->first();

            return $row?->value ?? $default;
        });
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = static::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        $value = static::get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public static function getJson(string $key, mixed $default = null): mixed
    {
        $value = static::get($key);
        if ($value === null) {
            return $default;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public static function set(string $key, mixed $value, string $category = self::CATEGORY_GENERAL, ?string $description = null): self
    {
        $stored = is_array($value) || is_object($value) ? json_encode($value) : (string) $value;

        $setting = static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $stored,
                'category' => $category,
                'description' => $description,
                'updated_by' => auth()->id(),
            ],
        );

        Cache::forget("governance_setting:{$key}");

        return $setting;
    }

    public static function forCategory(string $category): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('category', $category)->orderBy('key')->get();
    }
}
