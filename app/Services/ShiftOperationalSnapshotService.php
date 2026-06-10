<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;

class ShiftOperationalSnapshotService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshotForShift(?Shift $shift, ?User $staff = null): array
    {
        if (! $shift) {
            return $this->emptySnapshot($staff);
        }

        // `load` (not `loadMissing`) — see snapshotForTimesheet for why.
        $shift->load([
            'site:id,name',
            'client:id,first_name,last_name,site_id',
            'client.site:id,name',
            'staff:id,name',
            'serviceContext:id,name',
        ]);

        $siteId = $shift->site_id ?: $shift->client?->site_id;
        $siteName = $shift->site?->name ?: $shift->client?->site?->name;
        $staffModel = $staff ?: $shift->staff;

        return [
            'site_id' => $siteId ? (int) $siteId : null,
            'site_name' => $siteName,
            'location' => $shift->location,
            'service_context_id' => $shift->service_context_id ? (int) $shift->service_context_id : null,
            'service_context_name' => $shift->serviceContext?->name,
            'client_id' => $shift->client_id ? (int) $shift->client_id : null,
            'client_name' => $this->clientName($shift->client),
            'staff_id' => $staffModel?->id,
            'staff_name' => $staffModel?->name,
            'shift_type' => $shift->shift_type ?? 'standard',
            'coverage_roles' => array_values(array_filter((array) ($shift->coverage_roles ?? []))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotForClient(?Client $client, ?User $staff = null, ?string $location = null): array
    {
        if (! $client) {
            return $this->emptySnapshot($staff);
        }

        $client->loadMissing([
            'site:id,name',
            'serviceContext:id,name',
        ]);

        return [
            'site_id' => $client->site_id ? (int) $client->site_id : null,
            'site_name' => $client->site?->name,
            'location' => $location,
            'service_context_id' => $client->service_context_id ? (int) $client->service_context_id : null,
            'service_context_name' => $client->serviceContext?->name,
            'client_id' => $client->id,
            'client_name' => $this->clientName($client),
            'staff_id' => $staff?->id,
            'staff_name' => $staff?->name,
            'shift_type' => 'standard',
            'coverage_roles' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotForTimesheet(Timesheet $timesheet): array
    {
        // `load` (not `loadMissing`) so a prior partial-column eager load
        // elsewhere — e.g. `UserSiteAccessService::timesheetSiteId` only
        // loads `shift.client:id,site_id` — does not silently mask
        // first_name / last_name and produce an empty `client_name_snapshot`.
        $timesheet->load([
            'shift.site:id,name',
            'shift.client:id,first_name,last_name,site_id',
            'shift.client.site:id,name',
            'shift.staff:id,name',
            'shift.serviceContext:id,name',
            'client:id,first_name,last_name',
            'staff:id,name',
        ]);

        $shiftSnapshot = $this->snapshotForShift($timesheet->shift, $timesheet->staff);

        return [
            'shift_site_id' => $shiftSnapshot['site_id'],
            'shift_service_context_id' => $shiftSnapshot['service_context_id'],
            'shift_site_name_snapshot' => $shiftSnapshot['site_name'],
            'shift_location_snapshot' => $shiftSnapshot['location'],
            'service_context_name_snapshot' => $shiftSnapshot['service_context_name'],
            'client_name_snapshot' => $shiftSnapshot['client_name'] ?: $this->clientName($timesheet->client),
            'staff_name_snapshot' => $shiftSnapshot['staff_name'] ?: $timesheet->staff?->name,
            // Shift-less (activity/manual) timesheets have no shift to derive a
            // type from — keep the value stamped at draft time instead of
            // regenerating it to null and tripping the billing snapshot guard.
            'shift_type_snapshot' => $shiftSnapshot['shift_type'] ?: ($timesheet->shift_type_snapshot ?: ($timesheet->shift_id ? null : 'standard')),
            'coverage_roles_snapshot' => $shiftSnapshot['coverage_roles'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function billingSnapshotForTimesheet(Timesheet $timesheet, float $payRate = 0.0, float $payrollCost = 0.0): array
    {
        $timesheetSnapshot = $this->snapshotForTimesheet($timesheet);

        return [
            'site_id' => $timesheetSnapshot['shift_site_id'],
            'site_name_snapshot' => $timesheetSnapshot['shift_site_name_snapshot'],
            'location_snapshot' => $timesheetSnapshot['shift_location_snapshot'],
            'service_context_name_snapshot' => $timesheetSnapshot['service_context_name_snapshot'],
            'client_name_snapshot' => $timesheetSnapshot['client_name_snapshot'],
            'staff_name_snapshot' => $timesheetSnapshot['staff_name_snapshot'],
            'shift_type_snapshot' => $timesheetSnapshot['shift_type_snapshot'],
            'pay_type_snapshot' => $timesheet->pay_type,
            'pay_rate_snapshot' => round($payRate, 2),
            'payroll_cost' => round($payrollCost, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transportSnapshotForShift(?Shift $shift, ?User $driver = null): array
    {
        $snapshot = $this->snapshotForShift($shift, $driver);

        return [
            'site_id' => $snapshot['site_id'],
            'site_name_snapshot' => $snapshot['site_name'],
            'shift_location_snapshot' => $snapshot['location'],
            'service_context_name_snapshot' => $snapshot['service_context_name'],
            'driver_name_snapshot' => $snapshot['staff_name'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptySnapshot(?User $staff = null): array
    {
        return [
            'site_id' => null,
            'site_name' => null,
            'location' => null,
            'service_context_id' => null,
            'service_context_name' => null,
            'client_id' => null,
            'client_name' => null,
            'staff_id' => $staff?->id,
            'staff_name' => $staff?->name,
            'shift_type' => null,
            'coverage_roles' => [],
        ];
    }

    protected function clientName(?Client $client): ?string
    {
        if (! $client) {
            return null;
        }

        $name = trim(($client->first_name ?? '').' '.($client->last_name ?? ''));

        return $name !== '' ? $name : ($client->full_name ?: null);
    }
}
