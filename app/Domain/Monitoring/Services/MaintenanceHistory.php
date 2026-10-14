<?php

namespace App\Domain\Monitoring\Services;

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
    public function resolve(Monitor $monitor, DateTimeInterface $instant): array
    {
        $at = CarbonImmutable::instance($instant)->utc();
        $base = ['schema_version' => 1, 'owner' => 'Monitoring', 'basis' => 'occurrence_effective_maintenance_revisions',
            'observed_at' => $at->format('Y-m-d\TH:i:s.u\Z'), 'interpreted_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
            'clock_uncertainty_seconds' => null, 'state' => 'unavailable', 'reason_code' => null, 'winner' => null,
            'matching' => [], 'overridden' => [], 'assignment' => null];
        if (! Schema::hasTable('monitoring_maintenance_revisions') || ! Schema::hasTable('monitoring_policy_history_epochs')) {
            return [...$base, 'reason_code' => 'history_storage_unavailable'];
        }

        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($monitor, $at, $base): array {
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
            $ids = $reads->query(MonitoringMaintenanceRevision::query())->where('site_id', $siteId)->pluck('window_id')->all();
            $current = $reads->query(MonitoringMaintenanceWindow::query())->where('site_id', $siteId)->get();
            $ids = array_values(array_unique([...$ids, ...$current->modelKeys()]));
            if ($ids === []) {
                return [...$base, 'state' => 'none', 'reason_code' => 'no_applicable_maintenance'];
            }
            $windows = $reads->query(MonitoringMaintenanceWindow::query())->whereIn('id', $ids)->get()->keyBy('id');
            $chains = $reads->query(MonitoringMaintenanceRevision::query())->whereIn('window_id', $ids)->orderBy('version')->get()->groupBy('window_id');
            $matching = [];
            foreach ($windows as $window) {
                $chain = $chains->get($window->id, collect());
                if ($chain->isEmpty()) {
                    if ($this->scopeMatches($this->configuration($window), $fresh, $siteId)) {
                        return [...$base, 'reason_code' => 'legacy_rule_history_unavailable'];
                    }

                    continue;
                }
                $previous = null;
                foreach ($chain as $revision) {
                    if (! hash_equals(MaintenanceConfiguration::hash($revision->snapshot), $revision->snapshot_hash)
                        || ($revision->snapshot['id'] ?? null) !== (int) $window->id || ($revision->snapshot['version'] ?? null) !== $revision->version
                        || ($previous === null && (! in_array($revision->operation, ['create', 'baseline'], true) || $revision->supersedes_id !== null))
                        || ($previous !== null && ($revision->version !== $previous->version + 1 || $revision->supersedes_id !== $previous->id || $revision->effective_from < $previous->effective_from))) {
                        return [...$base, 'reason_code' => 'revision_chain_unverified'];
                    }
                    $previous = $revision;
                }
                if ($previous->version !== (int) $window->version || ! hash_equals($previous->snapshot_hash, MaintenanceConfiguration::hash($this->configuration($window)))) {
                    return [...$base, 'reason_code' => 'current_rule_history_diverged'];
                }
                $selected = $chain->last(fn ($revision): bool => $revision->effective_from <= $at);
                if ($selected === null) {
                    if ($chain->first()->operation === 'baseline' && $this->scopeMatches($chain->first()->snapshot, $fresh, $siteId)) {
                        return [...$base, 'reason_code' => 'observation_before_reviewed_baseline'];
                    }

                    continue;
                }
                $snapshot = $selected->snapshot;
                if ($snapshot['state'] !== 'active' || ! $this->scopeMatches($snapshot, $fresh, $siteId)) {
                    continue;
                }
                $schedule = [...$snapshot, 'starts_at' => CarbonImmutable::parse($snapshot['starts_at']), 'ends_at' => CarbonImmutable::parse($snapshot['ends_at']),
                    'recurrence_until' => $snapshot['recurrence_until'] === null ? null : CarbonImmutable::parse($snapshot['recurrence_until'])];
                if ((new MaintenanceSchedule)->contains($schedule, $at)) {
                    $matching[] = ['revision_id' => (int) $selected->id, 'window_id' => (int) $window->id, 'version' => $selected->version,
                        'actor_id' => $selected->actor_user_id, 'operation' => $selected->operation,
                        'effective_from' => $selected->effective_from->format('Y-m-d\TH:i:s.u\Z'), 'recorded_at' => $selected->recorded_at->format('Y-m-d\TH:i:s.u\Z'),
                        'snapshot_hash' => $selected->snapshot_hash, 'snapshot' => $snapshot];
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

    private function scopeMatches(array $snapshot, Monitor $monitor, int $siteId): bool
    {
        return $snapshot['site_id'] === $siteId && (
            ($snapshot['monitor_id'] !== null && $snapshot['device_id'] === null && $snapshot['monitor_id'] === (int) $monitor->id && $snapshot['monitor_device_id'] === (int) $monitor->device_id)
            || ($snapshot['device_id'] !== null && $snapshot['monitor_id'] === null && $snapshot['device_id'] === (int) $monitor->device_id)
            || ($snapshot['device_id'] === null && $snapshot['monitor_id'] === null));
    }
}
