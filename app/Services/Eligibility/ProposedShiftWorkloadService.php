<?php

namespace App\Services\Eligibility;

use App\Domain\Hr\Services\HrFatiguePolicySettings;
use App\Models\Shift;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Operations\WorkforceMutationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ProposedShiftWorkloadService
{
    public function __construct(
        private readonly LocalWorkTimeSegmenter $segments,
        private readonly HrFatiguePolicySettings $policies,
    ) {}

    /** Advisory compatibility; mutations explicitly request current evidence. */
    public function prepare(Collection $proposed, array $replacedShiftIds = []): Collection
    {
        return $this->build($proposed, $replacedShiftIds);
    }

    /** @return Collection<int, PreparedShiftWorkload> */
    public function prepareCurrent(Collection $proposed, array $replacedShiftIds = [], ?User $actor = null): Collection
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction() || ! $actor?->id) {
            throw new LogicException('Current assignment workload requires its actor and governing transaction.');
        }
        app(WorkforceMutationGuard::class)->lock();

        return CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => $this->build($proposed, $replacedShiftIds, $reads, $actor));
    }

    /** Current interval revalidation does not replace the batch's one policy snapshot. */
    public function assertCurrentFor(PreparedShiftWorkload $workload, Shift $candidate, User $user, User $actor): void
    {
        $workload->assertFor($candidate, $user);
        $evidence = $workload->currentEvidence;
        if (! $evidence || (int) $evidence['actor_id'] !== (int) $actor->id
            || DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw new LogicException('The prepared workload is not current command evidence.');
        }
        app(WorkforceMutationGuard::class)->lock();
        CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($evidence): void {
            $rows = $this->existing($evidence['user_ids'], $evidence['replaced_ids'], $evidence['from'], $evidence['to'], $reads);
            if ($this->signature($rows) !== $evidence['interval_signature']) {
                throw new LogicException('The prepared assignment workload changed before saving.');
            }
        });
    }

    private function build(Collection $proposed, array $replacedShiftIds, ?CurrentAuthorizationReads $reads = null, ?User $actor = null): Collection
    {
        $assigned = $proposed->filter(fn (Shift $shift) => $shift->user_id && $shift->starts_at && $shift->ends_at);
        if ($assigned->isEmpty()) {
            return collect();
        }
        $policy = $this->policies->values();
        $padding = max(2, $policy['max_consecutive_days'] + 2,
            (int) ceil($policy['min_rest_between_shifts_hours'] / 24) + 1);
        $from = CarbonImmutable::instance($assigned->min('starts_at'))->setTimezone($this->segments->timezone())
            ->startOfWeek(CarbonImmutable::MONDAY)->subDays($padding)->utc();
        $to = CarbonImmutable::instance($assigned->max('ends_at'))->setTimezone($this->segments->timezone())
            ->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->addDays($padding)->utc();
        $userIds = $assigned->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $replaced = collect([...$replacedShiftIds, ...$assigned->pluck('id')->all()])
            ->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->sort()->values()->all();
        $rows = $this->existing($userIds, $replaced, $from, $to, $reads);
        $existing = $rows->groupBy('user_id');
        $evidence = $reads ? ['actor_id' => (int) $actor->id, 'user_ids' => $userIds,
            'replaced_ids' => $replaced, 'from' => $from, 'to' => $to,
            'interval_signature' => $this->signature($rows)] : null;

        return $assigned->groupBy('user_id')->map(fn (Collection $candidates, int $userId) => new PreparedShiftWorkload(
            $existing->get($userId, collect())->concat($candidates)->values(),
            $this->segments, $policy, $userId, $candidates, $evidence,
        ));
    }

    private function existing(array $userIds, array $replaced, mixed $from, mixed $to, ?CurrentAuthorizationReads $reads): Collection
    {
        $query = Shift::query()->whereIn('user_id', $userIds)->whereNotIn('status', ['cancelled'])
            ->whereNotIn('id', $replaced)->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->orderBy('id')->with(['client' => function ($relation) use ($reads): void {
                // Eloquent eager constraints receive the Relation, not its builder.
                $query = $relation->getQuery();
                ($reads ? $reads->query($query) : $query)->select(['id', 'first_name', 'last_name']);
            }]);

        return ($reads ? $reads->query($query) : $query)->get([
            'id', 'user_id', 'client_id', 'site_id', 'service_context_id', 'status', 'starts_at', 'ends_at',
        ]);
    }

    private function signature(Collection $rows): string
    {
        return hash('sha256', json_encode($rows->map(fn (Shift $row) => [
            'id' => (int) $row->id, 'user_id' => (int) $row->user_id,
            'client_id' => (int) $row->client_id, 'site_id' => (int) $row->site_id,
            'service_context_id' => $row->service_context_id === null ? null : (int) $row->service_context_id,
            'status' => $row->status, 'starts_at' => $row->starts_at?->copy()->utc()->toJSON(),
            'ends_at' => $row->ends_at?->copy()->utc()->toJSON(),
        ])->all(), JSON_THROW_ON_ERROR));
    }
}
