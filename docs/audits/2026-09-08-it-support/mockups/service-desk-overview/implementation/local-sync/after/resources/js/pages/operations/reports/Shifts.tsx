import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
    type PageHeaderRailItem,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarRange,
    Clock,
    FileBarChart,
    MapPin,
    Scale,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useState } from 'react';

type Option = {
    id: number;
    name: string;
};

type Props = {
    filters: {
        date_from: string;
        date_to: string;
        site_id?: number | null;
        staff_id?: number | null;
    };
    sites: Option[];
    staff: Option[];
    export_url: string;
    report: Record<string, any>;
};

type ViewKey =
    | 'risk'
    | 'utilisation'
    | 'coverage'
    | 'reconciliation'
    | 'variance';

function isoDate(d: Date): string {
    return d.toISOString().split('T')[0];
}

export default function ShiftReports({
    filters,
    sites,
    staff,
    export_url,
    report,
}: Props) {
    const page = usePage();
    const canManageRoadmap = Boolean(
        (page.props as any)?.auth?.can?.roadmap?.manage,
    );

    const [view, setView] = useState<ViewKey>('risk');
    const [search, setSearch] = useState('');

    const applyServer = (overrides: Partial<Record<string, string>>) => {
        router.get(
            '/operations/reports/shifts',
            {
                date_from: overrides.date_from ?? filters.date_from,
                date_to: overrides.date_to ?? filters.date_to,
                site_id:
                    (overrides.site_id ?? String(filters.site_id ?? '')) ||
                    undefined,
                staff_id:
                    (overrides.staff_id ?? String(filters.staff_id ?? '')) ||
                    undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // Period presets — the URL still carries raw date_from/date_to, so
    // deep-linked custom ranges keep working and show as "Custom range".
    const today = new Date();
    const presets: Record<string, { from: string; to: string; label: string }> =
        {
            last7: {
                from: isoDate(new Date(today.getTime() - 6 * 86400000)),
                to: isoDate(today),
                label: 'Last 7 days',
            },
            last30: {
                from: isoDate(new Date(today.getTime() - 29 * 86400000)),
                to: isoDate(today),
                label: 'Last 30 days',
            },
            this_month: {
                from: isoDate(
                    new Date(today.getFullYear(), today.getMonth(), 1),
                ),
                to: isoDate(today),
                label: 'This month',
            },
            last_month: {
                from: isoDate(
                    new Date(today.getFullYear(), today.getMonth() - 1, 1),
                ),
                to: isoDate(new Date(today.getFullYear(), today.getMonth(), 0)),
                label: 'Last month',
            },
        };
    const currentPreset =
        Object.entries(presets).find(
            ([, p]) => p.from === filters.date_from && p.to === filters.date_to,
        )?.[0] ?? 'custom';
    const periodOptions = [
        ...Object.entries(presets).map(([value, p]) => ({
            value,
            label: p.label,
        })),
        ...(currentPreset === 'custom'
            ? [
                  {
                      value: 'custom',
                      label: `${filters.date_from} – ${filters.date_to}`,
                  },
              ]
            : []),
    ];

    const exportDataset = (dataset: string) => {
        const params = new URLSearchParams({
            dataset,
            date_from: filters.date_from,
            date_to: filters.date_to,
        });

        if (filters.site_id) {
            params.set('site_id', String(filters.site_id));
        }

        if (filters.staff_id) {
            params.set('staff_id', String(filters.staff_id));
        }

        window.location.href = `${export_url}?${params.toString()}`;
    };

    const formatIsoDate = (value: unknown) => {
        if (typeof value !== 'string' || value === '') {
            return value ?? '—';
        }

        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
            return value;
        }

        const hasTime = value.includes('T');

        return parsed.toLocaleString(
            'en-NZ',
            hasTime
                ? {
                      year: 'numeric',
                      month: 'short',
                      day: 'numeric',
                      hour: 'numeric',
                      minute: '2-digit',
                  }
                : {
                      year: 'numeric',
                      month: 'short',
                      day: 'numeric',
                  },
        );
    };

    const formatCellValue = (value: unknown) => {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        if (typeof value === 'number') {
            return Number.isInteger(value)
                ? value.toLocaleString('en-NZ')
                : value.toLocaleString('en-NZ', {
                      minimumFractionDigits: 0,
                      maximumFractionDigits: 2,
                  });
        }

        if (
            typeof value === 'string' &&
            (/^\d{4}-\d{2}-\d{2}$/.test(value) || value.includes('T'))
        ) {
            return formatIsoDate(value);
        }

        return value;
    };

    const matchesSearch = (row: any) => {
        const q = search.trim().toLowerCase();
        if (!q) return true;
        return JSON.stringify(row).toLowerCase().includes(q);
    };

    const renderSummaryCards = (
        items: Array<{ label: string; value: number | string }>,
    ) => (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {items.map((item) => (
                <Card key={item.label}>
                    <CardContent className="pt-4">
                        <div className="text-2xl font-semibold">
                            {item.value}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {item.label}
                        </div>
                    </CardContent>
                </Card>
            ))}
        </div>
    );

    const renderTable = (
        rows: any[],
        columns: Array<{ key: string; label: string }>,
    ) => {
        const visibleRows = (rows ?? []).filter(matchesSearch);
        if (!visibleRows.length) {
            return (
                <div className="text-sm text-muted-foreground">
                    {search.trim() !== ''
                        ? 'No rows match your search.'
                        : 'No rows for this filter set.'}
                </div>
            );
        }

        return (
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs tracking-wider text-muted-foreground uppercase">
                            {columns.map((column) => (
                                <th
                                    key={column.key}
                                    className="py-2 pr-4 font-medium"
                                >
                                    {column.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {visibleRows.map((row, index) => (
                            <tr
                                key={
                                    row.id ??
                                    row.shift_id ??
                                    row.timesheet_id ??
                                    row.attendance_session_id ??
                                    index
                                }
                                className="border-b last:border-0"
                            >
                                {columns.map((column) => (
                                    <td
                                        key={column.key}
                                        className="py-2 pr-4 align-top"
                                    >
                                        {Array.isArray(row[column.key])
                                            ? row[column.key]
                                                  .map(
                                                      (entry: any) =>
                                                          entry.label ??
                                                          entry.key ??
                                                          '',
                                                  )
                                                  .join(', ')
                                            : formatCellValue(row[column.key])}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        );
    };

    const riskSummary = report.risk_summary ?? {};
    const staffUtilisation = report.staff_utilisation ?? {};
    const coverage = report.coverage_gap_report ?? {};
    const reconciliation = report.timesheet_reconciliation_report ?? {};
    const variance = report.attendance_variance_report ?? {};
    const reconciliationRows = [
        ...(reconciliation.blocked_rows ?? []).map((row: any) => ({
            ...row,
            display_date: row.work_date,
            bucket_label: 'Blocked Reconciliation',
        })),
        ...(reconciliation.review_rows ?? []).map((row: any) => ({
            ...row,
            display_date: row.work_date,
            bucket_label: 'Review Finding',
        })),
        ...(reconciliation.completed_shift_without_timesheet_rows ?? []).map(
            (row: any) => ({
                ...row,
                display_date: row.date,
                bucket_label: 'Completed Shift Missing Timesheet',
            }),
        ),
        ...(reconciliation.attendance_without_timesheet_rows ?? []).map(
            (row: any) => ({
                ...row,
                display_date: row.date,
                bucket_label: 'Attendance Missing Timesheet',
            }),
        ),
        ...(reconciliation.approved_not_exported_rows ?? []).map(
            (row: any) => ({
                ...row,
                display_date: row.work_date,
                bucket_label: 'Approved Not Exported',
            }),
        ),
    ];

    const uncoveredCount = riskSummary.uncovered_shifts_count ?? 0;
    const highRiskCount = riskSummary.high_risk_reconciliation_count ?? 0;
    const raisedFlags = (riskSummary.flags ?? []).filter(
        (flag: any) => (flag.count ?? 0) > 0,
    ).length;

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        {
            key: 'risk',
            label: 'Risk summary',
            icon: ShieldAlert,
            count: raisedFlags,
            alert: true,
        },
        {
            key: 'utilisation',
            label: 'Staff utilisation',
            icon: Users,
            count: staffUtilisation.total_staff ?? 0,
        },
        {
            key: 'coverage',
            label: 'Coverage gaps',
            icon: MapPin,
            count: coverage.gap_window_count ?? 0,
        },
        {
            key: 'reconciliation',
            label: 'Reconciliation',
            icon: Scale,
            count: reconciliationRows.length,
        },
        {
            key: 'variance',
            label: 'Variance',
            icon: Clock,
            count: (variance.shift_rows ?? []).length,
        },
    ];

    const titleChip =
        uncoveredCount > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {uncoveredCount} uncovered shifts
            </PageHeaderStatusChip>
        ) : highRiskCount > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {highRiskCount} high-risk reconciliation
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                No risk flags
            </PageHeaderStatusChip>
        );

    const siteOptions = [
        { value: 'all', label: 'All sites' },
        ...sites.map((s) => ({ value: String(s.id), label: s.name })),
    ];
    const staffOptions = [
        { value: 'all', label: 'All staff' },
        ...staff.map((s) => ({ value: String(s.id), label: s.name })),
    ];

    const header = (
        <PageHeader
            variant="profile"
            backHref="/operations/reports"
            icon={FileBarChart}
            title="Shift operations"
            titleChip={titleChip}
            subline={`Staffing, coverage, reconciliation and variance · ${filters.date_from} – ${filters.date_to}`}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search report rows…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="High-risk reconciliation"
                        tone={highRiskCount > 0 ? 'critical' : 'success'}
                        ariaLabel="View the reconciliation report"
                        onClick={() => setView('reconciliation')}
                    >
                        <PageHeaderMeterBig>{highRiskCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            timesheets needing intervention
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue approvals"
                        tone={
                            (riskSummary.overdue_timesheet_approvals_count ??
                                0) > 0
                                ? 'warning'
                                : 'success'
                        }
                        ariaLabel="View the reconciliation report"
                        onClick={() => setView('reconciliation')}
                    >
                        <PageHeaderMeterBig>
                            {riskSummary.overdue_timesheet_approvals_count ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            timesheet approvals outstanding
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Uncovered shifts"
                        tone={uncoveredCount > 0 ? 'critical' : 'success'}
                        ariaLabel="View the coverage gap report"
                        onClick={() => setView('coverage')}
                    >
                        <PageHeaderMeterBig>
                            {uncoveredCount}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            in the selected period
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overtime risk"
                        tone={
                            (riskSummary.overtime_risk_staff_count ?? 0) > 0
                                ? 'warning'
                                : 'success'
                        }
                        ariaLabel="View staff utilisation"
                        onClick={() => setView('utilisation')}
                    >
                        <PageHeaderMeterBig>
                            {riskSummary.overtime_risk_staff_count ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            staff over their weekly hours
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={CalendarRange}
                        label="Period"
                        value={currentPreset}
                        allValue="__none__"
                        options={periodOptions}
                        onChange={(v) => {
                            const preset = presets[v];
                            if (preset) {
                                applyServer({
                                    date_from: preset.from,
                                    date_to: preset.to,
                                });
                            }
                        }}
                    />
                    <PageHeaderFilterSelect
                        icon={MapPin}
                        label="All sites"
                        value={String(filters.site_id ?? 'all')}
                        options={siteOptions}
                        onChange={(v) =>
                            applyServer({ site_id: v === 'all' ? '' : v })
                        }
                    />
                    <PageHeaderFilterSelect
                        icon={Users}
                        label="All staff"
                        value={String(filters.staff_id ?? 'all')}
                        options={staffOptions}
                        onChange={(v) =>
                            applyServer({ staff_id: v === 'all' ? '' : v })
                        }
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={setView}
                    ariaLabel="Report sections"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Reports', href: '/operations/reports' },
                {
                    title: 'Shift operations',
                    href: '/operations/reports/shifts',
                },
            ]}
        >
            <Head title="Shift operations reports" />

            <PageLayout hero={header}>
                <div className="space-y-6">
                    {view === 'risk' && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Operational Risk Summary</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {renderSummaryCards([
                                    {
                                        label: 'High-Risk Reconciliation',
                                        value: highRiskCount,
                                    },
                                    {
                                        label: 'Overdue Approvals',
                                        value:
                                            riskSummary.overdue_timesheet_approvals_count ??
                                            0,
                                    },
                                    {
                                        label: 'Uncovered Shifts',
                                        value: uncoveredCount,
                                    },
                                    {
                                        label: 'Overtime Risk Staff',
                                        value:
                                            riskSummary.overtime_risk_staff_count ??
                                            0,
                                    },
                                ])}
                                <div className="flex flex-wrap gap-2">
                                    {(riskSummary.flags ?? []).map(
                                        (flag: any) => (
                                            <Badge
                                                key={flag.key}
                                                variant={
                                                    flag.count > 0
                                                        ? 'destructive'
                                                        : 'secondary'
                                                }
                                            >
                                                {flag.label}: {flag.count}
                                            </Badge>
                                        ),
                                    )}
                                </div>
                                {renderTable(riskSummary.flags ?? [], [
                                    { key: 'label', label: 'Flag' },
                                    { key: 'count', label: 'Count' },
                                    { key: 'severity', label: 'Severity' },
                                    { key: 'reason', label: 'Reason' },
                                ])}
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        exportDataset('risk-summary')
                                    }
                                >
                                    Export Risk Summary CSV
                                </Button>
                            </CardContent>
                        </Card>
                    )}

                    {view === 'utilisation' && (
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Staff Utilisation</CardTitle>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        exportDataset('staff-utilisation')
                                    }
                                >
                                    Export CSV
                                </Button>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {renderSummaryCards([
                                    {
                                        label: 'Total Staff',
                                        value:
                                            staffUtilisation.total_staff ?? 0,
                                    },
                                    {
                                        label: 'Total Shifts',
                                        value:
                                            staffUtilisation.total_shifts ?? 0,
                                    },
                                    {
                                        label: 'Planned Hours',
                                        value:
                                            staffUtilisation.total_planned_hours ??
                                            0,
                                    },
                                    {
                                        label: 'Worked Hours',
                                        value:
                                            staffUtilisation.total_worked_hours ??
                                            0,
                                    },
                                ])}
                                {renderTable(staffUtilisation.rows ?? [], [
                                    { key: 'staff_name', label: 'Staff' },
                                    { key: 'total_shifts', label: 'Shifts' },
                                    {
                                        key: 'planned_hours',
                                        label: 'Planned Hours',
                                    },
                                    {
                                        key: 'worked_hours',
                                        label: 'Worked Hours',
                                    },
                                    {
                                        key: 'hours_per_week',
                                        label: 'Hours / Week',
                                    },
                                    {
                                        key: 'overtime_flag',
                                        label: 'Overtime Flag',
                                    },
                                ])}
                            </CardContent>
                        </Card>
                    )}

                    {view === 'coverage' && (
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Coverage / Gap Report</CardTitle>
                                <div className="flex flex-wrap gap-2">
                                    {canManageRoadmap &&
                                    (coverage.chronic_shortage_count ?? 0) >
                                        0 ? (
                                        <Link href="/roadmap/dashboard#quick-add">
                                            <Button variant="outline">
                                                Raise Roadmap Initiative
                                            </Button>
                                        </Link>
                                    ) : null}
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            exportDataset('coverage-gaps')
                                        }
                                    >
                                        Export CSV
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {renderSummaryCards([
                                    {
                                        label: 'Gap Windows',
                                        value: coverage.gap_window_count ?? 0,
                                    },
                                    {
                                        label: 'Total Deficit',
                                        value: coverage.total_deficit ?? 0,
                                    },
                                    {
                                        label: 'Unresolved Uncovered',
                                        value:
                                            coverage.unresolved_uncovered_count ??
                                            0,
                                    },
                                    {
                                        label: 'Chronic Shortages',
                                        value:
                                            coverage.chronic_shortage_count ??
                                            0,
                                    },
                                ])}
                                {renderTable(coverage.rows ?? [], [
                                    { key: 'site_name', label: 'Site' },
                                    { key: 'rule_name', label: 'Rule' },
                                    { key: 'window_label', label: 'Window' },
                                    {
                                        key: 'required_staff',
                                        label: 'Required',
                                    },
                                    {
                                        key: 'assigned_staff',
                                        label: 'Assigned',
                                    },
                                    { key: 'planned_staff', label: 'Planned' },
                                    { key: 'deficit', label: 'Deficit' },
                                    {
                                        key: 'role_shortage_summary',
                                        label: 'Role Shortages',
                                    },
                                ])}
                            </CardContent>
                        </Card>
                    )}

                    {view === 'reconciliation' && (
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Timesheet Reconciliation</CardTitle>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        exportDataset('reconciliation')
                                    }
                                >
                                    Export CSV
                                </Button>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {renderSummaryCards([
                                    {
                                        label: 'Blocked Timesheets',
                                        value:
                                            reconciliation.blocked_count ?? 0,
                                    },
                                    {
                                        label: 'Review Findings',
                                        value: reconciliation.review_count ?? 0,
                                    },
                                    {
                                        label: 'Completed Shifts Missing Timesheets',
                                        value:
                                            reconciliation.completed_shift_without_timesheet_count ??
                                            0,
                                    },
                                    {
                                        label: 'Approved Not Exported',
                                        value:
                                            reconciliation.approved_not_exported_count ??
                                            0,
                                    },
                                ])}
                                {renderTable(reconciliationRows, [
                                    { key: 'bucket_label', label: 'Bucket' },
                                    { key: 'display_date', label: 'Date' },
                                    { key: 'staff_name', label: 'Staff' },
                                    { key: 'client_name', label: 'Client' },
                                    { key: 'site_name', label: 'Site' },
                                    { key: 'status', label: 'Status' },
                                    { key: 'summary', label: 'Summary' },
                                ])}
                            </CardContent>
                        </Card>
                    )}

                    {view === 'variance' && (
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>
                                    Attendance / Shift Variance
                                </CardTitle>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        exportDataset('attendance-variance')
                                    }
                                >
                                    Export CSV
                                </Button>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {renderSummaryCards([
                                    {
                                        label: 'Avg Start Variance (min)',
                                        value:
                                            variance.avg_start_variance_minutes ??
                                            0,
                                    },
                                    {
                                        label: 'Avg End Variance (min)',
                                        value:
                                            variance.avg_end_variance_minutes ??
                                            0,
                                    },
                                    {
                                        label: 'No-Shows',
                                        value: variance.no_show_count ?? 0,
                                    },
                                    {
                                        label: 'Late Starts',
                                        value: variance.late_start_count ?? 0,
                                    },
                                ])}
                                {renderTable(variance.shift_rows ?? [], [
                                    { key: 'shift_id', label: 'Shift' },
                                    { key: 'site_name', label: 'Site' },
                                    { key: 'staff_name', label: 'Staff' },
                                    { key: 'client_name', label: 'Client' },
                                    {
                                        key: 'start_variance_minutes',
                                        label: 'Start Variance',
                                    },
                                    {
                                        key: 'end_variance_minutes',
                                        label: 'End Variance',
                                    },
                                    { key: 'start_flag', label: 'Start Flag' },
                                    {
                                        key: 'completion_flag',
                                        label: 'Completion Flag',
                                    },
                                ])}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
