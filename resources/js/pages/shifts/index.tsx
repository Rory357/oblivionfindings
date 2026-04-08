/**
 * @deprecated LEGACY PAGE — Not rendered by any controller.
 * The active shifts index is at: pages/operations/shifts/index.tsx
 * Rendered by: ShiftController::index → inertia('operations/shifts/index')
 * This file is kept as reference only. Do not develop against this file.
 */
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { ShiftStatusBadge } from '@/components/shift-status-badge';
import { FilterBar, type FilterField } from '@/components/filter-bar';
import { EmptyList } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, Clock, Users, AlertCircle } from 'lucide-react';
import { OpsStatCard } from '@/components/ops-stat-card';
import { useMemo } from 'react';

type Shift = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    location?: string | null;
    client: { id: number; first_name: string; last_name: string };
    staff: { id: number; name: string };
};

type Props = {
    shifts: { data: Shift[] };
    filters: {
        from: string;
        to: string;
        status?: string | null;
        client_id?: string | number | null;
        user_id?: string | number | null;
        assigned?: string | null;
        q?: string | null;
    };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    staff: Array<{ id: number; name: string; email: string }>;
    statuses: string[];
    canCreate: boolean;
};

const statusLabel: Record<string, string> = {
    draft: 'Draft',
    scheduled: 'Scheduled',
    in_progress: 'In Progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export default function ShiftsIndex({ shifts, filters, clients, staff, statuses, canCreate }: Props) {
    const { labels } = usePage().props as any;
    const { auth } = usePage().props as any;
    const canEdit = auth?.can?.shifts?.update;
    const shiftPlural = labels?.['shift.plural'] ?? 'Shifts';
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';

    const hoursBetween = (start: string, end: string) => {
        const s = new Date(start).getTime();
        const e = new Date(end).getTime();
        if (Number.isNaN(s) || Number.isNaN(e) || e <= s) return '—';
        const hours = (e - s) / (1000 * 60 * 60);
        return `${hours.toFixed(1)}h`;
    };

    const stats = useMemo(() => {
        const data = shifts.data;
        return {
            total: data.length,
            inProgress: data.filter((s) => s.status === 'in_progress').length,
            scheduled: data.filter((s) => s.status === 'scheduled').length,
            unassigned: data.filter((s) => !s.staff?.name).length,
        };
    }, [shifts.data]);

    const activeFilterCount = useMemo(() => {
        let count = 0;
        if (filters.status) count++;
        if (filters.client_id) count++;
        if (filters.user_id) count++;
        if (filters.assigned) count++;
        if (filters.q) count++;
        return count;
    }, [filters]);

    const filterFields: FilterField[] = [
        { type: 'date', key: 'from', label: 'From' },
        { type: 'date', key: 'to', label: 'To' },
        { type: 'search', key: 'q', label: 'Search', placeholder: `Search ${clientSingular.toLowerCase()}, staff, location` },
        {
            type: 'select', key: 'status', label: 'Status', placeholder: 'All statuses',
            options: statuses.map((s) => ({ value: s, label: statusLabel[s] ?? s })),
        },
        {
            type: 'select', key: 'client_id', label: clientSingular, placeholder: `All ${clientPlural.toLowerCase()}`,
            options: clients.map((c) => ({ value: String(c.id), label: `${c.first_name} ${c.last_name}` })),
        },
        {
            type: 'select', key: 'user_id', label: 'Staff', placeholder: 'All staff',
            options: staff.map((u) => ({ value: String(u.id), label: u.name })),
        },
        {
            type: 'select', key: 'assigned', label: 'Assignment', placeholder: 'All',
            options: [{ value: 'assigned', label: 'Assigned only' }, { value: 'unassigned', label: 'Unassigned only' }],
        },
    ];

    const handleFilterChange = (key: string, value: any) => {
        router.get('/shifts', { ...filters, [key]: value || null }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: shiftPlural, href: '/shifts' }]}>
            <Head title={shiftPlural} />

            <PageShell>
                <FleetHero
                    title={shiftPlural}
                    description="Rostered shifts and appointments. Filter by date, status, or staff."
                    icon={<CalendarDays className="h-7 w-7 text-white" />}
                    actions={
                        canCreate ? (
                            <Button asChild>
                                <Link href="/shifts/create">Create shift</Link>
                            </Button>
                        ) : null
                    }
                    stats={[
                        { label: 'Total', value: stats.total },
                        { label: 'In Progress', value: stats.inProgress },
                        { label: 'Scheduled', value: stats.scheduled },
                        { label: 'Unassigned', value: stats.unassigned },
                    ]}
                />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <OpsStatCard label="Total Shifts" value={stats.total} icon={CalendarDays} color="indigo" />
                    <OpsStatCard label="In Progress" value={stats.inProgress} icon={Clock} color="amber" />
                    <OpsStatCard label="Scheduled" value={stats.scheduled} icon={CalendarDays} color="blue" />
                    <OpsStatCard label="Unassigned" value={stats.unassigned} icon={stats.unassigned > 0 ? AlertCircle : Users} color={stats.unassigned > 0 ? 'red' : 'emerald'} />
                </div>

                <FilterBar
                    fields={filterFields}
                    values={filters}
                    onChange={handleFilterChange}
                    onReset={() => router.get('/shifts', {}, { preserveState: true, replace: true })}
                    activeCount={activeFilterCount}
                />

                {shifts.data.length > 0 ? (
                    <>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/40">
                                    <tr>
                                        <th className="p-3 text-left font-medium">Time</th>
                                        <th className="p-3 text-left font-medium">Hours</th>
                                        <th className="p-3 text-left font-medium">{clientSingular}</th>
                                        <th className="p-3 text-left font-medium">Staff</th>
                                        <th className="p-3 text-left font-medium">Status</th>
                                        <th className="p-3 text-right font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {shifts.data.map((s) => (
                                        <tr key={s.id} className="border-t transition-colors hover:bg-muted/20">
                                            <td className="p-3">
                                                <div className="font-medium">
                                                    {new Date(s.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                    {' – '}
                                                    {new Date(s.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                </div>
                                                {s.location ? <div className="text-xs text-muted-foreground">{s.location}</div> : null}
                                            </td>
                                            <td className="p-3 tabular-nums">{hoursBetween(s.starts_at, s.ends_at)}</td>
                                            <td className="p-3">
                                                <Link className="underline" href={`/clients/${s.client.id}`}>
                                                    {s.client.first_name} {s.client.last_name}
                                                </Link>
                                            </td>
                                            <td className="p-3">{s.staff?.name ?? <span className="text-muted-foreground italic">Unassigned</span>}</td>
                                            <td className="p-3">
                                                <ShiftStatusBadge status={s.status} />
                                            </td>
                                            <td className="p-3">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Link href={`/shifts/${s.id}`}>
                                                        <Button variant="ghost" size="sm" className="text-xs">View</Button>
                                                    </Link>
                                                    {canEdit ? (
                                                        <Link href={`/shifts/${s.id}/edit`}>
                                                            <Button variant="ghost" size="sm" className="text-xs">Edit</Button>
                                                        </Link>
                                                    ) : null}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="text-xs text-muted-foreground">
                            Showing {shifts.data.length} {shifts.data.length === 1 ? 'shift' : 'shifts'}
                        </div>
                    </>
                ) : (
                    <EmptyList
                        title="No shifts found"
                        itemName="shift"
                        createHref={canCreate ? '/shifts/create' : undefined}
                        createLabel="Create shift"
                        description="No shifts found for the current filters. Try adjusting the date range or clearing filters."
                    />
                )}
            </PageShell>
        </AppLayout>
    );
}
