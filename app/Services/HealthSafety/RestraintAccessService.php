<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Respite\RespiteStayScope;
use App\Services\UserSiteAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Current operation authority and exact confidential source tuples, never a tenant boundary. */
final class RestraintAccessService
{
    public const PERMISSIONS = ['hazards.view', 'restraints.view', 'restraints.create', 'restraints.review', 'restraints.manage', 'restraints.approve', 'respite.stays.manage', 'respite.viewAny', 'healthSafety.viewAllSites', 'clients.viewAny', 'clients.viewAssigned', 'clinical.accessAllSites', 'sites.viewAll', 'sites.view', 'incidents.viewAny', 'incidents.viewAssigned', 'incidents.update', 'incidents.create', 'incidents.submit', 'hr.cases.view', 'medications.controlled.view', 'controlRoom.viewAny', 'controlRoom.alerts.view', 'controlRoom.tasks.view'];

    public function __construct(private readonly UserSiteAccessService $sites, private readonly AuthorizationEvidenceLockService $authorization, private readonly HrCurrentStaffService $staff, private readonly ClientPolicy $clients, private readonly RespiteStayScope $stays, private readonly IncidentAccessService $incidents) {}

    public function withReadContext(HealthSafetyReadContext $context, callable $operation): mixed
    {
        return $context->consumeViewer(self::PERMISSIONS, $operation);
    }

    public function withCurrentViewer(User $viewer, callable $operation): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($viewer, $operation) {
            $current = $this->authorization->lockForUserWithoutWaiting($viewer, self::PERMISSIONS);
            abort_unless($reads->query($this->staff->currentUsersQuery())->whereKey($current->id)->exists(), 403);
            $current->setRelation('hrEmployeeProfile', $reads->query(HrEmployeeProfile::query())->where('user_id', $current->id)->first());
            $sites = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            abort_unless($sites !== [], 403);

            return $operation($current, $reads, $sites);
        }));
    }

    public function assertOperation(User $current, string $operation): void
    {
        $allowed = match ($operation) {
            'view', 'export' => $current->canDo('restraints.view'),
            'workspace', 'review', 'link_incident', 'correct', 'assign_review', 'add_observation', 'add_debrief', 'add_followup', 'update_followup' => $current->canDo('restraints.review') || $current->canDo('restraints.manage'),
            'capture', 'create', 'add_evidence' => $current->canDo('restraints.create') || $current->canDo('restraints.manage'),
            'respite_capture' => $current->canDo('respite.stays.manage'),
            'approve' => $current->canDo('restraints.approve'),
            'manage', 'edit', 'update', 'activate', 'submit_review', 'archive', 'remove_evidence' => $current->canDo('restraints.manage'),
            'complete_followup' => $current->canDo('restraints.view') || $current->canDo('restraints.review') || $current->canDo('restraints.manage'),
            default => false,
        };
        abort_unless($allowed, 403);
    }

    public function assertClient(Client $client, User $current, CurrentAuthorizationReads $reads, array $sites, string $operation = 'capture'): Client
    {
        $reads->assertActive();
        $this->assertOperation($current, $operation);
        $fresh = $reads->query(Client::query())->find($client->id);
        abort_unless($fresh && $this->clients->viewFromCurrentEvidence($current, $fresh, $reads), 403);
        $this->site((int) $fresh->site_id, $reads, $sites);

        return $fresh;
    }

    /** Preserves original recorded Site AND current Client read after a move. */
    private function context(int $clientId, ?int $siteId, ?int $stayId, ?int $schema, User $current, CurrentAuthorizationReads $reads, array $sites, ?array &$sourceEvidence = null, ?Closure $originalSource = null): array
    {
        $reads->assertActive();
        $client = $this->supportingQuery($reads->query(Client::query()), $originalSource, $sourceEvidence, $reads, $current)->find($clientId, ['id', 'site_id']);
        abort_unless($client && $this->clients->viewFromCurrentEvidence($current, $client, $reads), 403);
        $this->retainSourceEvidence($sourceEvidence, $client, ['id', 'site_id', 'deleted_at']);
        $this->site((int) $client->site_id, $reads, $sites, $sourceEvidence, $originalSource, $current);
        if ($siteId === null) {
            // Historical null Site remains uncertain and only the named global bypass can read it.
            abort_unless($schema === null && $current->canDo('healthSafety.viewAllSites'), 403);
        } else {
            $this->site($siteId, $reads, $sites, $sourceEvidence, $originalSource, $current);
        }
        $stay = null;
        if ($stayId !== null) {
            $stay = $this->assertStay($stayId, $clientId, $siteId, $current, $reads, $sites, $sourceEvidence, $originalSource);
        }

        return [$client, $stay];
    }

    public function assertStay(int $stayId, int $clientId, ?int $siteId, User $current, CurrentAuthorizationReads $reads, array $sites, ?array &$sourceEvidence = null, ?Closure $originalSource = null): RespiteStay
    {
        $reads->assertActive();
        $stay = $this->supportingQuery($reads->query(RespiteStay::query()), $originalSource, $sourceEvidence, $reads, $current)
            ->find($stayId, $sourceEvidence === null ? ['*'] : ['id', 'client_id', 'booking_id', 'deleted_at']);
        abort_unless($stay && (int) $stay->client_id === $clientId, 403);
        $this->retainSourceEvidence($sourceEvidence, $stay, ['id', 'client_id', 'booking_id', 'deleted_at']);
        $booking = $this->supportingQuery($reads->query(RespiteBooking::query())->where('client_id', $clientId), $originalSource, $sourceEvidence, $reads, $current)->find($stay->booking_id,
            $sourceEvidence === null ? ['*'] : ['id', 'client_id', 'location_id', 'booking_request_id', 'deleted_at']);
        abort_unless($booking, 403);
        $this->retainSourceEvidence($sourceEvidence, $booking, ['id', 'client_id', 'location_id', 'booking_request_id', 'deleted_at']);
        if ($booking->booking_request_id) {
            $requestQuery = $this->supportingQuery($reads->query(RespiteBookingRequest::withTrashed())->where('client_id', $clientId)->whereKey($booking->booking_request_id), $originalSource, $sourceEvidence, $reads, $current);
            if ($sourceEvidence === null) {
                abort_unless($requestQuery->exists(), 403);
            } else {
                $sourceRequest = $requestQuery->first(['id', 'client_id', 'deleted_at']);
                abort_unless($sourceRequest, 403);
                $this->retainSourceEvidence($sourceEvidence, $sourceRequest, ['id', 'client_id', 'deleted_at']);
            }
        }
        $request = Request::create('/');
        $request->setUserResolver(fn () => $current);
        $evidence = $sourceEvidence ?? [];
        $resolved = $originalSource === null ? $this->stays->resolveAuthorizedHealthSafetyStay($request, $stayId, false, $reads)
            : $this->stays->resolveAuthorizedHealthSafetyStayMetadata($request, $stayId, $reads,
                fn (Builder $query, User $receiver, CurrentAuthorizationReads $receiverReads): Builder => $originalSource($query, $receiver, $receiverReads, $evidence));
        abort_unless($siteId !== null && $this->stays->siteId($resolved) === $siteId && in_array($siteId, $sites, true), 403);
        // Retain BOTH observations, never let the resolver replace the first
        // accepted tuple with a later, differently rebound graph.
        $this->retainSourceEvidence($sourceEvidence, $resolved, ['id', 'client_id', 'booking_id', 'deleted_at']);
        $this->retainSourceEvidence($sourceEvidence, $resolved->getRelation('booking'), ['id', 'client_id', 'location_id', 'booking_request_id', 'deleted_at']);
        $this->retainSourceEvidence($sourceEvidence, $resolved->getRelation('client'), ['id', 'site_id', 'deleted_at']);
        if ($resolved->getRelation('booking')->relationLoaded('request')) {
            $this->retainSourceEvidence($sourceEvidence, $resolved->getRelation('booking')->getRelation('request'), ['id', 'client_id', 'deleted_at']);
        }

        return $resolved;
    }

    public function assertEvent(RestraintEvent $event, User $current, CurrentAuthorizationReads $reads, array $sites, string $operation = 'view', bool $identityOnly = false, ?array &$sourceEvidence = null, ?Closure $originalSource = null): void
    {
        $this->assertOperation($current, $operation);
        if ($event->source_schema_version !== null) {
            abort_unless((int) $event->source_schema_version === 1 && (int) $event->recorded_client_id === (int) $event->client_id && $event->recorded_site_id !== null && (int) $event->recorded_site_id === (int) $event->site_id && $event->recorded_stay_id === $event->stay_id, 403);
        }
        [$client, $stay] = $this->context((int) $event->client_id, $event->site_id === null ? null : (int) $event->site_id, $event->stay_id === null ? null : (int) $event->stay_id, $event->source_schema_version, $current, $reads, $sites, $sourceEvidence, $originalSource);
        if ($event->behaviour_support_plan_id) {
            $plan = $this->supportingQuery($reads->query(BehaviourSupportPlan::withTrashed()), $originalSource, $sourceEvidence, $reads, $current)->find($event->behaviour_support_plan_id, ['id', 'client_id']);
            abort_unless($plan && (int) $plan->client_id === (int) $event->client_id, 403);
            $this->retainSourceEvidence($sourceEvidence, $plan, ['id', 'client_id']);
            if ($event->behaviour_support_plan_version_id) {
                $version = $this->supportingQuery($reads->query(BehaviourSupportPlanVersion::query())->where('behaviour_support_plan_id', $plan->id), $originalSource, $sourceEvidence, $reads, $current)->find($event->behaviour_support_plan_version_id, ['id', 'behaviour_support_plan_id', 'client_id', 'site_id', 'stay_id']);
                abort_unless($version && (int) $version->client_id === (int) $event->client_id && (int) $version->site_id === (int) $event->site_id && ($version->stay_id === null || (int) $version->stay_id === (int) $event->stay_id), 403);
                $this->retainSourceEvidence($sourceEvidence, $version, ['id', 'behaviour_support_plan_id', 'client_id', 'site_id', 'stay_id']);
            }
        } else {
            abort_unless($event->behaviour_support_plan_version_id === null, 403);
        }
        if (! $identityOnly) {
            // Existing capture validates a not-yet-persisted factual candidate.
            // It has current Client authority, never a saved-source certificate.
            $parent = $event->exists ? $this->exactIdentity($reads->query(RestraintEvent::withTrashed()), $event,
                ['id', 'client_id', 'site_id', 'stay_id', 'source_schema_version', 'recorded_client_id', 'recorded_site_id', 'recorded_stay_id', 'behaviour_support_plan_id', 'behaviour_support_plan_version_id']) : null;
            $client = $this->clientAfterSource($client, $parent, $reads);
        }
        $event->setRelation('client', $client)->setRelation('stay', $stay);
    }

    public function assertPlan(BehaviourSupportPlan $plan, User $current, CurrentAuthorizationReads $reads, array $sites, string $operation = 'view', bool $identityOnly = false, ?array &$sourceEvidence = null, ?Closure $originalSource = null): void
    {
        $this->assertOperation($current, $operation);
        abort_unless($plan->source_schema_version === null || (int) $plan->source_schema_version === 1, 403);
        $client = $this->supportingQuery($reads->query(Client::query()), $originalSource, $sourceEvidence, $reads, $current)->find($plan->client_id, ['id', 'site_id']);
        abort_unless($client, 403);
        $this->retainSourceEvidence($sourceEvidence, $client, ['id', 'site_id', 'deleted_at']);
        $site = $plan->source_schema_version === null ? $client->site_id : $plan->source_site_id;
        [$client, $stay] = $this->context((int) $plan->client_id, $site === null ? null : (int) $site, $plan->source_stay_id === null ? null : (int) $plan->source_stay_id, $plan->source_schema_version, $current, $reads, $sites, $sourceEvidence, $originalSource);
        if (! $identityOnly) {
            $parent = $plan->exists ? $this->exactIdentity($reads->query(BehaviourSupportPlan::withTrashed()), $plan,
                ['id', 'client_id', 'source_schema_version', 'source_site_id', 'source_stay_id']) : null;
            $client = $this->clientAfterSource($client, $parent, $reads);
        }
        $plan->setRelation('client', $client)->setRelation('sourceStay', $stay);
    }

    /** Original equality AND the existing restraint current-staff rule on actual SQL. */
    public function metricSourceAuthorityQuery(HealthSafetyReadContext $context): Builder
    {
        return $this->withReadContext($context, function ($current, $reads, $sites) use ($context): Builder {
            return $context->originalSourceAuthorityQuery(self::PERMISSIONS, $sites)
                ->whereExists($reads->query($this->staff->currentUsersQuery())->whereKey($current->id)->selectRaw('1')->toBase());
        });
    }

    /** Same canonical operation; the original evidence is an equality guard, not a new policy. */
    public function protectedEventSourceQuery(RestraintEvent $event, HealthSafetyReadContext $context): Builder
    {
        return $this->withReadContext($context, function ($current, $reads, $sites) use ($event, $context): Builder {
            $authority = $this->metricSourceAuthorityQuery($context);
            $evidence = [];
            $seed = $this->protectedSourceQuery($event, ['id', 'client_id', 'site_id', 'stay_id', 'source_schema_version',
                'recorded_client_id', 'recorded_site_id', 'recorded_stay_id', 'behaviour_support_plan_id', 'behaviour_support_plan_version_id'], [], $reads, $authority);
            $this->assertEvent($event, $current, $reads, $sites, 'view', true, $evidence,
                $this->supportingSourceFrame($context, $current, $reads, $seed));
            return $this->protectedSourceQuery($event, ['id', 'client_id', 'site_id', 'stay_id', 'source_schema_version',
                'recorded_client_id', 'recorded_site_id', 'recorded_stay_id', 'behaviour_support_plan_id', 'behaviour_support_plan_version_id'], $evidence, $reads,
                $authority);
        });
    }

    public function protectedPlanSourceQuery(BehaviourSupportPlan $plan, HealthSafetyReadContext $context): Builder
    {
        return $this->withReadContext($context, function ($current, $reads, $sites) use ($plan, $context): Builder {
            $authority = $this->metricSourceAuthorityQuery($context);
            $evidence = [];
            $seed = $this->protectedSourceQuery($plan, ['id', 'client_id', 'source_schema_version', 'source_site_id', 'source_stay_id'], [], $reads, $authority);
            $this->assertPlan($plan, $current, $reads, $sites, 'view', true, $evidence,
                $this->supportingSourceFrame($context, $current, $reads, $seed));
            return $this->protectedSourceQuery($plan, ['id', 'client_id', 'source_schema_version', 'source_site_id', 'source_stay_id'], $evidence, $reads,
                $authority);
        });
    }

    private function retainSourceEvidence(?array &$evidence, Model $row, array $columns): void
    {
        if ($evidence === null) { return; }
        $raw = $row->getRawOriginal();
        $values = [];
        foreach ($columns as $column) { $values[$column] = $raw[$column] ?? null; }
        $evidence[] = ['table' => $row->getTable(), 'values' => $values];
    }

    private function protectedSourceQuery(RestraintEvent|BehaviourSupportPlan $source, array $columns, array $evidence, CurrentAuthorizationReads $reads, Builder $originalAuthority): Builder
    {
        $reads->assertActive();
        abort_unless($source->exists, 404);
        $query = $this->exactIdentity($reads->query($source->newQuery()), $source, $columns)
            ->whereExists((clone $originalAuthority)->selectRaw('1')->toBase());

        return $this->conjunctSourceEvidence($query, $evidence, $reads);
    }

    private function supportingSourceFrame(HealthSafetyReadContext $context, User $current, CurrentAuthorizationReads $reads, Builder $seed): Closure
    {
        $context->assertViewer($current);
        $reads->assertActive();
        $id = (int) $current->id;
        $original = clone $seed;
        return function (Builder $query, User $receiver, CurrentAuthorizationReads $receiverReads, array $evidence = []) use ($context, $current, $id, $reads, $original): Builder {
            $context->assertActive();
            $context->assertViewer($receiver);
            $reads->assertActive();
            if ($receiver !== $current || (int) $receiver->id !== $id || $receiverReads !== $reads) {
                throw new \LogicException('Supporting source must use its original prepared actor and reads.');
            }
            return $reads->query($query->whereExists($this->conjunctSourceEvidence(clone $original, $evidence, $reads)->selectRaw('1')->toBase()));
        };
    }

    private function supportingQuery(Builder $query, ?Closure $originalSource, ?array $evidence, CurrentAuthorizationReads $reads, ?User $current): Builder
    {
        if ($originalSource === null) { return $query; }
        if ($current === null) { throw new \LogicException('Supporting source requires its original prepared actor.'); }
        return $originalSource($query, $current, $reads, $evidence ?? []);
    }

    private function conjunctSourceEvidence(Builder $query, array $evidence, CurrentAuthorizationReads $reads): Builder
    {
        $reads->assertActive();
        foreach ($evidence as $receipt) {
            $support = $reads->query(DB::table($receipt['table']));
            foreach ($receipt['values'] as $column => $value) {
                $column = $receipt['table'].'.'.$column;
                if ($value === null) { $support->whereNull($column); }
                elseif (is_string($value)) { $support->whereRaw('BINARY '.$support->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
                else { $support->where($column, $value); }
            }
            $query->whereExists($support->selectRaw('1'));
        }
        return $query;
    }

    /** Existing default relation remains full; metadata-only metrics never request it. */
    private function clientAfterSource(Client $identity, ?Builder $parent, CurrentAuthorizationReads $reads): Client
    {
        $query = $reads->query(Client::query())->whereKey($identity->id)->where('site_id', $identity->site_id);
        if ($parent !== null) { $query->whereExists($parent->selectRaw('1')->toBase()); }
        return $query->firstOrFail();
    }

    private function exactIdentity(Builder $query, RestraintEvent|BehaviourSupportPlan $source, array $columns): Builder
    {
        $raw = $source->getRawOriginal();
        foreach ($columns as $column) {
            // Newly inserted genuine legacy models may omit nullable defaults
            // from rawOriginal; SQL must still prove that actual stored null.
            $raw[$column] ??= null;
            $name = $source->getTable().'.'.$column;
            if ($raw[$column] === null) { $query->whereNull($name); }
            elseif (is_string($raw[$column])) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($name).' = BINARY ?', [$raw[$column]]); }
            else { $query->where($name, $raw[$column]); }
        }
        return $query;
    }

    public function assertVersion(BehaviourSupportPlanVersion $version, BehaviourSupportPlan $plan, User $current, CurrentAuthorizationReads $reads, array $sites): void
    {
        abort_unless(($plan->source_schema_version === null || (int) $plan->source_schema_version === 1) && (int) $version->behaviour_support_plan_id === (int) $plan->id && (int) $version->client_id === (int) $plan->client_id, 403);
        $this->context((int) $version->client_id, (int) $version->site_id, $version->stay_id === null ? null : (int) $version->stay_id, 1, $current, $reads, $sites);
        if ($plan->source_schema_version !== null) {
            abort_unless((int) $version->site_id === (int) $plan->source_site_id && $version->stay_id === $plan->source_stay_id, 403);
        }
    }

    public function eligibleStaff(int $id, int $siteId, CurrentAuthorizationReads $reads, array $requiredPermissions = []): User
    {
        $person = $reads->query($this->staff->currentUsersQuery())->find($id);
        abort_unless($person, 403);
        $person = $this->authorization->lockForUserWithoutWaiting($person, self::PERMISSIONS);
        $person->setRelation('hrEmployeeProfile', $reads->query(HrEmployeeProfile::query())->where('user_id', $id)->first());
        abort_unless(in_array($siteId, $this->sites->accessibleSiteIds($person, [], $reads), true), 403);
        abort_unless($requiredPermissions === [] || collect($requiredPermissions)->contains(fn ($key) => $person->canDo($key)), 403);

        return $person;
    }

    public function assertStaffId(int $id, User $current, CurrentAuthorizationReads $reads, array $sites, string $operation, RestraintEvent|Client $source): User
    {
        $siteId = (int) ($source instanceof Client ? $source->site_id : $source->site_id);
        abort_unless(in_array($siteId, $sites, true), 403);
        $person = $this->eligibleStaff($id, $siteId, $reads);
        if (in_array($operation, ['review_owner', 'followup_owner', 'reviewer'], true)) {
            abort_unless($person->canDo('restraints.review') || $person->canDo('restraints.manage'), 403);
            $source instanceof Client
                ? $this->assertClient($source, $person, $reads, $this->sites->accessibleSiteIds($person, [], $reads), 'review')
                : $this->assertEvent($source, $person, $reads, $this->sites->accessibleSiteIds($person, [], $reads), 'review');
        }

        return $person;
    }

    public function assertIncident(RestraintEvent $event, ClientIncident $incident, User $current, CurrentAuthorizationReads $reads, bool $link = true): void
    {
        $sites = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
        $this->assertEvent($event, $current, $reads, $sites, 'link_incident');
        $fresh = $reads->query(ClientIncident::query())->find($incident->id);
        abort_unless($fresh && (int) $fresh->client_id === (int) $event->client_id && $fresh->site_id !== null && (int) $fresh->site_id === (int) $event->site_id && ($fresh->respite_stay_id === null ? null : (int) $fresh->respite_stay_id) === ($event->stay_id === null ? null : (int) $event->stay_id), 404);
        abort_unless($this->incidents->canView($current, $fresh, $reads), 403);
        if ($link) {
            abort_unless($current->canDo('incidents.update') && $fresh->status !== 'closed' && ($current->canDo('incidents.viewAny') || $fresh->isEditableByReporter($current)), 403);
        }
    }

    public function commandCan(RestraintEvent|BehaviourSupportPlan $source, User $viewer): array
    {
        $flags = ['view', 'workspace', 'create', 'edit', 'review', 'approve', 'activate', 'submit_review', 'archive', 'link_incident', 'add_evidence'];
        try {
            return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($source, $flags) {
                $fresh = $source instanceof RestraintEvent ? $reads->query(RestraintEvent::withTrashed())->findOrFail($source->id) : $reads->query(BehaviourSupportPlan::withTrashed())->findOrFail($source->id);
                $result = [];
                foreach ($flags as $flag) {
                    try {
                        $fresh instanceof RestraintEvent ? $this->assertEvent($fresh, $current, $reads, $sites, $flag) : $this->assertPlan($fresh, $current, $reads, $sites, $flag);
                        $result[$flag] = in_array($flag, ['view', 'workspace'], true) || (! $fresh->trashed() && (! $fresh instanceof BehaviourSupportPlan || $fresh->status !== 'archived'));
                    } catch (HttpException $error) {
                        if (! in_array($error->getStatusCode(), [403, 404], true)) {
                            throw $error;
                        }
                        $result[$flag] = false;
                    }
                }

                return $result;
            });
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return array_fill_keys($flags, false);
        }
    }

    private function site(int $id, CurrentAuthorizationReads $reads, array $sites, ?array &$sourceEvidence = null, ?Closure $originalSource = null, ?User $current = null): Site
    {
        $site = $this->supportingQuery($reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereIn('id', $sites), $originalSource, $sourceEvidence, $reads, $current)
            ->find($id, $originalSource === null ? ['*'] : ['id', 'is_active', 'archived', 'archived_at', 'deleted_at']);
        abort_unless($site, 403);
        $this->retainSourceEvidence($sourceEvidence, $site, ['id', 'is_active', 'archived', 'archived_at', 'deleted_at']);

        return $site;
    }
}
