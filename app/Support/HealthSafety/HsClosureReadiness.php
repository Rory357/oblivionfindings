<?php

namespace App\Support\HealthSafety;

use App\Models\HsClosureException;
use App\Support\Journeys\JourneyGate;
use InvalidArgumentException;

final readonly class HsClosureReadiness
{
    public const HARD = 'hard';

    public const EXCEPTIONAL = 'exceptional';

    /**
     * @param  list<array{key: string, complete: bool, label: string, href: string, classification: string}>  $requirements
     */
    public function __construct(
        public int $eventId,
        public ?int $siteId,
        public array $requirements,
    ) {
        foreach ($requirements as $requirement) {
            if (array_keys($requirement) !== ['key', 'complete', 'label', 'href', 'classification']) {
                throw new InvalidArgumentException('H&S closure requirements have an invalid shape.');
            }
            if (! in_array($requirement['classification'], [self::HARD, self::EXCEPTIONAL], true)) {
                throw new InvalidArgumentException('H&S closure requirement classification is invalid.');
            }
        }
    }

    public function ordinaryAllowed(): bool
    {
        return $this->blockers() === [];
    }

    /** @return list<array{key: string, complete: bool, label: string, href: string, classification: string}> */
    public function blockers(): array
    {
        return array_values(array_filter(
            $this->requirements,
            static fn (array $requirement): bool => ! $requirement['complete'],
        ));
    }

    /** @return list<array{key: string, complete: bool, label: string, href: string, classification: string}> */
    public function hardBlockers(): array
    {
        return array_values(array_filter(
            $this->blockers(),
            static fn (array $requirement): bool => $requirement['classification'] === self::HARD,
        ));
    }

    /** @return list<array{key: string, complete: bool, label: string, href: string, classification: string}> */
    public function exceptionalBlockers(): array
    {
        return array_values(array_filter(
            $this->blockers(),
            static fn (array $requirement): bool => $requirement['classification'] === self::EXCEPTIONAL,
        ));
    }

    /** @return list<string> */
    public function blockerLabels(): array
    {
        return array_column($this->blockers(), 'label');
    }

    /** @return list<string> */
    public function hardBlockerLabels(): array
    {
        return array_column($this->hardBlockers(), 'label');
    }

    /** @return list<string> */
    public function exceptionalBlockerKeys(): array
    {
        return array_column($this->exceptionalBlockers(), 'key');
    }

    public function canCloseWith(?HsClosureException $exception, ?\DateTimeInterface $at = null): bool
    {
        if ($this->ordinaryAllowed()) {
            return $exception === null;
        }
        if ($this->hardBlockers() !== [] || ! $exception || ! $exception->isCurrentApproved($at)) {
            return false;
        }
        if ((int) $exception->hs_event_id !== $this->eventId
            || (int) $exception->site_id !== (int) $this->siteId
        ) {
            return false;
        }

        $scope = array_values(array_unique(array_map('strval', $exception->scope ?? [])));
        $categoryScope = HsClosureException::CATEGORY_SCOPES[$exception->category] ?? [];
        if (array_diff($scope, $categoryScope) !== []) {
            return false;
        }

        return array_diff($this->exceptionalBlockerKeys(), $scope) === [];
    }

    public function toJourneyGate(): JourneyGate
    {
        return JourneyGate::fromRequirements(array_map(
            static fn (array $requirement): array => [
                'key' => $requirement['key'],
                'complete' => $requirement['complete'],
                'label' => $requirement['label'],
                'href' => $requirement['href'],
            ],
            $this->requirements,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ordinary_allowed' => $this->ordinaryAllowed(),
            'requirements' => $this->requirements,
            'hard_blockers' => $this->hardBlockers(),
            'exceptional_blockers' => $this->exceptionalBlockers(),
        ];
    }
}
