import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { MyDayList, type MyDayItem } from '@/components/workstream/my-day-list';

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
    myDayItems?: MyDayItem[];
    todayShifts: Shift[];
    upcomingShifts: Shift[];
};

export default function StaffShow({ user, myDayItems, todayShifts, upcomingShifts }: Props) {
    const { auth, labels } = usePage().props as any;
    const can = auth?.can;
    const getInitials = useInitials();

    const staffLabel = labels?.['staff.singular'] ?? 'Staff';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';

    return (
        <AppLayout breadcrumbs={[{ title: staffLabel, href: '/staff' }, { title: user.name, href: `/staff/${user.id}` }]}>
            <Head title={`${staffLabel}: ${user.name}`} />

            <PageShell>
                <PageHeader
                    title={user.name}
                    description={user.email}
                />

                <div className="flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Avatar className="h-10 w-10">
                            <AvatarImage src={(user as any).avatar ?? (user as any).profile_photo_url ?? undefined} alt={user.name} />
                            <AvatarFallback>{getInitials(user.name)}</AvatarFallback>
                        </Avatar>
                        <div>
                            <div className="text-sm text-muted-foreground">
                                {user.roles?.length ? user.roles.map((r) => r.label).join(', ') : user.role ?? '—'}
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Link href={`/staff/${user.id}/credentials`}>
                            <Button variant="outline">Credentials</Button>
                        </Link>
                        <Link href={`/staff/${user.id}/availability`}>
                            <Button variant="outline">Availability</Button>
                        </Link>
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
                    <div className="md:col-span-2">
                        <MyDayList
                            title="Workstream"
                            items={myDayItems ?? []}
                            emptyLabel="No tasks or follow-ups due."
                        />
                    </div>

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

                    <div className="rounded-md border p-4 md:col-span-2">
                        <div className="font-medium">Upcoming schedule (next 14 days)</div>
                        <div className="mt-3 divide-y">
                            {upcomingShifts?.length ? (
                                upcomingShifts.map((s) => (
                                    <div key={s.id} className="flex items-start justify-between gap-3 py-3">
                                        <div>
                                            <div className="text-sm font-medium">
                                                {new Date(s.starts_at).toLocaleString([], {
                                                    weekday: 'short',
                                                    month: 'short',
                                                    day: 'numeric',
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                                {' – '}
                                                {new Date(s.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {s.client ? `${s.client.first_name} ${s.client.last_name}` : '—'}
                                                {s.location ? ` • ${s.location}` : ''}
                                                {s.status ? ` • ${s.status}` : ''}
                                            </div>
                                        </div>
                                        <Link className="text-xs underline" href={`/shifts/${s.id}/edit`}>
                                            View
                                        </Link>
                                    </div>
                                ))
                            ) : (
                                <div className="py-3 text-sm text-muted-foreground">No upcoming shifts.</div>
                            )}
                        </div>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
