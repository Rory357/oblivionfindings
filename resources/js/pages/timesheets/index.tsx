import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, Link, router, usePage } from '@inertiajs/react';

type Timesheet = {
    id: number;
    work_date: string;
    starts_at: string;
    ends_at: string;
    break_minutes: number;
    status: string;
    client: { id: number; first_name: string; last_name: string };
    staff: { id: number; name: string };
};

type Props = {
    timesheets: { data: Timesheet[] };
    filters: { status?: string; from?: string; to?: string };
    canApprove: boolean;
    canCreate: boolean;
};

export default function TimesheetsIndex({ timesheets, filters, canApprove, canCreate }: Props) {
    const { labels } = usePage().props as any;
    const timesheetPlural = labels?.['timesheet.plural'] ?? 'Timesheets';

    return (
        <AppLayout breadcrumbs={[{ title: timesheetPlural, href: '/timesheets' }]}> 
            <Head title={timesheetPlural} />

            <div className="p-4 space-y-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="text-lg font-semibold">{timesheetPlural}</div>
                        <div className="text-sm text-muted-foreground">Work logs and approvals.</div>
                    </div>

                    <div className="flex items-center gap-2">
                        {canCreate ? (
                            <Link href="/timesheets/create">
                                <Button>Create</Button>
                            </Link>
                        ) : null}
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-2 rounded-md border p-3">
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
                            <option value="approved">approved</option>
                            <option value="rejected">rejected</option>
                        </select>
                    </div>
                    <div className="space-y-1">
                        <div className="text-xs text-muted-foreground">From</div>
                        <Input type="date" value={filters.from ?? ''} onChange={(e) => router.get('/timesheets', { ...filters, from: e.target.value || undefined }, { preserveState: true, replace: true })} />
                    </div>
                    <div className="space-y-1">
                        <div className="text-xs text-muted-foreground">To</div>
                        <Input type="date" value={filters.to ?? ''} onChange={(e) => router.get('/timesheets', { ...filters, to: e.target.value || undefined }, { preserveState: true, replace: true })} />
                    </div>
                    <Button variant="outline" onClick={() => router.get('/timesheets', {}, { preserveState: true, replace: true })}>Clear</Button>
                </div>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                <th className="p-3 text-left font-medium">Date</th>
                                <th className="p-3 text-left font-medium">Client</th>
                                <th className="p-3 text-left font-medium">Staff</th>
                                <th className="p-3 text-left font-medium">Status</th>
                                <th className="p-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {timesheets.data.map((t) => (
                                <tr key={t.id} className="border-t">
                                    <td className="p-3">
                                        <div className="font-medium">{t.work_date}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {new Date(t.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                            {' – '}
                                            {new Date(t.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                            {t.break_minutes ? ` • break ${t.break_minutes}m` : ''}
                                        </div>
                                    </td>
                                    <td className="p-3">
                                        <Link className="underline" href={`/clients/${t.client.id}`}>{t.client.first_name} {t.client.last_name}</Link>
                                    </td>
                                    <td className="p-3">{t.staff?.name ?? '—'}</td>
                                    <td className="p-3">{t.status}</td>
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
                                    <td colSpan={5} className="p-6 text-center text-muted-foreground">No timesheets found.</td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
