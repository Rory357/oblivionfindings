<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use WeakMap;
use WeakReference;

/** Canonical recipient purposes only; workforce notices never grant an uninvited detail view. */
final class WorkerParticipationRecipientSourceService
{
    public const PERMISSIONS = ['hazards.view', 'healthSafety.viewAllSites'];
    /** Request-bound fixed equality only; another service instance cannot refresh an earlier baseline. */
    private static ?WeakMap $receivers = null;

    /** Command observations are separate from immutable read receipts; no source handle escapes. */
    public function withManagerCommand(User $manager, string $rootKind, int $rootId, string $operation, callable $callback, ?HealthSafetyReadContext $context = null): mixed
    {
        $this->assertOwnReceiver($manager);
        $keys = [...self::PERMISSIONS, 'hazards.manage'];
        $consume = function (HealthSafetyReadContext $active) use ($manager, $rootKind, $rootId, $operation, $callback, $keys) {
            $active->assertViewer($manager);
            return HealthSafetyReadMaterialisationProof::within($active, fn ($proof) => $active->consumeSourceViewer($keys,
                function ($current, $reads) use ($active, $proof, $rootKind, $rootId, $operation, $callback) {
                    [$staff, $allowed, $wide, $receiver] = $this->managerBoundary($current, $active, $reads);
                    $authority = $active->originalSourceAuthorityQuery([...self::PERMISSIONS, 'hazards.manage'], [])
                        ->whereExists((clone $staff)->selectRaw('1')->toBase());
                    $factory = \Closure::bind(static fn (...$args) => new WorkerParticipationMeetingCommandContext(...$args), null, WorkerParticipationMeetingCommandContext::class);
                    $frame = $factory($current, $reads, $active, $proof, $authority, $allowed, $wide, $receiver, $rootKind, $rootId, $operation);
                    try {
                        $frame->initialize();
                        $result = $callback($current, $reads, $active, $proof, $frame);
                        $this->assertDetached($result);
                        $frame->assertOriginal();
                        return $result;
                    } finally { $frame->close(); }
                }));
        };
        return $context === null ? HealthSafetyReadContext::within($manager, $keys, $consume) : $consume($context);
    }

    /** One canonical manager policy shared by unchanged read and command purposes. */
    private function managerBoundary(User $current, HealthSafetyReadContext $context, CurrentAuthorizationReads $reads): array
    {
        $lease = WeakReference::create(request());
        $receiver = function () use ($lease, $current): void {
            if ($lease->get() !== request()) { throw new LogicException('Original manager request cannot transfer.'); }
            $this->assertOwnReceiver($current);
        };
        $receiver();
        abort_unless($current->approved_at !== null && $current->canDo('hazards.manage'), 403);
        $staff = $reads->query(User::query()->staff()->whereNotNull('approved_at'))->whereKey($current->id);
        abort_unless((clone $staff)->exists(), 403);
        $wide = $current->canDo('healthSafety.viewAllSites');
        $allowed = app(UserSiteAccessService::class)->accessibleSiteIds($current,
            $wide ? UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS : [], $reads);
        return [$staff, $allowed, $wide, $receiver];
    }

    /** Server-only discovery in the existing manager transaction; IDs are not recipient admission. */
    public function candidateRecipients(HsCommitteeMeeting $meeting, bool $includeWorkforce = true, ?HealthSafetyReadContext $originalContext = null, ?array $originalDescriptor = null): array
    {
        if (DB::transactionLevel() < 1) { throw new LogicException('Recipient discovery belongs to the existing meeting command transaction.'); }
        if (($originalContext === null) !== ($originalDescriptor === null)) {
            throw new LogicException('Nested recipient discovery requires both original context and descriptor.');
        }
        if ($originalContext !== null && HealthSafetyReadMaterialisationProof::current($originalContext) === null) {
            throw new LogicException('Nested recipient discovery requires its original active collector.');
        }
        $manager = request()->user();
        abort_unless($manager instanceof User && $meeting->exists && (int) $meeting->id > 0, 403);
        return $this->withManagerSource($manager, (int) $meeting->id,
            function ($current, $reads, $context, $proof, $descriptor, $source) use ($meeting, $includeWorkforce): array {
                $staff = app(HrCurrentStaffService::class)->currentUsersQuery()
                    ->whereExists((clone $source)->selectRaw('1')->toBase());
                $attendees = $this->guard((clone $staff)->whereExists(DB::table('hs_meeting_attendees')->where('meeting_id', $meeting->id)
                    ->whereColumn('user_id', 'users.id')->selectRaw('1')), $context, $reads, $proof)->orderBy('users.id')->get(['users.id'])->map(fn ($user) => (int) $user->id)->all();
                $candidates = array_map(fn ($id) => ['recipient_id' => $id, 'audience' => 'attendee'], $attendees);
                if ($includeWorkforce && $descriptor['site_id'] !== null) {
                    $workforce = $this->guard((clone $staff)->whereNotIn('users.id', $attendees)->whereHas('hrEmployeeProfile',
                        fn (Builder $profile) => $profile->where(fn (Builder $assigned) => $assigned->where('primary_site_id', $descriptor['site_id'])
                            ->orWhereJsonContains('secondary_site_ids', $descriptor['site_id'])
                            ->orWhereJsonContains('secondary_site_ids', (string) $descriptor['site_id']))), $context, $reads, $proof)
                        ->orderBy('users.id')->get(['users.id'])->map(fn ($user) => (int) $user->id)->all();
                    foreach ($workforce as $id) { $candidates[] = ['recipient_id' => $id, 'audience' => 'workforce_notice']; }
                }
                abort_unless((clone $source)->exists(), 404);
                usort($candidates, fn ($left, $right) => [$left['recipient_id'], $left['audience']] <=> [$right['recipient_id'], $right['audience']]);
                return $candidates;
            }, $originalContext, $originalDescriptor);
    }

    /** Original command-request manager only; does not impersonate recipients or grant old writers authority. */
    public function withManagerSource(User $manager, int $meetingId, callable $callback, ?HealthSafetyReadContext $context = null, ?array $expectedDescriptor = null): mixed
    {
        $this->assertOwnReceiver($manager); abort_unless($meetingId > 0, 404);
        $keys = [...self::PERMISSIONS, 'hazards.manage'];
        if ($context !== null && HealthSafetyReadMaterialisationProof::current($context) === null) {
            throw new LogicException('Supplied manager source requires its original active collector.');
        }
        $consume = function (HealthSafetyReadContext $active) use ($manager, $meetingId, $callback, $keys, $expectedDescriptor) {
            $active->assertViewer($manager);
            return HealthSafetyReadMaterialisationProof::within($active, fn ($proof) => $active->consumeSourceViewer($keys,
                function ($current, $reads) use ($active, $proof, $meetingId, $callback, $expectedDescriptor) {
                    [$descriptor, $scope] = $this->prepare($current, $meetingId, 'manager', $expectedDescriptor, $active, $reads, $proof);
                    $result = $callback($current, $reads, $active, $proof, $descriptor, clone $scope);
                    $this->assertDetached($result); $this->assertOwnReceiver($current);
                    abort_unless((clone $scope)->exists(), 404);
                    return $result;
                }));
        };
        return $context === null ? HealthSafetyReadContext::within($manager, $keys, $consume) : $consume($context);
    }

    /** Fixed callback arguments: current recipient, reads, context, proof, descriptor, protected source. */
    public function withSource(User $recipient, int $meetingId, string $audience, ?array $expectedDescriptor, callable $callback, ?HealthSafetyReadContext $context = null): mixed
    {
        abort_unless($meetingId > 0, 404);
        if (! in_array($audience, ['attendee', 'workforce_notice'], true)) { throw new LogicException('Unknown participation recipient purpose.'); }
        $consume = function (HealthSafetyReadContext $active) use ($recipient, $meetingId, $audience, $expectedDescriptor, $callback) {
            $active->assertViewer($recipient);
            return HealthSafetyReadMaterialisationProof::within($active, fn ($proof) => $active->consumeSourceViewer(self::PERMISSIONS,
                function ($current, $reads) use ($active, $proof, $meetingId, $audience, $expectedDescriptor, $callback) {
                    [$descriptor, $scope] = $this->prepare($current, $meetingId, $audience, $expectedDescriptor, $active, $reads, $proof);
                    $result = $callback($current, $reads, $active, $proof, $descriptor, clone $scope);
                    $this->assertDetached($result);
                    abort_unless((clone $scope)->exists(), 404);
                    return $result;
                }));
        };
        return $context === null ? HealthSafetyReadContext::within($recipient, self::PERMISSIONS, $consume) : $consume($context);
    }

    /** Inbox callers invoke once BEFORE their private delivery/notification identity discovery. */
    public function prepareReceiver(User $recipient, ?HealthSafetyReadContext $context = null): void
    {
        $this->assertOwnReceiver($recipient);
        $frames = self::$receivers ??= new WeakMap;
        if (isset($frames[request()])) {
            if ($context !== null) { $this->originalLifetime($recipient, $context)(); }
            $this->assertReceiverFrame($recipient, $frames[request()]); return;
        }
        if ($context !== null) { $this->originalLifetime($recipient, $context)(); }
        $capture = fn ($context) => $context->consumeSourceViewer(self::PERMISSIONS,
            function ($current, $reads) use ($context): array {
                abort_unless($reads->query(app(HrCurrentStaffService::class)->currentUsersQuery())->whereKey($current->id)->exists(), 404);
                $query = $context->originalSourceAuthorityQuery(self::PERMISSIONS, [])->whereExists(
                    $reads->query(app(HrCurrentStaffService::class)->currentUsersQuery())->whereKey($current->id)->selectRaw('1')->toBase());
                abort_unless((clone $query)->exists(), 404);
                [$sql, $bindings] = $this->fixedSql($query);
                $zone = (string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC'));
                return ['receiver_id' => (int) $current->id, 'sql' => $sql, 'bindings' => $bindings, 'timezone' => $zone, 'day' => now($zone)->toDateString()];
            });
        $parts = $context === null ? HealthSafetyReadContext::within($recipient, self::PERMISSIONS, $capture) : $capture($context);
        $frames[request()] = $parts;
    }

    /** Current request equality-frame presence only; never receiver admission or a permission. */
    public function hasPreparedReceiver(): bool
    {
        return isset((self::$receivers ??= new WeakMap)[request()]);
    }

    public function captureOrigin(User $recipient, int $meetingId, string $audience, ?array $expectedDescriptor = null, ?HealthSafetyReadContext $context = null): WorkerParticipationRecipientSourceOrigin
    {
        $origins = $this->captureOrigins($recipient, [['meeting_id' => $meetingId, 'audience' => $audience, 'expected_descriptor' => $expectedDescriptor]], $context);
        abort_unless(isset($origins[$meetingId.':'.$audience]), 404);
        return $origins[$meetingId.':'.$audience];
    }

    /** Caller supplies OWN source identities; this never discovers staff or recipient candidates. */
    public function captureOrigins(User $recipient, array $candidates, ?HealthSafetyReadContext $context = null): array
    {
        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ! is_int($candidate['meeting_id'] ?? null) || $candidate['meeting_id'] < 1
                || ! in_array($candidate['audience'] ?? null, ['attendee', 'workforce_notice'], true)
                || (isset($candidate['expected_descriptor']) && ! is_array($candidate['expected_descriptor']))) {
                throw new LogicException('Recipient origins require exact own source operands.');
            }
        }
        $this->prepareReceiver($recipient, $context);
        $frame = self::$receivers[request()]; $origins = [];
        $consume = function (HealthSafetyReadContext $active) use ($recipient, $candidates, $context, $frame, &$origins): array {
            $active->assertViewer($recipient);
            return HealthSafetyReadMaterialisationProof::within($active, fn ($proof) => $active->consumeSourceViewer(self::PERMISSIONS,
                function ($current, $reads) use ($active, $proof, $candidates, $context, $frame, &$origins): array {
                    $lifetime = $context === null ? null : $this->originalLifetime($current, $active);
                    foreach ($candidates as $candidate) {
                        $this->assertReceiverFrame($current, $frame);
                        try {
                            [$descriptor, $scope] = $this->prepare($current, $candidate['meeting_id'], $candidate['audience'], $candidate['expected_descriptor'] ?? null, $active, $reads, $proof);
                            [$sql, $bindings] = $this->fixedSql($scope);
                            $factory = \Closure::bind(static fn ($id, $meeting, $query, $values, $zone, $day, $request, $lease) =>
                                new WorkerParticipationRecipientSourceOrigin($id, $meeting, $query, $values, $zone, $day, $request, $lease), null, WorkerParticipationRecipientSourceOrigin::class);
                            $origins[$descriptor['meeting_id'].':'.$candidate['audience']] = $factory((int) $current->id, $descriptor['meeting_id'], $sql, $bindings,
                                $frame['timezone'], $frame['day'], WeakReference::create(request()), $lifetime);
                        } catch (HttpExceptionInterface $error) {
                            if ($error->getStatusCode() !== 404) { throw $error; }
                            // Conceal only a denied source; withdrawal of the original actor remains fatal.
                            $this->assertReceiverFrame($current, $frame);
                        }
                    }
                    $this->assertReceiverFrame($current, $frame);
                    return [];
                }));
        };
        $context === null ? HealthSafetyReadContext::within($recipient, self::PERMISSIONS, $consume) : $consume($context);
        foreach ($origins as $origin) { $origin->assertOriginal(); }
        return $origins;
    }

    private function originalLifetime(User $recipient, HealthSafetyReadContext $context): \Closure
    {
        $context->assertViewer($recipient);
        $proof = HealthSafetyReadMaterialisationProof::current($context);
        if ($proof === null) { throw new LogicException('Supplied recipient origins require their active original collector.'); }
        $contextLease = WeakReference::create($context); $proofLease = WeakReference::create($proof); $id = (int) $recipient->id;
        $readsLease = $context->consumeSourceViewer(self::PERMISSIONS, fn ($current, $reads) => WeakReference::create($reads));
        return static function () use ($contextLease, $proofLease, $readsLease, $id): void {
            $original = $contextLease->get();
            if ($original === null) { throw new LogicException('Original recipient context expired.'); }
            $original->consumeSourceViewer(self::PERMISSIONS, static function ($current, $reads) use ($readsLease, $id): void {
                if ((int) $current->id !== $id || $reads !== $readsLease->get()) { throw new LogicException('Original recipient reads cannot transfer.'); }
            });
            if (HealthSafetyReadMaterialisationProof::current($original) !== $proofLease->get()) { throw new LogicException('Original recipient collector cannot transfer.'); }
        };
    }

    private function prepare(User $current, int $meetingId, string $audience, ?array $expected, HealthSafetyReadContext $context, CurrentAuthorizationReads $reads, HealthSafetyReadMaterialisationProof $proof): array
    {
        $manager = $audience === 'manager';
        $keys = $manager ? [...self::PERMISSIONS, 'hazards.manage'] : self::PERMISSIONS;
        $requestLease = WeakReference::create(request());
        $receiver = $manager ? function () use ($requestLease, $current): void {
            if ($requestLease->get() !== request()) { throw new LogicException('Original manager request cannot transfer.'); }
            $this->assertOwnReceiver($current);
        } : null;
        if ($manager) {
            [$staff, $allowed, $wide, $receiver] = $this->managerBoundary($current, $context, $reads);
        } else {
            $staff = $reads->query(app(HrCurrentStaffService::class)->currentUsersQuery())->whereKey($current->id);
            abort_unless((clone $staff)->exists(), 404);
            $sites = app(UserSiteAccessService::class)->accessibleSiteIds($current, [], $reads);
            $wide = $audience === 'attendee' && $current->canDo('hazards.view') && $current->canDo('healthSafety.viewAllSites');
            $allowed = $wide ? app(UserSiteAccessService::class)->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads) : $sites;
        }
        $scope = $reads->query(HsCommitteeMeeting::query())->whereKey($meetingId)
            ->whereExists($context->originalSourceAuthorityQuery($keys, [])->selectRaw('1')->toBase())
            ->whereExists((clone $staff)->selectRaw('1')->toBase())
            ->whereHas('committee', function (Builder $committee) use ($allowed, $wide): void {
                $committee->where(fn (Builder $audience) => $wide ? $audience->whereIn('site_id', $allowed)->orWhereNull('site_id') : $audience->whereIn('site_id', $allowed));
            });
        if ($audience === 'attendee') {
            $scope->whereExists(DB::table('hs_meeting_attendees')->where('user_id', $current->id)->whereColumn('meeting_id', 'hs_committee_meetings.id')->selectRaw('1'));
        }
        if ($audience === 'workforce_notice') {
            // Current canonical Site admission remains required. Malformed historic JSON cannot
            // become assignment proof through PHP's integer coercion of e.g. "2x".
            $scope->whereHas('committee', fn (Builder $committee) => $committee->whereExists(HrEmployeeProfile::query()
                ->where('user_id', $current->id)->where(fn (Builder $assigned) => $assigned
                    ->whereColumn('primary_site_id', 'hs_committees.site_id')
                    ->orWhereRaw('JSON_CONTAINS(COALESCE(`secondary_site_ids`, JSON_ARRAY()), JSON_ARRAY(`hs_committees`.`site_id`))')
                    ->orWhereRaw('JSON_CONTAINS(COALESCE(`secondary_site_ids`, JSON_ARRAY()), JSON_ARRAY(CAST(`hs_committees`.`site_id` AS CHAR)))'))
                ->selectRaw('1')->toBase()));
        }
        if ($expected !== null) {
            $keys = array_keys($expected); sort($keys);
            abort_unless($keys === ['committee_id', 'committee_status', 'location', 'meeting_id', 'meeting_status', 'scheduled_at', 'site_id']
                && $expected['meeting_id'] === $meetingId && is_int($expected['committee_id']) && $expected['committee_id'] > 0
                && ($expected['site_id'] === null || (is_int($expected['site_id']) && $expected['site_id'] > 0))
                && is_string($expected['scheduled_at']) && is_string($expected['meeting_status']) && is_string($expected['committee_status'])
                && ($expected['location'] === null || is_string($expected['location'])), 404);
            $scope = $this->exact($scope, 'hs_committee_meetings', ['hs_committee_id' => $expected['committee_id'],
                'scheduled_at' => $expected['scheduled_at'], 'status' => $expected['meeting_status'], 'location' => $expected['location']]);
            $scope->whereHas('committee', fn (Builder $committee) => $this->exact($committee, 'hs_committees', [
                'id' => $expected['committee_id'], 'site_id' => $expected['site_id'], 'status' => $expected['committee_status']]));
        }
        if (! $manager && isset((self::$receivers ??= new WeakMap)[request()]) && (int) (request()->user()?->id ?? 0) === (int) $current->id) {
            $frame = self::$receivers[request()]; $this->assertReceiverFrame($current, $frame);
            $scope->whereRaw('EXISTS ('.$frame['sql'].')', $frame['bindings']);
        }
        $scope = $this->guard($scope, $context, $reads, $proof, $receiver);
        $root = (clone $scope)->first(['id', 'hs_committee_id', 'deleted_at']);
        abort_unless($root, 404);
        $scope = $this->exact($scope, 'hs_committee_meetings', $root->getRawOriginal());
        $committee = $this->guard(HsCommittee::query()->whereKey($root->hs_committee_id)->whereExists((clone $scope)->selectRaw('1')->toBase()), $context, $reads, $proof, $receiver)
            ->first(['id', 'site_id', 'status', 'deleted_at']);
        abort_unless($committee, 404);
        $scope->whereExists($this->exact(HsCommittee::query(), 'hs_committees', $committee->getRawOriginal())->selectRaw('1')->toBase());
        if ($committee->site_id !== null) {
            $site = $this->guard(Site::query()->whereKey($committee->site_id)->active()->notArchived()->whereNull('archived_at')
                ->whereExists((clone $scope)->selectRaw('1')->toBase()), $context, $reads, $proof, $receiver)->first(['id', 'is_active', 'archived', 'archived_at', 'deleted_at']);
            abort_unless($site, 404);
            $scope->whereExists($this->exact(Site::query(), 'sites', $site->getRawOriginal())->selectRaw('1')->toBase());
        }
        if ($audience === 'attendee') {
            $pivot = $reads->query(DB::table('hs_meeting_attendees')->where('meeting_id', $meetingId)->where('user_id', $current->id))
                ->whereExists((clone $scope)->selectRaw('1')->toBase())->first(['id', 'meeting_id', 'user_id', 'response', 'attended', 'created_at', 'updated_at']);
            abort_unless($pivot, 404);
            $scope->whereExists($this->exact(DB::table('hs_meeting_attendees'), '', (array) $pivot)->selectRaw('1'));
        }
        $meeting = (clone $scope)->first(['id', 'scheduled_at', 'status', 'location']);
        abort_unless($meeting, 404);
        $scope = $this->exact($scope, 'hs_committee_meetings', $meeting->getRawOriginal());
        $descriptor = ['meeting_id' => (int) $root->id, 'committee_id' => (int) $committee->id, 'site_id' => $committee->site_id === null ? null : (int) $committee->site_id,
            'scheduled_at' => $meeting->getRawOriginal('scheduled_at'), 'meeting_status' => $meeting->status, 'committee_status' => $committee->status, 'location' => $meeting->location];
        if ($expected !== null) {
            ksort($expected); $ordered = $descriptor; ksort($ordered);
            abort_unless($expected === $ordered, 404, 'Original meeting delivery descriptor no longer matches.');
        }
        abort_unless((clone $scope)->exists(), 404);
        return [$descriptor, $scope];
    }

    private function guard(Builder $query, HealthSafetyReadContext $context, CurrentAuthorizationReads $reads, HealthSafetyReadMaterialisationProof $proof, ?\Closure $receiver = null): Builder
    {
        $assert = static function () use ($context, $reads, $proof, $receiver): void {
            if ($receiver !== null) { $receiver(); }
            $context->consumeSourceViewer(self::PERMISSIONS, static function ($current, $original) use ($reads): void { if ($original !== $reads) { throw new LogicException('Recipient source reads cannot transfer.'); } });
            if (HealthSafetyReadMaterialisationProof::current($context) !== $proof) { throw new LogicException('Recipient source collector cannot transfer.'); }
        };
        $assert(); $query = $reads->query($query);
        $query->getQuery()->beforeQuery(static function () use ($assert): void { $assert(); });
        $query->getQuery()->afterQuery(static function ($rows) use ($assert) { $assert(); return $rows; });
        $query->whereRaw(new class($assert) extends \Illuminate\Database\Query\Expression {
            public function __construct(private readonly \Closure $assert) { parent::__construct('1 = 1'); }
            public function getValue(\Illuminate\Database\Grammar $grammar) { ($this->assert)(); return parent::getValue($grammar); }
        });
        return $proof->protect($query);
    }

    private function exact(Builder|QueryBuilder $query, string $table, array $values): Builder|QueryBuilder
    {
        $base = $query instanceof Builder ? $query->getQuery() : $query;
        foreach ($values as $column => $value) {
            $column = $table === '' ? $column : $table.'.'.$column;
            if ($value === null) { $query->whereNull($column); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$base->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
            else { $query->where($column, $value); }
        }
        return $query;
    }

    private function fixedSql(Builder $query): array
    {
        $base = (clone $query)->selectRaw('1')->toBase();
        $base->applyBeforeQueryCallbacks(); // Explicitly retain current locks on every nested block before scalar capture.
        return [$base->toSql(), $base->getBindings()];
    }

    private function assertOwnReceiver(User $recipient): void
    {
        if ((int) (request()->user()?->id ?? 0) !== (int) $recipient->id || (int) (auth()->id() ?? 0) !== (int) $recipient->id) { throw new LogicException('Own recipient capture requires its authenticated request.'); }
    }

    private function assertReceiverFrame(User $recipient, array $frame): void
    {
        $this->assertOwnReceiver($recipient);
        $zone = (string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC'));
        abort_unless($frame['receiver_id'] === (int) $recipient->id && $zone === $frame['timezone'] && now($zone)->toDateString() === $frame['day'], 404);
        abort_unless((bool) (DB::selectOne('SELECT EXISTS ('.$frame['sql'].') AS admitted', $frame['bindings'])->admitted ?? false), 404);
    }

    private function assertDetached(mixed $result): void
    {
        if (is_array($result)) { foreach ($result as $value) { $this->assertDetached($value); } }
        elseif (is_object($result) || is_resource($result)) { throw new LogicException('Recipient callbacks return detached scalar results only.'); }
    }
}
