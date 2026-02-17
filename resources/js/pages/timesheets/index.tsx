import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

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
        router.post(
            '/timesheets/bulk-approve',
            { ids: selectedIds, decision_notes: decisionNotes || null },
            { preserveScroll: true },
        );
    };

    const bulkReturn = () => {
        if (selectedIds.length === 0) return;
        if (!returnedNotes.trim()) {
            alert('Returned notes are required.');
            return;
        }
        router.post(
            '/timesheets/bulk-return',
            { ids: selectedIds, returned_notes: returnedNotes },
            { preserveScroll: true },
        );
    };

    const bulkReject = () => {
        if (selectedIds.length === 0) return;
        if (!decisionNotes.trim()) {
            alert('Decision notes are required to reject.');
            return;
        }
        router.post(
            '/timesheets/bulk-reject',
            { ids: selectedIds, decision_notes: decisionNotes },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: timesheetPlural, href: '/timesheets' }]}>
            <Head title={timesheetPlural} />

            <div className="p-4 space-y-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="text-lg font-semibold">{isApprovalMode ? 'Timesheet approvals' : timesheetPlural}</div>
                        <div className="text-sm text-muted-foreground">
                            {isApprovalMode ? 'Submitted timesheets waiting for a decision.' : 'Work logs and approvals.'}
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {isApprovalMode ? (
                            <Link href="/timesheets">
                                <Button variant="outline">All timesheets</Button>
                            </Link>
                        ) : canApprove ? (
                            <Link href="/timesheets?mode=approvals">
                                <Button variant="outline">Approval queue</Button>
                            </Link>
                        ) : null}

                        {canCreate && !isApprovalMode ? (
                            <Link href="/timesheets/create">
                                <Button>Create</Button>
                            </Link>
                        ) : null}
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-2 rounded-md border p-3">
                    {!isApprovalMode ? (
                        <div className="space-y-1">
                            <div className="text-xs text-muted-foreground">Status</div>
                            <select
                                className="rounded-md border bg-background p-2 text-sm"
                                value={filters.status ?? ''}
                                onChange={(e) => router.get('/timesheets', { ...filters, status: e.target.value || undefined }, { preserveState: true, replace: true })}
                            >
                                <option value="">All</option>
                                <option value="draft">draft</option>
                                <option value="submitted">submitted</option>
                                <option value="returned">returned</option>
                                <option value="approved">approved</option>
                                <option value="rejected">rejected</option>
                            </select>
                        </div>
                    ) : null}
                    <div className="space-y-1">
                        <div className="text-xs text-muted-foreground">From</div>
                        <Input type="date" value={filters.from ?? ''} onChange={(e) => router.get('/timesheets', { ...filters, from: e.target.value || undefined }, { preserveState: true, replace: true })} />
                    </div>
                    <div className="space-y-1">
                        <div className="text-xs text-muted-foreground">To</div>
                        <Input type="date" value={filters.to ?? ''} onChange={(e) => router.get('/timesheets', { ...filters, to: e.target.value || undefined }, { preserveState: true, replace: true })} />
                    </div>
                    {isApprovalMode ? (
                        <>
                            <div className="space-y-1">
                                <div className="text-xs text-muted-foreground">Client</div>
                                <select
                                    className="rounded-md border bg-background p-2 text-sm"
                                    value={(filters.client_id as any) ?? ''}
                                    onChange={(e) => router.get('/timesheets', { ...filters, client_id: e.target.value || undefined }, { preserveState: true, replace: true })}
                                >
                                    <option value="">All</option>
                                    {clients.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.first_name} {c.last_name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-1">
                                <div className="text-xs text-muted-foreground">Staff</div>
                                <select
                                    className="rounded-md border bg-background p-2 text-sm"
                                    value={(filters.staff_id as any) ?? ''}
                                    onChange={(e) => router.get('/timesheets', { ...filters, staff_id: e.target.value || undefined }, { preserveState: true, replace: true })}
                                >
                                    <option value="">All</option>
                                    {staff.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </>
                    ) : null}
                    <Button variant="outline" onClick={() => router.get('/timesheets', {}, { preserveState: true, replace: true })}>Clear</Button>
                </div>

                {isApprovalMode ? (
                    <div className="rounded-md border p-3 space-y-2">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div className="text-sm">
                                <span className="font-medium">Selected:</span> {selectedIds.length}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button disabled={selectedIds.length === 0} onClick={bulkApprove}>
                                    Approve selected
                                </Button>
                                <Button disabled={selectedIds.length === 0} variant="outline" onClick={bulkReturn}>
                                    Return selected
                                </Button>
                                <Button disabled={selectedIds.length === 0} variant="destructive" onClick={bulkReject}>
                                    Reject selected
                                </Button>
                            </div>
                        </div>

                        <div className="grid gap-2 sm:grid-cols-2">
                            <div className="space-y-1">
                                <div className="text-xs text-muted-foreground">Decision notes (optional for approve, required for reject)</div>
                                <textarea
                                    className="w-full rounded-md border bg-background p-2 text-sm"
                                    rows={3}
                                    value={decisionNotes}
                                    onChange={(e) => setDecisionNotes(e.target.value)}
                                    placeholder="Optional notes for approval, required for rejection"
                                />
                            </div>
                            <div className="space-y-1">
                                <div className="text-xs text-muted-foreground">Returned notes (required when returning)</div>
                                <textarea
                                    className="w-full rounded-md border bg-background p-2 text-sm"
                                    rows={3}
                                    value={returnedNotes}
                                    onChange={(e) => setReturnedNotes(e.target.value)}
                                    placeholder="What needs changing?"
                                />
                            </div>
                        </div>
                    </div>
                ) : null}

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                {isApprovalMode ? (
                                    <th className="p-3 text-left font-medium">
                                        <input type="checkbox" checked={allSelected} onChange={toggleAll} />
                                    </th>
                                ) : null}
                                <th className="p-3 text-left font-medium">Date</th>
                                <th className="p-3 text-left font-medium">Client</th>
                                <th className="p-3 text-left font-medium">Staff</th>
                                {isApprovalMode ? (
                                    <th className="p-3 text-left font-medium">Submitted</th>
                                ) : (
                                    <th className="p-3 text-left font-medium">Status</th>
                                )}
                                <th className="p-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {timesheets.data.map((t) => (
                                <tr key={t.id} className="border-t">
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
                                        <div className="font-medium">{t.work_date}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {new Date(t.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                            {' – '}
                                            {new Date(t.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                            {t.break_minutes ? ` • break ${t.break_minutes}m` : ''}
                                        </div>
                                        {t.is_residential_billable ? (
                                            <div className="mt-1 text-xs text-emerald-400">Residential billable</div>
                                        ) : null}
                                    </td>
                                    <td className="p-3">
                                        <Link className="underline" href={`/clients/${t.client.id}`}>{t.client.first_name} {t.client.last_name}</Link>
                                    </td>
                                    <td className="p-3">{t.staff?.name ?? '—'}</td>
                                    {isApprovalMode ? (
                                        <td className="p-3">{t.submitted_at ? new Date(t.submitted_at).toLocaleString() : '—'}</td>
                                    ) : (
                                        <td className="p-3">{t.status}</td>
                                    )}
                                    <td className="p-3">
                                        <div className="flex justify-end gap-2">
                                            <Link className="text-xs underline" href={`/timesheets/${t.id}/edit`}>View / Edit</Link>
                                            {canApprove && t.status === 'submitted' ? (
                                                <span className="text-xs text-muted-foreground">(needs approval)</span>
                                            ) : null}
                                        </div>
                                    </td>
                                </tr>
                            ))}

                            {timesheets.data.length === 0 ? (
                                <tr>
                                    <td colSpan={isApprovalMode ? 6 : 5} className="p-6 text-center text-muted-foreground">
                                        {isApprovalMode ? 'No submitted timesheets awaiting approval.' : 'No timesheets found.'}
                                    </td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
