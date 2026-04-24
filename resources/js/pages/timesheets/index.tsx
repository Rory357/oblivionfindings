/**
 * @deprecated LEGACY PAGE — Not rendered by any controller.
 * The active timesheets index is at: pages/operations/timesheets/index.tsx
 * Rendered by: TimesheetController::index → inertia('operations/timesheets/index')
 * This file is kept as reference only. Do not develop against this file.
 */
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import { OpsStatCard } from '@/components/ops-stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { EmptyList } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { formatDate } from '@/lib/date-format';
import { useMemo, useState } from 'react';
import { FileText, Clock, CheckCircle2, AlertCircle, Send } from 'lucide-react';

type Timesheet = {
    id: number;
    work_date: string;
    starts_at: string;
    ends_at: string;
    break_minutes: number;
    status: string;
    is_residential_billable?: boolean;
    submitted_at?: string | null;
    client: { id: number; first_name: string; last_name: string };
    staff: { id: number; name: string };
};

type Props = {
    timesheets: { data: Timesheet[] };
    filters: { status?: string; from?: string; to?: string; client_id?: string | number; staff_id?: string | number; mode?: string | null };
    approvalMode?: boolean;
    clients?: Array<{ id: number; first_name: string; last_name: string }>;
    staff?: Array<{ id: number; name: string; email?: string }>;
    canApprove: boolean;
    canCreate: boolean;
};

const ANY = '__any__';

export default function TimesheetsIndex({ timesheets, filters, approvalMode, clients = [], staff = [], canApprove, canCreate }: Props) {
    const { labels } = usePage().props as any;
    const timesheetPlural = labels?.['timesheet.plural'] ?? 'Timesheets';
    const isApprovalMode = !!approvalMode;

    const [selected, setSelected] = useState<Record<number, boolean>>({});
    const selectedIds = useMemo(
        () => Object.entries(selected).filter(([, v]) => v).map(([k]) => Number(k)),
        [selected],
    );
    const allSelected = timesheets.data.length > 0 && timesheets.data.every((t) => selected[t.id]);

    const [decisionNotes, setDecisionNotes] = useState('');
    const [returnedNotes, setReturnedNotes] = useState('');
    const [bulkError, setBulkError] = useState<string | null>(null);

    const toggleAll = () => {
        if (allSelected) {
            setSelected({});
            return;
        }
        const next: Record<number, boolean> = {};
        timesheets.data.forEach((t) => (next[t.id] = true));
        setSelected(next);
    };

    const bulkApprove = () => {
        if (selectedIds.length === 0) return;
        setBulkError(null);
        router.post('/timesheets/bulk-approve', { ids: selectedIds, decision_notes: decisionNotes || null }, { preserveScroll: true });
    };

    const bulkReturn = () => {
        if (selectedIds.length === 0) return;
        if (!returnedNotes.trim()) {
            setBulkError('Return notes are required when returning timesheets.');
            return;
        }
        setBulkError(null);
        router.post('/timesheets/bulk-return', { ids: selectedIds, returned_notes: returnedNotes }, { preserveScroll: true });
    };

    const bulkReject = () => {
        if (selectedIds.length === 0) return;
        if (!decisionNotes.trim()) {
            setBulkError('Decision notes are required to reject timesheets.');
            return;
        }
        setBulkError(null);
        router.post('/timesheets/bulk-reject', { ids: selectedIds, decision_notes: decisionNotes }, { preserveScroll: true });
    };

    const stats = useMemo(() => {
        const data = timesheets.data;
        return {
            total: data.length,
            draft: data.filter((t) => t.status === 'draft').length,
            submitted: data.filter((t) => t.status === 'submitted').length,
            approved: data.filter((t) => t.status === 'approved').length,
        };
    }, [timesheets.data]);

    return (
        <AppLayout breadcrumbs={[{ title: timesheetPlural, href: '/timesheets' }]}>
            <Head title={isApprovalMode ? 'Timesheet Approvals' : timesheetPlural} />

            <PageShell>
                <FleetHero
                    title={isApprovalMode ? 'Timesheet Approvals' : timesheetPlural}
                    description={isApprovalMode ? 'Submitted timesheets waiting for a decision.' : 'Work logs, approvals, and timesheet management.'}
                    icon={<FileText className="h-7 w-7 text-white" />}
                    stats={
                        isApprovalMode
                            ? [{ label: 'Pending', value: stats.submitted }]
                            : [
                                { label: 'Total', value: stats.total },
                                { label: 'Draft', value: stats.draft },
                                { label: 'Submitted', value: stats.submitted },
                                { label: 'Approved', value: stats.approved },
                            ]
                    }
                    actions={
                        <div className="flex items-center gap-2">
                            {isApprovalMode ? (
                                <Button asChild>
                                    <Link href="/timesheets">All timesheets</Link>
                                </Button>
                            ) : canApprove ? (
                                <Button asChild>
                                    <Link href="/timesheets?mode=approvals">Approval queue</Link>
                                </Button>
                            ) : null}
                            {canCreate && !isApprovalMode ? (
                                <Button asChild>
                                    <Link href="/timesheets/create">Create</Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                {!isApprovalMode ? (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <OpsStatCard label="Total" value={stats.total} icon={FileText} color="indigo" />
                        <OpsStatCard label="Draft" value={stats.draft} icon={Clock} color="slate" />
                        <OpsStatCard label="Submitted" value={stats.submitted} icon={Send} color="amber" />
                        <OpsStatCard label="Approved" value={stats.approved} icon={CheckCircle2} color="emerald" />
                    </div>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-3">
                        <OpsStatCard label="Pending Approval" value={stats.submitted} icon={Send} color="amber" />
                        <OpsStatCard label="Selected" value={selectedIds.length} icon={CheckCircle2} color="indigo" />
                        <OpsStatCard label="Total Shown" value={stats.total} icon={FileText} color="slate" />
                    </div>
                )}

                {/* Filters */}
                <div className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-4">
                    {!isApprovalMode ? (
                        <div className="space-y-1">
                            <Label className="text-xs text-muted-foreground">Status</Label>
                            <Select
                                value={filters.status ?? ANY}
                                onValueChange={(v) =>
                                    router.get('/timesheets', { ...filters, status: v === ANY ? undefined : v }, { preserveState: true, replace: true })
                                }
                            >
                                <SelectTrigger className="mt-1 w-36">
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>All statuses</SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="submitted">Submitted</SelectItem>
                                    <SelectItem value="returned">Returned</SelectItem>
                                    <SelectItem value="approved">Approved</SelectItem>
                                    <SelectItem value="rejected">Rejected</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    ) : null}
                    <div className="space-y-1">
                        <Label className="text-xs text-muted-foreground">From</Label>
                        <Input type="date" className="mt-1" value={filters.from ?? ''} onChange={(e) => router.get('/timesheets', { ...filters, from: e.target.value || undefined }, { preserveState: true, replace: true })} />
                    </div>
                    <div className="space-y-1">
                        <Label className="text-xs text-muted-foreground">To</Label>
                        <Input type="date" className="mt-1" value={filters.to ?? ''} onChange={(e) => router.get('/timesheets', { ...filters, to: e.target.value || undefined }, { preserveState: true, replace: true })} />
                    </div>
                    {isApprovalMode ? (
                        <>
                            <div className="space-y-1">
                                <Label className="text-xs text-muted-foreground">Client</Label>
                                <Select
                                    value={filters.client_id ? String(filters.client_id) : ANY}
                                    onValueChange={(v) => router.get('/timesheets', { ...filters, client_id: v === ANY ? undefined : v }, { preserveState: true, replace: true })}
                                >
                                    <SelectTrigger className="mt-1 w-44"><SelectValue placeholder="All clients" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>All clients</SelectItem>
                                        {clients.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs text-muted-foreground">Staff</Label>
                                <Select
                                    value={filters.staff_id ? String(filters.staff_id) : ANY}
                                    onValueChange={(v) => router.get('/timesheets', { ...filters, staff_id: v === ANY ? undefined : v }, { preserveState: true, replace: true })}
                                >
                                    <SelectTrigger className="mt-1 w-44"><SelectValue placeholder="All staff" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>All staff</SelectItem>
                                        {staff.map((u) => (
                                            <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </>
                    ) : null}
                    <Button variant="ghost" size="sm" onClick={() => router.get('/timesheets', isApprovalMode ? { mode: 'approvals' } : {}, { preserveState: true, replace: true })}>
                        Clear
                    </Button>
                </div>

                {/* Bulk approval panel */}
                {isApprovalMode ? (
                    <div className="rounded-xl border border-primary/20 bg-card p-4 space-y-3">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div className="text-sm">
                                <span className="font-medium">Selected:</span> {selectedIds.length} of {timesheets.data.length}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button size="sm" disabled={selectedIds.length === 0} onClick={bulkApprove}>
                                    Approve selected
                                </Button>
                                <Button size="sm" disabled={selectedIds.length === 0} variant="outline" onClick={bulkReturn}>
                                    Return selected
                                </Button>
                                <Button size="sm" disabled={selectedIds.length === 0} variant="destructive" onClick={bulkReject}>
                                    Reject selected
                                </Button>
                            </div>
                        </div>

                        {bulkError ? (
                            <div className="flex items-center gap-2 rounded-lg border border-status-critical/30 bg-status-critical-bg px-3 py-2 text-sm text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical">
                                <AlertCircle className="h-4 w-4 shrink-0" />
                                {bulkError}
                            </div>
                        ) : null}

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label className="text-xs text-muted-foreground">Decision notes (optional for approve, required for reject)</Label>
                                <Textarea rows={3} value={decisionNotes} onChange={(e) => { setDecisionNotes(e.target.value); setBulkError(null); }} placeholder="Optional notes for approval, required for rejection" />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs text-muted-foreground">Return notes (required when returning)</Label>
                                <Textarea rows={3} value={returnedNotes} onChange={(e) => { setReturnedNotes(e.target.value); setBulkError(null); }} placeholder="What needs changing?" />
                            </div>
                        </div>
                    </div>
                ) : null}

                {/* Table */}
                {timesheets.data.length > 0 ? (
                    <>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/40">
                                    <tr>
                                        {isApprovalMode ? (
                                            <th className="p-3 text-left font-medium w-10">
                                                <input type="checkbox" checked={allSelected} onChange={toggleAll} />
                                            </th>
                                        ) : null}
                                        <th className="p-3 text-left font-medium">Date</th>
                                        <th className="p-3 text-left font-medium">Client</th>
                                        <th className="p-3 text-left font-medium">Staff</th>
                                        <th className="p-3 text-left font-medium">{isApprovalMode ? 'Submitted' : 'Status'}</th>
                                        <th className="p-3 text-right font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {timesheets.data.map((t) => (
                                        <tr key={t.id} className="border-t transition-colors hover:bg-muted/20">
                                            {isApprovalMode ? (
                                                <td className="p-3">
                                                    <input
                                                        type="checkbox"
                                                        checked={!!selected[t.id]}
                                                        onChange={(e) => setSelected((prev) => ({ ...prev, [t.id]: e.target.checked }))}
                                                    />
                                                </td>
                                            ) : null}
                                            <td className="p-3">
                                                <div className="font-medium">{formatDate(t.work_date)}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {new Date(t.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                    {' – '}
                                                    {new Date(t.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                    {t.break_minutes ? ` · ${t.break_minutes}m break` : ''}
                                                </div>
                                                {t.is_residential_billable ? (
                                                    <Badge variant="outline" className="mt-1 border-status-success/30 text-status-success bg-status-success text-[10px]">
                                                        Residential billable
                                                    </Badge>
                                                ) : null}
                                            </td>
                                            <td className="p-3">
                                                <Link className="underline" href={`/clients/${t.client.id}`}>{t.client.first_name} {t.client.last_name}</Link>
                                            </td>
                                            <td className="p-3">{t.staff?.name ?? '—'}</td>
                                            {isApprovalMode ? (
                                                <td className="p-3 text-sm text-muted-foreground">{t.submitted_at ? new Date(t.submitted_at).toLocaleString() : '—'}</td>
                                            ) : (
                                                <td className="p-3">
                                                    <TimesheetStatusBadge status={t.status} />
                                                </td>
                                            )}
                                            <td className="p-3">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Link href={`/timesheets/${t.id}/edit`}>
                                                        <Button variant="ghost" size="sm" className="text-xs">View</Button>
                                                    </Link>
                                                    {canApprove && t.status === 'submitted' ? (
                                                        <Badge variant="outline" className="border-status-warning/30 text-status-warning bg-status-warning text-[10px]">
                                                            Needs approval
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Showing {timesheets.data.length} {timesheets.data.length === 1 ? 'timesheet' : 'timesheets'}
                        </div>
                    </>
                ) : (
                    <EmptyList
                        title={isApprovalMode ? 'No timesheets pending' : 'No timesheets found'}
                        itemName="timesheet"
                        createHref={canCreate && !isApprovalMode ? '/timesheets/create' : undefined}
                        createLabel="Create timesheet"
                        description={isApprovalMode ? 'No submitted timesheets awaiting approval.' : 'No timesheets found for the current filters.'}
                    />
                )}
            </PageShell>
        </AppLayout>
    );
}
