<?php

namespace App\Domain\It\Services;

use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\FleetSignalOutbox;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Safe, current-source-scoped facts from the existing delivery outboxes. */
final class ItTechnicalDeliveryOperationsPresenter
{
    private const STATES = [
        'pending' => 'Awaiting IT delivery', 'failed' => 'Delivery failed', 'dead_letter' => 'Retry limit reached',
        'unroutable' => 'Source review required', 'applied' => 'IT outcome recorded', 'ignored' => 'No IT work required',
    ];

    private const OUTCOMES = [
        'ticket_created' => 'Technical work created', 'ticket_updated' => 'Existing technical work updated',
        'recovery_recorded' => 'Recovery recorded for technician verification', 'recovery_unmatched' => 'No matching fault for this recovery',
        'source_suppressed' => 'Suppressed by source policy', 'unsupported_event' => 'Source event is not supported for IT work',
        'source_scope_changed' => 'Source ownership or evidence changed', 'source_unavailable' => 'Source is unavailable',
        'technical_routing_unavailable' => 'Technical routing requires review', 'canonical_evidence_unavailable' => 'Canonical source evidence requires review',
        'processing_failed' => 'Delivery could not finish',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $sources,
        private readonly ItWorkAccessService $work,
    ) {}

    public function operations(User $viewer, array $pages = []): array
    {
        return [
            'viewer_user_id' => (int) $viewer->id,
            'checked_at' => now()->toIso8601String(),
            'sources' => array_map(fn (string $source): array => $this->source(
                $viewer, $source, (int) ($pages[$source.'_delivery_page'] ?? 1), trim((string) ($pages['q'] ?? '')),
            ), ['device', 'fleet']),
        ];
    }

    private function source(User $viewer, string $source, int $page, string $search): array
    {
        $model = $source === 'device' ? new DeviceEventSignalOutbox : new FleetSignalOutbox;
        $canView = $this->canView($viewer, $source);
        $available = $canView && Schema::hasColumns($model->getTable(), [
            'it_status', 'it_scope', 'it_outcome_code', 'it_attempts', 'it_attempt_limit',
            'it_last_attempt_at', 'it_completed_at',
        ]);
        $result = [
            'source' => $source, 'can_view' => $canView, 'available' => $available,
            'coverage' => 'source_bound_it_intents',
            'total' => null, 'pending' => null, 'failures' => null, 'legacy_unverified' => null,
            'last_success_at' => null, 'oldest_pending_at' => null,
            'search_query' => $search, 'history_total' => null, 'links' => [],
            'rows' => [], 'page' => 1, 'last_page' => 1, 'previous_url' => null, 'next_url' => null,
        ];
        if (! $available) {
            return $result;
        }
        $query = $this->visibleQuery($viewer, $source, $model);
        $facts = (clone $query)->toBase()->selectRaw("COUNT(*) AS total,
            SUM(CASE WHEN it_status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN it_status IN ('failed', 'dead_letter', 'unroutable') THEN 1 ELSE 0 END) AS failures,
            MIN(CASE WHEN it_status IN ('pending', 'failed') THEN created_at ELSE NULL END) AS oldest_pending_at,
            MAX(CASE WHEN it_status = 'applied' THEN it_completed_at ELSE NULL END) AS last_success_at")->first();
        if ($search !== '') {
            $this->applySearch($query, $source, $search);
        }
        $historyTotal = (clone $query)->count();
        $lastPage = max(1, (int) ceil($historyTotal / 25));
        $page = max(1, min($page, $lastPage));
        $url = function (int $number) use ($source, $search): string {
            $filters = [$source.'_delivery_page' => $number, 'tab' => 'operations', ...($search !== '' ? ['q' => $search] : [])];
            ksort($filters);

            return '/it/setup?'.http_build_query($filters, '', '&', PHP_QUERY_RFC3986).'#it-'.$source.'-delivery-history';
        };
        $links = [['label' => 'Previous', 'url' => $page > 1 ? $url($page - 1) : null, 'active' => false]];
        for ($number = max(1, min($page - 1, $lastPage - 2)); $number <= min($lastPage, max(3, $page + 1)); $number++) {
            $links[] = ['label' => (string) $number, 'url' => $url($number), 'active' => $page === $number];
        }
        $links[] = ['label' => 'Next', 'url' => $page < $lastPage ? $url($page + 1) : null, 'active' => false];
        // Never select source payloads, exception text, stored scope, or result ticket IDs.
        $rows = (clone $query)->latest('id')->forPage($page, 25)->get([
            'id', 'status', 'it_status', 'it_outcome_code', 'it_attempts', 'it_attempt_limit',
            'it_last_attempt_at', 'it_completed_at', 'created_at',
        ])->map(fn (Model $row): array => $this->describe($row))->all();

        return array_replace($result, [
            'total' => (int) $facts->total, 'pending' => (int) $facts->pending,
            'failures' => (int) $facts->failures,
            'last_success_at' => $this->timestamp($facts->last_success_at),
            'oldest_pending_at' => $this->timestamp($facts->oldest_pending_at),
            'rows' => $rows, 'page' => $page, 'last_page' => $lastPage,
            'history_total' => $historyTotal, 'links' => $links,
            'previous_url' => $page > 1 ? $url($page - 1) : null,
            'next_url' => $page < $lastPage ? $url($page + 1) : null,
        ]);
    }

    /** Call only after applying the source authorization boundary. */
    public function describe(Model $row): array
    {
        $state = array_key_exists((string) $row->it_status, self::STATES)
            ? $row->it_status : 'unverified';
        $code = array_key_exists((string) $row->it_outcome_code, self::OUTCOMES) ? $row->it_outcome_code : null;

        return [
            'id' => (int) $row->id, 'state' => $state, 'outcome_code' => $code,
            'source_delivered' => $row->status === 'sent',
            'attempts' => $state === 'unverified' ? null : (int) $row->it_attempts,
            'attempt_limit' => $state === 'unverified' ? null : (int) $row->it_attempt_limit,
            'last_attempt_at' => $row->it_last_attempt_at?->toIso8601String(),
            'completed_at' => $row->it_completed_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function visibleQuery(User $viewer, string $source, Model $model): Builder
    {
        $deviceIds = $this->sources->visibleDevices($viewer)->select('devices.id');
        // Old source acknowledgements have no proven IT destination or original
        // Site binding. They are not zero failures or successful IT deliveries.
        $query = $model->newQuery()->whereNotNull('it_status')
            ->whereIn('it_scope->site_id', $this->work->approvedSiteIds($viewer));
        if ($source === 'device') {
            return $query->whereHas('event', fn (Builder $event) => $event
                ->whereIn('event_type', ['offline', 'online'])
                ->whereIn('device_id', $deviceIds)
                ->whereHas('device', fn (Builder $device) => $device->where('domain', 'it_infrastructure')));
        }

        return $query->whereHas('signal', fn (Builder $signal) => $signal
            ->whereIn('signal_type', ['device.offline', 'device.online'])
            ->whereIn('device_id', $deviceIds)
            ->whereIn('asset_id', $this->sources->accessibleAssets($viewer)->select('assets.id')));
    }

    private function applySearch(Builder $query, string $source, string $search): void
    {
        $needle = mb_strtolower($search);
        $matches = fn (string $label): bool => str_contains(mb_strtolower($label), $needle);
        $states = array_keys(array_filter(self::STATES, fn (string $label, string $key): bool => $matches($label.' '.str_replace('_', ' ', $key)), ARRAY_FILTER_USE_BOTH));
        $outcomes = array_keys(array_filter(self::OUTCOMES, fn (string $label, string $key): bool => $matches($label.' '.str_replace('_', ' ', $key)), ARRAY_FILTER_USE_BOTH));
        $query->where(function (Builder $match) use ($source, $search, $matches, $states, $outcomes): void {
            $match->whereRaw('1 = 0');
            if ($matches($source === 'device' ? 'Device monitoring' : 'Fleet tracking')) {
                $match->orWhereRaw('1 = 1');
            }
            if (ctype_digit($search)) {
                $match->orWhere($match->getModel()->getQualifiedKeyName(), $search);
            }
            $match->orWhereIn('it_status', $states)->orWhereIn('it_outcome_code', $outcomes);
            if ($matches('Outcome unverified')) {
                $match->orWhereNotIn('it_status', array_keys(self::STATES));
            }
            if ($matches('No classified outcome recorded')) {
                $match->orWhere(fn (Builder $unknown) => $unknown->whereNull('it_outcome_code')->orWhereNotIn('it_outcome_code', array_keys(self::OUTCOMES)));
            }
        });
    }

    public function authorizedRecord(User $viewer, string $source, int $id): Model
    {
        abort_unless(in_array($source, ['device', 'fleet'], true), 404);
        abort_unless($this->canView($viewer, $source), 403);
        $model = $source === 'device' ? new DeviceEventSignalOutbox : new FleetSignalOutbox;
        abort_unless(Schema::hasColumns($model->getTable(), ['it_scope', 'it_status']), 503);

        return $this->visibleQuery($viewer, $source, $model)->whereKey($id)->firstOrFail();
    }

    private function canView(User $viewer, string $source): bool
    {
        return $viewer->approved_at !== null && $viewer->canDo('it.manage')
            && $viewer->canDo('securityDevices.devices.view')
            && ($source !== 'fleet' || $viewer->canDo('assets.viewAny') || $viewer->canDo('assets.viewAssigned'));
    }

    private function timestamp(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}
