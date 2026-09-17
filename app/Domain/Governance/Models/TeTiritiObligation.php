<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Te Tiriti o Waitangi commitment, grouped by the Hauora (Wai 2575)
 * principles. The wording should be confirmed by the organisation's Māori
 * advisor.
 */
class TeTiritiObligation extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'principle', 'title', 'description', 'status', 'evidence',
        'actions_taken', 'target_date', 'progress_pct', 'owner_id',
    ];

    protected $casts = [
        'target_date' => 'date',
    ];

    public const PRINCIPLES = [
        'tino_rangatiratanga' => 'Tino rangatiratanga',
        'equity' => 'Equity',
        'active_protection' => 'Active protection',
        'options' => 'Options (Kōwhiringa)',
        'partnership' => 'Partnership',
    ];

    /** One plain line on what each principle asks of the organisation. */
    public const PRINCIPLE_DESCRIPTIONS = [
        'tino_rangatiratanga' => 'Māori make the decisions about their own health and wellbeing, including how services are designed, delivered and checked.',
        'equity' => 'We work to achieve fair health and wellbeing outcomes for Māori.',
        'active_protection' => 'We act early and do all we reasonably can to achieve fair outcomes for Māori, and keep ourselves well informed about them.',
        'options' => 'Māori can choose kaupapa Māori or culturally safe services.',
        'partnership' => 'Māori work with us as partners in governing, designing, delivering and checking services.',
    ];

    /** Principle keys used before the Hauora principles, and where they now sit. */
    public const LEGACY_PRINCIPLES = [
        'participation' => 'tino_rangatiratanga',
        'protection' => 'active_protection',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeByPrinciple($query, string $principle)
    {
        return $query->where('principle', $principle);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    /** The current principle key, mapping any older key. */
    public static function principleKey(?string $principle): string
    {
        $principle = (string) $principle;

        return self::LEGACY_PRINCIPLES[$principle] ?? $principle;
    }

    public function getPrincipleLabel(): string
    {
        $key = self::principleKey($this->principle);

        return self::PRINCIPLES[$key] ?? $key;
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function principleOptions(): array
    {
        return collect(self::PRINCIPLES)
            ->map(fn (string $label, string $key) => [
                'value' => $key,
                'label' => $label,
                'description' => self::PRINCIPLE_DESCRIPTIONS[$key],
            ])
            ->values()
            ->all();
    }

    public static function getProgressByPrinciple(): array
    {
        $obligations = static::all();
        $result = [];

        foreach (self::PRINCIPLES as $key => $label) {
            $group = $obligations->filter(fn (self $obligation) => self::principleKey($obligation->principle) === $key);
            $result[$key] = [
                'label' => $label,
                'total' => $group->count(),
                'avg_progress' => $group->avg('progress_pct') ?? 0,
                'achieved' => $group->where('status', 'achieved')->count(),
            ];
        }

        return $result;
    }
}
