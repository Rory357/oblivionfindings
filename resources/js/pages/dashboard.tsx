import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { ShiftTimeline, type ShiftLite } from '@/components/dashboard/timeline';

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Dashboard', href: dashboard().url },
];

type ClientLite = { id: number; first_name: string; last_name: string; status?: string | null };
type TimesheetLite = { id: number; status: string; work_date: string; client?: ClientLite | null; created_at?: string };

type Props = {
  mode: 'staff' | 'manager' | 'client';
  client?: { id: number; first_name: string; last_name: string; status?: string | null } | null;
  assignedStaff?: { id: number; name: string; email?: string }[];
  assignedClients?: ClientLite[];
  todayShifts: ShiftLite[];
  upcomingShifts?: ShiftLite[];
  todayTimesheets?: TimesheetLite[];
  managerSummary?: {
    staffWorkingTodayCount: number;
    timesheetsPendingCount: number;
  } | null;
};

export default function Dashboard(props: Props) {
  const { labels } = usePage().props as any;

  const clientLabelPlural = labels?.['client.plural'] ?? 'Clients';
  const clientLabelSingular = labels?.['client.singular'] ?? 'Client';
  const staffLabelPlural = labels?.['staff.plural'] ?? 'Staff';
  const staffLabelSingular = labels?.['staff.singular'] ?? 'Staff';

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="Dashboard" />

      <div className="space-y-6 p-4">
        {props.mode === 'manager' && props.managerSummary ? (
          <div className="grid gap-4 md:grid-cols-3">
            <div className="rounded-xl border p-4">
              <div className="text-xs text-muted-foreground">Staff working today</div>
              <div className="mt-2 text-2xl font-semibold">{props.managerSummary.staffWorkingTodayCount}</div>
            </div>
            <div className="rounded-xl border p-4">
              <div className="text-xs text-muted-foreground">Timesheets pending approval</div>
              <div className="mt-2 text-2xl font-semibold">{props.managerSummary.timesheetsPendingCount}</div>
            </div>
            <div className="rounded-xl border p-4">
              <div className="text-xs text-muted-foreground">Quick actions</div>
              <div className="mt-3 flex flex-wrap gap-2">
                <Button asChild size="sm" variant="outline">
                  <Link href="/shifts">View shifts</Link>
                </Button>
                <Button asChild size="sm" variant="outline">
                  <Link href="/timesheets">View timesheets</Link>
                </Button>
                <Button asChild size="sm">
                  <Link href="/shifts/create">Create shift</Link>
                </Button>
              </div>
            </div>
          </div>
        ) : null}

        {props.mode === 'client' ? (
          <div className="grid gap-4 lg:grid-cols-3">
            <div className="rounded-xl border p-4 lg:col-span-1">
              <div className="text-sm font-semibold">
                {clientLabelSingular}: {props.client?.first_name} {props.client?.last_name}
              </div>
              <div className="mt-1 text-xs text-muted-foreground">Status: {props.client?.status ?? '—'}</div>

              <div className="mt-4">
                <div className="text-xs font-medium text-muted-foreground">Assigned {staffLabelPlural}</div>
                <div className="mt-2 space-y-2">
                  {props.assignedStaff?.length ? (
                    props.assignedStaff.map((s) => (
                      <div key={s.id} className="rounded-md border p-2 text-sm">
                        <div className="font-medium">{s.name}</div>
                        {s.email ? <div className="text-xs text-muted-foreground">{s.email}</div> : null}
                      </div>
                    ))
                  ) : (
                    <div className="text-sm text-muted-foreground">No assigned {staffLabelPlural.toLowerCase()}.</div>
                  )}
                </div>
              </div>
            </div>

            <div className="lg:col-span-2">
              <ShiftTimeline
                title="Schedule"
                shifts={(props.upcomingShifts ?? props.todayShifts) as ShiftLite[]}
                mode="client"
                emptyText="No appointments scheduled."
              />
            </div>
          </div>
        ) : (
          <div className="grid gap-4 lg:grid-cols-3">
            <div className="lg:col-span-2">
              <ShiftTimeline
                title="To-do timeline"
                shifts={(props.upcomingShifts ?? props.todayShifts) as ShiftLite[]}
                mode={props.mode === 'manager' ? 'manager' : 'staff'}
                emptyText="No shifts scheduled."
              />
            </div>

            <div className="space-y-4 lg:col-span-1">
              <div className="rounded-xl border p-4">
                <div className="text-sm font-semibold">Assigned {clientLabelPlural}</div>
                <div className="mt-3 space-y-2">
                  {props.assignedClients?.length ? (
                    props.assignedClients.map((c) => (
                      <Link
                        key={c.id}
                        href={`/clients/${c.id}`}
                        className="block rounded-md border p-2 text-sm hover:bg-muted/50"
                      >
                        <div className="font-medium">
                          {c.first_name} {c.last_name}
                        </div>
                        {c.status ? <div className="text-xs text-muted-foreground">Status: {c.status}</div> : null}
                      </Link>
                    ))
                  ) : (
                    <div className="text-sm text-muted-foreground">No assigned {clientLabelPlural.toLowerCase()}.</div>
                  )}
                </div>
              </div>

              <div className="rounded-xl border p-4">
                <div className="flex items-center justify-between">
                  <div className="text-sm font-semibold">Today’s timesheets</div>
                  <Button asChild size="sm" variant="outline">
                    <Link href="/timesheets">Open</Link>
                  </Button>
                </div>

                <div className="mt-3 space-y-2">
                  {props.todayTimesheets?.length ? (
                    props.todayTimesheets.map((t) => (
                      <div key={t.id} className="rounded-md border p-2 text-sm">
                        <div className="flex items-center justify-between gap-2">
                          <div className="min-w-0">
                            <div className="truncate font-medium">
                              {t.client ? `${t.client.first_name} ${t.client.last_name}` : clientLabelSingular}
                            </div>
                            <div className="text-xs text-muted-foreground">{t.status}</div>
                          </div>
                          <Button asChild size="sm" variant="outline">
                            <Link href={`/timesheets/${t.id}/edit`}>View</Link>
                          </Button>
                        </div>
                      </div>
                    ))
                  ) : (
                    <div className="text-sm text-muted-foreground">No timesheets yet.</div>
                  )}
                </div>

                <div className="mt-4 flex flex-wrap gap-2">
                  <Button asChild size="sm">
                    <Link href="/timesheets/create">New timesheet</Link>
                  </Button>
                  <Button asChild size="sm" variant="outline">
                    <Link href="/shifts">All shifts</Link>
                  </Button>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
