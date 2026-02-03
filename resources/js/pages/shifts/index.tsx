import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, Link, router, usePage } from '@inertiajs/react';

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

export default function ShiftsIndex({ shifts, filters, clients, staff, statuses, canCreate }: Props) {
    const { labels } = usePage().props as any;
    const { auth } = usePage().props as any;
    const canEdit = auth?.can?.shifts?.update;
    const shiftPlural = labels?.['shift.plural'] ?? 'Shifts';
    const ANY = '__any__';
    const hoursBetween = (start: string, end: string) => {
        const s = new Date(start).getTime();
        const e = new Date(end).getTime();
        if (Number.isNaN(s) || Number.isNaN(e) || e <= s) return '—';
        const hours = (e - s) / (1000 * 60 * 60);
        return `${hours.toFixed(2)}h`;
    };

    return (
        <AppLayout breadcrumbs={[{ title: shiftPlural, href: '/shifts' }]}>
            <Head title={shiftPlural} />

            <PageShell>
                <PageHeader
                    title={shiftPlural}
                    description="Appointments / rostered shifts. Filter by day and open each shift to complete tasks and timesheets."
                    actions={
                        canCreate ? (
                            <Button asChild>
                                <Link href="/shifts/create">Create</Link>
                            </Button>
                        ) : null
                    }
                />

                <div className="flex flex-col gap-3">
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-6">
                        <Input
                            type="date"
                            value={filters.from}
                            onChange={(e) =>
                                router.get('/shifts', { ...filters, from: e.target.value }, { preserveState: true, replace: true })
                            }
                        />
                        <Input
                            type="date"
                            value={filters.to}
                            onChange={(e) =>
                                router.get('/shifts', { ...filters, to: e.target.value }, { preserveState: true, replace: true })
                            }
                        />
                        <Input
                            placeholder="Search client, staff, location"
                            value={filters.q ?? ''}
                            onChange={(e) =>
                                router.get('/shifts', { ...filters, q: e.target.value || null }, { preserveState: true, replace: true })
                            }
                        />
                        <Select
                            value={filters.status ?? ANY}
                            onValueChange={(v) =>
                                router.get('/shifts', { ...filters, status: v === ANY ? null : v }, { preserveState: true, replace: true })
                            }
                        >
                            <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>All statuses</SelectItem>
                                {statuses.map((s) => (
                                    <SelectItem key={s} value={s}>{s}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.client_id ? String(filters.client_id) : ANY}
                            onValueChange={(v) =>
                                router.get('/shifts', { ...filters, client_id: v === ANY ? null : v }, { preserveState: true, replace: true })
                            }
                        >
                            <SelectTrigger><SelectValue placeholder="Client" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>All clients</SelectItem>
                                {clients.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.first_name} {c.last_name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.user_id ? String(filters.user_id) : ANY}
                            onValueChange={(v) =>
                                router.get('/shifts', { ...filters, user_id: v === ANY ? null : v }, { preserveState: true, replace: true })
                            }
                        >
                            <SelectTrigger><SelectValue placeholder="Staff" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>All staff</SelectItem>
                                {staff.map((u) => (
                                    <SelectItem key={u.id} value={String(u.id)}>
                                        {u.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Select
                            value={filters.assigned ?? ANY}
                            onValueChange={(v) =>
                                router.get('/shifts', { ...filters, assigned: v === ANY ? null : v }, { preserveState: true, replace: true })
                            }
                        >
                            <SelectTrigger className="w-44"><SelectValue placeholder="Assignment" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>All assignments</SelectItem>
                                <SelectItem value="assigned">Assigned only</SelectItem>
                                <SelectItem value="unassigned">Unassigned only</SelectItem>
                            </SelectContent>
                        </Select>
                        <div className="text-sm text-muted-foreground">
                            Showing {shifts.data.length}
                        </div>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                <th className="p-3 text-left font-medium">Time</th>
                                <th className="p-3 text-left font-medium">Hours</th>
                                <th className="p-3 text-left font-medium">Client</th>
                                <th className="p-3 text-left font-medium">Staff</th>
                                <th className="p-3 text-left font-medium">Status</th>
                                <th className="p-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {shifts.data.map((s) => (
                                <tr key={s.id} className="border-t">
                                    <td className="p-3">
                                        <div className="font-medium">
                                            {new Date(s.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                            {' – '}
                                            {new Date(s.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                        </div>
                                        {s.location ? <div className="text-xs text-muted-foreground">{s.location}</div> : null}
                                    </td>
                                    <td className="p-3">{hoursBetween(s.starts_at, s.ends_at)}</td>
                                    <td className="p-3">
                                        <Link className="underline" href={`/clients/${s.client.id}`}>
                                            {s.client.first_name} {s.client.last_name}
                                        </Link>
                                    </td>
                                    <td className="p-3">{s.staff?.name ?? '—'}</td>
                                    <td className="p-3">{s.status}</td>
                                    <td className="p-3">
                                        <div className="flex justify-end gap-2">
                                            <Link className="text-xs underline" href={`/shifts/${s.id}`}>View</Link>
                                            {canEdit ? (
                                                <Link className="text-xs underline" href={`/shifts/${s.id}/edit`}>Edit</Link>
                                            ) : null}
                                            <Link className="text-xs underline" href={`/timesheets/create?shift_id=${s.id}`}>Timesheet</Link>
                                        </div>
                                    </td>
                                </tr>
                            ))}

                            {shifts.data.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="p-6 text-center text-muted-foreground">No shifts for this day.</td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
            </PageShell>
        </AppLayout>
    );
}
