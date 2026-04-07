<?php

namespace App\Console\Commands;

use App\Models\BillingEntry;
use App\Models\FleetResidentTransport;
use App\Models\Timesheet;
use App\Services\Operations\PayrollRateResolver;
use App\Services\ShiftOperationalSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillOperationalSnapshots extends Command
{
    protected $signature = 'operations:backfill-operational-snapshots
        {--dry-run : Report changes without saving them}
        {--chunk=200 : Chunk size for batched processing}';

    protected $description = 'Backfill historical snapshot fields for timesheets, billing entries, and resident transports.';

    public function handle(ShiftOperationalSnapshotService $snapshots, PayrollRateResolver $rateResolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max((int) $this->option('chunk'), 50);

        $summary = [
            'timesheets' => ['updated' => 0, 'skipped' => 0, 'unrepairable' => 0],
            'billing_entries' => ['updated' => 0, 'skipped' => 0, 'unrepairable' => 0],
            'fleet_transports' => ['updated' => 0, 'skipped' => 0, 'unrepairable' => 0],
        ];
        $samples = [
            'timesheets' => [],
            'billing_entries' => [],
            'fleet_transports' => [],
        ];

        $timesheetColumns = Schema::hasTable('timesheets') ? Schema::getColumnListing('timesheets') : [];
        $billingColumns = Schema::hasTable('billing_entries') ? Schema::getColumnListing('billing_entries') : [];
        $transportColumns = Schema::hasTable('fleet_resident_transports') ? Schema::getColumnListing('fleet_resident_transports') : [];

        if (Schema::hasTable('timesheets')) {
            Timesheet::query()
                ->with([
                    'shift.site:id,name',
                    'shift.client:id,first_name,last_name,site_id',
                    'shift.serviceContext:id,name',
                    'shift.staff:id,name',
                    'client:id,first_name,last_name,site_id,service_context_id',
                    'client.site:id,name',
                    'client.serviceContext:id,name',
                    'staff:id,name',
                    'user.hrEmployeeProfile',
                ])
                ->where(function ($query) use ($timesheetColumns) {
                    $snapshotColumns = ['shift_site_name_snapshot', 'client_name_snapshot', 'staff_name_snapshot', 'shift_type_snapshot'];

                    foreach ($snapshotColumns as $column) {
                        if (in_array($column, $timesheetColumns, true)) {
                            $query->orWhereNull($column);
                        }
                    }

                    if (in_array('status', $timesheetColumns, true)) {
                        $query->orWhere(function ($subquery) use ($timesheetColumns) {
                            $subquery->where('status', 'approved')
                                ->where(function ($inner) use ($timesheetColumns) {
                                    foreach (['pay_type', 'pay_rate'] as $column) {
                                        if (in_array($column, $timesheetColumns, true)) {
                                            $inner->orWhereNull($column);
                                        }
                                    }
                                });
                        });
                    }
                })
                ->chunkById($chunk, function ($timesheets) use (&$summary, &$samples, $dryRun, $snapshots, $rateResolver) {
                    foreach ($timesheets as $timesheet) {
                        $snapshot = $timesheet->shift
                            ? $snapshots->snapshotForTimesheet($timesheet)
                            : $snapshots->snapshotForClient($timesheet->client, $timesheet->staff, $timesheet->shift_location_snapshot);

                        $payload = $timesheet->shift
                            ? $snapshot
                            : [
                                'shift_site_id' => $snapshot['site_id'],
                                'shift_service_context_id' => $snapshot['service_context_id'],
                                'shift_site_name_snapshot' => $snapshot['site_name'],
                                'shift_location_snapshot' => $snapshot['location'],
                                'service_context_name_snapshot' => $snapshot['service_context_name'],
                                'client_name_snapshot' => $snapshot['client_name'],
                                'staff_name_snapshot' => $snapshot['staff_name'],
                                'shift_type_snapshot' => $timesheet->shift_type_snapshot ?? 'standard',
                                'coverage_roles_snapshot' => $timesheet->coverage_roles_snapshot ?? [],
                            ];

                        if ($timesheet->status === 'approved') {
                            $rate = $rateResolver->resolve($timesheet);
                            $payload['pay_type'] = $timesheet->pay_type ?: $rate['pay_type'];
                            $payload['pay_rate'] = $timesheet->pay_rate ?: $rate['pay_rate'];
                        }

                        $failure = $this->missingRequiredFields($payload, [
                            'client_name_snapshot',
                            'staff_name_snapshot',
                        ]);

                        if ($timesheet->status === 'approved') {
                            $failure = $failure ?: $this->missingRequiredFields($payload, ['pay_type', 'pay_rate']);
                        }

                        if ($failure) {
                            $summary['timesheets']['unrepairable']++;
                            $this->pushSample($samples['timesheets'], $timesheet->id, $failure);
                            continue;
                        }

                        if ($this->wouldChange($timesheet, $payload)) {
                            $summary['timesheets']['updated']++;
                            if (! $dryRun) {
                                $timesheet->forceFill($payload)->saveQuietly();
                            }
                        } else {
                            $summary['timesheets']['skipped']++;
                        }
                    }
                });
        }

        if (Schema::hasTable('billing_entries')) {
            BillingEntry::query()
                ->with([
                    'client:id,first_name,last_name,site_id',
                    'client.site:id,name',
                    'staff:id,name',
                ])
                ->where(function ($query) use ($billingColumns) {
                    foreach (['site_name_snapshot', 'client_name_snapshot', 'staff_name_snapshot', 'shift_type_snapshot', 'pay_type_snapshot', 'pay_rate_snapshot'] as $column) {
                        if (in_array($column, $billingColumns, true)) {
                            $query->orWhereNull($column);
                        }
                    }
                })
                ->chunkById($chunk, function ($entries) use (&$summary, &$samples, $dryRun, $snapshots, $rateResolver) {
                    foreach ($entries as $entry) {
                        $timesheet = $entry->timesheet_id ? Timesheet::query()->with(['client.site:id,name', 'client.serviceContext:id,name', 'staff:id,name', 'user.hrEmployeeProfile'])->find($entry->timesheet_id) : null;
                        if ($timesheet) {
                            $rate = $rateResolver->resolve($timesheet);
                            $payload = $snapshots->billingSnapshotForTimesheet($timesheet, $rate['pay_rate'], $rate['payroll_cost']);
                            $payload['site_id'] = $payload['site_id'] ?? $entry->site_id;
                        } else {
                            $client = $entry->client;
                            $payload = [
                                'site_id' => $client?->site_id,
                                'site_name_snapshot' => $client?->site?->name,
                                'client_name_snapshot' => $client?->full_name,
                                'staff_name_snapshot' => $entry->staff?->name,
                            ];
                        }

                        $failure = $this->missingRequiredFields($payload, [
                            'client_name_snapshot',
                            'staff_name_snapshot',
                        ]);

                        if ($failure) {
                            $summary['billing_entries']['unrepairable']++;
                            $this->pushSample($samples['billing_entries'], $entry->id, $failure);
                            continue;
                        }

                        if ($this->wouldChange($entry, $payload)) {
                            $summary['billing_entries']['updated']++;
                            if (! $dryRun) {
                                $entry->forceFill($payload)->saveQuietly();
                            }
                        } else {
                            $summary['billing_entries']['skipped']++;
                        }
                    }
                });
        }

        if (Schema::hasTable('fleet_resident_transports')) {
            FleetResidentTransport::query()
                ->with([
                    'shift.site:id,name',
                    'shift.client:id,first_name,last_name,site_id',
                    'shift.serviceContext:id,name',
                    'driver:id,name',
                    'resident:id,first_name,last_name,site_id,service_context_id',
                    'resident.site:id,name',
                    'resident.serviceContext:id,name',
                ])
                ->where(function ($query) use ($transportColumns) {
                    foreach (['site_name_snapshot', 'driver_name_snapshot', 'service_context_name_snapshot'] as $column) {
                        if (in_array($column, $transportColumns, true)) {
                            $query->orWhereNull($column);
                        }
                    }
                })
                ->chunkById($chunk, function ($transports) use (&$summary, &$samples, $dryRun, $snapshots) {
                    foreach ($transports as $transport) {
                        $payload = $transport->shift
                            ? $snapshots->transportSnapshotForShift($transport->shift, $transport->driver)
                            : [
                                'site_id' => $transport->resident?->site_id,
                                'site_name_snapshot' => $transport->resident?->site?->name,
                                'shift_location_snapshot' => $transport->shift_location_snapshot ?: $transport->pickup_location,
                                'service_context_name_snapshot' => $transport->resident?->serviceContext?->name,
                                'driver_name_snapshot' => $transport->driver?->name,
                            ];

                        $failure = $this->missingRequiredFields($payload, [
                            'driver_name_snapshot',
                        ]);

                        if ($failure) {
                            $summary['fleet_transports']['unrepairable']++;
                            $this->pushSample($samples['fleet_transports'], $transport->id, $failure);
                            continue;
                        }

                        if ($this->wouldChange($transport, $payload)) {
                            $summary['fleet_transports']['updated']++;
                            if (! $dryRun) {
                                $transport->forceFill($payload)->saveQuietly();
                            }
                        } else {
                            $summary['fleet_transports']['skipped']++;
                        }
                    }
                });
        }

        $this->table(
            ['Record type', 'Updated', 'Skipped', 'Unrepairable'],
            collect($summary)->map(fn ($counts, $type) => [
                $type,
                $counts['updated'],
                $counts['skipped'],
                $counts['unrepairable'],
            ])->all(),
        );

        foreach ($samples as $type => $rows) {
            if ($rows === []) {
                continue;
            }

            $this->newLine();
            $this->warn(sprintf('Unrepairable %s (sample):', $type));
            $this->table(['ID', 'Reason'], $rows);
        }

        $this->info($dryRun ? 'Dry run completed.' : 'Operational snapshot backfill completed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function wouldChange(object $model, array $payload): bool
    {
        foreach ($payload as $field => $value) {
            if ($model->{$field} !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function missingRequiredFields(array $payload, array $requiredFields): ?string
    {
        $missing = collect($requiredFields)
            ->filter(fn (string $field) => blank($payload[$field] ?? null))
            ->values()
            ->all();

        if ($missing === []) {
            return null;
        }

        return 'Missing required snapshot fields: ' . implode(', ', $missing);
    }

    /**
     * @param  array<int, array{0:int|string,1:string}>  $samples
     */
    protected function pushSample(array &$samples, int|string $id, string $reason): void
    {
        if (count($samples) >= 10) {
            return;
        }

        $samples[] = [$id, $reason];
    }
}
