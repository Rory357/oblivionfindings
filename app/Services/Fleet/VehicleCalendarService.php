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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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

    private const OPEN_WORK = ['open', 'in_progress', 'on_hold'];

    /** Provider actions that leave an appointment planned. */
    private const LIVE_PROVIDER_STATES = ['plan_provider', 'record_provider_confirmation'];

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
        return $this->feed($viewer, $asset, $start, $end, $this->bookable($viewer, $asset), $this->readsMaintenance($viewer, $asset));
    }

    /** @return list<array<string,mixed>> */
    private function feed(User $viewer, Asset $asset, CarbonImmutable $start, CarbonImmutable $end, bool $bookable, bool $readsMaintenance): array
    {
        $items = [];
        if ($readsMaintenance) {
            $plans = $this->providerPlans($asset);
            $held = $this->heldWorkIds($asset);
            array_push($items, ...$this->restrictionItems($asset, $start, $end));
            array_push($items, ...$this->appointmentItems($plans, $held, $start, $end));
            array_push($items, ...$this->estimateItems($asset, $plans, $held, $start, $end));
        }
        array_push($items, ...$this->bookingItems($viewer, $asset, $start, $end));
        array_push($items, ...$this->unavailableItems($asset, $start, $end, $bookable, $readsMaintenance));
        array_push($items, ...$this->reminderItems($viewer, $asset, $start, $end));

        usort($items, fn (array $a, array $b): int => strcmp((string) $a['start'], (string) $b['start']));

        return $items;
    }

    /** @return array<string,mixed> */
    public function summary(User $viewer, Asset $asset): array
    {
        $now = CarbonImmutable::now();
        $readsMaintenance = $this->readsMaintenance($viewer, $asset);
        $restriction = $readsMaintenance ? $this->activeRestriction($asset) : null;
        $bookable = $this->bookable($viewer, $asset);
        $assessment = $this->readiness->assess($asset, new VehicleReadinessContext(
            purpose: 'booking_request', startsAt: $now, endsAt: $now->addHour(),
        ));
        $useProblem = collect($assessment->reasons)
            ->first(fn ($reason): bool => $reason->blocksDecision && ! str_starts_with($reason->code, 'driver.')
                && ! str_starts_with($reason->code, 'booking.'));

        $upcoming = $this->feed($viewer, $asset, $now->startOfDay(), $now->addYear(), $bookable, $readsMaintenance);
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
            // Lets the calendar link the problem to where it's resolved.
            'use_problem_code' => $useProblem?->code,
            'use_problem_kind' => $useProblem?->kind,
            // The reason's source record, e.g. the check run holding the vehicle.
            'use_problem_source_id' => $useProblem?->sourceId,
            'readiness_label' => $restriction ? 'Restricted' : ($assessment->canProceed ? 'Ready' : 'Needs assessment'),
            'next_appointment' => $nextAppointment ? ['start' => $nextAppointment['start'], 'title' => $nextAppointment['title'], 'id' => $nextAppointment['id']] : null,
            'next_due' => $nextDue ? ['start' => $nextDue['start'], 'title' => $nextDue['title'], 'id' => $nextDue['id']] : null,
            'bookings' => $this->bookingRows($viewer, $asset, $bookable, $readsMaintenance),
            // Drivers follow the booking rule (the vehicle's Site).
            'drivers' => $bookable ? $this->drivers($asset) : [],
            'open_work' => $readsMaintenance ? $this->openWork($asset) : [],
            'can' => [
                'view_bookings' => $bookable,
                'request' => $bookable && ($viewer->canDo('fleet.viewAny') || $viewer->canDo('assets.viewAny')),
                'manage' => $viewer->canDo('fleet.manage'),
                'approve' => $bookable && ($viewer->canDo('fleet.bookings.approve') || $viewer->canDo('fleet.manage')),
                'authority' => $bookable && ($viewer->canDo('fleet.bookings.approve') || $viewer->canDo('fleet.manage')),
                'schedule_service' => $this->maintenance->canManage($viewer)
                    && in_array((int) $asset->site_id, $this->maintenance->approvedSiteIds($viewer), true),
                'report_work' => $this->maintenance->canReport($viewer),
                'add_reminder' => $viewer->canDo('fleet.manage'),
                'mark_unavailable' => $bookable && $viewer->canDo('fleet.manage'),
                'view_maintenance' => $readsMaintenance,
                // An authorised release reviewer for this vehicle's Site and category.
                'review_release' => $this->maintenance->canReview($viewer, $asset),
            ],
        ];
    }

    /**
     * One booking or unavailable period, exactly as the summary lists it, for
     * a record outside the summary's capped lists. Both keep the booking rule
     * (the viewer's own Sites); periods must belong to the vehicle. Null when
     * not found or not open to the viewer.
     *
     * @return array<string,mixed>|null
     */
    public function record(User $viewer, Asset $asset, string $kind, int $id): ?array
    {
        if ($kind === 'booking') {
            $booking = $this->custodyBookings($viewer, $asset)->whereKey($id)->first();

            return $booking ? $this->bookingCustodyRows($viewer, $asset, collect([$booking]))[0] : null;
        }
        if ($kind === 'unavailable' && $this->bookable($viewer, $asset)) {
            $period = $this->custodyPeriods($asset)->whereKey($id)->first();

            return $period ? $this->periodCustodyRows($viewer, $asset, collect([$period]),
                $this->readsMaintenance($viewer, $asset))[0] : null;
        }

        return null;
    }

    private function readsMaintenance(User $viewer, Asset $asset): bool
    {
        return $this->maintenance->canRead($viewer)
            && in_array((int) $asset->site_id, $this->maintenance->approvedSiteIds($viewer), true);
    }

    /**
     * Whether the vehicle is at one of the viewer's own Sites. Central fleet
     * oversight opens the vehicle's records; its bookings, drivers and
     * unavailable periods keep this booking rule.
     */
    private function bookable(User $viewer, Asset $asset): bool
    {
        return $this->bookings->vehicle($viewer, (int) $asset->id) !== null;
    }

    /** @return array<string,mixed>|null */
    private function activeRestriction(Asset $asset): ?array
    {
        $row = $this->restrictionRows($asset)->where('restriction.state', 'active')
            ->orderBy('restriction.created_at')->orderBy('restriction.id')->first();
        if (! $row) {
            return null;
        }
        $record = $this->restrictionRecord($row);

        return [
            'id' => (int) $row->id,
            'started_at' => CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone(self::ZONE)->toIso8601String(),
            'kind' => $row->restriction_kind,
            'work_order_id' => (int) $row->work_order_id,
            'work_reference' => $row->reference_number,
            'work_title' => $row->title,
            'work_status' => $record['work_status'],
            'owner' => $record['owner'],
            'source_check' => $record['source_check'],
        ];
    }

    /**
     * Restriction records. An active one has no end yet, so it spans the whole
     * browsed window; a released one ends on its release day.
     *
     * @return list<array<string,mixed>>
     */
    private function restrictionItems(Asset $asset, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $windowEnd = $end->setTimezone(self::ZONE);

        return $this->restrictionRows($asset)
            ->where('restriction.created_at', '<', $end->utc())
            ->where(fn ($open) => $open->where('restriction.state', 'active')
                ->orWhere('restriction.released_at', '>=', $start->utc()))
            ->orderBy('restriction.id')->get()
            ->map(function (object $row) use ($windowEnd): array {
                $active = $row->state === 'active';
                $started = CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone(self::ZONE)->startOfDay();
                $released = $row->released_at
                    ? CarbonImmutable::parse($row->released_at, 'UTC')->setTimezone(self::ZONE)->addDay()->startOfDay()
                    : null;

                return $this->item(
                    id: 'restriction:'.$row->id, kind: 'restriction', source: 'damage',
                    title: $active ? 'Restriction started · still active' : 'Restriction · released',
                    start: $started, end: $active ? $windowEnd : $released, allDay: true,
                    status: $active ? 'overdue' : 'completed', statusLabel: $active ? 'Active restriction' : 'Released',
                    ref: $row->reference_number, recordId: (int) $row->id,
                    link: '/fleet-assets/maintenance/work-orders/'.$row->work_order_id,
                    desc: $active ? 'No end or authorised release recorded. Calendar gaps do not mean available.' : null,
                    workOrderId: (int) $row->work_order_id,
                    meta: $this->restrictionRecord($row),
                );
            })->all();
    }

    /** A vehicle's restrictions with their work, its owner and the check that raised each. */
    private function restrictionRows(Asset $asset): QueryBuilder
    {
        return DB::table('fleet_maintenance_restrictions as restriction')
            ->join('fleet_work_orders as work', 'work.id', '=', 'restriction.work_order_id')
            ->leftJoin('users as owner', 'owner.id', '=', 'work.assigned_to_user_id')
            ->leftJoin('fleet_checklist_runs as run', 'run.id', '=', 'restriction.source_run_id')
            ->leftJoin('fleet_checklist_templates as template', 'template.id', '=', 'run.template_id')
            ->where('restriction.asset_id', $asset->id)
            ->select(['restriction.id', 'restriction.state', 'restriction.created_at', 'restriction.released_at',
                'restriction.restriction_kind', 'work.id as work_order_id', 'work.reference_number', 'work.title',
                'work.status as work_status', 'owner.name as owner_name', 'run.id as run_id', 'run.outcome as run_outcome',
                'run.presented_template_json as run_template', 'template.name as template_name']);
    }

    /**
     * The restriction record shown in place: the work's owner and status and
     * the check that raised it.
     *
     * @return array{owner:?string,source_check:?array{id:int,label:string,outcome:string},work_status:?string,work_reference:?string}
     */
    private function restrictionRecord(object $row): array
    {
        $check = null;
        if ($row->run_id !== null) {
            $presented = json_decode((string) $row->run_template, true);
            $name = is_array($presented) && is_string($presented['name'] ?? null) && trim($presented['name']) !== ''
                ? trim($presented['name']) : ($row->template_name ?: 'Vehicle check');
            $check = [
                'id' => (int) $row->run_id,
                'label' => $name.' · CHK-'.$row->run_id,
                'outcome' => (string) ($row->run_outcome ?? 'needs_assessment'),
            ];
        }

        return [
            'owner' => $row->owner_name,
            'source_check' => $check,
            'work_status' => $row->work_status,
            'work_reference' => $row->reference_number,
        ];
    }

    /**
     * The latest provider action and plan of each work order of this vehicle
     * that was ever planned with a provider.
     *
     * @return array<int, array{state:string, plan:?array<string,mixed>}>
     */
    private function providerPlans(Asset $asset): array
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

        return array_map(fn (array $row): array => [
            'state' => (string) $row['state'],
            'plan' => is_array($row['plan'] ?? null) ? $row['plan'] : null,
        ], $latest);
    }

    /** Work orders whose appointment holds the vehicle now. @return list<int> */
    private function heldWorkIds(Asset $asset): array
    {
        return FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)->whereNotNull('work_order_id')
            ->where('state', FleetVehicleUnavailablePeriod::STATE_ACTIVE)
            ->pluck('work_order_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * Latest provider action per work order wins; one stable identity per work order.
     *
     * @param  array<int, array{state:string, plan:?array<string,mixed>}>  $plans
     * @param  list<int>  $held
     * @return list<array<string,mixed>>
     */
    private function appointmentItems(array $plans, array $held, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($plans === []) {
            return [];
        }
        $work = FleetWorkOrder::query()->whereKey(array_keys($plans))->get(['id', 'reference_number', 'title', 'status', 'version']);
        $items = [];
        foreach ($work as $order) {
            $source = $plans[(int) $order->id] ?? null;
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
                    'open' => in_array($order->status, self::OPEN_WORK, true)
                        && $source['state'] !== 'record_provider_completion',
                ],
            );
        }

        return $items;
    }

    /**
     * Advisory estimates; one whose work already has a planned appointment
     * carries it, so the calendar can show both together.
     *
     * @param  array<int, array{state:string, plan:?array<string,mixed>}>  $plans
     * @param  list<int>  $held
     * @return list<array<string,mixed>>
     */
    private function estimateItems(Asset $asset, array $plans, array $held, CarbonImmutable $start, CarbonImmutable $end): array
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
            ->map(function (object $row) use ($plans, $held): array {
                $appointment = $this->liveAppointment($plans[(int) $row->work_order_id] ?? null, (int) $row->work_order_id, $held);

                return $this->item(
                    id: 'estimate:'.$row->id, kind: 'estimate', source: 'asset',
                    title: 'Estimated work · advisory',
                    start: CarbonImmutable::parse($row->estimated_start_date, self::ZONE)->startOfDay(),
                    end: CarbonImmutable::parse($row->estimated_end_date, self::ZONE)->addDay()->startOfDay(),
                    allDay: true, status: 'scheduled', statusLabel: 'Advisory dates',
                    ref: $row->reference_number, recordId: (int) $row->work_order_id,
                    link: '/fleet-assets/maintenance/work-orders/'.$row->work_order_id,
                    desc: 'Planning estimate from a maintenance report. It does not reserve the vehicle.',
                    workOrderId: (int) $row->work_order_id,
                    meta: $appointment ? ['appointment' => $appointment] : null,
                );
            })->all();
    }

    /**
     * The appointment still planned on a work order (planned or confirmed,
     * not cancelled or completed), wherever it falls.
     *
     * @param  array{state:string, plan:?array<string,mixed>}|null  $source
     * @param  list<int>  $held
     * @return array{start:string,end:string,provider:?string,unavailable:bool}|null
     */
    private function liveAppointment(?array $source, int $workOrderId, array $held): ?array
    {
        $plan = $source['plan'] ?? null;
        if ($source === null || ! in_array($source['state'], self::LIVE_PROVIDER_STATES, true)
            || ! is_array($plan) || empty($plan['starts_at']) || empty($plan['ends_at'])) {
            return null;
        }

        return [
            'start' => CarbonImmutable::parse($plan['starts_at'], 'UTC')->setTimezone(self::ZONE)->toIso8601String(),
            'end' => CarbonImmutable::parse($plan['ends_at'], 'UTC')->setTimezone(self::ZONE)->toIso8601String(),
            'provider' => isset($plan['provider_name']) ? (string) $plan['provider_name'] : null,
            'unavailable' => in_array($workOrderId, $held, true),
        ];
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

    /**
     * Unavailable periods. Outside the viewer's own Sites they are busy time
     * only, like another Site's bookings. A period an appointment holds shows
     * its work and provider only to those who can read Maintenance.
     *
     * @return list<array<string,mixed>>
     */
    private function unavailableItems(Asset $asset, CarbonImmutable $start, CarbonImmutable $end, bool $bookable, bool $readsMaintenance): array
    {
        return FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)
            ->overlapping($start->utc(), $end->utc())->orderBy('starts_at')->limit(200)->get()
            ->map(function (FleetVehicleUnavailablePeriod $period) use ($bookable, $readsMaintenance): array {
                $starts = CarbonImmutable::parse($period->starts_at)->setTimezone(self::ZONE);
                $ends = CarbonImmutable::parse($period->ends_at)->setTimezone(self::ZONE);
                if (! $bookable) {
                    return $this->item(
                        id: 'busy:unavailable-'.$period->id, kind: 'busy', source: 'respite', title: 'Busy',
                        start: $starts, end: $ends, allDay: false, status: 'scheduled', statusLabel: 'Busy only',
                        ref: null, recordId: null, link: null, desc: 'Details are restricted.',
                    );
                }
                $held = $period->work_order_id !== null;
                $withheld = $held && ! $readsMaintenance;

                return $this->item(
                    id: 'unavailable:'.$period->id, kind: 'unavailable', source: 'respite',
                    title: 'Unavailable · '.($withheld ? 'Maintenance' : $period->reason),
                    start: $starts, end: $ends, allDay: false, status: 'scheduled', statusLabel: 'Unavailable',
                    ref: null, recordId: (int) $period->id, link: null,
                    desc: 'Marked unavailable on the calendar. It does not create or clear a safety restriction.',
                    workOrderId: $held && ! $withheld ? (int) $period->work_order_id : null,
                    // Held by a service appointment: it moves and ends with the appointment.
                    meta: $held ? ['held_by_appointment' => true] : null,
                );
            })->all();
    }

    /** Due dates and follow-ups; none of them reserve the vehicle. @return list<array<string,mixed>> */
    private function reminderItems(User $viewer, Asset $asset, CarbonImmutable $start, CarbonImmutable $end): array
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

        app(VehicleReminderAccess::class)->scope(FleetVehicleReminder::query(), $viewer)->where('asset_id', $asset->id)
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

    /**
     * Bookings and unavailable periods for the custody list. Both keep the
     * booking rule: outside the viewer's own Sites there are none.
     *
     * @return list<array<string,mixed>>
     */
    private function bookingRows(User $viewer, Asset $asset, bool $bookable, bool $readsMaintenance): array
    {
        $live = ['pending', 'approved', 'checked_out'];
        $active = $this->custodyBookings($viewer, $asset)->whereIn('status', $live)
            ->orderBy('starts_at')->limit(50)->get();
        $recent = $this->custodyBookings($viewer, $asset)->whereNotIn('status', $live)
            ->orderByDesc('ends_at')->limit(10)->get();
        $periods = $bookable
            ? $this->custodyPeriods($asset)
                ->where(fn ($query) => $query->where('state', 'active')->where('ends_at', '>=', now()->subDays(30))
                    ->orWhere(fn ($cancelled) => $cancelled->where('state', 'cancelled')->where('cancelled_at', '>=', now()->subDays(30))))
                ->orderBy('starts_at')->limit(40)->get()
            : collect();

        return [
            ...$this->bookingCustodyRows($viewer, $asset, $active->concat($recent)->values()),
            ...$this->periodCustodyRows($viewer, $asset, $periods, $readsMaintenance),
        ];
    }

    /** The viewer's bookings of this vehicle (the booking Site rule). */
    private function custodyBookings(User $viewer, Asset $asset): Builder
    {
        return $this->bookings->accessibleBookings($viewer)->where('asset_id', $asset->id)
            ->with(['user:id,name', 'driver:id,name']);
    }

    private function custodyPeriods(Asset $asset): Builder
    {
        return FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)->with('workOrder:id,reference_number');
    }

    /**
     * Custody rows for bookings: details, history, keys, files and what the
     * viewer can do. The summary and the single-record lookup both use it.
     *
     * @param  Collection<int,FleetVehicleBooking>  $shown
     * @return list<array<string,mixed>>
     */
    private function bookingCustodyRows(User $viewer, Asset $asset, Collection $shown): array
    {
        if ($shown->isEmpty()) {
            return [];
        }
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

        return $shown->map(function (FleetVehicleBooking $booking) use ($viewer, $history, $keys, $files, $asset, $manage, $approve, $uploads): array {
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
        })->values()->all();
    }

    /**
     * Custody rows for unavailable periods, for viewers the booking rule
     * admits (the vehicle is at one of their Sites). A period an appointment
     * holds changes only with its appointment, and shows its work and
     * provider only to those who can read Maintenance; only a cancelled
     * calendar period can be restored.
     *
     * @param  Collection<int,FleetVehicleUnavailablePeriod>  $periods
     * @return list<array<string,mixed>>
     */
    private function periodCustodyRows(User $viewer, Asset $asset, Collection $periods, bool $readsMaintenance): array
    {
        if ($periods->isEmpty()) {
            return [];
        }
        $history = $this->history(FleetVehicleUnavailablePeriod::class, $periods->pluck('id')->all(), 'fleet.vehicle.unavailable.');
        $uploads = Gate::forUser($viewer)->allows('manageDocuments', $asset);
        $files = Gate::forUser($viewer)->allows('view', $asset)
            ? AssetDocumentSet::query()->where('asset_id', $asset->id)->where('source_type', 'unavailable_period')
                ->whereIn('source_id', $periods->pluck('id'))->with('files')->get()->groupBy('source_id')
            : collect();
        $manage = $viewer->canDo('fleet.manage');

        return $periods->map(function (FleetVehicleUnavailablePeriod $period) use ($history, $files, $asset, $manage, $uploads, $readsMaintenance): array {
            $active = $period->state === FleetVehicleUnavailablePeriod::STATE_ACTIVE;
            $calendarPeriod = $period->work_order_id === null;
            // The appointment's reason, cancellation and history name its work and provider.
            $withheld = ! $calendarPeriod && ! $readsMaintenance;
            $entries = $history[(int) $period->id] ?? [];

            return [
                'kind' => 'unavailable',
                'id' => (int) $period->id,
                'reference' => $withheld ? null : $period->workOrder?->reference_number,
                'work_order_id' => $calendarPeriod || $withheld ? null : (int) $period->work_order_id,
                'purpose' => $withheld ? 'Maintenance' : $period->reason,
                'starts_at' => $period->starts_at?->toIso8601String(),
                'ends_at' => $period->ends_at?->toIso8601String(),
                'status' => $period->state,
                'status_label' => $active ? 'Unavailable' : 'Cancelled',
                'cancellation_reason' => $withheld ? null : $period->cancellation_reason,
                'lock_version' => (int) $period->lock_version,
                'history' => $withheld
                    ? array_map(fn (array $entry): array => array_replace($entry, ['reason' => null]), $entries)
                    : $entries,
                'files' => $this->fileRows($asset, $files->get($period->id)),
                'can' => [
                    'edit' => $active && $calendarPeriod && $manage,
                    'cancel' => $active && $calendarPeriod && $manage,
                    // Undo of a cancellation; the window is rechecked when restoring.
                    'restore' => ! $active && $calendarPeriod && $manage && (bool) $period->ends_at?->isFuture(),
                    'upload' => $uploads,
                ],
            ];
        })->values()->all();
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
        $orders = FleetWorkOrder::query()->where('asset_id', $asset->id)
            ->whereIn('status', self::OPEN_WORK)->orderByDesc('id')->limit(30)
            ->get(['id', 'reference_number', 'title', 'status', 'version']);
        $sources = $this->workSources($orders->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        return $orders->map(fn (FleetWorkOrder $order): array => [
            'id' => (int) $order->id, 'reference' => $order->reference_number,
            'title' => $order->title, 'status' => $order->status, 'version' => (int) $order->version,
            'source' => $sources[(int) $order->id] ?? null,
        ])->all();
    }

    /**
     * What each work order was reported from: its first report's source (a
     * service schedule, compliance record, check, booking…), or null.
     *
     * @param  list<int>  $workIds
     * @return array<int, array{type:string,id:int}|null>
     */
    private function workSources(array $workIds): array
    {
        if ($workIds === []) {
            return [];
        }
        $sources = [];
        DB::table('fleet_maintenance_reports')->whereIn('work_order_id', $workIds)->whereNull('duplicate_of_report_id')
            ->orderBy('id')->get(['work_order_id', 'source_type', 'source_id'])
            ->each(function (object $report) use (&$sources): void {
                $id = (int) $report->work_order_id;
                if (array_key_exists($id, $sources)) {
                    return;
                }
                $sources[$id] = $report->source_type !== null && $report->source_id !== null
                    ? ['type' => (string) $report->source_type, 'id' => (int) $report->source_id]
                    : null;
            });

        return $sources;
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
            VehicleReturnConcernService::REPORTED_ACTION => 'Concern sent to Maintenance',
            VehicleReturnConcernService::NOT_ROUTED_ACTION => 'Concern not sent to Maintenance',
            'fleet.vehicle.unavailable.create' => 'Recorded', 'fleet.vehicle.unavailable.update' => 'Changed',
            'fleet.vehicle.unavailable.cancel' => 'Cancelled',
            VehicleUnavailablePeriodService::RESTORED_ACTION => 'Restored',
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
