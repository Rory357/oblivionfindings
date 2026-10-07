<?php

namespace App\Domain\Shifts\Timesheets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;

/** Current evidence for existing Timesheet commands; does not change read scope. */
class TimesheetCommandEvidence
{
    public function __construct(private readonly UserSiteAccessService $sites) {}

    /** @return array{site: Site, client: ?Client, shift: ?Shift, owner: User} */
    public function current(Timesheet $timesheet): array
    {
        return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($timesheet): array {
            $owner = $reads->query(User::query()->whereKey($timesheet->user_id))->first();
            abort_unless($owner, 403);
            $client = $timesheet->client_id === null ? null : $reads->query(Client::query()->whereKey($timesheet->client_id))->first();
            abort_unless($timesheet->client_id === null || ($client && (int) $client->site_id > 0), 403);
            $shift = $timesheet->shift_id === null ? null : $reads->query(Shift::query()->whereKey($timesheet->shift_id))->first();
            abort_unless($timesheet->shift_id === null || ($shift && $shift->user_id !== null
                && (int) $shift->user_id === (int) $timesheet->user_id
                && (int) $shift->client_id === (int) $timesheet->client_id
                && $client && ($shift->site_id === null || (int) $shift->site_id === (int) $client->site_id)), 403);
            $ids = collect([$timesheet->site_id, $timesheet->shift_site_id, $client?->site_id, $shift?->site_id])
                ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)->map(fn ($id) => (int) $id)->unique()->values();
            abort_unless($ids->count() === 1, 403, 'You are not authorized to access timesheets for this site.');
            $site = $reads->query(Site::query()->whereKey($ids->first()))->first();
            abort_unless($site, 403);
            if ($shift) {
                $worker = $this->sites->applyFleetRecipientEligibility(User::query()->whereKey($shift->user_id), (int) $site->id);
                abort_unless($reads->query($worker)->exists(), 403);
                $shift->setRelation('client', $client)->setRelation('staff', $owner)->setRelation('site', $site);
            }
            $owner->setRelation('hrEmployeeProfile', $reads->query(HrEmployeeProfile::query()->where('user_id', $owner->id))->first());
            $timesheet->setRelation('user', $owner);
            $timesheet->setRelation('shift', $shift)->setRelation('client', $client)->setRelation('staff', $owner);

            return compact('site', 'client', 'shift', 'owner');
        });
    }

    /** Rebuild editable source defaults under the command's existing locks. */
    public function editable(Timesheet $timesheet, array $updates): array
    {
        $candidate = clone $timesheet;
        $candidate->fill($updates);
        $source = $this->current($candidate);
        $shift = $source['shift'];
        $client = $source['client'];
        $site = $source['site'];
        $owner = $source['owner'];
        $contextId = $shift?->service_context_id ?? $client?->service_context_id;
        $context = $contextId ? CurrentAuthorizationReads::within(fn ($reads) => $reads->query(ServiceContext::query()->whereKey($contextId))->first()) : null;
        $manual = ! $shift && ! $client;
        foreach ($candidate->getRelations() as $name => $relation) {
            $timesheet->setRelation($name, $relation);
        }

        return array_replace($this->normalizeInput($updates), [
            'sleepover' => $shift ? (bool) $shift->is_sleepover : (bool) ($updates['sleepover'] ?? false),
            'on_call' => $shift ? (bool) $shift->is_on_call : (bool) ($updates['on_call'] ?? false),
            'shift_site_id' => (int) $site->id,
            'shift_service_context_id' => $contextId,
            'shift_site_name_snapshot' => $site->name,
            'shift_location_snapshot' => $shift ? $shift->location : ($client ? ($updates['notes'] ?? $timesheet->notes) : $site->name),
            'service_context_name_snapshot' => $manual ? ($candidate->activity_type ?? null) : $context?->name,
            'client_name_snapshot' => $client ? (trim($client->first_name.' '.$client->last_name) ?: $client->full_name) : null,
            'staff_name_snapshot' => $owner->name,
            'shift_type_snapshot' => $shift?->shift_type ?? ($client ? 'standard' : ($candidate->activity_type ?: 'manual')),
            'coverage_roles_snapshot' => $shift ? array_values(array_filter((array) ($shift->coverage_roles ?? []))) : [],
        ]);
    }

    /** The same roster predicate as the existing allocation picker, currently read. */
    public function allocationCandidates(Timesheet $timesheet): array
    {
        $siteId = (int) $this->current($timesheet)['site']->id;

        return CurrentAuthorizationReads::within(function ($reads) use ($timesheet, $siteId): array {
            $query = Client::query()->where('site_id', $siteId)->where('status', '!=', 'archived');
            if (! $timesheet->shift_id) {
                $query->whereKey($timesheet->client_id);
            }

            return $reads->query($query->orderBy('first_name')->orderBy('last_name'))->get()
                ->map(fn ($client) => ['id' => (int) $client->id, 'name' => trim($client->first_name.' '.$client->last_name),
                    'is_primary' => (int) $client->id === (int) $timesheet->client_id])->all();
        });
    }

    public function snapshot(Timesheet $timesheet): array
    {
        $values = $this->editable($timesheet, ['sleepover' => $timesheet->sleepover, 'on_call' => $timesheet->on_call]);

        return array_intersect_key($values, array_flip(['shift_site_id', 'shift_service_context_id',
            'shift_site_name_snapshot', 'shift_location_snapshot', 'service_context_name_snapshot',
            'client_name_snapshot', 'staff_name_snapshot', 'shift_type_snapshot', 'coverage_roles_snapshot']));
    }

    public function normalizeInput(array $values): array
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = Carbon::parse($values[$field])->utc()->format('Y-m-d H:i:s');
            }
        }
        if (isset($values['work_date'])) {
            $values['work_date'] = Carbon::parse($values['work_date'])->toDateString();
        }

        return $values;
    }

    /** Confirm the exact intended typed fields in the same transaction. */
    public function persisted(Timesheet $intent, array $fields): Timesheet
    {
        $persisted = Timesheet::query()->whereKey($intent->id)->lockForUpdate()->first();
        abort_unless($persisted, 409, 'The timesheet could not be saved. Refresh and review the current record.');
        foreach (array_unique(['user_id', 'client_id', 'shift_id', ...$fields]) as $field) {
            $actual = $persisted->getAttribute($field);
            $expected = $intent->getAttribute($field);
            $equal = $actual instanceof \DateTimeInterface && $expected instanceof \DateTimeInterface
                ? $actual->getTimestamp() === $expected->getTimestamp()
                : (($field === 'break_minutes' || str_ends_with($field, '_id') || str_ends_with($field, '_by')) && $actual !== null && $expected !== null
                    ? (int) $actual === (int) $expected : $actual === $expected);
            abort_unless($equal, 409, 'The timesheet could not be saved. Refresh and review the current record.');
        }
        // Use already-current locked relations for the result; no post-lock reload.
        foreach ($intent->getRelations() as $name => $relation) {
            $persisted->setRelation($name, $relation);
        }

        return $persisted;
    }

    public function save(Timesheet $timesheet): Timesheet
    {
        $fields = array_keys($timesheet->getDirty());
        $intent = clone $timesheet;
        abort_unless($timesheet->save() === true, 409, 'The timesheet could not be saved. Refresh and review the current record.');
        $intent->setAttribute($timesheet->getKeyName(), $timesheet->getKey());

        return $this->persisted($intent, $fields);
    }
}
