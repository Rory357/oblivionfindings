import { Button } from '@/components/ui/button';
import { Tabs } from '@/components/ui/tabs';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

import {
    ActivityTimeline,
    type ActivityEventLite,
} from '@/components/dashboard/activity-timeline';
import { DashboardAnalytics } from '@/components/dashboard/analytics';
import { ShiftTimeline, type ShiftLite } from '@/components/dashboard/timeline';
import { MyDayList, type MyDayItem } from '@/components/workstream/my-day-list';

function SmallKpi({
    label,
    value,
    hint,
}: {
    label: string;
    value: string | number;
    hint?: string;
}) {
    return (
        <div className="rounded-xl border p-4">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="mt-2 text-2xl font-semibold">{value}</div>
            {hint ? (
                <div className="mt-1 text-xs text-muted-foreground">{hint}</div>
            ) : null}
        </div>
    );
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
];

type ClientLite = {
    id: number;
    first_name: string;
    last_name: string;
    status?: string | null;
};

type TimesheetLite = {
    id: number;
    status: string;
    work_date: string;
    client?: ClientLite | null;
    created_at?: string;
};

type Props = {
    mode: 'staff' | 'manager' | 'client';
    filters?: {
        range?: 'today' | 'week';
        status?: string;
        client_id?: number | null;
    };
    client?: {
        id: number;
        first_name: string;
        last_name: string;
        status?: string | null;
    } | null;
    assignedStaff?: { id: number; name: string; email?: string }[];
    assignedClients?: ClientLite[];
    myDayItems?: MyDayItem[];
    todayShifts: ShiftLite[];
    upcomingShifts?: ShiftLite[];
    upcomingEvents?: ActivityEventLite[];
    todayTimesheets?: TimesheetLite[];
    managerSummary?: {
        staffWorkingTodayCount: number;
        timesheetsPendingCount: number;
    } | null;
    incidentKpis?: {
        incidentsLast30: number;
        incidentsHighLast30: number;
        followupsOpen: number;
        followupsOverdue: number;
        reviewedLast30: number;
        unreviewedLast30: number;
    } | null;
    analytics?: {
        shiftSeries?: Array<{
            date: string;
            count: number;
            hours: number;
            status?: Record<string, number>;
        }>;
        shiftSeries30?: Array<{
            date: string;
            count: number;
            hours: number;
            status?: Record<string, number>;
        }>;
        incidentSeries?: Array<{ date: string; count: number }>;
        incidentSeries30?: Array<{ date: string; count: number }>;
        incidentBySeverity30?: Array<{ severity: string; count: number }>;
        timesheetByStatus?: Array<{ status: string; count: number }>;
        timesheetSeries30?: Array<{
            date: string;
            count: number;
            hours: number;
        }>;
    } | null;
};

function fullName(c: ClientLite) {
    return `${c.first_name} ${c.last_name}`;
}

export default function Dashboard(props: Props) {
    const { labels } = usePage().props as any;

    const shiftSeries = props.analytics?.shiftSeries ?? [];
    const shiftSeries30 = props.analytics?.shiftSeries30 ?? [];
    const incidentSeries30 = props.analytics?.incidentSeries30 ?? [];
    const incidentBySeverity30 = props.analytics?.incidentBySeverity30 ?? [];
    const timesheetByStatus = props.analytics?.timesheetByStatus ?? [];
    const timesheetSeries30 = props.analytics?.timesheetSeries30 ?? [];

    const clientLabelPlural = labels?.['client.plural'] ?? 'Clients';
    const clientLabelSingular = labels?.['client.singular'] ?? 'Client';
    const staffLabelPlural = labels?.['staff.plural'] ?? 'Staff';

    const myDayItems = props.myDayItems ?? [];

    const shiftsForWorkTab = [
        ...(props.todayShifts ?? []),
        ...(props.upcomingShifts ?? []),
    ];

    const filters = props.filters ?? { range: 'week', status: 'all', client_id: null };

    function updateFilters(next: Partial<typeof filters>) {
        router.get(
            '/dashboard',
            {
                range: next.range ?? filters.range,
                status: next.status ?? filters.status,
                client_id: Object.prototype.hasOwnProperty.call(next, 'client_id')
                    ? (next as any).client_id
                    : filters.client_id,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    const workTab = (
        <div className="space-y-4">
            {props.mode !== 'client' ? (
                <div className="flex flex-wrap items-end gap-3 rounded-xl border p-4">
                    <div>
                        <div className="text-xs text-muted-foreground">Range</div>
                        <Select
                            value={filters.range ?? 'week'}
                            onValueChange={(v) => updateFilters({ range: v as any })}
                        >
                            <SelectTrigger className="mt-1 w-[160px]">
                                <SelectValue placeholder="Range" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="today">Today</SelectItem>
                                <SelectItem value="week">Next 7 days</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <div className="text-xs text-muted-foreground">Status</div>
                        <Select
                            value={filters.status ?? 'all'}
                            onValueChange={(v) => updateFilters({ status: v })}
                        >
                            <SelectTrigger className="mt-1 w-[180px]">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="scheduled">Scheduled</SelectItem>
                                <SelectItem value="in_progress">In progress</SelectItem>
                                <SelectItem value="completed">Completed</SelectItem>
                                <SelectItem value="cancelled">Cancelled</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <div className="text-xs text-muted-foreground">Client</div>
                        <Select
                            value={filters.client_id ? String(filters.client_id) : 'all'}
                            onValueChange={(v) =>
                                updateFilters({ client_id: v === 'all' ? null : Number(v) })
                            }
                        >
                            <SelectTrigger className="mt-1 w-[260px]">
                                <SelectValue placeholder="Client" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All clients</SelectItem>
                                {(props.assignedClients ?? []).map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {fullName(c)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="ml-auto text-xs text-muted-foreground">
                        Showing {shiftsForWorkTab.length} shift
                        {shiftsForWorkTab.length === 1 ? '' : 's'}
                    </div>
                </div>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <ShiftTimeline
                        title="To do timeline"
                        shifts={shiftsForWorkTab}
                        mode={props.mode}
                        emptyText="No shifts scheduled."
                    />
                </div>

                <div className="lg:col-span-1 space-y-4">
                    {props.mode !== 'client' ? (
                        <MyDayList
                            title="My day"
                            items={props.myDayItems ?? []}
                            emptyLabel="No tasks or follow-ups due."
                        />
                    ) : null}

                    <ActivityTimeline
                        title="Activity"
                        events={props.upcomingEvents ?? []}
                        emptyText="No upcoming activity."
                    />
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="rounded-xl border p-4 lg:col-span-2">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <div className="text-sm font-semibold">
                                Assigned {clientLabelPlural}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                Quick access to your{' '}
                                {clientLabelPlural.toLowerCase()}.
                            </div>
                        </div>
                        <Button asChild size="sm" variant="outline">
                            <Link href="/clients">View all</Link>
                        </Button>
                    </div>

                    <div className="mt-4 grid gap-2 md:grid-cols-2">
                        {props.assignedClients?.length ? (
                            props.assignedClients.map((c) => (
                                <Link
                                    key={c.id}
                                    href={`/clients/${c.id}`}
                                    className="rounded-lg border p-3 transition hover:bg-muted/30"
                                >
                                    <div className="text-sm font-medium">
                                        {fullName(c)}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        Status: {c.status ?? '—'}
                                    </div>
                                </Link>
                            ))
                        ) : (
                            <div className="text-sm text-muted-foreground">
                                No assigned {clientLabelPlural.toLowerCase()}.
                            </div>
                        )}
                    </div>
                </div>

                <div className="rounded-xl border p-4 lg:col-span-1">
                    <div className="text-sm font-semibold">Quick actions</div>
                    <div className="mt-1 text-xs text-muted-foreground">
                        Common actions for today.
                    </div>

                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button asChild size="sm" variant="outline">
                            <Link href="/shifts">Shifts</Link>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <Link href="/timesheets">Timesheets</Link>
                        </Button>
                        <Button asChild size="sm">
                            <Link href="/shifts/create">Create shift</Link>
                        </Button>
                    </div>

                    {props.todayTimesheets?.length ? (
                        <div className="mt-6">
                            <div className="text-xs font-medium text-muted-foreground">
                                Today’s timesheets
                            </div>
                            <div className="mt-2 space-y-2">
                                {props.todayTimesheets.slice(0, 5).map((t) => (
                                    <Link
                                        key={t.id}
                                        href={`/timesheets/${t.id}/edit`}
                                        className="block rounded-lg border p-3 transition hover:bg-muted/30"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <div className="truncate text-sm font-medium">
                                                {t.client
                                                    ? fullName(t.client)
                                                    : clientLabelSingular}
                                            </div>
                                            <span className="shrink-0 rounded-md border px-2 py-0.5 text-xs">
                                                {t.status}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {t.work_date}
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );

    const analyticsTab = (
        <DashboardAnalytics
            shiftSeries7={shiftSeries as any}
            shiftSeries30={shiftSeries30 as any}
            timesheetByStatus={timesheetByStatus}
            timesheetSeries30={timesheetSeries30 as any}
            incidentSeries30={incidentSeries30 as any}
            incidentBySeverity30={incidentBySeverity30 as any}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="space-y-6">
                {props.mode === 'manager' && props.managerSummary ? (
                    <div className="grid gap-4 md:grid-cols-3">
                        <SmallKpi
                            label="Staff working today"
                            value={props.managerSummary.staffWorkingTodayCount}
                            hint={`${props.managerSummary.staffWorkingTodayCount} scheduled`}
                        />
                        <SmallKpi
                            label="Timesheets pending approval"
                            value={props.managerSummary.timesheetsPendingCount}
                            hint="Awaiting review"
                        />
                        <div className="rounded-xl border p-4">
                            <div className="text-xs text-muted-foreground">
                                Quick actions
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button asChild size="sm" variant="outline">
                                    <Link href="/shifts">View shifts</Link>
                                </Button>
                                <Button asChild size="sm" variant="outline">
                                    <Link href="/timesheets">
                                        View timesheets
                                    </Link>
                                </Button>
                                <Button asChild size="sm">
                                    <Link href="/shifts/create">
                                        Create shift
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </div>
                ) : null}

                {props.mode === 'manager' && props.incidentKpis ? (
                    <div className="grid gap-4 md:grid-cols-5">
                        <SmallKpi
                            label="Incidents (last 30 days)"
                            value={props.incidentKpis.incidentsLast30}
                            hint="All severities"
                        />
                        <SmallKpi
                            label="High severity (last 30 days)"
                            value={props.incidentKpis.incidentsHighLast30}
                            hint="Requires attention"
                        />
                        <SmallKpi
                            label="Open follow-ups"
                            value={props.incidentKpis.followupsOpen}
                            hint="Not completed"
                        />
                        <SmallKpi
                            label="Overdue follow-ups"
                            value={props.incidentKpis.followupsOverdue}
                            hint="Past due"
                        />
                        <div className="rounded-xl border p-4">
                            <div className="text-xs text-muted-foreground">
                                Incident review
                            </div>
                            <div className="mt-2 text-2xl font-semibold">
                                {props.incidentKpis.reviewedLast30}/
                                {props.incidentKpis.reviewedLast30 +
                                    props.incidentKpis.unreviewedLast30}
                            </div>
                            <div className="mt-1 text-xs text-muted-foreground">
                                Reviewed vs unreviewed (30 days)
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button asChild size="sm" variant="outline">
                                    <Link href="/incidents">View incidents</Link>
                                </Button>
                                <Button asChild size="sm" variant="outline">
                                    <Link href="/reports/incidents">Reports</Link>
                                </Button>
                            </div>
                        </div>
                    </div>
                ) : null}

                {props.mode !== 'client' ? (
                    <Tabs
                        tabs={[
                            { key: 'work', label: 'Work', content: workTab },
                            {
                                key: 'analytics',
                                label: 'Analytics',
                                content: analyticsTab,
                            },
                        ]}
                    />
                ) : null}

                {props.mode === 'client' ? (
                    <div className="grid gap-4 lg:grid-cols-3">
                        <div className="rounded-xl border p-4 lg:col-span-1">
                            <div className="text-sm font-semibold">
                                {clientLabelSingular}:{' '}
                                {props.client?.first_name}{' '}
                                {props.client?.last_name}
                            </div>
                            <div className="mt-1 text-xs text-muted-foreground">
                                Status: {props.client?.status ?? '—'}
                            </div>

                            <div className="mt-4">
                                <div className="text-xs font-medium text-muted-foreground">
                                    Assigned {staffLabelPlural}
                                </div>
                                <div className="mt-2 space-y-2">
                                    {props.assignedStaff?.length ? (
                                        props.assignedStaff.map((s) => (
                                            <div
                                                key={s.id}
                                                className="rounded-md border p-2 text-sm"
                                            >
                                                <div className="font-medium">
                                                    {s.name}
                                                </div>
                                                {s.email ? (
                                                    <div className="text-xs text-muted-foreground">
                                                        {s.email}
                                                    </div>
                                                ) : null}
                                            </div>
                                        ))
                                    ) : (
                                        <div className="text-sm text-muted-foreground">
                                            No assigned{' '}
                                            {staffLabelPlural.toLowerCase()}.
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        <div className="lg:col-span-2">
                            <ShiftTimeline
                                title="Upcoming shifts"
                                shifts={shiftsForWorkTab}
                                mode="client"
                                emptyText="No shifts scheduled."
                            />
                        </div>
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
