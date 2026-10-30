<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Exceptions\MaintenanceResolutionLimitExceeded;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringMaintenanceRevision;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Append-only maintenance interpretation. It never grants execution or source access. */
final class MaintenanceHistory
{
    public const WINDOW_PAGE_SIZE = 100;

    public const REVISION_PAGE_SIZE = 100;

    public function storage(): void
    {
        abort_unless(Schema::hasTable('monitoring_maintenance_revisions') && Schema::hasTable('monitoring_policy_history_epochs'), 503, 'Maintenance history needs an application update.');
    }

    public function before(MonitoringMaintenanceWindow $window, User $actor, string $reason): MonitoringMaintenanceRevision
    {
        $this->storage();
        $last = MonitoringMaintenanceRevision::query()->where('window_id', $window->id)->orderByDesc('version')->lockForUpdate()->first();
        if ($last === null) {
            // Explicitly known from this review instant, never an invented earlier revision.
            return $this->record($window, $actor, 'baseline', null, $reason);
        }
        if ($last->version !== (int) $window->version || ! hash_equals($last->snapshot_hash, MaintenanceConfiguration::hash($this->configuration($window)))) {
            throw ValidationException::withMessages(['form' => 'This maintenance rule changed outside its recorded history. Review the source before editing it.']);
        }

        return $last;
    }

    public function record(MonitoringMaintenanceWindow $window, User $actor, string $operation, ?MonitoringMaintenanceRevision $previous, string $reason): MonitoringMaintenanceRevision
    {
        $this->storage();
        $at = CarbonImmutable::now('UTC');
        if ($previous !== null && ($previous->window_id !== (int) $window->id || $previous->version + 1 !== (int) $window->version || $at < $previous->effective_from)) {
            throw ValidationException::withMessages(['form' => 'Maintenance revision order cannot be verified. Review the application clock and latest rule.']);
        }
        $snapshot = $this->configuration($window);

        return MonitoringMaintenanceRevision::query()->create([
            'window_id' => (int) $window->id, 'version' => (int) $window->version, 'site_id' => (int) $window->site_id,
            'actor_user_id' => (int) $actor->id, 'operation' => $operation, 'change_reason' => $reason,
            'supersedes_id' => $previous?->id, 'effective_from' => $at, 'recorded_at' => $at,
            'snapshot' => $snapshot, 'snapshot_hash' => MaintenanceConfiguration::hash($snapshot),
        ]);
    }

    public function configuration(MonitoringMaintenanceWindow $window): array
    {
        return [...MaintenanceConfiguration::snapshot($window), 'monitor_device_id' => $window->monitor_id === null ? null : (int) Monitor::query()->useWritePdo()->findOrFail($window->monitor_id)->device_id];
    }

    /** Exact UTC occurrence plus explicit history/assignment uncertainty; current scope is rechecked. */
    public function resolve(Monitor $monitor, DateTimeInterface $instant, ?int $maxRevisions = null, ?int $maxMatching = null): array
    {
        if (($maxRevisions !== null && $maxRevisions < 1) || ($maxMatching !== null && $maxMatching < 1)) {
            throw new MaintenanceResolutionLimitExceeded;
        }
        $at = CarbonImmutable::instance($instant)->utc();
        $base = ['schema_version' => 1, 'owner' => 'Monitoring', 'basis' => 'occurrence_effective_maintenance_revisions',
            'observed_at' => $at->format('Y-m-d\TH:i:s.u\Z'), 'interpreted_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
            'clock_uncertainty_seconds' => null, 'state' => 'unavailable', 'reason_code' => null, 'winner' => null,
            'matching' => [], 'overridden' => [], 'assignment' => null];
        if (! Schema::hasTable('monitoring_maintenance_revisions') || ! Schema::hasTable('monitoring_policy_history_epochs')) {
            return [...$base, 'reason_code' => 'history_storage_unavailable'];
        }

        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($monitor, $at, $base, $maxRevisions, $maxMatching): array {
            $fresh = $reads->query(Monitor::query())->findOrFail($monitor->id);
            $siteId = app(CanonicalDeviceSiteResolver::class)->forCurrentEvidence($reads)->resolve((int) $fresh->device_id);
            $reads->query(Site::query())->whereKey($siteId)->firstOrFail();
            $epoch = $reads->query(DB::table('monitoring_policy_history_epochs'))->where('owner', 'maintenance')->first();
            if ($epoch === null || $at < CarbonImmutable::parse($epoch->started_at, 'UTC')) {
                return [...$base, 'reason_code' => 'observation_before_history_tracking'];
            }
            $assignments = $reads->query(DeviceAssignment::query())->where('device_id', $fresh->device_id)->effectiveAt($at)->get();
            $assignment = $assignments->count() === 1 ? $assignments->first() : null;
            if ($assignment === null || $assignment->assignable_type !== DeviceAssignment::TARGET_SITE || (int) $assignment->assignable_id !== $siteId || $assignment->created_at === null || $at < $assignment->created_at) {
                return [...$base, 'reason_code' => 'occurrence_assignment_unverified'];
            }
            $base = [...$base, 'assignment' => ['basis' => 'direct_site_assignment_interval_at_processing', 'assignment_id' => (int) $assignment->id,
                'site_id' => $siteId, 'device_id' => (int) $fresh->device_id, 'assigned_at' => $assignment->assigned_at->utc()->toIso8601String(),
                'released_at' => $assignment->released_at?->utc()->toIso8601String(), 'row_created_at' => $assignment->created_at->utc()->toIso8601String()]];
            $ids = $this->discoverCandidateWindowIds($reads, $siteId, $maxRevisions);
            if ($ids === []) {
                return [...$base, 'state' => 'none', 'reason_code' => 'no_applicable_maintenance'];
            }
            $matching = [];
            $scannedChainRevisions = 0;
            foreach (array_chunk($ids, self::WINDOW_PAGE_SIZE) as $idChunk) {
                $orderedWindows = $reads->query(MonitoringMaintenanceWindow::query())
                    ->whereIn('id', $idChunk)
                    ->orderBy('id')
                    ->get()
                    ->values()
                    ->all();
                if ($orderedWindows === []) {
                    continue;
                }
                $windowCount = count($orderedWindows);
                $windowIndex = 0;
                $activeWindowId = (int) $orderedWindows[0]->id;
                $first = null;
                $previous = null;
                $selected = null;
                $cursorWindowId = null;
                $cursorVersion = 0;

                do {
                    $pageLimit = $maxRevisions !== null
                        ? min(self::REVISION_PAGE_SIZE, $maxRevisions - $scannedChainRevisions + 1)
                        : self::REVISION_PAGE_SIZE;
                    $revisionQuery = $reads->query(MonitoringMaintenanceRevision::query());
                    if ($cursorWindowId === null) {
                        $revisionQuery->whereIn('window_id', $idChunk);
                    } else {
                        $afterIds = array_values(array_filter($idChunk, fn (int $id): bool => $id > $cursorWindowId));
                        $revisionQuery->where(function ($query) use ($cursorWindowId, $cursorVersion, $afterIds): void {
                            $query->where(function ($sameWindow) use ($cursorWindowId, $cursorVersion): void {
                                $sameWindow->where('window_id', $cursorWindowId)->where('version', '>', $cursorVersion);
                            });
                            if ($afterIds !== []) {
                                $query->orWhereIn('window_id', $afterIds);
                            }
                        });
                    }
                    $revisions = $revisionQuery
                        ->orderBy('window_id')
                        ->orderBy('version')
                        ->limit($pageLimit)
                        ->get();
                    $scannedChainRevisions += $revisions->count();
                    if ($maxRevisions !== null && $scannedChainRevisions > $maxRevisions) {
                        throw new MaintenanceResolutionLimitExceeded;
                    }
                    foreach ($revisions as $revision) {
                        $revisionWindowId = (int) $revision->window_id;
                        while ($windowIndex < $windowCount && (int) $orderedWindows[$windowIndex]->id < $revisionWindowId) {
                            $earlyReason = $this->finalizeWindow(
                                $orderedWindows[$windowIndex],
                                $first,
                                $previous,
                                $selected,
                                $fresh,
                                $siteId,
                                $at,
                                $matching,
                                $maxMatching,
                            );
                            if ($earlyReason !== null) {
                                return [...$base, 'reason_code' => $earlyReason];
                            }
                            $windowIndex++;
                            $activeWindowId = $windowIndex < $windowCount ? (int) $orderedWindows[$windowIndex]->id : 0;
                            $first = null;
                            $previous = null;
                            $selected = null;
                        }
                        if ($windowIndex >= $windowCount || $activeWindowId !== $revisionWindowId) {
                            $cursorWindowId = $revisionWindowId;
                            $cursorVersion = (int) $revision->version;

                            continue;
                        }
                        $window = $orderedWindows[$windowIndex];
                        if (! hash_equals(MaintenanceConfiguration::hash($revision->snapshot), $revision->snapshot_hash)
                            || ($revision->snapshot['id'] ?? null) !== (int) $window->id || ($revision->snapshot['version'] ?? null) !== $revision->version
                            || ($previous === null && (! in_array($revision->operation, ['create', 'baseline'], true) || $revision->supersedes_id !== null))
                            || ($previous !== null && ($revision->version !== $previous->version + 1 || $revision->supersedes_id !== $previous->id || $revision->effective_from < $previous->effective_from))) {
                            return [...$base, 'reason_code' => 'revision_chain_unverified'];
                        }
                        $first ??= $revision;
                        $previous = $revision;
                        if ($revision->effective_from <= $at) {
                            $selected = $revision;
                        }
                        $cursorWindowId = $revisionWindowId;
                        $cursorVersion = (int) $revision->version;
                    }
                } while ($revisions->count() === $pageLimit);

                while ($windowIndex < $windowCount) {
                    $earlyReason = $this->finalizeWindow(
                        $orderedWindows[$windowIndex],
                        $first,
                        $previous,
                        $selected,
                        $fresh,
                        $siteId,
                        $at,
                        $matching,
                        $maxMatching,
                    );
                    if ($earlyReason !== null) {
                        return [...$base, 'reason_code' => $earlyReason];
                    }
                    $windowIndex++;
                    $first = null;
                    $previous = null;
                    $selected = null;
                }
            }
            if ($matching === []) {
                return [...$base, 'state' => 'none', 'reason_code' => 'no_applicable_maintenance'];
            }
            $rank = fn ($row): int => $row['snapshot']['monitor_id'] !== null ? 2 : ($row['snapshot']['device_id'] !== null ? 1 : 0);
            $priority = max(array_map($rank, $matching));
            $winners = array_values(array_filter($matching, fn ($row): bool => $rank($row) === $priority));
            $overridden = array_values(array_filter($matching, fn ($row): bool => $rank($row) < $priority));
            $state = count($winners) > 1 ? 'conflict' : ($winners[0]['snapshot']['policy'] === 'suppress_notifications_and_ticketing' ? 'applied' : 'unsupported');

            return [...$base, 'state' => $state, 'reason_code' => $state === 'applied' ? 'most_specific_occurrence_rule' : ($state === 'conflict' ? 'equal_priority_rules' : 'unsupported_maintenance_policy'),
                'winner' => $state === 'applied' ? $winners[0] : null, 'matching' => $winners, 'overridden' => $overridden];
        }));
    }

    /** @return list<int> */
    private function discoverCandidateWindowIds(CurrentAuthorizationReads $reads, int $siteId, ?int $maxRevisions): array
    {
        $windowIds = [];
        $scannedSiteRevisions = 0;
        $lastSiteRevisionId = 0;
        do {
            $pageLimit = $maxRevisions !== null
                ? min(self::REVISION_PAGE_SIZE, $maxRevisions - $scannedSiteRevisions + 1)
                : self::REVISION_PAGE_SIZE;
            $revisionIds = $reads->query(MonitoringMaintenanceRevision::query())
                ->select(['id', 'window_id'])
                ->where('site_id', $siteId)
                ->where('id', '>', $lastSiteRevisionId)
                ->orderBy('id')
                ->limit($pageLimit)
                ->get();
            $scannedSiteRevisions += $revisionIds->count();
            if ($maxRevisions !== null && $scannedSiteRevisions > $maxRevisions) {
                throw new MaintenanceResolutionLimitExceeded;
            }
            foreach ($revisionIds as $row) {
                $windowIds[(int) $row->window_id] = true;
                $lastSiteRevisionId = (int) $row->id;
            }
        } while ($revisionIds->count() === $pageLimit);

        $scannedSiteWindows = 0;
        $lastSiteWindowId = 0;
        do {
            $pageLimit = $maxRevisions !== null
                ? min(self::WINDOW_PAGE_SIZE, $maxRevisions - $scannedSiteWindows + 1)
                : self::WINDOW_PAGE_SIZE;
            $currentWindows = $reads->query(MonitoringMaintenanceWindow::query())
                ->select(['id'])
                ->where('site_id', $siteId)
                ->where('id', '>', $lastSiteWindowId)
                ->orderBy('id')
                ->limit($pageLimit)
                ->get();
            $scannedSiteWindows += $currentWindows->count();
            if ($maxRevisions !== null && $scannedSiteWindows > $maxRevisions) {
                throw new MaintenanceResolutionLimitExceeded;
            }
            foreach ($currentWindows as $row) {
                $windowIds[(int) $row->id] = true;
                $lastSiteWindowId = (int) $row->id;
            }
        } while ($currentWindows->count() === $pageLimit);

        $ids = array_keys($windowIds);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @param list<array<string, mixed>> $matching */
    private function finalizeWindow(
        MonitoringMaintenanceWindow $window,
        ?MonitoringMaintenanceRevision $first,
        ?MonitoringMaintenanceRevision $previous,
        ?MonitoringMaintenanceRevision $selected,
        Monitor $fresh,
        int $siteId,
        CarbonImmutable $at,
        array &$matching,
        ?int $maxMatching,
    ): ?string {
        if ($previous === null) {
            return $this->scopeMatches($this->configuration($window), $fresh, $siteId)
                ? 'legacy_rule_history_unavailable'
                : null;
        }
        if ($previous->version !== (int) $window->version || ! hash_equals($previous->snapshot_hash, MaintenanceConfiguration::hash($this->configuration($window)))) {
            return 'current_rule_history_diverged';
        }
        if ($selected === null) {
            return $first !== null && $first->operation === 'baseline' && $this->scopeMatches($first->snapshot, $fresh, $siteId)
                ? 'observation_before_reviewed_baseline'
                : null;
        }
        $snapshot = $selected->snapshot;
        if ($snapshot['state'] !== 'active' || ! $this->scopeMatches($snapshot, $fresh, $siteId)) {
            return null;
        }
        $schedule = [...$snapshot, 'starts_at' => CarbonImmutable::parse($snapshot['starts_at']), 'ends_at' => CarbonImmutable::parse($snapshot['ends_at']),
            'recurrence_until' => $snapshot['recurrence_until'] === null ? null : CarbonImmutable::parse($snapshot['recurrence_until'])];
        if ((new MaintenanceSchedule)->contains($schedule, $at)) {
            $matching[] = ['revision_id' => (int) $selected->id, 'window_id' => (int) $window->id, 'version' => $selected->version,
                'actor_id' => $selected->actor_user_id, 'operation' => $selected->operation,
                'effective_from' => $selected->effective_from->format('Y-m-d\TH:i:s.u\Z'), 'recorded_at' => $selected->recorded_at->format('Y-m-d\TH:i:s.u\Z'),
                'snapshot_hash' => $selected->snapshot_hash, 'snapshot' => $snapshot];
            if ($maxMatching !== null && count($matching) > $maxMatching) {
                throw new MaintenanceResolutionLimitExceeded;
            }
        }

        return null;
    }

    private function scopeMatches(array $snapshot, Monitor $monitor, int $siteId): bool
    {
        return $snapshot['site_id'] === $siteId && (
            ($snapshot['monitor_id'] !== null && $snapshot['device_id'] === null && $snapshot['monitor_id'] === (int) $monitor->id && $snapshot['monitor_device_id'] === (int) $monitor->device_id)
            || ($snapshot['device_id'] !== null && $snapshot['monitor_id'] === null && $snapshot['device_id'] === (int) $monitor->device_id)
            || ($snapshot['device_id'] === null && $snapshot['monitor_id'] === null));
    }
}
