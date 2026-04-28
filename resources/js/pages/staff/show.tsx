import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { MyDayList, type MyDayItem } from '@/components/workstream/my-day-list';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateTime,
    formatDistance,
    formatDuration,
} from '@/lib/fleet-utils';
import { edit as editShift } from '@/routes/operations/shifts';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Car,
    CheckCircle2,
    IdCard,
    Route,
    Shield,
    Truck,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type Client = {
    id: number;
    first_name: string;
    last_name: string;
    status: string;
};
type Shift = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    location?: string | null;
    client?: { id: number; first_name: string; last_name: string };
};

type FleetData = {
    eligibility: {
        licence_class: string | null;
        licence_expires_at: string | null;
        can_drive_clients: boolean;
        can_drive_clients_approved_at: string | null;
        status: string;
        incident_free_since: string | null;
        last_reviewed_at: string | null;
        next_review_at: string | null;
    } | null;
    stats: {
        trips_30d: number;
        distance_km_30d: number;
        safety_score: number | null;
        incidents_30d: number;
    };
    recent_trips: Array<{
        id: number;
        vehicle: { id: number; name: string } | null;
        started_at: string | null;
        ended_at: string | null;
        distance_km: number | null;
        duration_s: number | null;
        status: string;
    }>;
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
    fleet?: FleetData;
};

export default function StaffShow({
    user,
    myDayItems,
    todayShifts,
    upcomingShifts,
    fleet,
}: Props) {
    const { auth, labels } = usePage().props as any;
    const can = auth?.can;
    const getInitials = useInitials();

    const staffLabel = labels?.['staff.singular'] ?? 'Staff';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';

    // Lazy-load fleet data on first render
    const [fleetLoaded, setFleetLoaded] = useState(!!fleet);
    useEffect(() => {
        if (!fleetLoaded) {
            router.reload({
                only: ['fleet'],
                onSuccess: () => setFleetLoaded(true),
            });
        }
    }, [fleetLoaded]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: staffLabel, href: '/staff' },
                { title: user.name, href: `/staff/${user.id}` },
            ]}
        >
            <Head title={`${staffLabel}: ${user.name}`} />

            <PageShell>
                <PageHeader title={user.name} description={user.email} />

                <div className="flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Avatar className="h-10 w-10">
                            <AvatarImage
                                src={
                                    (user as any).avatar ??
                                    (user as any).profile_photo_url ??
                                    undefined
                                }
                                alt={user.name}
                            />
                            <AvatarFallback>
                                {getInitials(user.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div>
                            <div className="text-sm text-muted-foreground">
                                {user.roles?.length
                                    ? user.roles.map((r) => r.label).join(', ')
                                    : (user.role ?? '—')}
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
                        <div className="font-medium">
                            Assigned {clientPlural}
                        </div>
                        <div className="mt-3 space-y-2">
                            {user.assigned_clients?.length ? (
                                user.assigned_clients.map((c) => (
                                    <div
                                        key={c.id}
                                        className="flex items-center justify-between"
                                    >
                                        <Link
                                            className="text-sm underline"
                                            href={`/clients/${c.id}`}
                                        >
                                            {c.first_name} {c.last_name}
                                        </Link>
                                        <span className="text-xs text-muted-foreground">
                                            {c.status}
                                        </span>
                                    </div>
                                ))
                            ) : (
                                <div className="text-sm text-muted-foreground">
                                    No assigned {clientPlural.toLowerCase()}.
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="rounded-md border p-4">
                        <div className="font-medium">Today’s shifts</div>
                        <div className="mt-3 space-y-2">
                            {todayShifts?.length ? (
                                todayShifts.map((s) => (
                                    <div
                                        key={s.id}
                                        className="flex items-start justify-between gap-3"
                                    >
                                        <div>
                                            <div className="text-sm font-medium">
                                                {new Date(
                                                    s.starts_at,
                                                ).toLocaleTimeString([], {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                                {' – '}
                                                {new Date(
                                                    s.ends_at,
                                                ).toLocaleTimeString([], {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {s.client
                                                    ? `${s.client.first_name} ${s.client.last_name}`
                                                    : '—'}
                                                {s.location
                                                    ? ` • ${s.location}`
                                                    : ''}
                                            </div>
                                        </div>
                                        <Link
                                            className="text-xs underline"
                                            href={editShift.url(s.id)}
                                        >
                                            View
                                        </Link>
                                    </div>
                                ))
                            ) : (
                                <div className="text-sm text-muted-foreground">
                                    No shifts today.
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="rounded-md border p-4 md:col-span-2">
                        <div className="font-medium">
                            Upcoming schedule (next 14 days)
                        </div>
                        <div className="mt-3 divide-y">
                            {upcomingShifts?.length ? (
                                upcomingShifts.map((s) => (
                                    <div
                                        key={s.id}
                                        className="flex items-start justify-between gap-3 py-3"
                                    >
                                        <div>
                                            <div className="text-sm font-medium">
                                                {new Date(
                                                    s.starts_at,
                                                ).toLocaleString([], {
                                                    weekday: 'short',
                                                    month: 'short',
                                                    day: 'numeric',
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                                {' – '}
                                                {new Date(
                                                    s.ends_at,
                                                ).toLocaleTimeString([], {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {s.client
                                                    ? `${s.client.first_name} ${s.client.last_name}`
                                                    : '—'}
                                                {s.location
                                                    ? ` • ${s.location}`
                                                    : ''}
                                                {s.status
                                                    ? ` • ${s.status}`
                                                    : ''}
                                            </div>
                                        </div>
                                        <Link
                                            className="text-xs underline"
                                            href={editShift.url(s.id)}
                                        >
                                            View
                                        </Link>
                                    </div>
                                ))
                            ) : (
                                <div className="py-3 text-sm text-muted-foreground">
                                    No upcoming shifts.
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Fleet & Driving */}
                {fleet && (
                    <Card className="border-l-4 border-l-purple-500">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Truck className="h-4 w-4 text-primary" />
                                Fleet &amp; Driving
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            {/* Driver Eligibility */}
                            {fleet.eligibility ? (
                                <div className="space-y-3 rounded-md border p-4">
                                    <div className="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase">
                                        <IdCard className="h-3.5 w-3.5" />{' '}
                                        Driver Eligibility
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <div>
                                            <div className="text-xs text-muted-foreground">
                                                Licence Class
                                            </div>
                                            <div className="text-sm font-medium">
                                                {fleet.eligibility
                                                    .licence_class ?? '—'}
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">
                                                Licence Expires
                                            </div>
                                            <div className="flex items-center gap-1.5 text-sm font-medium">
                                                {fleet.eligibility
                                                    .licence_expires_at ?? '—'}
                                                {fleet.eligibility
                                                    .licence_expires_at &&
                                                    (() => {
                                                        const days = Math.ceil(
                                                            (new Date(
                                                                fleet
                                                                    .eligibility
                                                                    .licence_expires_at!,
                                                            ).getTime() -
                                                                Date.now()) /
                                                                86400000,
                                                        );
                                                        if (days < 0)
                                                            return (
                                                                <Badge
                                                                    variant="destructive"
                                                                    className="text-[9px]"
                                                                >
                                                                    Expired
                                                                </Badge>
                                                            );
                                                        if (days <= 30)
                                                            return (
                                                                <Badge
                                                                    variant="destructive"
                                                                    className="text-[9px]"
                                                                >
                                                                    {days}d
                                                                </Badge>
                                                            );
                                                        if (days <= 90)
                                                            return (
                                                                <Badge className="bg-status-warning text-[9px]">
                                                                    {days}d
                                                                </Badge>
                                                            );
                                                        return null;
                                                    })()}
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-muted-foreground">
                                                Can Drive Residents
                                            </div>
                                            <div className="flex items-center gap-1.5 text-sm font-medium">
                                                {fleet.eligibility
                                                    .can_drive_clients ? (
                                                    <>
                                                        <CheckCircle2 className="h-3.5 w-3.5 text-status-success" />{' '}
                                                        Approved
                                                    </>
                                                ) : (
                                                    <>
                                                        <XCircle className="h-3.5 w-3.5 text-status-critical" />{' '}
                                                        Not approved
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-3 text-xs text-muted-foreground">
                                        <span>
                                            Status:{' '}
                                            <span
                                                className={`font-medium ${fleet.eligibility.status === 'eligible' ? 'text-status-success' : fleet.eligibility.status === 'suspended' ? 'text-status-critical' : 'text-muted-foreground'}`}
                                            >
                                                {fleet.eligibility.status}
                                            </span>
                                        </span>
                                        {fleet.eligibility
                                            .incident_free_since && (
                                            <span>
                                                · Incident-free since{' '}
                                                {
                                                    fleet.eligibility
                                                        .incident_free_since
                                                }
                                            </span>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <div className="rounded-md border border-dashed p-4 text-center">
                                    <IdCard className="mx-auto mb-1 h-6 w-6 text-muted-foreground/40" />
                                    <div className="text-sm text-muted-foreground">
                                        No driver eligibility record
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        This staff member has not been set up as
                                        a driver.
                                    </div>
                                </div>
                            )}

                            {/* Stats Row */}
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div className="rounded-md border p-3 text-center">
                                    <Route className="mx-auto mb-1 h-4 w-4 text-status-info" />
                                    <div className="text-lg font-bold">
                                        {fleet.stats.trips_30d}
                                    </div>
                                    <div className="text-[10px] text-muted-foreground">
                                        Trips (30d)
                                    </div>
                                </div>
                                <div className="rounded-md border p-3 text-center">
                                    <Car className="mx-auto mb-1 h-4 w-4 text-primary" />
                                    <div className="text-lg font-bold">
                                        {fleet.stats.distance_km_30d}{' '}
                                        <span className="text-xs font-normal text-muted-foreground">
                                            km
                                        </span>
                                    </div>
                                    <div className="text-[10px] text-muted-foreground">
                                        Distance (30d)
                                    </div>
                                </div>
                                <div className="rounded-md border p-3 text-center">
                                    <Shield className="mx-auto mb-1 h-4 w-4 text-status-success" />
                                    <div className="text-lg font-bold">
                                        {fleet.stats.safety_score ?? '—'}
                                        <span className="text-xs font-normal text-muted-foreground">
                                            /100
                                        </span>
                                    </div>
                                    <div className="text-[10px] text-muted-foreground">
                                        Safety Score
                                    </div>
                                </div>
                                <div
                                    className={`rounded-md border p-3 text-center ${fleet.stats.incidents_30d > 0 ? 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30 dark:bg-status-critical' : ''}`}
                                >
                                    <AlertTriangle
                                        className={`mx-auto mb-1 h-4 w-4 ${fleet.stats.incidents_30d > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
                                    />
                                    <div
                                        className={`text-lg font-bold ${fleet.stats.incidents_30d > 0 ? 'text-status-critical' : ''}`}
                                    >
                                        {fleet.stats.incidents_30d}
                                    </div>
                                    <div className="text-[10px] text-muted-foreground">
                                        Incidents (30d)
                                    </div>
                                </div>
                            </div>

                            {/* Recent Trips */}
                            {fleet.recent_trips.length > 0 ? (
                                <div>
                                    <div className="mb-2 text-xs font-semibold text-muted-foreground uppercase">
                                        Recent Trips
                                    </div>
                                    <div className="space-y-1.5">
                                        {fleet.recent_trips.map((trip) => (
                                            <div
                                                key={trip.id}
                                                className="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                                            >
                                                <div className="flex min-w-0 items-center gap-3">
                                                    <Truck className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                                    <div className="min-w-0">
                                                        <span className="font-medium">
                                                            {trip.vehicle
                                                                ?.name ??
                                                                'Unknown vehicle'}
                                                        </span>
                                                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                            {trip.started_at && (
                                                                <span>
                                                                    {formatDateTime(
                                                                        trip.started_at,
                                                                    )}
                                                                </span>
                                                            )}
                                                            {trip.distance_km !=
                                                                null && (
                                                                <span>
                                                                    ·{' '}
                                                                    {formatDistance(
                                                                        Number(
                                                                            trip.distance_km,
                                                                        ),
                                                                    )}
                                                                </span>
                                                            )}
                                                            {trip.duration_s !=
                                                                null && (
                                                                <span>
                                                                    ·{' '}
                                                                    {formatDuration(
                                                                        trip.duration_s,
                                                                    )}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                                <Badge
                                                    variant={
                                                        trip.status === 'closed'
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    className="shrink-0 text-[10px]"
                                                >
                                                    {trip.status}
                                                </Badge>
                                            </div>
                                        ))}
                                    </div>
                                    <Link
                                        href={`/fleet-assets/drivers/${user.id}`}
                                        className="mt-2 inline-block text-xs text-primary hover:underline"
                                    >
                                        View full driving profile →
                                    </Link>
                                </div>
                            ) : (
                                <div className="py-4 text-center text-sm text-muted-foreground">
                                    <Truck className="mx-auto mb-1 h-6 w-6 text-muted-foreground/40" />
                                    No recent driving activity
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
