<?php

namespace App\Support\Journeys;

use InvalidArgumentException;

final readonly class JourneyGate
{
    /**
     * @param  list<array{key: string, complete: bool, label: string, href: string}>  $requirements
     */
    public function __construct(
        public bool $allowed,
        public array $requirements,
    ) {
        foreach ($requirements as $requirement) {
            if (array_keys($requirement) !== ['key', 'complete', 'label', 'href']) {
                throw new InvalidArgumentException('Journey gate requirements must contain key, complete, label, and href.');
            }

            if (trim($requirement['key']) === ''
                || trim($requirement['label']) === ''
                || trim($requirement['href']) === ''
            ) {
                throw new InvalidArgumentException('Journey gate requirement key, label, and href cannot be empty.');
            }
        }

        $derivedAllowed = collect($requirements)
            ->every(static fn (array $requirement): bool => $requirement['complete']);
        if ($allowed !== $derivedAllowed) {
            throw new InvalidArgumentException('Journey gate allowed state must match its requirements.');
        }
    }

    /**
     * @param  list<array{key: string, complete: bool, label: string, href: string}>  $requirements
     */
    public static function fromRequirements(array $requirements): self
    {
        $requirements = array_values($requirements);

        return new self(
            collect($requirements)
                ->every(static fn (array $requirement): bool => $requirement['complete']),
            $requirements,
        );
    }

    /** @return list<string> */
    public function blockers(): array
    {
        return collect($this->requirements)
            ->where('complete', false)
            ->pluck('label')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     allowed: bool,
     *     requirements: list<array{key: string, complete: bool, label: string, href: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'requirements' => $this->requirements,
        ];
    }
}
