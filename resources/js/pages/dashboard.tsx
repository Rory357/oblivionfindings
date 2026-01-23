import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { ShiftTimeline, type ShiftLite } from '@/components/dashboard/timeline';
import { ActivityTimeline, type ActivityEventLite } from '@/components/dashboard/activity-timeline';

function formatShortDate(iso: string) {
  const d = new Date(iso + 'T00:00:00');
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function clamp(n: number, min: number, max: number) {
  return Math.min(max, Math.max(min, n));
}

function SimpleBarChart({
  data,
  height = 120,
}: {
  data: Array<{ label: string; value: number }>;
  height?: number;
}) {
  const max = Math.max(1, ...data.map((d) => d.value));
  const barGap = 6;
  const barWidth = 18;
  const width = data.length * (barWidth + barGap);

  return (
    <div className="w-full overflow-x-auto">
      <svg width={width} height={height} className="text-foreground">
        {data.map((d, i) => {
          const h = clamp((d.value / max) * (height - 30), 2, height - 30);
          const x = i * (barWidth + barGap);
          const y = height - 20 - h;
          return (
            <g key={d.label}>
              <rect x={x} y={y} width={barWidth} height={h} rx={4} opacity={0.25} />
              <rect x={x} y={y} width={barWidth} height={h} rx={4} opacity={0.55} />
              <text x={x + barWidth / 2} y={height - 6} fontSize={10} textAnchor="middle" opacity={0.7}>
                {d.label}
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

function SmallKpi({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
  return (
    <div className="rounded-xl border p-4">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="mt-2 text-2xl font-semibold">{value}</div>
      {hint ? <div className="mt-1 text-xs text-muted-foreground">{hint}</div> : null}
    </div>
  );
}

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
  upcomingEvents?: ActivityEventLite[];
  todayTimesheets?: TimesheetLite[];
  managerSummary?: {
    staffWorkingTodayCount: number;
    timesheetsPendingCount: number;
  } | null;
  analytics?: {
    shiftSeries?: Array<{ date: string; count: number; hours: number }>;
    incidentSeries?: Array<{ date: string; count: number }>;
    timesheetByStatus?: Array<{ status: string; count: number }>;
  } | null;
};

export default function Dashboard(props: Props) {
  const { labels } = usePage().props as any;

  const shiftSeries = props.analytics?.shiftSeries ?? [];
  const incidentSeries = props.analytics?.incidentSeries ?? [];
  const timesheetByStatus = props.analytics?.timesheetByStatus ?? [];

  const shiftHoursData = shiftSeries.map((d) => ({
    label: formatShortDate(d.date),
    value: Number(d.hours ?? 0),
  }));

  const shiftCountData = shiftSeries.map((d) => ({
    label: formatShortDate(d.date),
    value: Number(d.count ?? 0),
  }));

  const incidentData = incidentSeries.map((d) => ({
    label: formatShortDate(d.date),
    value: Number(d.count ?? 0),
  }));

  const timesheetData = timesheetByStatus.map((d) => ({
    label: String(d.status).slice(0, 3).toUpperCase(),
    value: Number(d.count ?? 0),
  }));

  const clientLabelPlural = labels?.['client.plural'] ?? 'Clients';
  const clientLabelSingular = labels?.['client.singular'] ?? 'Client';
  const staffLabelPlural = labels?.['staff.plural'] ?? 'Staff';
  const staffLabelSingular = labels?.['staff.singular'] ?? 'Staff';

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

        {props.mode !== 'client' ? (
          <div className="grid gap-4 lg:grid-cols-3">
            <div className="rounded-xl border p-4 lg:col-span-1">
              <div className="text-sm font-semibold">Shifts next 7 days</div>
              <div className="mt-1 text-xs text-muted-foreground">Hours scheduled</div>
              <div className="mt-4">
                <SimpleBarChart data={shiftHoursData} />
              </div>
            </div>

            <div className="rounded-xl border p-4 lg:col-span-1">
              <div className="text-sm font-semibold">Timesheets</div>
              <div className="mt-1 text-xs text-muted-foreground">Dashboard breakdown</div>
              <div className="mt-4">
                <SimpleBarChart data={timesheetData} />
              </div>
            </div>

            <div className="rounded-xl border p-4 lg:col-span-1">
              <div className="text-sm font-semibold">Incidents</div>
              <div className="mt-1 text-xs text-muted-foreground">Last 14 days</div>
              <div className="mt-4">
                {incidentData.length ? (
                  <SimpleBarChart data={incidentData} />
                ) : (
                  <div className="text-sm text-muted-foreground">No incident data available.</div>
                )}
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
              {props.mode === 'staff' ? (
                <ActivityTimeline
                  title="To-do timeline"
                  events={(props.upcomingEvents ?? []) as ActivityEventLite[]}
                  emptyText="No activity scheduled."
                />
              ) : (
                <ShiftTimeline
                  title="To-do timeline"
                  shifts={(props.upcomingShifts ?? props.todayShifts) as ShiftLite[]}
                  mode="manager"
                  emptyText="No shifts scheduled."
                />
              )}
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
