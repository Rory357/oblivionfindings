<?php

namespace App\Domain\Rostering\AutoSchedule;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;

/** Current planning authority, preserving the existing Site bypass and source predicates. */
final class RosterSuggestionEvidence
{
    private const KEYS = ['rostering.autoSchedule', 'shifts.manageAny'];

    public function __construct(private readonly AuthorizationEvidenceLockService $authorization, private readonly UserSiteAccessService $sites) {}

    public function assertRun(RosterSuggestionRun $run, User $actor): User
    {
        $current = $this->authorization->lockForUserWithoutWaiting($actor, self::KEYS);
        abort_unless($current->isApproved() && $current->canDo('rostering.autoSchedule'), 403);
        CurrentAuthorizationReads::within(function ($reads) use ($run, $current): void {
            $accessible = $this->sites->accessibleSiteIds($current, ['shifts.manageAny'], $reads);
            abort_unless((int) $run->site_id > 0 && in_array((int) $run->site_id, $accessible, true), 403);
        });

        return $current;
    }

    public function lockSingle(RosterSuggestion $bound, RosterSuggestionRun $runHint, User $actor): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Suggestion evidence requires its transaction.');
        }
        $run = RosterSuggestionRun::query()->whereKey($runHint->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $run->site_id === (int) $runHint->getRawOriginal('site_id'), 409, 'This suggestion run changed Site. Reload before acting.');
        $row = RosterSuggestion::query()->whereKey($bound->id)->lockForUpdate()->firstOrFail();
        foreach (['roster_suggestion_run_id', 'shift_id', 'candidate_user_id'] as $key) {
            abort_unless(RosterSuggestionSource::id($row->getRawOriginal($key)) === RosterSuggestionSource::id($bound->getRawOriginal($key)), 409, 'This suggestion changed source. Reload before acting.');
        }
        abort_unless((int) $row->roster_suggestion_run_id === (int) $run->id, 409);
        $hint = Shift::query()->whereKey($row->shift_id)->first();
        abort_unless($hint, 403);
        // Hints select the prefix; only the locked tuple can authorize a write.
        $context = $hint->service_context_id ? ServiceContext::query()->whereKey($hint->service_context_id)->lockForUpdate()->first() : null;
        $client = $hint->client_id ? Client::query()->whereKey($hint->client_id)->lockForUpdate()->first() : null;
        $shift = Shift::query()->employeeDuties()->whereKey($hint->id)->lockForUpdate()->first();
        abort_unless($shift, 403);
        foreach (['client_id', 'site_id', 'service_context_id', 'user_id'] as $key) {
            abort_unless(RosterSuggestionSource::id($hint->getRawOriginal($key)) === RosterSuggestionSource::id($shift->getRawOriginal($key)), 409, 'The suggested duty changed while waiting. Reload before acting.');
        }
        abort_unless($shift->client_id === null || ($client && (int) $client->site_id === (int) $run->site_id), 403);
        $siteId = (int) ($shift->site_id ?? $client?->site_id);
        abort_unless($siteId > 0 && $siteId === (int) $run->site_id, 403);
        $ids = collect([$actor->id, $shift->user_id])->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $users = $ids->mapWithKeys(fn ($id) => [$id => $this->authorization->lockForUserWithoutWaiting($id, self::KEYS)]);
        $profiles = CurrentAuthorizationReads::within(fn ($reads) => $reads->query(HrEmployeeProfile::query()->whereIn('user_id', $ids)->orderBy('user_id'))->get()->keyBy('user_id'));
        foreach ($users as $user) {
            $user->setRelation('hrEmployeeProfile', $profiles->get($user->id));
        }
        $current = $users->get((int) $actor->id);
        $current = $this->assertRun($run, $current);
        if ($shift->user_id !== null) {
            CurrentAuthorizationReads::within(function ($reads) use ($shift, $siteId): void {
                abort_unless($reads->query($this->sites->applyFleetRecipientEligibility(User::query()->whereKey($shift->user_id), $siteId))->exists(), 403);
            });
        }
        $shift->setRelation('client', $client)->setRelation('serviceContext', $context);
        $row->setRelation('run', $run)->setRelation('shift', $shift);

        return ['run' => $run, 'suggestion' => $row, 'shift' => $shift, 'actor' => $current];
    }

    public function assertExpected(?array $expected, array $actual): void
    {
        abort_unless($expected === null || $expected === $actual, 409, 'The displayed suggestion changed. Reload before acting.');
    }
}
