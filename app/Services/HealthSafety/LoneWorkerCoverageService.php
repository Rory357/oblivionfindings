<?php

namespace App\Services\HealthSafety;

use App\Models\Client;
use App\Models\LoneWorkerSession;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Actual Workforce requirements and received observations, never a safety assurance. */
final class LoneWorkerCoverageService
{
    public function __construct(private readonly LoneWorkerSessionAccessService $access) {}

    public function summary(User $viewer, ?int $siteId = null): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($siteId) {
            abort_unless($current->canDo('hazards.view'), 403);
            if ($siteId !== null) {
                abort_unless(in_array($siteId, $sites, true), 403);
                $sites = [$siteId];
            }
            $active = $this->population($sites, $reads, false);
            $upcoming = $this->population($sites, $reads, true);
            $required = $active->filter(fn ($shift) => $this->requirement($shift) === true);
            $unknown = $active->filter(fn ($shift) => $this->requirement($shift) === null)->count();
            $sessions = $reads->query(app(LoneWorkerSessionScope::class)->applyForSites(LoneWorkerSession::query(), $sites, true))->whereIn('status', LoneWorkerSessionAccessService::LIVE_STATUSES)->whereNull('ended_at')->get()->filter(function ($session) use ($reads, $sites) {
                try {
                    return in_array($this->access->assertSource($session, $reads), $sites, true);
                } catch (HttpException) {
                    return false;
                }
            });
            $monitored = $required->filter(fn ($shift) => $sessions->contains(fn ($session) => $this->sessionMatchesShift($session, $shift)))->count();
            $unmonitored = $required->count() - $monitored;
            $state = $active->isEmpty() ? 'no_active_duty' : ($unknown > 0 ? 'unknown' : ($required->isEmpty() ? 'configured_zero' : ($unmonitored > 0 ? 'unmonitored' : 'monitored')));

            return ['state' => $state, 'expected_count' => $unknown > 0 ? null : $required->count(), 'required_count' => $required->count(), 'monitored_count' => $monitored, 'unmonitored_count' => $unmonitored, 'unknown_count' => $unknown,
                'active_duty_count' => $active->count(), 'source' => 'workforce_shift_requirement', 'source_checked_at' => now()->toISOString(), 'checked_at' => now()->toISOString(), 'source_availability' => 'available',
                'reason' => match ($state) {
                    'no_active_duty' => 'no_active_duty_to_assess', 'unknown' => 'requirement_not_established', 'configured_zero' => 'no_current_shift_requires_monitoring', 'unmonitored' => 'required_shift_without_valid_live_session', default => 'required_shifts_have_valid_live_sessions'
                },
                'upcoming_expected_count' => $upcoming->filter(fn ($shift) => $this->requirement($shift) === true)->count(), 'upcoming_unknown_count' => $upcoming->filter(fn ($shift) => $this->requirement($shift) === null)->count(), 'upcoming_window' => ['from' => now()->toISOString(), 'to' => now()->addDay()->toISOString()]];
        });
    }

    /** Picker pagination belongs to the caller; the required denominator never shares its limit. */
    public function monitorableShifts(User $viewer, ?int $siteId = null): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($siteId) {
            abort_unless($current->canDo('hazards.view'), 403);
            if ($siteId !== null) {
                abort_unless(in_array($siteId, $sites, true), 403);
                $sites = [$siteId];
            }
            $population = $this->population($sites, $reads, false);
            $sessions = $reads->query(app(LoneWorkerSessionScope::class)->applyForSites(LoneWorkerSession::query(), $sites, true))->whereIn('status', LoneWorkerSessionAccessService::LIVE_STATUSES)->whereNull('ended_at')->get()->filter(function ($session) use ($reads, $sites) {
                try {
                    return in_array($this->access->assertSource($session, $reads), $sites, true);
                } catch (HttpException) {
                    return false;
                }
            });
            $rows = $population->reject(fn ($shift) => $sessions->contains(fn ($session) => $this->sessionMatchesShift($session, $shift)))->filter(function ($shift) use ($sites, $reads) {
                $probe = new LoneWorkerSession(['user_id' => $shift->user_id, 'site_id' => $shift->site_id, 'client_id' => $shift->client_id, 'shift_id' => $shift->id]);
                try {
                    return in_array($this->access->assertSource($probe, $reads), $sites, true);
                } catch (HttpException) {
                    return false;
                }
            })->map(function ($shift) use ($current, $reads) {
                $worker = $shift->staff;
                $site = $shift->site ?? $shift->client?->site;

                return ['id' => $shift->id, 'worker' => $worker ? ['id' => $worker->id, 'name' => $worker->name] : null, 'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
                    'client' => $shift->client && app(ClientPolicy::class)->viewFromCurrentEvidence($current, $shift->client, $reads) ? ['id' => $shift->client->id, 'name' => trim($shift->client->first_name.' '.$shift->client->last_name)] : null, 'starts_at' => $shift->starts_at?->toISOString(), 'ends_at' => $shift->ends_at?->toISOString(), 'location' => $shift->location, 'location_lat' => null, 'location_lng' => null,
                    'is_on_call' => (bool) $shift->is_on_call, 'is_lone' => $this->requirement($shift) === true, 'is_lone_worker' => $this->requirement($shift), 'requirement_source' => 'workforce_shift_requirement'];
            })->sortByDesc('is_lone')->values();

            return ['shifts' => $rows, 'unmonitored_lone' => $rows->where('is_lone', true)->count()];
        });
    }

    public function observation(LoneWorkerSession $session, User $viewer): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($session) {
            $row = $reads->query(LoneWorkerSession::query())->findOrFail($session->id);
            $this->access->assertRecord($row, $current, $sites);
            $check = $reads->query($row->latestReceivedCheckInQuery())->first();
            $received = $check?->received_at ?? $check?->created_at;
            $occurred = $check?->checked_in_at;
            $baseline = $row->started_at;
            // An unbacked historical last_check_in_at remains scheduling evidence only.
            $due = $row->checkInDueAt($check);
            $observationDue = $occurred && (int) $row->check_in_interval_minutes > 0 ? $occurred->copy()->addMinutes((int) $row->check_in_interval_minutes) : null;
            $state = $check === null ? 'no_check_in' : ($observationDue === null || $received === null || $received->gt(now()) || $received->lt($occurred) ? 'unknown' : ($observationDue->lte(now()) ? 'stale' : 'received'));
            $monitorChecked = $this->instant($row->last_monitor_checked_at);
            $monitorFailed = $this->instant($row->monitor_failed_at);
            $monitorState = ($monitorChecked?->gt(now()) || $monitorFailed?->gt(now())) ? 'unknown' : ($monitorFailed && (! $monitorChecked || $monitorFailed->gte($monitorChecked)) ? 'failed' : ($monitorChecked === null ? 'unknown' : ($monitorChecked->lt(now()->subMinutes(10)) ? 'stale' : 'current')));

            return ['state' => $state, 'real_check_in_at' => $occurred?->toISOString(), 'received_at' => $received?->toISOString(), 'receipt_source' => $check === null ? null : ($check->received_at ? 'recorded_receipt' : ($check->created_at ? 'legacy_created_at' : 'unknown')),
                'startup_baseline_at' => $baseline?->toISOString(), 'next_due_at' => $due?->toISOString(), 'monitor' => ['state' => $monitorState, 'checked_at' => $monitorChecked?->toISOString(), 'failed_at' => $monitorFailed?->toISOString(), 'failure_code' => $monitorFailed && $monitorState === 'failed' ? $this->safeFailure($row->monitor_failure_code) : null]];
        });
    }

    private function population(array $sites, CurrentAuthorizationReads $reads, bool $upcoming): Collection
    {
        $query = Shift::query()->whereNotIn('status', ['cancelled', 'completed'])->whereNull('actual_ends_at')
            ->where(function (Builder $scope) use ($sites) {
                $scope->whereIn('site_id', $sites)->orWhere(fn ($fallback) => $fallback->whereNull('site_id')->whereHas('client', fn ($client) => $client->whereIn('site_id', $sites)));
            });
        if ($upcoming) {
            $query->whereNull('actual_starts_at')->whereBetween('starts_at', [now(), now()->addDay()]);
        } else {
            $query->whereNotNull('actual_starts_at')->where('actual_starts_at', '<=', now());
        }
        $rows = $reads->query($query)->orderBy('id')->get();
        foreach ($rows as $row) {
            $row->setRelation('staff', $reads->query(User::query())->find($row->user_id));
            $client = $row->client_id ? $reads->query(Client::query())->find($row->client_id) : null;
            $siteId = $row->site_id ?? $client?->site_id;
            $site = $siteId ? $reads->query(Site::query())->find($siteId) : null;
            $row->setRelation('client', $client)->setRelation('site', $site);
            if ($client) {
                $client->setRelation('site', $site);
            }
        }

        return $rows;
    }

    private function requirement(Shift $shift): ?bool
    {
        $raw = $shift->getRawOriginal('is_lone_worker');
        if ($raw === null || ! in_array($raw, [0, 1, false, true, '0', '1'], true)) {
            return null;
        }

        return in_array($raw, [1, true, '1'], true);
    }

    private function sessionMatchesShift(LoneWorkerSession $session, Shift $shift): bool
    {
        return (int) $session->shift_id === (int) $shift->id && (int) $session->user_id === (int) $shift->user_id
            && $session->client_id === $shift->client_id && (int) ($session->site_id ?? $shift->site_id ?? $shift->client?->site_id) === (int) ($shift->site_id ?? $shift->client?->site_id);
    }

    private function instant(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }

    private function safeFailure(mixed $value): ?string
    {
        return in_array($value, ['source_unavailable', 'source_integrity_failed', 'signal_delivery_failed', 'monitor_failed'], true) ? $value : ($value === null ? null : 'monitor_failed');
    }
}
