<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Policies\SafeguardingConcernPolicy;
use Illuminate\Support\Collection;

/**
 * Readiness audit P4 (Stephan, 6 Nov 2026, by pop-up via MASTER: "Safeguarding access only"). A safeguarding-type
 * incident, or one a safeguarding concern links to, shows its description, immediate actions, witnesses and files
 * only to people with safeguarding access to it. Everyone else (the incident register, its CSV, the record, its PDF,
 * the H&S review, tasks) sees "Restricted". The row itself stays where it is today; only its words and files hide.
 *
 * Who sees the content:
 * - with a linked concern: whoever may see what that concern says (SafeguardingConcernPolicy::viewDetail: its Site,
 *   safeguarding access or its lead/reporter, need-to-know, never the named alleged perpetrator);
 * - a safeguarding-type incident with no concern yet: safeguarding.viewAny at the incident's Site (or every Site);
 * - the person who reported the incident always sees their own words, unless a linked concern names them as the
 *   alleged perpetrator.
 */
final class IncidentSafeguardingContent
{
    public const RESTRICTED = 'Restricted';

    /** The incident's own words: what happened, what was done straight away, and who saw it. */
    public const TEXT_FIELDS = ['description', 'immediate_action_taken', 'immediate_action', 'witnesses'];

    /** @var array<int, Collection<int, SafeguardingConcern>> */
    private array $concerns = [];

    /** @var array<string, bool> */
    private array $decisions = [];

    public function __construct(private readonly SafeguardingConcernPolicy $policy) {}

    /**
     * Load the linked concerns for many incidents in one query (registers, exports).
     *
     * @param  iterable<int|ClientIncident>  $incidents
     */
    public function prime(iterable $incidents): void
    {
        $ids = collect($incidents)->map(fn ($i) => (int) ($i instanceof ClientIncident ? $i->getKey() : $i))
            ->filter(fn (int $id) => $id > 0 && ! array_key_exists($id, $this->concerns))->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        $found = SafeguardingConcern::withTrashed()->whereIn('related_incident_id', $ids->all())->get()
            ->groupBy(fn (SafeguardingConcern $c) => (int) $c->related_incident_id);
        foreach ($ids as $id) {
            $this->concerns[$id] = $found->get($id, collect())->values();
        }
    }

    public function isSafeguarding(ClientIncident $incident): bool
    {
        return $incident->type === 'safeguarding' || $this->concernsFor($incident)->isNotEmpty();
    }

    public function canSee(User $user, ClientIncident $incident): bool
    {
        $key = $user->getKey().'/'.$incident->getKey();
        if (array_key_exists($key, $this->decisions)) {
            return $this->decisions[$key];
        }

        return $this->decisions[$key] = $this->decide($user, $incident);
    }

    public function restricted(User $user, ClientIncident $incident): bool
    {
        return ! $this->canSee($user, $incident);
    }

    public const REFUSAL = 'This is a safeguarding incident. Only people with safeguarding access can see or change what it says, or open its files.';

    /** For a file download, an edit of the incident's words, or a note on them. */
    public function assertCanSee(?User $user, ClientIncident $incident): void
    {
        abort_if($user === null || $this->restricted($user, $incident), 403, self::REFUSAL);
    }

    /**
     * Replace the incident's words in a serialized row with "Restricted" (empty values stay empty, arrays empty).
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function mask(array $row, User $user, ClientIncident $incident, array $fields = self::TEXT_FIELDS): array
    {
        if ($this->canSee($user, $incident)) {
            return $row;
        }
        foreach ($fields as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            $row[$field] = is_array($row[$field]) ? [] : (filled($row[$field]) ? self::RESTRICTED : $row[$field]);
        }

        return $row;
    }

    /** @return Collection<int, SafeguardingConcern> */
    private function concernsFor(ClientIncident $incident): Collection
    {
        $this->prime([$incident]);

        return $this->concerns[(int) $incident->getKey()] ?? collect();
    }

    private function decide(User $user, ClientIncident $incident): bool
    {
        $concerns = $this->concernsFor($incident);
        if ($incident->type !== 'safeguarding' && $concerns->isEmpty()) {
            return true;
        }
        if ($concerns->contains(fn (SafeguardingConcern $c) => $this->policy->namesAsAllegedPerpetrator($user, $c))) {
            return false;
        }
        if ((int) $incident->reported_by === (int) $user->getKey()) {
            return true;
        }
        if ($concerns->isNotEmpty()) {
            return $concerns->every(fn (SafeguardingConcern $c) => $this->policy->viewDetail($user, $c));
        }
        if (! $user->canDo('safeguarding.viewAny')) {
            return false;
        }
        $siteId = (int) ($incident->site_id ?: $incident->client?->site_id);

        return $siteId <= 0 || in_array($siteId, $this->policy->visibleSiteIds($user), true);
    }
}
