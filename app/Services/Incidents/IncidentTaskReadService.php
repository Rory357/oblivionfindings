<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Additional incident source admission only; supplies neither Tasks nor writer authority. */
final class IncidentTaskReadService
{
    public const PERMISSIONS = ['incidents.viewAny', 'incidents.viewAssigned', 'healthSafety.viewAllSites', 'hr.cases.view'];

    public function __construct(private readonly IncidentAccessService $access, private readonly IncidentIndependenceIntakeService $independence) {}

    /** Identity/Site refinements only before source proof; private search is applied afterwards. */
    public function incidentParents(User $current, CurrentAuthorizationReads $reads, array $filters = []): Builder
    {
        $reads->assertActive();
        $query = ClientIncident::query()->when(isset($filters['id']), fn ($query) => $query->whereKey((int) $filters['id']));
        return $this->parents($query, $current, $reads, $filters);
    }

    public function followupParents(User $current, CurrentAuthorizationReads $reads, array $filters = []): Builder
    {
        $reads->assertActive();
        // Independent child identities, never child instructions or a recursively scoped relation query.
        $children = IncidentFollowup::query()->when(isset($filters['id']), fn ($query) => $query->whereKey((int) $filters['id']))
            ->when(empty($filters['include_done']), fn ($query) => $query->whereNull('completed_at'))->select('client_incident_id');
        return $this->parents(ClientIncident::query()->whereIn('client_incidents.id', $children), $current, $reads, $filters);
    }

    private function parents(Builder $query, User $current, CurrentAuthorizationReads $reads, array $filters): Builder
    {
        $query = $this->access->apply($query, $current, $reads);
        if (isset($filters['site_id'])) { $this->access->forSiteIds($query, [(int) $filters['site_id']]); }
        return $this->independence->applySourceScopeInReads($query, $current, $reads);
    }

    public function current(Builder $query, CurrentAuthorizationReads $reads): Builder
    {
        return $reads->query($query);
    }

    private const STILL_INCIDENT_COLUMNS = ['client_id', 'site_id', 'shift_id', 'reported_by', 'submitted_at', 'type', 'is_hr_confidential',
        'status', 'incident_owner_user_id', 'owner_revision', 'reference_number', 'title', 'description', 'severity', 'source',
        'occurred_at', 'created_at', 'hs_event_id', 'control_room_alert_id', 'investigation_assigned_to',
        'immediate_action_taken', 'immediate_action', 'witnesses', 'potential_consequence'];

    /** @var \WeakMap<CurrentAuthorizationReads, \WeakMap<Builder, array<string, bool>>>|null */
    private static ?\WeakMap $stillIncident = null;

    public function isStillIncident(ClientIncident $incident, Builder $parents, CurrentAuthorizationReads $reads): bool
    {
        $reads->assertActive();
        // One answer per read lifetime, parent scope and exact snapshot (speed, 30 Oct: the same check ran 2–3 times per
        // incident in one request). The read holds share locks on what it read, so within its lifetime the answer for
        // the same scope and the same facts cannot change; a different snapshot is a different key.
        $facts = [];
        foreach (self::STILL_INCIDENT_COLUMNS as $column) {
            if (array_key_exists($column, $incident->getAttributes())) { $facts[$column] = $incident->getRawOriginal($column); }
        }
        $key = (int) $incident->id.'|'.hash('sha256', serialize($facts));
        self::$stillIncident ??= new \WeakMap;
        $byParents = self::$stillIncident[$reads] ?? new \WeakMap;
        $known = $byParents[$parents] ?? [];
        if (array_key_exists($key, $known)) { return $known[$key]; }

        $query = IncidentIndependenceIntakeService::narrowedToIncident($parents, (int) $incident->id, $reads) ?? (clone $parents)->whereKey($incident->id);
        $this->exactSnapshot($query, $incident, self::STILL_INCIDENT_COLUMNS);
        $answer = $reads->query($query)->exists();

        $known[$key] = $answer;
        $byParents[$parents] = $known;
        self::$stillIncident[$reads] = $byParents;

        return $answer;
    }

    public function isStillFollowup(IncidentFollowup $followup, Builder $parents, CurrentAuthorizationReads $reads): bool
    {
        // With the snapshot pinning client_incident_id to P, "IN (parents)" and "IN (parents narrowed to P)" are the same test.
        $parentId = array_key_exists('client_incident_id', $followup->getAttributes()) ? $followup->getRawOriginal('client_incident_id') : null;
        $parent = $parentId === null ? null : IncidentIndependenceIntakeService::narrowedToIncident($parents, (int) $parentId, $reads);
        $query = IncidentFollowup::query()->whereKey($followup->id)
            ->whereIn('client_incident_id', ($parent ?? clone $parents)->reorder()->select('client_incidents.id'));
        $this->exactSnapshot($query, $followup, ['client_incident_id', 'assigned_to_user_id', 'due_at', 'completed_at', 'notes', 'created_by', 'created_at']);
        return $reads->query($query)->exists();
    }

    /** Compare the exact selected task facts, including case and trailing bytes, not a later refreshed baseline. */
    private function exactSnapshot(Builder $query, \Illuminate\Database\Eloquent\Model $row, array $columns): void
    {
        $grammar = $query->getQuery()->getGrammar();
        foreach ($columns as $column) {
            if (! array_key_exists($column, $row->getAttributes())) { continue; }
            $value = $row->getRawOriginal($column);
            $qualified = $query->qualifyColumn($column);
            if ($value === null) { $query->whereNull($qualified); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$grammar->wrap($qualified).' = BINARY ?', [$value]); }
            else { $query->where($qualified, $value); }
        }
    }

    /** Actual eager relation builders retain this current-read lifetime; parent copies carry the same final graph. */
    public function relations(array $relations, CurrentAuthorizationReads $reads, ?Builder $parents = null): array
    {
        $reads->assertActive(); $result = [];
        foreach ($relations as $relation) {
            [$name, $selection] = array_pad(explode(':', $relation, 2), 2, null);
            $result[$name] = function ($related) use ($reads, $parents, $name, $selection): void {
                $query = $related instanceof Relation ? $related->getQuery() : $related;
                // Closure-valued eager keys are not parsed as Laravel's string-only colon syntax.
                if ($selection !== null) { $query->select(explode(',', $selection)); }
                if ($parents !== null && $name === 'incident') {
                    $query->whereIn('client_incidents.id', (clone $parents)->reorder()->select('client_incidents.id'));
                }
                $reads->query($query);
            };
        }
        return $result;
    }
}
