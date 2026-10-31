<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\Site;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Current staff and exact treatment subjects for this single organisation. */
final class FirstAidAccessService
{
    private ?\WeakMap $sourceAuthorities = null;
    public const PERMISSIONS = ['hazards.view', 'hazards.create', 'hazards.manage', 'healthSafety.viewAllSites', 'clients.viewAny', 'clients.viewAssigned', 'clinical.accessAllSites', 'sites.viewAll', 'incidents.viewAny', 'incidents.viewAssigned', 'incidents.create', 'incidents.update', 'incidents.submit', 'hr.cases.view', 'medications.controlled.view', 'controlRoom.viewAny', 'controlRoom.alerts.view', 'controlRoom.tasks.view', 'sites.view'];

    private const COMMAND_FLAGS = ['view', 'create', 'edit', 'archive', 'link_incident', 'create_incident', 'add_followup', 'add_evidence', 'remove_evidence', 'export'];

    public function __construct(private readonly UserSiteAccessService $sites, private readonly AuthorizationEvidenceLockService $authorization, private readonly HrCurrentStaffService $staff, private readonly ClientPolicy $clients, private readonly IncidentAccessService $incidents) {}

    /** Reuses only evidence freshly prepared inside this active read callback. */
    public function withReadContext(HealthSafetyReadContext $context, callable $operation): mixed
    {
        return $context->consumeViewer(self::PERMISSIONS, $operation);
    }

    public function withCurrentViewer(User $viewer, callable $operation): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($viewer, $operation) {
            $current = $this->authorization->lockForUserWithoutWaiting($viewer, self::PERMISSIONS);
            $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
            $preparedSourceDate = [$timezone, now($timezone)->toDateString()];
            abort_unless($reads->query($this->staff->currentUsersQuery())->whereKey($current->id)->exists(), 403);
            $current->setRelation('hrEmployeeProfile', $reads->query(HrEmployeeProfile::query())->where('user_id', $current->id)->first());
            $originalProfile = $current->getRelation('hrEmployeeProfile')?->getRawOriginal();
            $this->sourceAuthorities ??= new \WeakMap;
            $siteIds = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            abort_unless($siteIds !== [], 403);
            $this->sourceAuthorities[$reads] = HealthSafetyReadContext::captureSourceAuthorityQuery($current, $reads, self::PERMISSIONS, $originalProfile, $preparedSourceDate, $siteIds);

            return $operation($current, $reads, $siteIds);
        }));
    }

    /** Query-level boundary is shared by rows, counts, exports and source work. */
    public function readableQuery(User $current, CurrentAuthorizationReads $reads, array $siteIds, bool $includeArchived = false, ?HealthSafetyReadContext $context = null): Builder
    {
        $reads->assertActive();
        $query = FirstAidRecord::query();
        if ($includeArchived) {
            $query->withTrashed();
        }
        if (! $current->canDo('hazards.view')) {
            return $reads->query($query->whereRaw('1 = 0'));
        }
        $context?->assertViewer($current);

        if ($context !== null) {
            $authority = $context->originalSourceAuthorityQuery(self::PERMISSIONS, $siteIds);
        } else {
            $this->sourceAuthorities ??= new \WeakMap;
            if (! isset($this->sourceAuthorities[$reads])) {
                $this->sourceAuthorities[$reads] = HealthSafetyReadContext::captureSourceAuthorityQuery($current, $reads, self::PERMISSIONS, $current->getRelation('hrEmployeeProfile')?->getRawOriginal(), null, $siteIds);
            }
            $authority = $this->sourceAuthorities[$reads];
        }
        $authority = clone $authority;
        // This is the existing treatment-reader eligibility gate, retained on actual SQL.
        // It also covers withdrawal between the initial staff check and profile preparation.
        $authority->whereExists($reads->query($this->staff->currentUsersQuery())->whereKey($current->id)->selectRaw('1')->toBase());
        $query->whereExists((clone $authority)->selectRaw('1')->toBase());
        // EXISTS compilation must reconsume the original lifetime/day guard on the actual destination SELECT.
        $query->whereRaw(new class(clone $authority) extends \Illuminate\Database\Query\Expression {
            public function __construct(private readonly Builder $authority) { parent::__construct('1 = 1'); }
            public function getValue(\Illuminate\Database\Grammar $grammar)
            {
                (clone $this->authority)->toSql();
                abort_unless((clone $this->authority)->exists(), 404, 'Original First Aid source authority changed.');
                return parent::getValue($grammar);
            }
        });

        return $context !== null
            ? $context->withFirstAidSubjects($siteIds, $includeArchived, fn ($approved, $clientIds) => $this->applyReadableSubjects($query, $reads, $approved, $clientIds))
            : $this->applyReadableSubjects($query, $reads, ...array_values($this->readableSubjects($current, $reads, $siteIds)));
    }

    /** Canonical Site and Client preparation; never stored beyond an active read callback. */
    public function readableSubjects(User $current, CurrentAuthorizationReads $reads, array $siteIds): array
    {
        $reads->assertActive();
        $approved = $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereIn('id', $siteIds)->pluck('id')->all();
        $clientIds = $this->readableClients($current, $reads, $approved, true)->pluck('id')->all();

        return ['approved' => $approved, 'clients' => $clientIds];
    }

    private function applyReadableSubjects(Builder $query, CurrentAuthorizationReads $reads, array $approved, array $clientIds): Builder
    {
        $reads->assertActive();
        $query->whereIn('site_id', $approved)->where(function (Builder $subjects) use ($clientIds): void {
            $subjects->where(function (Builder $client) use ($clientIds): void {
                $client->where('treated_person_type', 'client')->whereNull('treated_person_id')->whereIn('client_id', $clientIds)
                    ->whereHas('client', fn (Builder $source) => $source->whereColumn('clients.site_id', 'first_aid_records.site_id'));
            })->orWhere(function (Builder $staff): void {
                $staff->where('treated_person_type', 'staff')->whereNull('client_id')->where(function (Builder $identity): void {
                    $identity->whereNull('treated_person_id')->orWhereHas('treatedPerson', fn (Builder $person) => $person->staff());
                });
            })->orWhere(function (Builder $other): void {
                $other->whereIn('treated_person_type', ['visitor', 'contractor'])->whereNull('client_id')->whereNull('treated_person_id');
            });
        });
        $query->whereHas('site', fn (Builder $site) => $site->active()->notArchived()->whereNull('archived_at'));

        return $reads->query($query);
    }

    public function recordForViewer(FirstAidRecord $record, User $viewer, bool $includeArchived = false): FirstAidRecord
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($record, $includeArchived) {
            abort_unless($current->canDo('hazards.view'), 403);
            $row = $reads->query($includeArchived ? FirstAidRecord::withTrashed() : FirstAidRecord::query())->findOrFail($record->id);
            $this->assertSource($row, $current, $reads, $sites);

            return $row;
        });
    }

    /** Source authority deliberately does not grant or require treatment GET permission. */
    public function assertSource(FirstAidRecord $record, User $current, CurrentAuthorizationReads $reads, array $siteIds): void
    {
        $reads->assertActive();
        $site = $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereIn('id', $siteIds)->find($record->site_id);
        abort_unless($site, 403, UserSiteAccessService::DEFAULT_MESSAGE);
        $client = null;
        $person = null;
        if ($record->treated_person_type === 'client') {
            abort_unless($record->client_id !== null && $record->treated_person_id === null, 403);
            $client = $reads->query(Client::query())->where('site_id', $site->id)->find($record->client_id);
            abort_unless($client && $this->clients->viewFromCurrentEvidence($current, $client, $reads), 403);
        } elseif ($record->treated_person_type === 'staff') {
            abort_unless($record->client_id === null, 403);
            if ($record->treated_person_id !== null) {
                // Historical treatment identity is not current employment eligibility.
                $person = $reads->query(User::query()->staff())->find($record->treated_person_id);
                abort_unless($person, 403);
            }
        } else {
            abort_unless(in_array($record->treated_person_type, ['visitor', 'contractor'], true) && $record->client_id === null && $record->treated_person_id === null, 403);
        }
        $record->setRelation('site', $site)->setRelation('client', $client)->setRelation('treatedPerson', $person);
    }

    public function assertCommand(FirstAidRecord $record, User $current, CurrentAuthorizationReads $reads, array $siteIds, string $command): void
    {
        $this->assertSource($record, $current, $reads, $siteIds);
        $capture = $current->canDo('hazards.create') || $current->canDo('hazards.manage');
        $allowed = match ($command) {
            'create', 'update' => $capture,
            'archive', 'link_incident', 'add_followup', 'update_followup', 'add_evidence', 'remove_evidence' => $current->canDo('hazards.manage'),
            // Child identity and named executor are checked by followupCan / the locked service.
            'complete_followup' => $current->canDo('hazards.manage') || $current->canDo('hazards.view'),
            default => false,
        };
        abort_unless($allowed, 403);
        // Terminal-state writes are blocked by the command service after exact authorized receipt replay.
    }

    public function eligibleStaff(int $id, int $siteId, CurrentAuthorizationReads $reads, bool $firstAider = false, bool $followupAssignee = false): User
    {
        $reads->assertActive();
        $candidate = $reads->query($this->staff->currentUsersQuery())->find($id);
        abort_unless($candidate, 403);
        $candidate = $this->authorization->lockForUserWithoutWaiting($candidate, self::PERMISSIONS);
        $profile = $reads->query(HrEmployeeProfile::query())->where('user_id', $id)->first();
        $candidate->setRelation('hrEmployeeProfile', $profile);
        abort_unless(in_array($siteId, $this->sites->accessibleSiteIds($candidate, [], $reads), true), 403);
        abort_unless(! $firstAider || $profile?->is_first_aider === true, 403);
        abort_unless(! $followupAssignee || $candidate->canDo('hazards.view'), 403);

        return $candidate;
    }

    public function assertIncident(FirstAidRecord $record, ClientIncident $incident, User $current, CurrentAuthorizationReads $reads, bool $write): void
    {
        $reads->assertActive();
        $this->assertSource($record, $current, $reads, $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads));
        abort_unless($record->treated_person_type === 'client' && $record->client_id !== null, 403);
        $fresh = $reads->query(ClientIncident::query())->find($incident->id);
        abort_unless($fresh && (int) $fresh->client_id === (int) $record->client_id && $fresh->site_id !== null && (int) $fresh->site_id === (int) $record->site_id && $this->incidents->canView($current, $fresh, $reads), 403);
        if ($write) {
            abort_unless($current->canDo('incidents.update') && $fresh->status !== 'closed' && ($current->canDo('incidents.viewAny') || $fresh->isEditableByReporter($current)), 403);
        }
        $record->setRelation('relatedIncident', $fresh);
    }

    public function commandCan(FirstAidRecord $record, User $viewer): array
    {
        try {
            return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($record) {
                $row = $reads->query(FirstAidRecord::withTrashed())->findOrFail($record->id);
                $this->assertSource($row, $current, $reads, $sites);
                $view = $current->canDo('hazards.view');
                $live = ! $row->trashed();
                $capture = $current->canDo('hazards.create') || $current->canDo('hazards.manage');
                $manage = $live && $current->canDo('hazards.manage');
                $client = $row->treated_person_type === 'client' && $row->client !== null;

                return ['view' => $view, 'create' => $live && $capture, 'edit' => $live && $capture, 'archive' => $manage && ! $this->hasOpenFollowups($row, $reads),
                    'link_incident' => $manage && $client && $current->canDo('incidents.update') && ($current->canDo('incidents.viewAny') || $current->canDo('incidents.viewAssigned')),
                    'create_incident' => $manage && $client && $current->canDo('incidents.create'), 'add_followup' => $manage,
                    'add_evidence' => $manage, 'remove_evidence' => $manage, 'export' => $view];
            });
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return array_fill_keys(self::COMMAND_FLAGS, false);
            }
            throw $error;
        }
    }

    /** Only source-bound, unfinished work blocks a new archive command. */
    public function hasOpenFollowups(FirstAidRecord $record, CurrentAuthorizationReads $reads): bool
    {
        $reads->assertActive();

        return $reads->query(FirstAidFollowup::query())->where('first_aid_record_id', $record->id)
            ->whereNull('completed_at')->exists();
    }

    public function followupCan(FirstAidRecord $record, FirstAidFollowup $followup, User $viewer): array
    {
        try {
            return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($record, $followup) {
                $parent = $reads->query(FirstAidRecord::withTrashed())->findOrFail($record->id);
                $this->assertSource($parent, $current, $reads, $sites);
                $child = $reads->query(FirstAidFollowup::query())->where('first_aid_record_id', $parent->id)->find($followup->id);
                if ($child === null) {
                    return ['edit' => false, 'complete' => false];
                }
                $live = ! $parent->trashed() && $child->completed_at === null;
                $manage = $current->canDo('hazards.manage');

                return ['edit' => $live && $manage, 'complete' => $live && ($manage || ($current->canDo('hazards.view') && (int) $child->assigned_to_user_id === (int) $current->id))];
            });
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return ['edit' => false, 'complete' => false];
            }
            throw $error;
        }
    }

    public function pickerOptions(User $viewer, ?FirstAidRecord $record = null, ?int $siteId = null, ?int $clientId = null): array
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $siteIds) use ($record, $siteId, $clientId) {
            abort_unless($current->canDo('hazards.create') || $current->canDo('hazards.manage'), 403);
            if ($record !== null) {
                // A denied reader receives no private existing-record prefill/options.
                abort_unless($current->canDo('hazards.view'), 403);
                $record = $reads->query(FirstAidRecord::query())->findOrFail($record->id);
                $this->assertSource($record, $current, $reads, $siteIds);
                $siteId ??= (int) $record->site_id;
            }
            abort_unless($siteId === null || in_array($siteId, $siteIds, true), 403);
            $source = $record;
            if ($clientId !== null) {
                abort_unless($siteId !== null, 403);
                // Selected new-capture context is independently authorized; it grants no treatment GET.
                $source = new FirstAidRecord(['site_id' => $siteId, 'treated_person_type' => 'client', 'client_id' => $clientId, 'treated_person_id' => null]);
                $this->assertSource($source, $current, $reads, $siteIds);
            }
            $selectedSites = $siteId !== null ? [$siteId] : $siteIds;
            $siteRows = $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name'])->map(fn ($site) => ['id' => (int) $site->id, 'name' => $site->name])->all();
            $staff = [];
            $firstAiders = [];
            $assignees = [];
            foreach ($reads->query($this->staff->currentUsersQuery())->orderBy('name')->get() as $candidate) {
                foreach ($selectedSites as $selectedSite) {
                    try {
                        $person = $this->eligibleStaff((int) $candidate->id, (int) $selectedSite, $reads);
                    } catch (HttpException $error) {
                        if ($error->getStatusCode() !== 403) {
                            throw $error;
                        }

                        continue;
                    }
                    $item = ['id' => (int) $person->id, 'name' => $person->name, 'site_id' => (int) $selectedSite];
                    $staff[] = $item;
                    if ($person->hrEmployeeProfile?->is_first_aider === true) {
                        $firstAiders[] = $item;
                    }
                    if ($person->canDo('hazards.view')) {
                        try {
                            if ($source !== null) {
                                $this->assertSource($source, $person, $reads, $this->sites->accessibleSiteIds($person, [], $reads));
                            }
                            $assignees[] = $item;
                        } catch (HttpException $error) {
                            if ($error->getStatusCode() !== 403) {
                                throw $error;
                            }
                        }
                    }
                }
            }
            $clients = $this->readableClients($current, $reads, $selectedSites)->map(fn ($client) => ['id' => (int) $client->id, 'name' => trim($client->first_name.' '.$client->last_name), 'site_id' => (int) $client->site_id])->values()->all();
            $incidentRows = [];
            if ($source?->treated_person_type === 'client' && $current->canDo('hazards.manage') && $current->canDo('incidents.update')) {
                foreach ($this->incidents->apply(ClientIncident::query(), $current, $reads)->where('client_id', $source->client_id)->where('site_id', $source->site_id)->where('status', '!=', 'closed')->orderByDesc('occurred_at')->get() as $incident) {
                    try {
                        $this->assertIncident($source, $incident, $current, $reads, true);
                    } catch (HttpException $error) {
                        if ($error->getStatusCode() !== 403) {
                            throw $error;
                        }

                        continue;
                    }
                    $incidentRows[] = ['id' => (int) $incident->id, 'reference' => $incident->reference_number, 'label' => $incident->reference_number ?: 'Incident '.$incident->id, 'status' => $incident->status, 'href' => '/incidents/'.$incident->id];
                }
            }

            return ['sites' => $siteRows, 'staff' => $staff, 'first_aiders' => $firstAiders, 'assignees' => $assignees, 'clients' => $clients, 'incidents' => $incidentRows];
        });
    }

    private function readableClients(User $current, CurrentAuthorizationReads $reads, array $siteIds, bool $identityOnly = false): Collection
    {
        // ClientPolicy consumes only persisted ID/Site and current relationships.
        // Subject preparation must not read denied clients' names or care bodies.
        $identities = $reads->query(Client::query())->whereIn('site_id', $siteIds)->orderBy('id')->get(['id', 'site_id'])
            ->filter(fn (Client $client) => $this->clients->viewFromCurrentEvidence($current, $client, $reads));
        if ($identityOnly || $identities->isEmpty()) {
            return $identities;
        }
        // Picker labels preserve their existing shape/order, but only the exact
        // original ID/Site tuples admitted by canonical ClientPolicy reach SQL.
        $query = $reads->query(Client::query())->where(function (Builder $allowed) use ($identities): void {
            foreach ($identities as $client) {
                $allowed->orWhere(fn (Builder $exact) => $exact->whereKey($client->id)->where('site_id', $client->site_id));
            }
        });
        return $query->orderBy('first_name')->orderBy('last_name')->get(['id', 'site_id', 'first_name', 'last_name']);
    }
}
