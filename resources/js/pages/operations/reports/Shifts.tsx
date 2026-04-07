import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';

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

export default function ShiftReports({ filters, sites, staff, export_url, report }: Props) {
    const requestValue = (formData: FormData, key: string) => {
        const value = formData.get(key);

        return typeof value === 'string' && value !== '' ? value : undefined;
    };

    const applyFilters = (formData: FormData) => {
        router.get(
            '/operations/reports/shifts',
            {
                date_from: requestValue(formData, 'date_from'),
                date_to: requestValue(formData, 'date_to'),
                site_id: requestValue(formData, 'site_id'),
                staff_id: requestValue(formData, 'staff_id'),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

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

        return parsed.toLocaleString('en-NZ', hasTime
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
              });
    };

    const formatCellValue = (value: unknown) => {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        if (typeof value === 'number') {
            return Number.isInteger(value)
                ? value.toLocaleString('en-NZ')
                : value.toLocaleString('en-NZ', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        }

        if (typeof value === 'string' && (/^\d{4}-\d{2}-\d{2}$/.test(value) || value.includes('T'))) {
            return formatIsoDate(value);
        }

        return value;
    };

    const renderSummaryCards = (items: Array<{ label: string; value: number | string }>) => (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {items.map((item) => (
                <Card key={item.label}>
                    <CardContent className="pt-4">
                        <div className="text-2xl font-semibold">{item.value}</div>
                        <div className="text-xs text-muted-foreground">{item.label}</div>
                    </CardContent>
                </Card>
            ))}
        </div>
    );

    const renderTable = (rows: any[], columns: Array<{ key: string; label: string }>) => {
        if (!rows?.length) {
            return <div className="text-sm text-muted-foreground">No rows for this filter set.</div>;
        }

        return (
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs uppercase tracking-wider text-muted-foreground">
                            {columns.map((column) => (
                                <th key={column.key} className="py-2 pr-4 font-medium">
                                    {column.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, index) => (
                            <tr key={row.id ?? row.shift_id ?? row.timesheet_id ?? row.attendance_session_id ?? index} className="border-b last:border-0">
                                {columns.map((column) => (
                                    <td key={column.key} className="py-2 pr-4 align-top">
                                        {Array.isArray(row[column.key])
                                            ? row[column.key].map((entry: any) => entry.label ?? entry.key ?? '').join(', ')
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
        ...(reconciliation.completed_shift_without_timesheet_rows ?? []).map((row: any) => ({
            ...row,
            display_date: row.date,
            bucket_label: 'Completed Shift Missing Timesheet',
        })),
        ...(reconciliation.attendance_without_timesheet_rows ?? []).map((row: any) => ({
            ...row,
            display_date: row.date,
            bucket_label: 'Attendance Missing Timesheet',
        })),
        ...(reconciliation.approved_not_exported_rows ?? []).map((row: any) => ({
            ...row,
            display_date: row.work_date,
            bucket_label: 'Approved Not Exported',
        })),
    ];

    return (
        <AppLayout>
            <Head title="Shift Operations Reports" />
            <PageHeader
                title="Shift Operations Reports"
                description="Decision-grade reporting for staffing, coverage, reconciliation, attendance variance, and payroll risk."
                backHref="/operations/reports"
            />
            <PageShell>
                <form
                    className="mb-6 grid gap-3 rounded-xl border bg-card p-4 lg:grid-cols-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        applyFilters(new FormData(event.currentTarget));
                    }}
                >
                    <Input name="date_from" type="date" defaultValue={filters.date_from} />
                    <Input name="date_to" type="date" defaultValue={filters.date_to} />
                    <select
                        name="site_id"
                        defaultValue={filters.site_id ?? ''}
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All Sites</option>
                        {sites.map((site) => (
                            <option key={site.id} value={site.id}>
                                {site.name}
                            </option>
                        ))}
                    </select>
                    <select
                        name="staff_id"
                        defaultValue={filters.staff_id ?? ''}
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All Staff</option>
                        {staff.map((staffMember) => (
                            <option key={staffMember.id} value={staffMember.id}>
                                {staffMember.name}
                            </option>
                        ))}
                    </select>
                    <Button type="submit">Apply Filters</Button>
                </form>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Operational Risk Summary</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {renderSummaryCards([
                                { label: 'High-Risk Reconciliation', value: riskSummary.high_risk_reconciliation_count ?? 0 },
                                { label: 'Overdue Approvals', value: riskSummary.overdue_timesheet_approvals_count ?? 0 },
                                { label: 'Uncovered Shifts', value: riskSummary.uncovered_shifts_count ?? 0 },
                                { label: 'Overtime Risk Staff', value: riskSummary.overtime_risk_staff_count ?? 0 },
                            ])}
                            <div className="flex flex-wrap gap-2">
                                {(riskSummary.flags ?? []).map((flag: any) => (
                                    <Badge key={flag.key} variant={flag.count > 0 ? 'destructive' : 'secondary'}>
                                        {flag.label}: {flag.count}
                                    </Badge>
                                ))}
                            </div>
                            {renderTable(riskSummary.flags ?? [], [
                                { key: 'label', label: 'Flag' },
                                { key: 'count', label: 'Count' },
                                { key: 'severity', label: 'Severity' },
                                { key: 'reason', label: 'Reason' },
                            ])}
                            <Button variant="outline" onClick={() => exportDataset('risk-summary')}>
                                Export Risk Summary CSV
                            </Button>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Staff Utilisation</CardTitle>
                            <Button variant="outline" onClick={() => exportDataset('staff-utilisation')}>
                                Export CSV
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {renderSummaryCards([
                                { label: 'Total Staff', value: staffUtilisation.total_staff ?? 0 },
                                { label: 'Total Shifts', value: staffUtilisation.total_shifts ?? 0 },
                                { label: 'Planned Hours', value: staffUtilisation.total_planned_hours ?? 0 },
                                { label: 'Worked Hours', value: staffUtilisation.total_worked_hours ?? 0 },
                            ])}
                            {renderTable(staffUtilisation.rows ?? [], [
                                { key: 'staff_name', label: 'Staff' },
                                { key: 'total_shifts', label: 'Shifts' },
                                { key: 'planned_hours', label: 'Planned Hours' },
                                { key: 'worked_hours', label: 'Worked Hours' },
                                { key: 'hours_per_week', label: 'Hours / Week' },
                                { key: 'overtime_flag', label: 'Overtime Flag' },
                            ])}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Coverage / Gap Report</CardTitle>
                            <Button variant="outline" onClick={() => exportDataset('coverage-gaps')}>
                                Export CSV
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {renderSummaryCards([
                                { label: 'Gap Windows', value: coverage.gap_window_count ?? 0 },
                                { label: 'Total Deficit', value: coverage.total_deficit ?? 0 },
                                { label: 'Unresolved Uncovered', value: coverage.unresolved_uncovered_count ?? 0 },
                                { label: 'Chronic Shortages', value: coverage.chronic_shortage_count ?? 0 },
                            ])}
                            {renderTable(coverage.rows ?? [], [
                                { key: 'site_name', label: 'Site' },
                                { key: 'rule_name', label: 'Rule' },
                                { key: 'window_label', label: 'Window' },
                                { key: 'required_staff', label: 'Required' },
                                { key: 'assigned_staff', label: 'Assigned' },
                                { key: 'planned_staff', label: 'Planned' },
                                { key: 'deficit', label: 'Deficit' },
                                { key: 'role_shortage_summary', label: 'Role Shortages' },
                            ])}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Timesheet Reconciliation</CardTitle>
                            <Button variant="outline" onClick={() => exportDataset('reconciliation')}>
                                Export CSV
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {renderSummaryCards([
                                { label: 'Blocked Timesheets', value: reconciliation.blocked_count ?? 0 },
                                { label: 'Review Findings', value: reconciliation.review_count ?? 0 },
                                { label: 'Completed Shifts Missing Timesheets', value: reconciliation.completed_shift_without_timesheet_count ?? 0 },
                                { label: 'Approved Not Exported', value: reconciliation.approved_not_exported_count ?? 0 },
                            ])}
                            {renderTable(
                                reconciliationRows,
                                [
                                    { key: 'bucket_label', label: 'Bucket' },
                                    { key: 'display_date', label: 'Date' },
                                    { key: 'staff_name', label: 'Staff' },
                                    { key: 'client_name', label: 'Client' },
                                    { key: 'site_name', label: 'Site' },
                                    { key: 'status', label: 'Status' },
                                    { key: 'summary', label: 'Summary' },
                                ],
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Attendance / Shift Variance</CardTitle>
                            <Button variant="outline" onClick={() => exportDataset('attendance-variance')}>
                                Export CSV
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {renderSummaryCards([
                                { label: 'Avg Start Variance (min)', value: variance.avg_start_variance_minutes ?? 0 },
                                { label: 'Avg End Variance (min)', value: variance.avg_end_variance_minutes ?? 0 },
                                { label: 'No-Shows', value: variance.no_show_count ?? 0 },
                                { label: 'Late Starts', value: variance.late_start_count ?? 0 },
                            ])}
                            {renderTable(variance.shift_rows ?? [], [
                                { key: 'shift_id', label: 'Shift' },
                                { key: 'site_name', label: 'Site' },
                                { key: 'staff_name', label: 'Staff' },
                                { key: 'client_name', label: 'Client' },
                                { key: 'start_variance_minutes', label: 'Start Variance' },
                                { key: 'end_variance_minutes', label: 'End Variance' },
                                { key: 'start_flag', label: 'Start Flag' },
                                { key: 'completion_flag', label: 'Completion Flag' },
                            ])}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
