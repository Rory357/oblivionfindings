<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedicationAdministration;
use App\Models\DataSubjectRequest;
use App\Models\SafeguardingConcern;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CombinedReportController extends Controller
{
    public function show(Request $request, string $report)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('reports.viewAny'), 403);

        $payload = $this->reportPayload($report);
        abort_unless($payload !== null, 404);

        return inertia('reports/combined', $payload);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('reports.viewAny'), 403);

        $payload = $this->reportPayload($report);
        abort_unless($payload !== null, 404);

        $filename = sprintf(
            '%s_combined_report_%s.csv',
            $payload['report']['key'],
            now()->format('Ymd_His'),
        );

        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Metric', 'Value']);
            foreach ($payload['metrics'] as $metric) {
                fputcsv($out, [$metric['label'], $metric['value']]);
            }

            foreach ($payload['sections'] as $section) {
                fputcsv($out, []);
                fputcsv($out, [$section['title']]);
                fputcsv($out, $section['columns']);
                foreach ($section['rows'] as $row) {
                    fputcsv($out, $row['cells']);
                }
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'care-quality',
                'label' => 'Care Quality Overview',
                'description' => 'Cross-module care quality view spanning incidents, meds, safeguarding, and delivery.',
                'route' => '/reports/combined/care-quality',
                'export_route' => '/reports/combined/care-quality/export',
                'modules' => ['incidents', 'medication_administrations', 'controlled_drug_discrepancies', 'safeguarding', 'shifts'],
            ],
            [
                'key' => 'workforce-operations',
                'label' => 'Workforce & Operations',
                'description' => 'Scheduling and workforce efficiency across shifts, timesheets, and staffing approvals.',
                'route' => '/reports/combined/workforce-operations',
                'export_route' => '/reports/combined/workforce-operations/export',
                'modules' => ['shifts', 'timesheets', 'staff', 'sites'],
            ],
            [
                'key' => 'compliance-risk',
                'label' => 'Compliance & Risk',
                'description' => 'Risk and compliance indicators across audit logs, safeguarding, privacy, break-glass, and assets.',
                'route' => '/reports/combined/compliance-risk',
                'export_route' => '/reports/combined/compliance-risk/export',
                'modules' => ['audit_logs', 'assets', 'safeguarding', 'clients'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reportPayload(string $key): ?array
    {
        $definition = collect(self::definitions())->firstWhere('key', $key);
        if (!is_array($definition)) {
            return null;
        }

        $now = now();
        $from7 = $now->copy()->subDays(7)->startOfDay();
        $from30 = $now->copy()->subDays(30)->startOfDay();

        if ($key === 'care-quality') {
            $metrics = [
                ['label' => 'Open incidents', 'value' => ClientIncident::query()->whereIn('status', ['submitted', 'reviewed'])->count()],
                ['label' => 'High severity incidents (30d)', 'value' => ClientIncident::query()->where('occurred_at', '>=', $from30)->where('severity', 'high')->count()],
                ['label' => 'Open safeguarding concerns', 'value' => SafeguardingConcern::query()->where('status', '!=', 'closed')->count()],
                ['label' => 'Medication exceptions (7d)', 'value' => ClientMedicationAdministration::query()->where('administered_at', '>=', $from7)->whereIn('status', ['missed', 'withheld', 'refused'])->count()],
                ['label' => 'Open controlled discrepancies', 'value' => ClientControlledDrugDiscrepancy::query()->where('status', 'open')->count()],
                ['label' => 'Completed shifts (7d)', 'value' => Shift::query()->where('starts_at', '>=', $from7)->where('status', 'completed')->count()],
            ];
            $sections = [
                [
                    'title' => 'Recent Incidents',
                    'columns' => ['ID', 'Occurred At', 'Client ID', 'Severity', 'Status', 'Type'],
                    'rows' => ClientIncident::query()
                        ->select(['id', 'occurred_at', 'client_id', 'severity', 'status', 'type'])
                        ->latest('occurred_at')
                        ->limit(20)
                        ->get()
                        ->map(fn (ClientIncident $i) => [
                            'id' => $i->id,
                            'cells' => [
                                (string) $i->id,
                                optional($i->occurred_at)->toDateTimeString() ?? '',
                                (string) ($i->client_id ?? ''),
                                (string) ($i->severity ?? ''),
                                (string) ($i->status ?? ''),
                                (string) ($i->type ?? ''),
                            ],
                            'href' => '/incidents/' . $i->id,
                        ])
                        ->values()
                        ->all(),
                ],
                [
                    'title' => 'Recent Medication Exceptions',
                    'columns' => ['ID', 'Administered At', 'Client ID', 'Status', 'Reason'],
                    'rows' => ClientMedicationAdministration::query()
                        ->whereIn('status', ['missed', 'withheld', 'refused'])
                        ->select(['id', 'administered_at', 'client_id', 'status', 'reason'])
                        ->latest('administered_at')
                        ->limit(20)
                        ->get()
                        ->map(fn (ClientMedicationAdministration $m) => [
                            'id' => $m->id,
                            'cells' => [
                                (string) $m->id,
                                optional($m->administered_at)->toDateTimeString() ?? '',
                                (string) ($m->client_id ?? ''),
                                (string) ($m->status ?? ''),
                                (string) ($m->reason ?? ''),
                            ],
                            'href' => null,
                        ])
                        ->values()
                        ->all(),
                ],
                [
                    'title' => 'Recent Safeguarding Concerns',
                    'columns' => ['ID', 'Reference', 'Reported At', 'Severity', 'Status', 'Assigned To'],
                    'rows' => SafeguardingConcern::query()
                        ->select(['id', 'reference_number', 'reported_at', 'severity', 'status', 'assigned_to_user_id'])
                        ->latest('reported_at')
                        ->limit(20)
                        ->get()
                        ->map(fn (SafeguardingConcern $s) => [
                            'id' => $s->id,
                            'cells' => [
                                (string) $s->id,
                                (string) ($s->reference_number ?? ''),
                                optional($s->reported_at)->toDateTimeString() ?? '',
                                (string) ($s->severity ?? ''),
                                (string) ($s->status ?? ''),
                                (string) ($s->assigned_to_user_id ?? ''),
                            ],
                            'href' => '/safeguarding/' . $s->id,
                        ])
                        ->values()
                        ->all(),
                ],
            ];
        } elseif ($key === 'workforce-operations') {
            $metrics = [
                ['label' => 'Scheduled shifts (next 7d)', 'value' => Shift::query()->whereBetween('starts_at', [$now, $now->copy()->addDays(7)->endOfDay()])->count()],
                ['label' => 'Unassigned shifts (next 7d)', 'value' => Shift::query()->whereBetween('starts_at', [$now, $now->copy()->addDays(7)->endOfDay()])->whereNull('user_id')->count()],
                ['label' => 'Pending timesheets', 'value' => Timesheet::query()->whereIn('status', ['draft', 'submitted'])->count()],
                ['label' => 'Approved timesheets (30d)', 'value' => Timesheet::query()->where('approved_at', '>=', $from30)->count()],
                ['label' => 'Returned timesheets (30d)', 'value' => Timesheet::query()->where('returned_at', '>=', $from30)->count()],
                ['label' => 'Staff awaiting approval', 'value' => User::query()->whereNull('approved_at')->count()],
            ];
            $sections = [
                [
                    'title' => 'Upcoming Shifts (7 Days)',
                    'columns' => ['ID', 'Starts At', 'Ends At', 'Client ID', 'Staff ID', 'Status'],
                    'rows' => Shift::query()
                        ->whereBetween('starts_at', [$now, $now->copy()->addDays(7)->endOfDay()])
                        ->select(['id', 'starts_at', 'ends_at', 'client_id', 'user_id', 'status'])
                        ->orderBy('starts_at')
                        ->limit(25)
                        ->get()
                        ->map(fn (Shift $s) => [
                            'id' => $s->id,
                            'cells' => [
                                (string) $s->id,
                                optional($s->starts_at)->toDateTimeString() ?? '',
                                optional($s->ends_at)->toDateTimeString() ?? '',
                                (string) ($s->client_id ?? ''),
                                (string) ($s->user_id ?? ''),
                                (string) ($s->status ?? ''),
                            ],
                            'href' => '/shifts/' . $s->id,
                        ])
                        ->values()
                        ->all(),
                ],
                [
                    'title' => 'Pending Timesheets',
                    'columns' => ['ID', 'Work Date', 'Staff ID', 'Client ID', 'Shift ID', 'Status'],
                    'rows' => Timesheet::query()
                        ->whereIn('status', ['draft', 'submitted'])
                        ->select(['id', 'work_date', 'user_id', 'client_id', 'shift_id', 'status'])
                        ->latest('work_date')
                        ->limit(25)
                        ->get()
                        ->map(fn (Timesheet $t) => [
                            'id' => $t->id,
                            'cells' => [
                                (string) $t->id,
                                optional($t->work_date)->toDateString() ?? '',
                                (string) ($t->user_id ?? ''),
                                (string) ($t->client_id ?? ''),
                                (string) ($t->shift_id ?? ''),
                                (string) ($t->status ?? ''),
                            ],
                            'href' => '/timesheets/' . $t->id . '/edit',
                        ])
                        ->values()
                        ->all(),
                ],
                [
                    'title' => 'Staff Awaiting Approval',
                    'columns' => ['ID', 'Name', 'Email', 'Role', 'Created At'],
                    'rows' => User::query()
                        ->whereNull('approved_at')
                        ->select(['id', 'name', 'email', 'role', 'created_at'])
                        ->latest('created_at')
                        ->limit(25)
                        ->get()
                        ->map(fn (User $u) => [
                            'id' => $u->id,
                            'cells' => [
                                (string) $u->id,
                                (string) ($u->name ?? ''),
                                (string) ($u->email ?? ''),
                                (string) ($u->role ?? ''),
                                optional($u->created_at)->toDateTimeString() ?? '',
                            ],
                            'href' => '/staff/' . $u->id,
                        ])
                        ->values()
                        ->all(),
                ],
            ];
        } else {
            $metrics = [
                ['label' => 'Audit events (7d)', 'value' => AuditLog::query()->where('created_at', '>=', $from7)->count()],
                ['label' => 'Overdue asset inspections', 'value' => Asset::query()->where('requires_inspection', true)->whereNotNull('inspection_due_at')->where('inspection_due_at', '<', $now->toDateString())->count()],
                ['label' => 'Overdue maintenance', 'value' => Asset::query()->where('requires_maintenance', true)->whereNotNull('maintenance_due_at')->where('maintenance_due_at', '<', $now->toDateString())->count()],
                ['label' => 'Open safeguarding concerns', 'value' => SafeguardingConcern::query()->where('status', '!=', 'closed')->count()],
                ['label' => 'Overdue privacy requests', 'value' => DataSubjectRequest::query()->overdue()->count()],
                ['label' => 'Break-glass accesses (7d)', 'value' => ClientBreakGlassAccess::query()->where('created_at', '>=', $from7)->count()],
            ];
            $sections = [
                [
                    'title' => 'Recent Audit Events',
                    'columns' => ['ID', 'Created At', 'Action', 'User ID', 'Client ID', 'IP Address'],
                    'rows' => AuditLog::query()
                        ->select(['id', 'created_at', 'action', 'user_id', 'client_id', 'ip_address'])
                        ->latest('created_at')
                        ->limit(25)
                        ->get()
                        ->map(fn (AuditLog $a) => [
                            'id' => $a->id,
                            'cells' => [
                                (string) $a->id,
                                optional($a->created_at)->toDateTimeString() ?? '',
                                (string) ($a->action ?? ''),
                                (string) ($a->user_id ?? ''),
                                (string) ($a->client_id ?? ''),
                                (string) ($a->ip_address ?? ''),
                            ],
                            'href' => '/audit-logs',
                        ])
                        ->values()
                        ->all(),
                ],
                [
                    'title' => 'Overdue Asset Checks',
                    'columns' => ['ID', 'Name', 'Asset Tag', 'Inspection Due', 'Maintenance Due', 'Status'],
                    'rows' => Asset::query()
                        ->where(function ($q) use ($now) {
                            $q->where(function ($x) use ($now) {
                                $x->where('requires_inspection', true)
                                    ->whereNotNull('inspection_due_at')
                                    ->where('inspection_due_at', '<', $now->toDateString());
                            })->orWhere(function ($x) use ($now) {
                                $x->where('requires_maintenance', true)
                                    ->whereNotNull('maintenance_due_at')
                                    ->where('maintenance_due_at', '<', $now->toDateString());
                            });
                        })
                        ->select(['id', 'name', 'asset_tag', 'inspection_due_at', 'maintenance_due_at', 'status'])
                        ->orderBy('inspection_due_at')
                        ->orderBy('maintenance_due_at')
                        ->limit(25)
                        ->get()
                        ->map(fn (Asset $a) => [
                            'id' => $a->id,
                            'cells' => [
                                (string) $a->id,
                                (string) ($a->name ?? ''),
                                (string) ($a->asset_tag ?? ''),
                                optional($a->inspection_due_at)->toDateString() ?? '',
                                optional($a->maintenance_due_at)->toDateString() ?? '',
                                (string) ($a->status ?? ''),
                            ],
                            'href' => '/assets/' . $a->id,
                        ])
                        ->values()
                        ->all(),
                ],
                [
                    'title' => 'Overdue Privacy Requests',
                    'columns' => ['ID', 'Reference', 'Status', 'Due Date', 'Extended Due Date', 'Assigned To'],
                    'rows' => DataSubjectRequest::query()
                        ->overdue()
                        ->select(['id', 'reference_number', 'status', 'due_date', 'extended_due_date', 'assigned_to_user_id'])
                        ->orderBy('due_date')
                        ->limit(25)
                        ->get()
                        ->map(fn (DataSubjectRequest $d) => [
                            'id' => $d->id,
                            'cells' => [
                                (string) $d->id,
                                (string) ($d->reference_number ?? ''),
                                (string) ($d->status ?? ''),
                                optional($d->due_date)->toDateString() ?? '',
                                optional($d->extended_due_date)->toDateString() ?? '',
                                (string) ($d->assigned_to_user_id ?? ''),
                            ],
                            'href' => '/privacy/requests/' . $d->id,
                        ])
                        ->values()
                        ->all(),
                ],
            ];
        }

        return [
            'report' => $definition,
            'generated_at' => $now->toDateTimeString(),
            'metrics' => $metrics,
            'sections' => $sections ?? [],
        ];
    }
}
