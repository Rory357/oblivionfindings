import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    filters: { date: string };
    canCreate: boolean;
};

export default function ShiftsIndex({ shifts, filters, canCreate }: Props) {
    const { labels } = usePage().props as any;
    const shiftPlural = labels?.['shift.plural'] ?? 'Shifts';

    return (
        <AppLayout breadcrumbs={[{ title: shiftPlural, href: '/shifts' }]}>
            <Head title={shiftPlural} />

            <div className="p-4 space-y-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="text-lg font-semibold">{shiftPlural}</div>
                        <div className="text-sm text-muted-foreground">Appointments / rostered shifts.</div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Input
                            type="date"
                            value={filters.date}
                            onChange={(e) => router.get('/shifts', { date: e.target.value }, { preserveState: true, replace: true })}
                        />
                        {canCreate ? (
                            <Link href="/shifts/create">
                                <Button>Create</Button>
                            </Link>
                        ) : null}
                    </div>
                </div>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                <th className="p-3 text-left font-medium">Time</th>
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
                                    <td className="p-3">
                                        <Link className="underline" href={`/clients/${s.client.id}`}>
                                            {s.client.first_name} {s.client.last_name}
                                        </Link>
                                    </td>
                                    <td className="p-3">{s.staff?.name ?? '—'}</td>
                                    <td className="p-3">{s.status}</td>
                                    <td className="p-3">
                                        <div className="flex justify-end gap-2">
                                            <Link className="text-xs underline" href={`/shifts/${s.id}/edit`}>Edit</Link>
                                            <Link className="text-xs underline" href={`/timesheets/create?shift_id=${s.id}`}>Timesheet</Link>
                                        </div>
                                    </td>
                                </tr>
                            ))}

                            {shifts.data.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center text-muted-foreground">No shifts for this day.</td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
