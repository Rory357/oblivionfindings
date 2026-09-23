<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetDocumentSet;
use App\Models\FleetKeyLog;
use App\Models\FleetServiceSchedule;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleComplianceRecord;
use App\Models\FleetVehicleComplianceVersion;
use App\Models\FleetVehicleReminder;
use App\Models\FleetVehicleUnavailablePeriod;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\Fleet\Data\VehicleReadinessContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * One vehicle's calendar: service appointments and estimates (Maintenance),
 * bookings and unavailable periods, due reminders and restriction records.
 * Items use the shared Site Calendar shape so the same views render them.
 * Bookings the viewer can't open show as "Busy" with no details.
 */
class VehicleCalendarService
{
    private const ZONE = 'Pacific/Auckland';

    public const BOOKING_STATUS_LABELS = [
        'pending' => 'Pending approval',
        'approved' => 'Confirmed',
        'checked_out' => 'Checked out',
        'returned' => 'Returned',
        'rejected' => 'Declined',
        'cancelled' => 'Cancelled',
    ];

    public function __construct(
        private readonly VehicleBookingAccessService $bookings,
        private readonly MaintenanceAccessService $maintenance,
        private readonly VehicleReadinessService $readiness,
        private readonly VehicleStaffDirectory $staff,
    ) {}

    /** @return list<array<string,mixed>> */
    public function events(User $viewer, Asset $asset, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $items = [];
        $readsMaintenance = $this->readsMaintenance($viewer, $asset);
        if ($readsMaintenance) {
            array_push($items, ...$this->restrictionItems($asset, $start, $end));
            array_push($items, ...$this->appointmentItems($asset, $start, $end));
            array_push($items, ...$this->estimateItems($asset, $start, $end));
        }
        array_push($items, ...$this->bookingItems($viewer, $asset, $start, $end));
        array_push($items, ...$this->unavailableItems($asset, $start, $end));
        array_push($items, ...$this->reminderItems($asset, $start, $end));

        usort($items, fn (array $a, array $b): int => strcmp((string) $a['start'], (string) $b['start']));

        return $items;
    }

    /** @return array<string,mixed> */
    public function summary(User $viewer, Asset $asset): array
    {
        $now = CarbonImmutable::now();
        $readsMaintenance = $this->readsMaintenance($viewer, $asset);
        $restriction = $readsMaintenance ? $this->activeRestriction($asset) : null;
        $assessment = $this->readiness->assess($asset, new VehicleReadinessContext(
            purpose: 'booking_request', startsAt: $now, endsAt: $now->addHour(),
        ));
        $useProblem = collect($assessment->reasons)
            ->first(fn ($reason): bool => $reason->blocksDecision && ! str_starts_with($reason->code, 'driver.')
                && ! str_starts_with($reason->code, 'booking.'));

        $upcoming = $this->events($viewer, $asset, $now->startOfDay(), $now->addYear());
        $nextAppointment = collect($upcoming)->first(fn (array $item): bool => $item['kind'] === 'appointment'
            && $item['status'] !== 'completed');
        $nextDue = collect($upcoming)->first(fn (array $item): bool => $item['source'] === 'compliance');

        return [
            'asset' => [
                'id' => $asset->id, 'name' => $asset->name, 'asset_tag' => $asset->asset_tag,
                'registration_number' => $asset->registration_number,
            ],
            'restriction' => $restriction,
            'use_problem' => $useProblem?->message,
            'readiness_label' => $restriction ? 'Restricted' : ($assessment->canProceed ? 'Ready' : 'Needs assessment'),
            'next_appointment' => $nextAppointment ? ['start' => $nextAppointment['start'], 'title' => $nextAppointment['title'], 'id' => $nextAppointment['id']] : null,
            'next_due' => $nextDue ? ['start' => $nextDue['start'], 'title' => $nextDue['title'], 'id' => $nextDue['id']] : null,
            'bookings' => $this->bookingRows($viewer, $asset),
            'drivers' => $this->drivers($asset),
            'open_work' => $readsMaintenance ? $this->openWork($asset) : [],
            'can' => [
                'request' => $viewer->canDo('fleet.viewAny') || $viewer->canDo('assets.viewAny'),
                'manage' => $viewer->canDo('fleet.manage'),
                'approve' => $viewer->canDo('fleet.bookings.approve') || $viewer->canDo('fleet.manage'),
                'authority' => $viewer->canDo('fleet.bookings.approve') || $viewer->canDo('fleet.manage'),
                'schedule_service' => $this->maintenance->canManage($viewer)
                    && in_array((int) $asset->site_id, $this->maintenance->approvedSiteIds($viewer), true),
                'report_work' => $this->maintenance->canReport($viewer),
                'add_reminder' => $viewer->canDo('fleet.manage'),
                'mark_unavailable' => $viewer->canDo('fleet.manage'),
                'view_maintenance' => $readsMaintenance,
            ],
        ];
    }

    private function readsMaintenance(User $viewer, Asset $asset): bool
    {
        return $this->maintenance->canRead($viewer)
            && in_array((int) $asset->site_id, $this->maintenance->approvedSiteIds($viewer), true);
    }

    /** @return array<string,mixed>|null */
    private function activeRestriction(Asset $asset): ?array
    {
        $row = DB::table('fleet_maintenance_restrictions as restriction')
            ->join('fleet_work_orders as work', 'work.id', '=', 'restriction.work_order_id')
            ->where('restriction.asset_id', $asset->id)->where('restriction.state', 'active')
            ->orderBy('restriction.created_at')
            ->first(['restriction.id', 'restriction.created_at', 'restriction.restriction_kind',
                'work.id as work_order_id', 'work.reference_number', 'work.title']);

        return $row ? [
            'id' => (int) $row->id,
            'started_at' => CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone(self::ZONE)->toIso8601String(),
            'kind' => $row->restriction_kind,
            'work_order_id' => (int) $row->work_order_id,
            'work_reference' => $row->reference_number,
            'work_title' => $row->title,
        ] : null;
    }

    /** @return list<array<string,mixed>> */
    private function restrictionItems(Asset $asset, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return DB::table('fleet_maintenance_restrictions as restriction')
            ->join('fleet_work_orders as work', 'work.id', '=', 'restriction.work_order_id')
            ->where('restriction.asset_id', $asset->id)
            ->where('restriction.created_at', '<', $end->utc())
            ->where(fn ($open) => $open->where('restriction.state', 'active')
                ->orWhere('restriction.released_at', '>=', $start->utc()))
            ->orderBy('restriction.id')
            ->get(['restriction.id', 'restriction.state', 'restriction.created_at', 'restriction.released_at',
                'work.id as work_order_id', 'work.reference_number'])
            ->map(function (object $row): array {
                $active = $row->state === 'active';
                $started = CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone(self::ZONE)->startOfDay();
                $released = $row->released_at
                    ? CarbonImmutable::parse($row->released_at, 'UTC')->setTimezone(self::ZONE)->addDay()->startOfDay()
                    : null;

                return $this->item(
                    id: 'restriction:'.$row->id, kind: 'restriction', source: 'damage',
                    title: $active ? 'Restriction started · still active' : 'Restriction · released',
                    start: $started, end: $active ? null : $released, allDay: true,
                    status: $active ? 'overdue' : 'completed', statusLabel: $active ? 'Active restriction' : 'Released',
                    ref: $row->reference_number, recordId: (int) $row->id,
                    link: '/fleet-assets/maintenance/work-orders/'.$row->work_order_id,
                    desc: $active ? 'No end or authorised release recorded. Calendar gaps do not mean available.' : null,
                    workOrderId: (int) $row->work_order_id,
                );
            })->all();
    }

    /** Latest provider action per work order wins; one stable identity per work order. @return list<array<string,mixed>> */
    private function appointmentItems(Asset $asset, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $workIds = DB::table('fleet_maintenance_actions as action')
            ->join('fleet_work_orders as work', 'work.id', '=', 'action.work_order_id')
            ->where('work.asset_id', $asset->id)->where('action.action_type', 'plan_provider')
            ->distinct()->pluck('action.work_order_id')->all();
        if ($workIds === []) {
            return [];
        }
        $latest = [];
        DB::table('fleet_maintenance_actions')->whereIn('work_order_id', $workIds)
            ->whereIn('action_type', ['plan_provider', 'record_provider_confirmation',
                'record_provider_cancellation', 'record_provider_completion'])
            ->orderBy('id')->get(['work_order_id', 'action_type', 'payload_json'])
            ->each(function (object $action) use (&$latest): void {
                $id = (int) $action->work_order_id;
                $latest[$id]['state'] = $action->action_type;
                if ($action->action_type === 'plan_provider') {
                    $latest[$id]['plan'] = json_decode((string) $action->payload_json, true);
                }
            });
        $work = FleetWorkOrder::query()->whereKey($workIds)->get(['id', 'reference_number', 'title', 'status', 'version']);
        $held = FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)
            ->whereIn('work_order_id', $workIds)->where('state', FleetVehicleUnavailablePeriod::STATE_ACTIVE)
            ->pluck('work_order_id')->map(fn (mixed $id): int => (int) $id)->all();
        $items = [];
        foreach ($work as $order) {
            $source = $latest[$order->id] ?? null;
            $plan = $source['plan'] ?? null;
            if (! is_array($plan) || empty($plan['starts_at']) || empty($plan['ends_at'])
                || $source['state'] === 'record_provider_cancellation' || $order->status === 'cancelled') {
                continue;
            }
            $starts = CarbonImmutable::parse($plan['starts_at'], 'UTC')->setTimezone(self::ZONE);
            $ends = CarbonImmutable::parse($plan['ends_at'], 'UTC')->setTimezone(self::ZONE);
            if (! $starts->lessThan($end) || ! $ends->greaterThan($start)) {
                continue;
            }
            [$status, $label] = match ($source['state']) {
                'record_provider_confirmation' => ['approved', 'Confirmed appointment'],
                'record_provider_completion' => ['completed', 'Provider completed'],
                default => ['scheduled', 'Planned appointment'],
            };
            $items[] = $this->item(
                id: 'appointment:'.$order->id, kind: 'appointment', source: 'event',
                title: ($order->title ?: 'Maintenance work').' · '.$label,
                start: $starts, end: $ends, allDay: false, status: $status, statusLabel: $label,
                ref: $order->reference_number, recordId: (int) $order->id,
                link: '/fleet-assets/maintenance/work-orders/'.$order->id,
                desc: 'Provider: '.($plan['provider_name'] ?? 'Not recorded').'. The provider response is recorded on the work order.',
                workOrderId: (int) $order->id,
                meta: [
                    'provider' => $plan['provider_name'] ?? null,
                    'unavailable' => in_array((int) $order->id, $held, true),
                    'open' => in_array($order->status, ['open', 'in_progress', 'on_hold'], true)
                        && $source['state'] !== 'record_provider_completion',
                ],
            );
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function estimateItems(Asset $asset, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return DB::table('fleet_maintenance_reports as report')
            ->join('fleet_work_orders as work', 'work.id', '=', 'report.work_order_id')
            ->where('report.asset_id', $asset->id)
            ->whereNotIn('work.status', ['completed', 'cancelled'])
            ->whereNotNull('report.estimated_start_date')->whereNotNull('report.estimated_end_date')
            ->where('report.estimated_start_date', '<=', $end->toDateString())
            ->where('report.estimated_end_date', '>=', $start->toDateString())
            ->orderBy('report.id')
            ->get(['report.id', 'report.work_order_id', 'report.estimated_start_date', 'report.estimated_end_date',
                'work.reference_number'])
            ->map(fn (object $row): array => $this->item(
                id: 'estimate:'.$row->id, kind: 'estimate', source: 'asset',
                title: 'Estimated work · advisory',
                start: CarbonImmutable::parse($row->estimated_start_date, self::ZONE)->startOfDay(),
                end: CarbonImmutable::parse($row->estimated_end_date, self::ZONE)->addDay()->startOfDay(),
                allDay: true, status: 'scheduled', statusLabel: 'Advisory dates',
                ref: $row->reference_number, recordId: (int) $row->work_order_id,
                link: '/fleet-assets/maintenance/work-orders/'.$row->work_order_id,
                desc: 'Planning estimate from a maintenance report. It does not reserve the vehicle.',
                workOrderId: (int) $row->work_order_id,
            ))->all();
    }

    /** @return list<array<string,mixed>> */
    private function bookingItems(User $viewer, Asset $asset, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = FleetVehicleBooking::query()->where('asset_id', $asset->id)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())
            ->orderBy('starts_at')->limit(500)
            ->get(['id', 'reference_number', 'purpose', 'status', 'starts_at', 'ends_at']);
        $visible = $this->bookings->accessibleBookings($viewer)->whereKey($rows->pluck('id'))->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)->all();

        return $rows->map(function (FleetVehicleBooking $booking) use ($visible): array {
            $starts = CarbonImmutable::parse($booking->starts_at)->setTimezone(self::ZONE);
            $ends = CarbonImmutable::parse($booking->ends_at)->setTimezone(self::ZONE);
            if (! in_array((int) $booking->id, $visible, true)) {
                return $this->item(
                    id: 'busy:'.$booking->id, kind: 'busy', source: 'respite', title: 'Busy',
                    start: $starts, end: $ends, allDay: false, status: 'scheduled', statusLabel: 'Busy only',
                    ref: null, recordId: null, link: null, desc: 'Booking details are restricted.',
                );
            }
            $label = $this->bookingLabel($booking);

            return $this->item(
                id: 'booking:'.$booking->id, kind: 'booking', source: 'respite',
                title: ($booking->purpose ?: 'Booking').' · '.$label,
                start: $starts, end: $ends, allDay: false,
                status: match ($booking->status) {
                    'pending' => 'pending', 'approved', 'checked_out' => 'approved', default => 'completed',
                },
                statusLabel: $label, ref: $booking->reference_number, recordId: (int) $booking->id,
                link: '/fleet-assets/bookings/'.$booking->id, desc: null,
            );
        })->all();
    }

    /** @return list<array<string,mixed>> */
    private function unavailableItems(Asset $asset, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)
            ->overlapping($start->utc(), $end->utc())->orderBy('starts_at')->limit(200)->get()
            ->map(fn (FleetVehicleUnavailablePeriod $period): array => $this->item(
                id: 'unavailable:'.$period->id, kind: 'unavailable', source: 'respite',
                title: 'Unavailable · '.$period->reason,
                start: CarbonImmutable::parse($period->starts_at)->setTimezone(self::ZONE),
                end: CarbonImmutable::parse($period->ends_at)->setTimezone(self::ZONE),
                allDay: false, status: 'scheduled', statusLabel: 'Unavailable',
                ref: null, recordId: (int) $period->id, link: null,
                desc: 'Marked unavailable on the calendar. It does not create or clear a safety restriction.',
                workOrderId: $period->work_order_id ? (int) $period->work_order_id : null,
            ))->all();
    }

    /** Due dates and follow-ups; none of them reserve the vehicle. @return list<array<string,mixed>> */
    private function reminderItems(Asset $asset, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $items = [];
        FleetServiceSchedule::query()->where('asset_id', $asset->id)->where('is_active', true)
            ->whereNotNull('next_due_at')->whereBetween('next_due_at', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'name', 'next_due_at'])
            ->each(function (FleetServiceSchedule $schedule) use (&$items): void {
                $items[] = $this->item(
                    id: 'schedule:'.$schedule->id, kind: 'schedule', source: 'compliance',
                    title: ($schedule->name ?: 'Service').' due · reminder',
                    start: CarbonImmutable::parse($schedule->next_due_at->toDateString(), self::ZONE)->startOfDay(),
                    end: null, allDay: true, status: 'scheduled', statusLabel: 'Due reminder',
                    ref: null, recordId: (int) $schedule->id, link: null, desc: null,
                );
            });

        $records = FleetVehicleComplianceRecord::query()->where('asset_id', $asset->id)
            ->whereNotNull('current_version_id')->get(['id', 'kind', 'current_version_id'])->keyBy('current_version_id');
        if ($records->isNotEmpty()) {
            FleetVehicleComplianceVersion::query()->whereKey($records->keys())
                ->where('applicability', 'applicable')->whereNotNull('expires_on')
                ->whereBetween('expires_on', [$start->toDateString(), $end->toDateString()])
                ->get(['id', 'expires_on'])
                ->each(function (FleetVehicleComplianceVersion $version) use ($records, &$items): void {
                    $record = $records->get($version->id);
                    $label = VehicleComplianceService::LABELS[$record->kind] ?? $record->kind;
                    $items[] = $this->item(
                        id: 'compliance:'.$record->kind, kind: 'compliance', source: 'compliance',
                        title: $label.' due · reminder',
                        start: CarbonImmutable::parse($version->expires_on->toDateString(), self::ZONE)->startOfDay(),
                        end: null, allDay: true, status: 'scheduled', statusLabel: 'Due reminder',
                        ref: null, recordId: (int) $record->id, link: null, desc: null,
                    );
                });
        }

        if ($asset->inspection_due_at && $asset->inspection_due_at->betweenIncluded($start->startOfDay(), $end)) {
            $items[] = $this->item(
                id: 'check-due', kind: 'check', source: 'compliance', title: 'Vehicle check due · reminder',
                start: CarbonImmutable::parse($asset->inspection_due_at->toDateString(), self::ZONE)->startOfDay(),
                end: null, allDay: true, status: 'scheduled', statusLabel: 'Due reminder',
                ref: null, recordId: null, link: null, desc: null,
            );
        }

        FleetVehicleReminder::query()->where('asset_id', $asset->id)
            ->whereIn('state', ['scheduled', 'acknowledged'])
            ->whereBetween('due_at', [$start->utc(), $end->utc()])
            ->orderBy('due_at')->limit(200)->get(['id', 'title', 'due_at', 'state'])
            ->each(function (FleetVehicleReminder $reminder) use (&$items): void {
                $items[] = $this->item(
                    id: 'reminder:'.$reminder->id, kind: 'reminder', source: 'compliance',
                    title: $reminder->title.' · reminder',
                    start: CarbonImmutable::parse($reminder->due_at)->setTimezone(self::ZONE),
                    end: null, allDay: false,
                    status: $reminder->state === 'acknowledged' ? 'approved' : 'scheduled',
                    statusLabel: $reminder->state === 'acknowledged' ? 'Acknowledged' : 'Follow-up',
                    ref: null, recordId: (int) $reminder->id, link: null, desc: null,
                );
            });

        return $items;
    }

    /** Bookings and unavailable periods for the custody list. @return list<array<string,mixed>> */
    private function bookingRows(User $viewer, Asset $asset): array
    {
        $live = ['pending', 'approved', 'checked_out'];
        $active = $this->bookings->accessibleBookings($viewer)->where('asset_id', $asset->id)
            ->whereIn('status', $live)->with(['user:id,name', 'driver:id,name'])
            ->orderBy('starts_at')->limit(50)->get();
        $recent = $this->bookings->accessibleBookings($viewer)->where('asset_id', $asset->id)
            ->whereNotIn('status', $live)->with(['user:id,name', 'driver:id,name'])
            ->orderByDesc('ends_at')->limit(10)->get();
        $shown = $active->concat($recent)->values();
        $history = $this->history(FleetVehicleBooking::class, $shown->pluck('id')->all(), 'fleet.booking.');
        $keys = FleetKeyLog::query()->whereIn('booking_id', $shown->pluck('id'))->orderBy('id')
            ->with('user:id,name')->get(['id', 'booking_id', 'action', 'user_id', 'created_at'])->groupBy('booking_id');
        $seesFiles = Gate::forUser($viewer)->allows('view', $asset);
        $uploads = Gate::forUser($viewer)->allows('manageDocuments', $asset);
        $files = $seesFiles
            ? AssetDocumentSet::query()->where('asset_id', $asset->id)->where('source_type', 'booking')
                ->whereIn('source_id', $shown->pluck('id'))->with('files')->get()->groupBy('source_id')
            : collect();
        $manage = $viewer->canDo('fleet.manage');
        $approve = $viewer->canDo('fleet.bookings.approve') || $manage;

        $rows = $shown->map(function (FleetVehicleBooking $booking) use ($viewer, $history, $keys, $files, $asset, $manage, $approve, $uploads): array {
            $status = $booking->status;
            $selfIndependent = $booking->approval_route !== 'not_required' && (int) $booking->user_id === (int) $viewer->id;

            return [
                'kind' => 'booking',
                'id' => (int) $booking->id,
                'reference' => $booking->reference_number,
                'purpose' => $booking->purpose,
                'destination' => $booking->destination,
                'passengers' => $booking->passengers,
                'notes' => $booking->notes,
                'starts_at' => $booking->starts_at?->toIso8601String(),
                'ends_at' => $booking->ends_at?->toIso8601String(),
                'status' => $status,
                'status_label' => $this->bookingLabel($booking),
                'requester' => $booking->user ? ['id' => $booking->user->id, 'name' => $booking->user->name] : null,
                'driver' => $booking->driver
                    ? ['id' => $booking->driver->id, 'name' => $booking->driver->name]
                    : ($booking->user ? ['id' => $booking->user->id, 'name' => $booking->user->name] : null),
                'approval_route' => $booking->approval_route ?: 'required',
                'approval_not_required_reason' => $booking->approval_not_required_reason,
                'approval_not_required_evidence' => $booking->approval_not_required_evidence,
                'pickup_arrangement' => $booking->pickup_arrangement,
                'odometer_out' => $booking->odometer_out !== null ? (float) $booking->odometer_out : null,
                'odometer_in' => $booking->odometer_in !== null ? (float) $booking->odometer_in : null,
                'checkout_condition' => $booking->checkout_condition,
                'checkout_evidence_reference' => $booking->checkout_evidence_reference,
                'checkout_notes' => $booking->checkout_notes,
                'condition_on_return' => $booking->condition_on_return,
                'return_evidence_reference' => $booking->return_evidence_reference,
                'return_notes' => $booking->return_notes,
                'rejection_reason' => $booking->rejection_reason,
                'cancellation_reason' => $booking->cancellation_reason,
                'lock_version' => (int) ($booking->lock_version ?? 1),
                'history' => $history[(int) $booking->id] ?? [],
                'keys' => ($keys->get($booking->id) ?? collect())->map(fn (FleetKeyLog $log): array => [
                    'action' => $log->action,
                    'holder' => $log->user?->name,
                    'at' => $log->created_at?->toIso8601String(),
                ])->values()->all(),
                'files' => $this->fileRows($asset, $files->get($booking->id)),
                'can' => [
                    'edit' => in_array($status, ['pending', 'approved'], true)
                        && ($manage || ((int) $booking->user_id === (int) $viewer->id && $status === 'pending')),
                    'approve' => $status === 'pending' && $approve && ! $selfIndependent,
                    'decline' => $status === 'pending' && $approve,
                    'checkout' => $status === 'approved' && $manage,
                    'return' => $status === 'checked_out' && $manage,
                    'cancel' => in_array($status, ['pending', 'approved', 'checked_out'], true) && $manage,
                    // Evidence rules follow the vehicle documents endpoint: document
                    // managers, or the requester or an approver of this booking.
                    'upload' => $uploads || $approve || (int) $booking->user_id === (int) $viewer->id,
                ],
            ];
        })->all();

        $periods = FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)
            ->where(fn ($query) => $query->where('state', 'active')->where('ends_at', '>=', now()->subDays(30))
                ->orWhere(fn ($cancelled) => $cancelled->where('state', 'cancelled')->where('cancelled_at', '>=', now()->subDays(30))))
            ->with('workOrder:id,reference_number')->orderBy('starts_at')->limit(40)->get();
        $periodHistory = $this->history(FleetVehicleUnavailablePeriod::class, $periods->pluck('id')->all(), 'fleet.vehicle.unavailable.');
        $periodFiles = $seesFiles
            ? AssetDocumentSet::query()->where('asset_id', $asset->id)->where('source_type', 'unavailable_period')
                ->whereIn('source_id', $periods->pluck('id'))->with('files')->get()->groupBy('source_id')
            : collect();
        foreach ($periods as $period) {
            $rows[] = [
                'kind' => 'unavailable',
                'id' => (int) $period->id,
                'reference' => $period->workOrder?->reference_number,
                'work_order_id' => $period->work_order_id ? (int) $period->work_order_id : null,
                'purpose' => $period->reason,
                'starts_at' => $period->starts_at?->toIso8601String(),
                'ends_at' => $period->ends_at?->toIso8601String(),
                'status' => $period->state,
                'status_label' => $period->state === 'active' ? 'Unavailable' : 'Cancelled',
                'cancellation_reason' => $period->cancellation_reason,
                'lock_version' => (int) $period->lock_version,
                'history' => $periodHistory[(int) $period->id] ?? [],
                'files' => $this->fileRows($asset, $periodFiles->get($period->id)),
                'can' => [
                    'edit' => $period->state === 'active' && $manage,
                    'cancel' => $period->state === 'active' && $manage,
                    'upload' => $uploads,
                ],
            ];
        }

        return $rows;
    }

    /** @return list<array{id:int,name:string,licence_status:?string,licence_expires_at:?string}> */
    private function drivers(Asset $asset): array
    {
        $candidates = $this->staff->candidates($asset);
        if ($candidates->isEmpty()) {
            return [];
        }
        $eligibility = DB::table('hr_driver_eligibility')->whereIn('user_id', $candidates->pluck('id'))
            ->get(['user_id', 'status', 'licence_expires_at'])->keyBy('user_id');

        return $candidates->map(fn (array $person): array => [
            'id' => (int) $person['id'],
            'name' => (string) $person['name'],
            'licence_status' => $eligibility->get($person['id'])?->status,
            'licence_expires_at' => $eligibility->get($person['id'])?->licence_expires_at
                ? CarbonImmutable::parse($eligibility->get($person['id'])->licence_expires_at)->toDateString() : null,
        ])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function openWork(Asset $asset): array
    {
        return FleetWorkOrder::query()->where('asset_id', $asset->id)
            ->whereIn('status', ['open', 'in_progress', 'on_hold'])->orderByDesc('id')->limit(30)
            ->get(['id', 'reference_number', 'title', 'status', 'version'])
            ->map(fn (FleetWorkOrder $order): array => [
                'id' => (int) $order->id, 'reference' => $order->reference_number,
                'title' => $order->title, 'status' => $order->status, 'version' => (int) $order->version,
            ])->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int,list<array{label:string,reason:?string,actor:?string,at:string}>>
     */
    private function history(string $modelClass, array $ids, string $prefix): array
    {
        if ($ids === []) {
            return [];
        }
        $labels = [
            'fleet.booking.create' => 'Requested', 'fleet.booking.update' => 'Changed',
            'fleet.booking.approve' => 'Approved', 'fleet.booking.reject' => 'Declined',
            'fleet.booking.checkout' => 'Checked out', 'fleet.booking.return' => 'Returned',
            'fleet.booking.cancel' => 'Cancelled',
            'fleet.vehicle.unavailable.create' => 'Recorded', 'fleet.vehicle.unavailable.update' => 'Changed',
            'fleet.vehicle.unavailable.cancel' => 'Cancelled',
        ];
        $morph = (new $modelClass)->getMorphClass();

        return DB::table('audit_logs as log')->leftJoin('users', 'users.id', '=', 'log.user_id')
            ->where('log.auditable_type', $morph)->whereIn('log.auditable_id', $ids)
            ->where('log.action', 'like', $prefix.'%')->orderBy('log.id')
            ->get(['log.auditable_id', 'log.action', 'log.meta', 'log.created_at', 'users.name as actor'])
            ->groupBy('auditable_id')
            ->map(fn (Collection $rows): array => $rows->map(function (object $row) use ($labels): array {
                $meta = json_decode((string) $row->meta, true) ?: [];

                return [
                    'label' => $labels[$row->action] ?? 'Updated',
                    'reason' => $meta['reason'] ?? null,
                    'actor' => $row->actor,
                    'at' => CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone(self::ZONE)->toIso8601String(),
                ];
            })->values()->all())
            ->mapWithKeys(fn (array $rows, mixed $id): array => [(int) $id => $rows])->all();
    }

    /**
     * Current files kept with a booking or unavailable period.
     *
     * @param  Collection<int,AssetDocumentSet>|null  $sets
     * @return list<array{id:int,name:string,state:?string,url:?string}>
     */
    private function fileRows(Asset $asset, ?Collection $sets): array
    {
        return ($sets ?? collect())
            ->flatMap(fn (AssetDocumentSet $set) => $set->files->whereNull('archived_at'))
            ->map(fn (AssetDocument $file): array => [
                'id' => (int) $file->id,
                'name' => (string) ($file->original_name ?: $file->title),
                'state' => $file->state,
                'url' => $file->isOpenable()
                    ? route('fleet-assets.vehicles.documents.file', ['asset' => $asset->id, 'document' => $file->id])
                    : null,
            ])->values()->all();
    }

    private function bookingLabel(FleetVehicleBooking $booking): string
    {
        if ($booking->status === 'checked_out' && $booking->ends_at && $booking->ends_at->isPast()) {
            return 'Overdue return';
        }

        return self::BOOKING_STATUS_LABELS[$booking->status] ?? ucfirst(str_replace('_', ' ', (string) $booking->status));
    }

    /** @return array<string,mixed> */
    private function item(
        string $id, string $kind, string $source, string $title, CarbonInterface $start, ?CarbonInterface $end,
        bool $allDay, string $status, string $statusLabel, ?string $ref, ?int $recordId, ?string $link, ?string $desc,
        ?int $workOrderId = null, ?array $meta = null,
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'source' => $source,
            'group' => 'auto',
            'title' => $title,
            'start' => $start->toIso8601String(),
            'end' => $end?->toIso8601String(),
            'allDay' => $allDay,
            'status' => $status,
            'statusLabel' => $statusLabel,
            'owner' => null,
            'room' => null,
            'ref' => $ref,
            'site' => null,
            'link' => $link,
            'editable' => false,
            'recordId' => $recordId,
            'workOrderId' => $workOrderId,
            'desc' => $desc,
            'meta' => $meta,
        ];
    }
}
