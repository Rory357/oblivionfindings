<?php

namespace App\Services\Medication\Recording;

use Illuminate\Support\Collection;

/**
 * Facts DoseRecordingRequirements reads once per viewer, house or person
 * while it answers for a whole list of doses.
 *
 * @internal
 */
final class RequirementsCache
{
    /** @var list<int>|null */
    public ?array $siteIds = null;

    /** @var array<int, bool> whether the viewer may see each person's medicines */
    public array $personVisible = [];

    /** @var array<int, array<string, mixed>> competency decision by Site */
    public array $competency = [];

    /** @var array<string, Collection<int, array<string, mixed>>> second-person candidates by Site and controlled status */
    public array $candidates = [];

    /** @var array<int, list<array{id: int, name: string}>> */
    public array $whoCanGive = [];

    /** @var array<int, array{id: int, name: string}|null> */
    public array $houseLead = [];

    /** @var array<int, list<string>|null> allergy labels by person (null = couldn't be read) */
    public array $allergyLabels = [];
}
