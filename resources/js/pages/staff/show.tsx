import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Client = { id: number; first_name: string; last_name: string; status: string };
type Shift = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    location?: string | null;
    client?: { id: number; first_name: string; last_name: string };
};

type Props = {
    user: {
        id: number;
        name: string;
        email: string;
        role?: string | null;
        roles?: { id: number; name: string; label: string }[];
        staff_profile?: any;
        assigned_clients?: Client[];
    };
    todayShifts: Shift[];
};

export default function StaffShow({ user, todayShifts }: Props) {
    const { auth, labels } = usePage().props as any;
    const can = auth?.can;

    const staffLabel = labels?.['staff.singular'] ?? 'Staff';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';

    return (
        <AppLayout breadcrumbs={[{ title: staffLabel, href: '/staff' }, { title: user.name, href: `/staff/${user.id}` }]}>
            <Head title={`${staffLabel}: ${user.name}`} />

            <div className="p-4 space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="text-lg font-semibold">{user.name}</div>
                        <div className="text-sm text-muted-foreground">{user.email}</div>
                        <div className="text-xs text-muted-foreground mt-1">
                            {user.roles?.length ? user.roles.map((r) => r.label).join(', ') : user.role ?? '—'}
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {can?.staff?.assignmentsUpdate ? (
                            <Link href={`/staff/${user.id}/assignments`}>
                                <Button variant="outline">Assignments</Button>
                            </Link>
                        ) : null}
                        {can?.staff?.update ? (
                            <Link href={`/staff/${user.id}/edit`}>
                                <Button>Edit</Button>
                            </Link>
                        ) : null}
                    </div>
                </div>

                <div className="grid gap-6 md:grid-cols-2">
                    <div className="rounded-md border p-4">
                        <div className="font-medium">Assigned {clientPlural}</div>
                        <div className="mt-3 space-y-2">
                            {user.assigned_clients?.length ? (
                                user.assigned_clients.map((c) => (
                                    <div key={c.id} className="flex items-center justify-between">
                                        <Link className="text-sm underline" href={`/clients/${c.id}`}>{c.first_name} {c.last_name}</Link>
                                        <span className="text-xs text-muted-foreground">{c.status}</span>
                                    </div>
                                ))
                            ) : (
                                <div className="text-sm text-muted-foreground">No assigned clients.</div>
                            )}
                        </div>
                    </div>

                    <div className="rounded-md border p-4">
                        <div className="font-medium">Today’s shifts</div>
                        <div className="mt-3 space-y-2">
                            {todayShifts?.length ? (
                                todayShifts.map((s) => (
                                    <div key={s.id} className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="text-sm font-medium">
                                                {new Date(s.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                {' – '}
                                                {new Date(s.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {s.client ? `${s.client.first_name} ${s.client.last_name}` : '—'}
                                                {s.location ? ` • ${s.location}` : ''}
                                            </div>
                                        </div>
                                        <Link className="text-xs underline" href={`/shifts/${s.id}/edit`}>View</Link>
                                    </div>
                                ))
                            ) : (
                                <div className="text-sm text-muted-foreground">No shifts today.</div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
