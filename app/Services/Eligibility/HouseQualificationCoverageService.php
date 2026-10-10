<?php

namespace App\Services\Eligibility;

use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Models\Client;
use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteStaffRequirement;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Operations\WorkforceMutationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Qualification coverage is distinct from Site headcount/role coverage and actual attendance. */
final class HouseQualificationCoverageService
{
    public function __construct(
        private readonly WorkforceRequirementMapping $mapping,
        private readonly WorkforceQualificationEvidence $evidence,
        private readonly HrEligibilityRuleSettings $settings,
    ) {}

    /** Planning may build a K-person roster one assignment at a time. */
    public function evaluateAll(Shift $shift, User $user, bool $current = false): array
    {
        return $this->withReads($current, fn ($reads) => $this->assignmentChecks($shift, $user, $reads));
    }

    /** Mandatory complete-period coverage is enforced only at publication/review. */
    public function validatePeriod(RosterPeriod $period, bool $current = false): array
    {
        return $this->withReads($current, function ($reads) use ($period): array {
            if ($reads) {
                $period = $this->query(RosterPeriod::query()->whereKey($period->id), $reads)->firstOrFail();
            }
            $timezone = (string) (config('app.worker_timezone') ?: (config('app.timezone') ?: 'UTC'));
            $start = CarbonImmutable::parse($period->week_start->toDateString(), $timezone)->startOfDay()->utc();
            $end = $start->setTimezone($timezone)->addDays(7)->utc();
            $siteId = (int) $period->site_id;
            $site = $this->query(Site::query()->whereKey($siteId), $reads)->first();
            $requirements = $this->requirements($siteId, $reads);
            $duties = $this->duties($siteId, $start, $end, $reads);
            // Existing publication skips completed/cancelled individual review.
            // Do not turn already completed history into new qualification demand.
            $demand = $duties->filter(fn (Shift $row) => $row->status !== 'completed'
                && ($row->roster_period_id === null || (int) $row->roster_period_id === (int) $period->id));
            $workers = $this->workers($duties->pluck('user_id')->filter()->unique(), $reads);
            $policy = $this->settings->snapshot(current: $reads !== null)['values'];
            $blocks = [];
            $warnings = [];

            foreach ($requirements as $requirement) {
                if ($demand->isEmpty()) {
                    continue;
                }
                $mapping = $this->mapping->present($requirement);
                $issues = $this->configurationIssues($requirement, $mapping, $policy);
                if ($issues !== []) {
                    foreach ($issues as $issue) {
                        $entry = $this->periodEntry($period, $site?->name, $requirement, $issue['message']);
                        $issue['severity'] === 'block' ? $blocks[] = $entry : $warnings[] = $entry;
                    }

                    continue;
                }
                if ($requirement->applicability_mode !== 'minimum_staff') {
                    // Existing per-worker validator checks all_workers; this
                    // collective seam does not reclassify those/history checks.
                    continue;
                }
                $shortages = $this->shortages($requirement, $demand, $duties, $workers, $start, $end, $reads);
                foreach ($shortages as $shortage) {
                    $entry = [...$this->periodEntry($period, $site?->name, $requirement,
                        $this->shortageMessage($requirement)), ...$shortage];
                    $requirement->category === 'mandatory' ? $blocks[] = $entry : $warnings[] = $entry;
                }
            }

            return ['blocks' => $blocks, 'warnings' => $warnings];
        });
    }

    private function assignmentChecks(Shift $shift, User $user, ?CurrentAuthorizationReads $reads): array
    {
        $siteId = $this->siteId($shift, $reads);
        if (! $siteId) {
            return [];
        }
        $requirements = $this->requirements($siteId, $reads);
        if ($requirements->isEmpty()) {
            return [];
        }
        if (! $shift->starts_at || ! $shift->ends_at || $shift->ends_at->lessThanOrEqualTo($shift->starts_at)) {
            return [$this->check(null, false, 'block', 'The house qualification duty window is invalid.')];
        }
        $start = CarbonImmutable::instance($shift->starts_at)->utc();
        $end = CarbonImmutable::instance($shift->ends_at)->utc();
        $policy = $this->settings->snapshot(current: $reads !== null)['values'];
        $checks = [];
        $duties = null;
        $workers = null;
        foreach ($requirements as $requirement) {
            $mapping = $this->mapping->present($requirement);
            $issues = $this->configurationIssues($requirement, $mapping, $policy);
            if ($issues !== []) {
                foreach ($issues as $issue) {
                    $checks[] = [...$this->check($requirement, false, $issue['severity'], $issue['message']),
                        'requires_assignment_acknowledgement' => $issue['requires_assignment_acknowledgement'] ?? false];
                }

                continue;
            }
            if ($requirement->applicability_mode === 'all_workers') {
                $result = $this->evidence->check($user, collect([$requirement->hrComplianceRequirement]), $shift, $reads !== null);
                foreach ($result['failures'] as $failure) {
                    $checks[] = $this->check($requirement, false,
                        $requirement->category === 'mandatory' ? 'block' : 'warning',
                        $requirement->requirement_name.': '.$failure['reason']);
                }
                if ($result['passed']) {
                    $checks[] = $this->check($requirement, true, 'block', null);
                }

                continue;
            }
            if ($duties === null) {
                // Replace this exact existing occurrence rather than counting
                // its old worker plus the proposed recipient as two staff.
                $duties = $this->duties($siteId, $start, $end, $reads)
                    ->reject(fn (Shift $row) => $shift->id && (int) $row->id === (int) $shift->id)
                    ->values();
                $candidate = clone $shift;
                $candidate->user_id = (int) $user->id;
                $candidate->setRelation('staff', $user);
                $duties->push($candidate);
                $workers = $this->workers($duties->pluck('user_id')->filter()->unique(), $reads);
            }
            $shortages = $this->shortages($requirement, collect([$shift]), $duties, $workers, $start, $end, $reads);
            $checks[] = [...$this->check($requirement, $shortages === [], 'warning',
                $shortages === [] ? null : $this->shortageMessage($requirement)), 'coverage_shortages' => $shortages];
        }

        return $checks;
    }

    private function configurationIssues(SiteStaffRequirement $requirement, array $mapping, array $policy): array
    {
        $issues = [];
        if (! $requirement->hasConfiguredApplicability()) {
            $issues[] = ['severity' => 'warning', 'message' => $requirement->requirement_name
                .': configure whether every worker or a minimum qualified count must cover the duty. Coverage is unresolved.',
                'requires_assignment_acknowledgement' => $requirement->category === 'mandatory'];
        }
        if (! in_array($mapping['status'], ['configured', 'unmapped'], true)) {
            if ($requirement->category === 'mandatory') {
                throw new \RuntimeException('The configured House qualification evidence is unavailable.');
            }
            $issues[] = ['severity' => 'warning', 'message' => $requirement->requirement_name
                .': the configured qualification evidence is unavailable. Review its recorded mapping.',
                'requires_assignment_acknowledgement' => false];
        }
        if ($mapping['status'] === 'unmapped') {
            $issues[] = ['severity' => $requirement->category === 'mandatory'
                && $policy['unmapped_mandatory_qualification'] === 'block' ? 'block' : 'warning',
                'message' => $requirement->requirement_name.': the qualification evidence mapping needs to be configured.',
                'requires_assignment_acknowledgement' => $requirement->category === 'mandatory'
                    && $policy['unmapped_mandatory_qualification'] === 'warn'];
        }

        return $issues;
    }

    /**
     * Each nonempty duty slice must have K distinct staff whose canonical
     * qualification covers that entire slice. Split duties can hand over;
     * duplicate/overlapping duties for one worker never increase the count.
     */
    private function shortages(
        SiteStaffRequirement $requirement, Collection $demand, Collection $duties,
        Collection $workers, CarbonImmutable $start, CarbonImmutable $end, ?CurrentAuthorizationReads $reads,
    ): array {
        $edges = collect([$start, $end]);
        foreach ($demand->concat($duties) as $duty) {
            if ($duty->starts_at && $duty->ends_at && $duty->starts_at->lessThan($end) && $duty->ends_at->greaterThan($start)) {
                $edges->push(CarbonImmutable::instance($duty->starts_at)->max($start)->utc());
                $edges->push(CarbonImmutable::instance($duty->ends_at)->min($end)->utc());
            }
        }
        $edges = $edges->unique(fn ($edge) => $edge->format('U.u'))->sort(fn ($a, $b) => $a->lessThan($b) ? -1 : ($a->greaterThan($b) ? 1 : 0))->values();
        $shortages = [];
        foreach ($edges as $index => $sliceStart) {
            $sliceEnd = $edges->get($index + 1);
            if (! $sliceEnd || ! $sliceEnd->greaterThan($sliceStart)) {
                continue;
            }
            $target = $demand->first(fn (Shift $row) => $row->starts_at && $row->ends_at
                && $row->starts_at->lessThan($sliceEnd) && $row->ends_at->greaterThan($sliceStart));
            if (! $target) {
                continue;
            }
            $qualified = [];
            foreach ($duties as $duty) {
                $worker = $workers->get((int) $duty->user_id);
                if (! $worker || isset($qualified[$worker->id]) || ! $duty->starts_at || ! $duty->ends_at
                    || $duty->starts_at->greaterThan($sliceStart) || $duty->ends_at->lessThan($sliceEnd)
                    || ! $this->currentMembershipCovers($worker, (int) $requirement->site_id, $sliceStart, $sliceEnd)) {
                    continue;
                }
                $slice = clone $duty;
                $slice->forceFill(['starts_at' => $sliceStart, 'ends_at' => $sliceEnd, 'user_id' => (int) $worker->id]);
                $result = $this->evidence->check($worker, collect([$requirement->hrComplianceRequirement]), $slice, $reads !== null);
                if ($result['passed']) {
                    $qualified[$worker->id] = true;
                }
            }
            $count = count($qualified);
            if ($count < $requirement->minimum_qualified_staff) {
                $shortages[] = ['starts_at' => $sliceStart->format('Y-m-d\TH:i:s.u\Z'),
                    'ends_at' => $sliceEnd->format('Y-m-d\TH:i:s.u\Z'), 'qualified_count' => $count,
                    'minimum_qualified_staff' => (int) $requirement->minimum_qualified_staff];
            }
        }

        return $shortages;
    }

    private function currentMembershipCovers(User $worker, int $siteId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $profile = $worker->hrEmployeeProfile;
        if (! $worker->isApproved() || ! $profile || ! $profile->is_active || $profile->trashed()) {
            return false;
        }
        $sites = [(int) $profile->primary_site_id, ...array_map('intval', $profile->secondary_site_ids ?? [])];
        $timezone = (string) (config('app.worker_timezone') ?: (config('app.timezone') ?: 'UTC'));

        return in_array($siteId, $sites, true)
            && (! $profile->start_date || $profile->start_date->toDateString() <= $start->setTimezone($timezone)->toDateString())
            && (! $profile->end_date || $profile->end_date->toDateString() >= $end->subMicrosecond()->setTimezone($timezone)->toDateString());
    }

    private function requirements(int $siteId, ?CurrentAuthorizationReads $reads): Collection
    {
        return $this->query(SiteStaffRequirement::query()->where('site_id', $siteId)->active()
            ->with('hrComplianceRequirement'), $reads)->get();
    }

    private function duties(int $siteId, CarbonImmutable $start, CarbonImmutable $end, ?CurrentAuthorizationReads $reads): Collection
    {
        return $this->query(Shift::query()->whereNotIn('status', ['cancelled'])
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->where(fn ($query) => $query->where('site_id', $siteId)
                ->orWhere(fn ($fallback) => $fallback->whereNull('site_id')
                    ->whereHas('client', fn ($client) => $client->where('site_id', $siteId))))
            ->with('client:id,site_id'), $reads)->get()
            ->filter(fn (Shift $row) => (! $row->client_id || ($row->client && (int) $row->client->site_id === $siteId)))
            ->values();
    }

    private function workers(Collection $ids, ?CurrentAuthorizationReads $reads): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->query(User::staff()->whereIn('id', $ids->sort()->values())
            ->whereNotNull('approved_at')->with(['hrEmployeeProfile' => fn ($profile) => $profile->withTrashed()]), $reads)
            ->get()->keyBy('id');
    }

    private function siteId(Shift $shift, ?CurrentAuthorizationReads $reads): ?int
    {
        $client = $shift->client_id
            ? $this->query(Client::query()->whereKey($shift->client_id), $reads)->first() : null;
        if ($shift->client_id && ! $client) {
            throw new \RuntimeException('The current house qualification source is unavailable.');
        }
        $siteId = $shift->site_id ?: $client?->site_id;
        if ($client && (int) $client->site_id !== (int) $siteId) {
            throw new \RuntimeException('The house qualification source has conflicting Site ownership.');
        }
        if ($siteId) {
            $this->query(Site::query()->whereKey($siteId), $reads)->firstOrFail();
        }

        return $siteId ? (int) $siteId : null;
    }

    private function query(Builder $query, ?CurrentAuthorizationReads $reads): Builder
    {
        if (! $reads) {
            return $query->orderBy($query->getModel()->qualifyColumn($query->getModel()->getKeyName()));
        }
        $constraints = [];
        foreach ($query->getEagerLoads() as $name => $constraint) {
            $constraints[$name] = static function ($relation) use ($constraint, $reads): void {
                $constraint($relation);
                $model = $relation->getRelated();
                $reads->query($relation->getQuery())->orderBy($model->qualifyColumn($model->getKeyName()));
            };
        }
        $query->setEagerLoads($constraints);

        return $reads->query($query)->orderBy($query->getModel()->qualifyColumn($query->getModel()->getKeyName()));
    }

    private function withReads(bool $current, callable $callback): array
    {
        if (! $current) {
            return $callback(null);
        }
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw new \LogicException('Current house qualification coverage requires a physical mutation transaction.');
        }
        app(WorkforceMutationGuard::class)->lock();

        return CurrentAuthorizationReads::within($callback);
    }

    private function check(?SiteStaffRequirement $requirement, bool $passed, string $severity, ?string $message): array
    {
        return ['rule' => 'house_qualification'.($requirement ? '_'.$requirement->id : ''),
            'passed' => $passed, 'severity' => $severity,
            'overrideable' => ! $passed && $severity === 'warning', 'message' => $message,
            'site_staff_requirement_id' => $requirement?->id];
    }

    private function shortageMessage(SiteStaffRequirement $requirement): string
    {
        return $requirement->requirement_name.': planned coverage needs at least '.$requirement->minimum_qualified_staff
            .' distinct qualified staff throughout every duty window. Part of this window has insufficient qualified coverage.';
    }

    private function periodEntry(RosterPeriod $period, ?string $site, SiteStaffRequirement $requirement, string $message): array
    {
        return ['shift_id' => null, 'issue_type' => 'house_qualification_coverage', 'message' => $message,
            'site_staff_requirement_id' => (int) $requirement->id, 'site' => $site,
            'client' => null, 'staff' => null, 'fix_url' => route('operations.rostering.index',
                ['week' => $period->week_start->toDateString(), 'site_id' => $period->site_id], false)];
    }
}
