<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use LogicException;
use WeakMap;

/** Original callback-bound participation authority. No command or credential grant. */
final class WorkerParticipationAccessService
{
    public const PERMISSIONS = ['hazards.view', 'hazards.manage', 'healthSafety.viewAllSites'];

    private const SOURCES = [
        'representatives' => [HsRepresentative::class, ['id', 'user_id', 'site_id', 'created_by', 'updated_by', 'deleted_at']],
        'committees' => [HsCommittee::class, ['id', 'site_id', 'members', 'created_by', 'updated_by', 'deleted_at']],
        'meetings' => [HsCommitteeMeeting::class, ['id', 'hs_committee_id', 'attendees', 'confirmed_attendees', 'recorded_by', 'created_by', 'updated_by', 'deleted_at']],
        'consultations' => [HsConsultation::class, ['id', 'site_id', 'initiated_by', 'workers_consulted', 'created_by', 'updated_by', 'deleted_at']],
    ];

    private WeakMap $frames;

    public function __construct(private readonly UserSiteAccessService $sites, private readonly HrCurrentStaffService $staff)
    {
        $this->frames = new WeakMap;
    }

    /** The supplied context and outer collector are reused; only detached data may leave. */
    public function materialize(User $viewer, callable $operation, ?HealthSafetyReadContext $context = null, ?int $selectedSiteId = null, bool $includePickers = false): array
    {
        return $this->consume($viewer, $operation, $context, $selectedSiteId, $includePickers, null, null);
    }

    /** Explicit non-null Site audience, captured before source discovery; never a source-ID certificate. */
    public function materializeSubset(User $viewer, array $requestedSites, callable $operation, ?HealthSafetyReadContext $context = null): array
    {
        foreach ($requestedSites as $siteId) {
            if (! is_int($siteId) || $siteId <= 0) { throw new \InvalidArgumentException('Participation Site subsets require positive integer identities.'); }
        }
        $requestedSites = array_values(array_unique($requestedSites));
        sort($requestedSites, SORT_NUMERIC);
        return $this->consume($viewer, $operation, $context, null, false, null, null, $requestedSites);
    }

    /** Bounded original-root mode for evidence preflight and its equality-checked continuation. */
    public function materializeSource(User $viewer, string $kind, int $rootId, callable $operation, ?HealthSafetyReadContext $context = null, ?int $selectedSiteId = null): array
    {
        $this->source($kind);
        abort_unless($rootId > 0, 404);
        return $this->consume($viewer, $operation, $context, $selectedSiteId, false, $kind, $rootId);
    }

    private function consume(User $viewer, callable $operation, ?HealthSafetyReadContext $context, ?int $selectedSiteId, bool $includePickers, ?string $rootKind, ?int $rootId, ?array $requestedSubset = null): array
    {
        $consume = function (HealthSafetyReadContext $active) use ($viewer, $operation, $selectedSiteId, $includePickers, $rootKind, $rootId, $requestedSubset): array {
            $active->assertViewer($viewer);
            return HealthSafetyReadMaterialisationProof::within($active, function ($proof) use ($active, $operation, $selectedSiteId, $includePickers, $rootKind, $rootId, $requestedSubset): array {
                $this->prepareAudience($active, $selectedSiteId, $includePickers, $rootKind, $rootId, $requestedSubset);
                $result = $active->consumeSourceViewer(self::PERMISSIONS, fn ($current, $reads) => $operation($current, $reads, $active, $proof));
                $this->assertDetached($result);
                $this->assertBarrier($active);
                return $result;
            });
        };
        return $context === null ? HealthSafetyReadContext::within($viewer, self::PERMISSIONS, $consume) : $consume($context);
    }

    /** Scalars only. Source metadata is captured once, before any body/name query. */
    public function prepare(HealthSafetyReadContext $context, ?int $selectedSiteId = null): array
    {
        if (! isset($this->frames[$context])) { $this->prepareAudience($context, $selectedSiteId, false, null, null); }
        $frame = $this->frame($context);
        $siteIds = $frame['site_ids'];
        if ($selectedSiteId !== null) { $siteIds = in_array($selectedSiteId, $siteIds, true) ? [$selectedSiteId] : []; }
        return ['site_ids' => $siteIds, 'wide' => $frame['wide'], 'includes_unconfirmed_site' => $frame['allow_null'] && $selectedSiteId === null];
    }

    private function prepareAudience(HealthSafetyReadContext $context, ?int $selectedSiteId, bool $includePickers, ?string $rootKind, ?int $rootId, ?array $requestedSubset = null): void
    {
        if (isset($this->frames[$context])) {
            $frame = $this->frame($context);
            if ([$selectedSiteId, $includePickers, $rootKind, $rootId, $requestedSubset] !== [$frame['requested_site_id'], $frame['include_pickers'], $frame['root_kind'], $frame['root_id'], $frame['requested_subset']]) {
                throw new LogicException('Participation audience cannot be replaced inside its original collector.');
            }
            return;
        }
        if (! isset($this->frames[$context])) {
            $context->consumeSourceViewer(self::PERMISSIONS, function (User $current, CurrentAuthorizationReads $reads) use ($context, $selectedSiteId, $includePickers, $rootKind, $rootId, $requestedSubset): void {
                $proof = HealthSafetyReadMaterialisationProof::current($context);
                if ($proof === null) { throw new LogicException('Participation reads require the active outer materialisation collector.'); }
                abort_unless($current->approved_at !== null && ! $current->isExternalClinicianAccount() && $current->canDo('hazards.view'), 403);
                abort_unless($reads->query(User::query()->staff())->whereKey($current->id)->exists(), 403);
                $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
                $day = now($timezone)->toDateString();
                $wide = $this->sites->canBypass($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
                $approved = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
                abort_unless($selectedSiteId === null || in_array($selectedSiteId, $approved, true), 404);
                $siteIds = $selectedSiteId === null ? $approved : (in_array($selectedSiteId, $approved, true) ? [$selectedSiteId] : []);
                if ($requestedSubset !== null) { $siteIds = array_values(array_intersect($requestedSubset, $approved)); }
                $allowNull = $wide && $selectedSiteId === null && $requestedSubset === null;
                $authority = $context->originalSourceAuthorityQuery(self::PERMISSIONS, $rootKind === null ? $siteIds : []);
                $authority->whereExists($reads->query(User::query()->staff())->whereKey($current->id)->selectRaw('1')->toBase());
                // This is the existing ordinary Site-access rule, not an employment rule for wide readers.
                if (! $wide) { $authority->whereExists($reads->query($this->staff->currentUsersQuery())->whereKey($current->id)->selectRaw('1')->toBase()); }
                $barrier = clone $authority;
                $capture = function (Builder|QueryBuilder $selector, array $columns, array $columnMap = []) use ($reads, &$barrier): array {
                    $base = $selector instanceof Builder ? $selector->toBase() : clone $selector;
                    $base = $reads->query(clone $base);
                    $rows = (clone $base)->whereExists((clone $barrier)->selectRaw('1')->toBase())->orderBy($base->from.'.id')->get($columns)->map(fn ($row) => (array) $row)->all();
                    abort_unless((clone $barrier)->exists(), 404, 'Participation source authority changed.');
                    $this->conjunctSet($barrier, $base, $rows, $columnMap);
                    return $rows;
                };
                $sourceSites = [];
                // One metadata statement retains BOTH the actual meeting and its original committee tuple.
                $sourceQuery = function (string $kind) use ($rootKind, $rootId, &$siteIds, &$allowNull): Builder {
                    [$class] = self::SOURCES[$kind];
                    return $this->rootFilter($this->siteScope($class::query(), $kind, $siteIds, $allowNull), $kind, $rootKind, $rootId);
                };
                $metadata = function (Builder $query, string $kind) use ($capture): array {
                    [, $columns] = self::SOURCES[$kind];
                    $table = $query->getModel()->getTable();
                    if ($kind !== 'meetings') { return $capture($query, $columns); }
                    $query->join('hs_committees as participation_parent', 'participation_parent.id', '=', 'hs_committee_meetings.hs_committee_id')->whereNull('participation_parent.deleted_at');
                    $columns = array_map(fn ($column) => $table.'.'.$column, $columns);
                    return $capture($query, [...$columns, 'participation_parent.id as parent_id', 'participation_parent.site_id as parent_site_id', 'participation_parent.deleted_at as parent_deleted_at'],
                        ['parent_id' => 'participation_parent.id', 'parent_site_id' => 'participation_parent.site_id', 'parent_deleted_at' => 'participation_parent.deleted_at']);
                };
                $first = [];
                if ($rootKind !== null) {
                    [$class] = self::SOURCES[$rootKind];
                    // Wide direct-object discovery needs only this canonical active Site, not unrelated source/Site witnesses.
                    $initialSites = $selectedSiteId === null ? $approved : [$selectedSiteId];
                    $initial = $this->rootFilter($this->siteScope($class::query(), $rootKind, $initialSites, $allowNull, $wide && $selectedSiteId === null), $rootKind, $rootKind, $rootId);
                    $first = $metadata($initial, $rootKind);
                    abort_unless(count($first) === 1, 404);
                    $actualSite = $rootKind === 'meetings' ? $first[0]['parent_site_id'] : $first[0]['site_id'];
                    $actualSite = $actualSite === null ? null : (int) $actualSite;
                    abort_unless($selectedSiteId === null || $selectedSiteId === $actualSite, 404);
                    $siteIds = $actualSite === null ? [] : [$actualSite];
                    $allowNull = $actualSite === null && $wide;
                }
                $siteSelector = Site::query()->active()->notArchived()->whereNull('archived_at');
                if (! ($rootKind === null && $selectedSiteId === null && $wide && $requestedSubset === null)) { $siteSelector->whereIn('id', $includePickers ? $approved : $siteIds); }
                $capture($siteSelector, ['id', 'is_active', 'archived', 'archived_at', 'deleted_at']);
                $choiceSites = [];
                if ($includePickers && $current->canDo('hazards.manage')) {
                    $eligible = $this->eligibleUsers($approved);
                    $profileColumns = ['id', 'user_id', 'primary_site_id', 'secondary_site_ids', 'is_active', 'start_date', 'end_date', 'deleted_at'];
                    $joined = (clone $eligible)->join('hr_employee_profiles as participation_picker_profile', 'participation_picker_profile.user_id', '=', 'users.id')->whereNull('participation_picker_profile.deleted_at');
                    $columns = ['users.id', 'users.role', 'users.approved_at', 'users.external_clinical_account']; $map = [];
                    foreach ($profileColumns as $column) {
                        $columns[] = 'participation_picker_profile.'.$column.' as profile_'.$column;
                        $map['profile_'.$column] = 'participation_picker_profile.'.$column;
                    }
                    $capture($joined, $columns, $map);
                    $capture(HrEmployeeProfile::query()->whereExists((clone $eligible)->whereColumn('users.id', 'hr_employee_profiles.user_id')->selectRaw('1')->toBase()),
                        $profileColumns);
                    $choices = $this->siteScope(HsCommittee::query(), 'committees', $approved, $wide);
                    $choiceRows = $capture($choices, self::SOURCES['committees'][1]);
                    foreach ($choiceRows as $row) { $choiceSites[(int) $row['id']] = $row['site_id'] === null ? null : (int) $row['site_id']; }
                    $choiceMeetings = $this->siteScope(HsCommitteeMeeting::query(), 'meetings', $approved, $wide)
                        ->join('hs_committees as participation_choice_parent', 'participation_choice_parent.id', '=', 'hs_committee_meetings.hs_committee_id')->whereNull('participation_choice_parent.deleted_at');
                    $capture($choiceMeetings, ['hs_committee_meetings.id', 'hs_committee_meetings.hs_committee_id', 'hs_committee_meetings.deleted_at',
                        'participation_choice_parent.site_id as parent_site_id', 'participation_choice_parent.deleted_at as parent_deleted_at'],
                        ['parent_site_id' => 'participation_choice_parent.site_id', 'parent_deleted_at' => 'participation_choice_parent.deleted_at']);
                }
                foreach (self::SOURCES as $kind => [$class, $columns]) {
                    if ($rootKind !== null && ! $this->relevantFamily($kind, $rootKind)) { continue; }
                    $rows = $kind === $rootKind ? $first : $metadata($sourceQuery($kind), $kind);
                    foreach ($rows as $row) {
                        $sourceSite = $kind === 'meetings' ? $row['parent_site_id'] : $row['site_id'];
                        $sourceSites[$kind][(int) $row['id']] = $sourceSite === null ? null : (int) $sourceSite;
                    }
                }
                $meetingIds = $sourceQuery('meetings')->select('hs_committee_meetings.id');
                $capture(DB::table('hs_meeting_attendees')->whereExists($meetingIds->whereColumn('hs_committee_meetings.id', 'hs_meeting_attendees.meeting_id')->selectRaw('1')->toBase()),
                    ['id', 'meeting_id', 'user_id', 'response', 'attended']);
                $this->frames[$context] = ['actor' => $current, 'reads' => $reads, 'proof' => $proof, 'site_ids' => $siteIds, 'approved_site_ids' => $approved,
                    'wide' => $wide, 'allow_null' => $allowNull, 'barrier' => $barrier, 'timezone' => $timezone, 'day' => $day, 'source_sites' => $sourceSites,
                    'root_kind' => $rootKind, 'root_id' => $rootId, 'requested_site_id' => $selectedSiteId, 'requested_subset' => $requestedSubset, 'include_pickers' => $includePickers, 'choice_sites' => $choiceSites];
            });
        }
    }

    /** Counts, filters and row bodies share this complete original source predicate. */
    public function query(string $kind, HealthSafetyReadContext $context, ?int $selectedSiteId = null): Builder
    {
        [$class] = $this->source($kind);
        $this->prepare($context, $selectedSiteId);
        $frame = $this->frame($context);
        return $this->protect($class::query(), $kind, $context, $frame['actor'], $frame['reads'], $frame['proof'], $selectedSiteId);
    }

    /** Only the canonical source model is accepted. Related names use relatedQuery. */
    public function protect(Builder $actual, string $kind, HealthSafetyReadContext $context, User $receiver, CurrentAuthorizationReads $reads, HealthSafetyReadMaterialisationProof $proof, ?int $selectedSiteId = null, ?int $rootId = null): Builder
    {
        [$class] = $this->source($kind);
        if (get_class($actual->getModel()) !== $class) { throw new LogicException('Participation source protection requires its canonical model.'); }
        $scope = $this->prepare($context, $selectedSiteId);
        $frame = $this->frame($context, $receiver, $reads, $proof);
        $actual = $this->rootFilter($this->siteScope($actual, $kind, $scope['site_ids'], $scope['includes_unconfirmed_site']), $kind, $frame['root_kind'], $frame['root_id']);
        if ($rootId !== null) { $actual->whereKey($rootId); }
        return $this->attach($actual, $context);
    }

    /** Fixed relationships correlate every actual name/support query to its admitted parent. */
    public function relatedQuery(string $kind, string $relation, HealthSafetyReadContext $context, ?int $selectedSiteId = null, ?int $rootId = null): Builder
    {
        $parent = $this->query($kind, $context, $selectedSiteId);
        if ($rootId !== null) { $parent->whereKey($rootId); }
        $table = $parent->getModel()->getTable();
        $columns = ['creator' => 'created_by', 'updater' => 'updated_by'];
        if ($kind === 'representatives') { $columns['user'] = 'user_id'; }
        if ($kind === 'consultations') { $columns['initiator'] = 'initiated_by'; }
        if ($kind === 'meetings') { $columns['recorder'] = 'recorded_by'; }
        if (isset($columns[$relation])) {
            $actual = User::query()->select(['users.id', 'users.name']);
            $parent->whereColumn($table.'.'.$columns[$relation], 'users.id');
        } elseif ($relation === 'site') {
            $actual = Site::query()->select(['sites.id', 'sites.name']);
            if ($kind === 'meetings') { $parent->whereHas('committee', fn (Builder $committee) => $committee->whereColumn('hs_committees.site_id', 'sites.id')); }
            else { $parent->whereColumn($table.'.site_id', 'sites.id'); }
        } elseif ($kind === 'meetings' && $relation === 'committee') {
            $actual = HsCommittee::query();
            $parent->whereColumn('hs_committee_meetings.hs_committee_id', 'hs_committees.id');
        } elseif ($kind === 'committees' && $relation === 'meetings') {
            $actual = $this->query('meetings', $context, $selectedSiteId);
            $parent->whereColumn('hs_committees.id', 'hs_committee_meetings.hs_committee_id');
        } elseif ($kind === 'meetings' && $relation === 'attendees') {
            $actual = User::query()->select(['users.id', 'users.name']);
            $parent->whereExists(DB::table('hs_meeting_attendees')->whereColumn('hs_meeting_attendees.meeting_id', 'hs_committee_meetings.id')->whereColumn('hs_meeting_attendees.user_id', 'users.id')->selectRaw('1'));
        } elseif (($kind === 'committees' && $relation === 'members') || ($kind === 'consultations' && $relation === 'workersConsulted')) {
            $actual = User::query()->select(['users.id', 'users.name']);
            $column = $kind === 'committees' ? 'members' : 'workers_consulted';
            $json = 'COALESCE('.$parent->getQuery()->getGrammar()->wrap($table.'.'.$column).', JSON_ARRAY())';
            $parent->whereRaw('(JSON_CONTAINS('.$json.', JSON_ARRAY(`users`.`id`)) OR JSON_CONTAINS('.$json.', JSON_ARRAY(CAST(`users`.`id` AS CHAR))))');
        } else { throw new LogicException('Unknown participation source relationship.'); }
        return $this->attach($actual->whereExists($parent->selectRaw('1')->toBase()), $context);
    }

    /** Creation choices are distinct from historical attribution and require current management. */
    public function pickerUsers(HealthSafetyReadContext $context, int|array|null $siteIds = null): Builder
    {
        $frame = $this->frame($context);
        if (! $frame['include_pickers']) { throw new LogicException('Participation picker subjects must be prepared before body materialisation.'); }
        abort_unless($frame['actor']->canDo('hazards.manage'), 403);
        $sites = $siteIds === null ? $frame['approved_site_ids'] : array_values(array_intersect($frame['approved_site_ids'], (array) $siteIds));
        return $this->attach($this->eligibleUsers($sites)->select(['users.id', 'users.name']), $context);
    }

    /** Filter/form choices are independently declared before source body materialisation. */
    public function sitesQuery(HealthSafetyReadContext $context, ?int $selectedSiteId = null): Builder
    {
        $frame = $this->frame($context);
        if (! $frame['include_pickers']) { throw new LogicException('Participation Site choices must be prepared before body materialisation.'); }
        $sites = $selectedSiteId === null ? $frame['approved_site_ids'] : array_values(array_intersect($frame['approved_site_ids'], [$selectedSiteId]));
        return $this->attach(Site::query()->select(['sites.id', 'sites.name'])->active()->notArchived()->whereNull('archived_at')->whereIn('id', $sites), $context);
    }

    /** Metric denominator only; no form picker or management authority is implied. */
    public function siteDenominator(HealthSafetyReadContext $context, ?array $requestedSiteIds = null): int
    {
        $frame = $this->frame($context);
        $sites = $requestedSiteIds === null ? $frame['site_ids'] : array_values(array_intersect($frame['site_ids'], $requestedSiteIds));
        return $this->attach(Site::query()->active()->notArchived()->whereNull('archived_at')->whereIn('id', $sites), $context)->count();
    }

    public function committeeChoicesQuery(HealthSafetyReadContext $context): Builder
    {
        $frame = $this->choiceFrame($context);
        return $this->attach($this->siteScope(HsCommittee::query(), 'committees', $frame['approved_site_ids'], $frame['wide'])
            ->select(['hs_committees.id', 'hs_committees.name', 'hs_committees.site_id', 'hs_committees.meeting_frequency']), $context);
    }

    /** Count blocks are actual current-read queries, never expired/early-compiled withCount SQL. */
    public function committeeChoiceMeetingCountsQuery(HealthSafetyReadContext $context): Builder
    {
        $frame = $this->choiceFrame($context);
        return $this->attach($this->siteScope(HsCommitteeMeeting::query(), 'meetings', $frame['approved_site_ids'], $frame['wide'])
            ->selectRaw('hs_committee_id, COUNT(*) AS meetings_count')->groupBy('hs_committee_id'), $context);
    }

    public function committeeChoices(HealthSafetyReadContext $context): array
    {
        $counts = $this->committeeChoiceMeetingCountsQuery($context)->get()->pluck('meetings_count', 'hs_committee_id')->all();
        $rows = $this->committeeChoicesQuery($context)->orderBy('hs_committees.name')->get()->map(fn ($row) => [
            'id' => (int) $row->id, 'name' => $row->name, 'site_id' => $row->site_id === null ? null : (int) $row->site_id,
            'meeting_frequency' => $row->meeting_frequency, 'meetings_count' => (int) ($counts[$row->id] ?? 0),
            'site_ownership' => $row->site_id === null ? 'unconfirmed' : 'recorded',
        ])->all();
        $this->assertBarrier($context);
        return $rows;
    }

    private function choiceFrame(HealthSafetyReadContext $context): array
    {
        $frame = $this->frame($context);
        if (! $frame['include_pickers']) { throw new LogicException('Participation choices must be declared before body materialisation.'); }
        abort_unless($frame['actor']->canDo('hazards.manage'), 403);
        return $frame;
    }

    private function eligibleUsers(array $siteIds): Builder
    {
        return $this->staff->currentUsersQuery()->whereHas('hrEmployeeProfile', fn (Builder $profile) => $profile->where(function (Builder $sites) use ($siteIds): void {
            $sites->whereIn('primary_site_id', $siteIds);
            foreach ($siteIds as $siteId) { $sites->orWhereJsonContains('secondary_site_ids', $siteId); }
        }));
    }

    /** Server-only equality operand for a later independently admitted read, never a grant. */
    public function sourceStamp(HealthSafetyReadContext $context, ?int $selectedSiteId = null): string
    {
        $this->prepare($context, $selectedSiteId);
        $this->assertBarrier($context);
        $frame = $this->frame($context);
        return hash('sha256', json_encode(['sql' => (clone $frame['barrier'])->toSql(), 'bindings' => $frame['barrier']->getBindings(),
            'timezone' => $frame['timezone'], 'day' => $frame['day'], 'selected_site_id' => $selectedSiteId,
            'root_kind' => $frame['root_kind'], 'root_id' => $frame['root_id'], 'requested_subset' => $frame['requested_subset'], 'include_pickers' => $frame['include_pickers']], JSON_THROW_ON_ERROR));
    }

    /** Identity metadata only. Readable null ownership remains unconfirmed, never intentional-global provenance. */
    public function sourceSiteId(string $kind, HealthSafetyReadContext $context, int $rootId): ?int
    {
        $this->source($kind);
        $this->prepare($context);
        $this->assertBarrier($context);
        $frame = $this->frame($context);
        abort_unless(array_key_exists($rootId, $frame['source_sites'][$kind] ?? []), 404);
        return $frame['source_sites'][$kind][$rootId];
    }

    public function sourceOwnership(string $kind, HealthSafetyReadContext $context, int $rootId): string
    {
        return $this->sourceSiteId($kind, $context, $rootId) === null ? 'unconfirmed' : 'recorded';
    }

    /** Batch flags for emitted rows; present Site means recorded ownership, not immutable historical proof. */
    public function sourceOwnerships(string $kind, HealthSafetyReadContext $context): array
    {
        $this->source($kind);
        $this->assertBarrier($context);
        return array_map(fn ($site) => $site === null ? 'unconfirmed' : 'recorded', $this->frame($context)['source_sites'][$kind] ?? []);
    }

    private function siteScope(Builder $query, string $kind, array $sites, bool $allowNull = false, bool $allActive = false): Builder
    {
        $scope = static function (Builder $root) use ($sites, $allowNull, $allActive): void {
            $root->where(function (Builder $audience) use ($sites, $allowNull, $allActive): void {
                $audience->where(function (Builder $owned) use ($sites, $allActive): void {
                    if (! $allActive) { $owned->whereIn($owned->qualifyColumn('site_id'), $sites); }
                    $owned->whereHas('site', fn (Builder $site) => $site->active()->notArchived()->whereNull('archived_at'));
                });
                if ($allowNull) { $audience->orWhereNull($audience->qualifyColumn('site_id')); }
            });
        };
        return $kind === 'meetings' ? $query->whereHas('committee', $scope) : $query->where($scope);
    }

    private function relevantFamily(string $kind, string $rootKind): bool
    {
        return $kind === $rootKind || (in_array($rootKind, ['committees', 'meetings'], true) && in_array($kind, ['committees', 'meetings'], true));
    }

    private function rootFilter(Builder $query, string $kind, ?string $rootKind, ?int $rootId): Builder
    {
        if ($rootKind === null) { return $query; }
        if ($kind === $rootKind) { return $query->whereKey($rootId); }
        if ($rootKind === 'meetings' && $kind === 'committees') { return $query->whereHas('meetings', fn (Builder $meeting) => $meeting->whereKey($rootId)); }
        if ($rootKind === 'committees' && $kind === 'meetings') { return $query->where('hs_committee_id', $rootId); }
        return $query->whereRaw('1 = 0');
    }

    private function attach(Builder $query, HealthSafetyReadContext $context): Builder
    {
        $frame = $this->frame($context);
        $query->whereExists((clone $frame['barrier'])->selectRaw('1')->toBase());
        $query->whereRaw(new class($this, $context) extends Expression {
            public function __construct(private WorkerParticipationAccessService $access, private HealthSafetyReadContext $context) { parent::__construct('1 = 1'); }
            public function getValue(\Illuminate\Database\Grammar $grammar) {
                $this->access->assertOriginal($this->context);
                return parent::getValue($grammar);
            }
        });
        $frame['reads']->query($query);
        // CurrentReads callbacks alone do not run for a Builder embedded under another query.
        // The Expression above retains lifetime; the barrier also remains on actual SQL.
        $query->getQuery()->afterQuery(function ($rows) use ($context) {
            $this->assertBarrier($context);
            return $rows;
        });
        return $frame['proof']->protect($query);
    }

    /** Used by the scoped compiler expression, including nested EXISTS under fresh reads. */
    public function assertOriginal(HealthSafetyReadContext $context): void
    {
        $frame = $this->frame($context);
        (clone $frame['barrier'])->toSql();
    }

    private function assertBarrier(HealthSafetyReadContext $context): void
    {
        $frame = $this->frame($context);
        abort_unless((clone $frame['barrier'])->exists(), 404, 'Participation source evidence changed.');
    }

    private function frame(HealthSafetyReadContext $context, ?User $receiver = null, ?CurrentAuthorizationReads $reads = null, ?HealthSafetyReadMaterialisationProof $proof = null): array
    {
        $context->assertActive();
        $frame = $this->frames[$context] ?? null;
        if ($frame === null) { throw new LogicException('Participation source evidence was not prepared.'); }
        $frame['reads']->assertActive();
        $context->assertViewer($frame['actor']);
        if (HealthSafetyReadMaterialisationProof::current($context) !== $frame['proof']
            || ($receiver !== null && $receiver !== $frame['actor']) || ($reads !== null && $reads !== $frame['reads'])
            || ($proof !== null && $proof !== $frame['proof'])) { throw new LogicException('Participation source must retain its original actor, reads and collector.'); }
        return $frame;
    }

    private function source(string $kind): array
    {
        return self::SOURCES[$kind] ?? throw new LogicException('Unknown participation source family.');
    }

    /** Full positive facts and a negative novel-identity guard, not an admitted ID whitelist. */
    private function conjunctSet(Builder $barrier, QueryBuilder $selector, array $rows, array $columnMap = []): void
    {
        foreach ($rows as $row) { $barrier->whereExists($this->exact(clone $selector, $row, $columnMap)->selectRaw('1')); }
        $extra = clone $selector;
        if ($rows !== []) { $extra->whereNot(function (QueryBuilder $known) use ($rows): void {
            foreach ($rows as $row) { $known->orWhere(fn (QueryBuilder $identity) => $identity->where($identity->from.'.id', $row['id'])); }
        }); }
        $barrier->whereNotExists($extra->selectRaw('1'));
    }

    private function exact(QueryBuilder $query, array $row, array $columnMap = []): QueryBuilder
    {
        foreach ($row as $column => $value) {
            $column = $columnMap[$column] ?? $query->from.'.'.$column;
            if ($value === null) { $query->whereNull($column); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
            else { $query->where($column, $value); }
        }
        return $query;
    }

    private function assertDetached(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $item) { $this->assertDetached($item); } return; }
        if ($value !== null && ! is_scalar($value)) { throw new LogicException('Participation materialisation must return detached scalar arrays.'); }
    }
}
