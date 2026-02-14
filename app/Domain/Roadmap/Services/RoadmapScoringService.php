<?php

namespace App\Domain\Roadmap\Services;

use App\Domain\Roadmap\Events\InitiativeScored;
use App\Domain\Roadmap\Models\Initiative;

class RoadmapScoringService
{
    public const PRESETS = [
        'board_ceo' => [
            'safety' => 20,
            'compliance' => 15,
            'reputation' => 10,
            'financial' => 15,
            'efficiency' => 10,
            'urgency' => 10,
            'multi_site' => 10,
            'complexity_penalty' => 6,
            'dependency_penalty' => 4,
        ],
        'budget_first' => [
            'safety' => 15,
            'compliance' => 10,
            'reputation' => 5,
            'financial' => 30,
            'efficiency' => 15,
            'urgency' => 10,
            'multi_site' => 10,
            'complexity_penalty' => 3,
            'dependency_penalty' => 2,
        ],
        'security_compliance' => [
            'safety' => 20,
            'compliance' => 25,
            'reputation' => 10,
            'financial' => 8,
            'efficiency' => 7,
            'urgency' => 10,
            'multi_site' => 7,
            'complexity_penalty' => 8,
            'dependency_penalty' => 5,
        ],
        'house_rollout' => [
            'safety' => 18,
            'compliance' => 12,
            'reputation' => 8,
            'financial' => 10,
            'efficiency' => 8,
            'urgency' => 10,
            'multi_site' => 20,
            'complexity_penalty' => 7,
            'dependency_penalty' => 7,
        ],
    ];

    public function score(Initiative $initiative, string $preset = 'board_ceo', bool $persist = true): array
    {
        $weights = $this->preset($preset);
        $ratings = $this->ratings($initiative);

        $positive = (
            $ratings['safety'] * $weights['safety'] +
            $ratings['compliance'] * $weights['compliance'] +
            $ratings['reputation'] * $weights['reputation'] +
            $ratings['financial'] * $weights['financial'] +
            $ratings['efficiency'] * $weights['efficiency'] +
            $ratings['urgency'] * $weights['urgency'] +
            $ratings['multi_site'] * $weights['multi_site']
        );

        $penalty = (
            $ratings['complexity'] * $weights['complexity_penalty'] +
            $ratings['dependency'] * $weights['dependency_penalty']
        );

        $appetiteBoost = $this->appetiteBoost($initiative);
        $deadlineBoost = $this->deadlineBoost($initiative);

        $positiveMax = (
            $weights['safety'] +
            $weights['compliance'] +
            $weights['reputation'] +
            $weights['financial'] +
            $weights['efficiency'] +
            $weights['urgency'] +
            $weights['multi_site']
        ) * 5;

        $penaltyMax = ($weights['complexity_penalty'] + $weights['dependency_penalty']) * 5;
        $raw = $positive - $penalty + $appetiteBoost + $deadlineBoost;

        $minRaw = -$penaltyMax;
        $maxRaw = $positiveMax + 10;
        $normalized = $maxRaw === $minRaw
            ? 0
            : (($raw - $minRaw) / ($maxRaw - $minRaw)) * 100;

        $score = max(0, min(100, round($normalized, 2)));
        $band = $this->priorityBand($score);

        $breakdown = [
            'preset' => $preset,
            'weights' => $weights,
            'ratings' => $ratings,
            'positive' => round($positive, 2),
            'penalty' => round($penalty, 2),
            'appetite_boost' => round($appetiteBoost, 2),
            'deadline_boost' => round($deadlineBoost, 2),
            'raw' => round($raw, 2),
            'score' => $score,
            'priority_band' => $band,
            'scored_at' => now()->toIso8601String(),
        ];

        if ($persist) {
            $initiative->update([
                'priority_score' => $score,
                'priority_band' => $band,
                'score_profile' => $preset,
                'score_breakdown' => $breakdown,
            ]);

            event(new InitiativeScored($initiative->fresh(), $breakdown));
        }

        return $breakdown;
    }

    public function rank(iterable $initiatives, string $preset = 'board_ceo'): array
    {
        $scored = [];

        foreach ($initiatives as $initiative) {
            $breakdown = $this->score($initiative, $preset, true);
            $scored[] = [
                'initiative_id' => $initiative->id,
                'score' => $breakdown['score'],
                'priority_band' => $breakdown['priority_band'],
            ];
        }

        usort($scored, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return $a['initiative_id'] <=> $b['initiative_id'];
            }

            return $b['score'] <=> $a['score'];
        });

        return $scored;
    }

    public function preset(string $preset): array
    {
        return self::PRESETS[$preset] ?? self::PRESETS['board_ceo'];
    }

    public function allPresets(): array
    {
        return self::PRESETS;
    }

    protected function ratings(Initiative $initiative): array
    {
        $impact = $initiative->impact_profile ?? [];

        $keys = [
            'safety',
            'compliance',
            'reputation',
            'financial',
            'efficiency',
            'urgency',
            'complexity',
            'dependency',
            'multi_site',
        ];

        $ratings = [];
        foreach ($keys as $key) {
            $value = is_array($impact) ? ($impact[$key] ?? 0) : 0;
            $ratings[$key] = max(0, min(5, (int) $value));
        }

        return $ratings;
    }

    protected function appetiteBoost(Initiative $initiative): float
    {
        $aboveAppetite = $initiative->riskLinks()
            ->whereHas('risk', fn ($q) => $q->where('within_appetite', false))
            ->count();

        return min(6, $aboveAppetite * 2);
    }

    protected function deadlineBoost(Initiative $initiative): float
    {
        if ($initiative->decision_due_at === null) {
            return 0;
        }

        $days = now()->diffInDays($initiative->decision_due_at, false);

        if ($days < 0) {
            return 6;
        }

        if ($days <= 14) {
            return 4;
        }

        if ($days <= 30) {
            return 2;
        }

        return 0;
    }

    protected function priorityBand(float $score): string
    {
        return match (true) {
            $score >= 80 => 'critical',
            $score >= 60 => 'high',
            $score >= 40 => 'medium',
            default => 'low',
        };
    }
}
