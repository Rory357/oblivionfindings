import * as React from 'react';
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type ClientLite = { id: number; first_name: string; last_name: string };
type StaffLite = { id: number; name: string; email?: string };

export type ShiftLite = {
  id: number;
  starts_at: string;
  ends_at: string;
  location?: string | null;
  status?: string | null;
  client?: ClientLite | null;
  staff?: StaffLite | null;
};

function fmtTime(iso: string) {
  const d = new Date(iso);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function fmtDate(iso: string) {
  const d = new Date(iso);
  return d.toLocaleDateString([], { weekday: 'short', day: '2-digit', month: 'short' });
}

function isSameDay(aIso: string, bIso: string) {
  const a = new Date(aIso);
  const b = new Date(bIso);
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

export function ShiftTimeline({
  title,
  shifts,
  mode,
  emptyText,
}: {
  title: string;
  shifts: ShiftLite[];
  mode: 'staff' | 'manager' | 'client';
  emptyText?: string;
}) {
  const [range, setRange] = React.useState<'today' | 'week'>('today');

  const todayIso = React.useMemo(() => new Date().toISOString(), []);

  const filtered = React.useMemo(() => {
    if (range === 'today') {
      return shifts.filter((s) => isSameDay(s.starts_at, todayIso));
    }
    return shifts;
  }, [range, shifts, todayIso]);

  const grouped = React.useMemo(() => {
    const map = new Map<string, ShiftLite[]>();
    for (const s of filtered) {
      const key = fmtDate(s.starts_at);
      map.set(key, [...(map.get(key) ?? []), s]);
    }
    // ensure time ordering inside groups
    for (const [k, arr] of map) {
      arr.sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());
      map.set(k, arr);
    }
    return Array.from(map.entries());
  }, [filtered]);

  return (
    <div className="rounded-xl border p-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="text-sm font-semibold">{title}</div>
          <div className="text-xs text-muted-foreground">
            {range === 'today' ? 'Today' : 'Next 7 days'}
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button
            type="button"
            size="sm"
            variant={range === 'today' ? 'default' : 'outline'}
            onClick={() => setRange('today')}
          >
            Today
          </Button>
          <Button
            type="button"
            size="sm"
            variant={range === 'week' ? 'default' : 'outline'}
            onClick={() => setRange('week')}
          >
            Next 7 days
          </Button>
        </div>
      </div>

      <div className="mt-4 max-h-[60vh] space-y-4 overflow-y-auto pr-1">
        {grouped.length === 0 ? (
          <div className="text-sm text-muted-foreground">{emptyText ?? 'No shifts scheduled.'}</div>
        ) : (
          grouped.map(([dateLabel, items]) => (
            <div key={dateLabel} className="space-y-2">
              <div className="text-xs font-medium text-muted-foreground">{dateLabel}</div>

              <div className="space-y-2">
                {items.map((s) => (
                  <div
                    key={s.id}
                    className="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between"
                  >
                    <div className="flex min-w-0 items-start gap-3">
                      <div className="mt-0.5 w-24 shrink-0 text-xs font-medium">
                        {fmtTime(s.starts_at)}–{fmtTime(s.ends_at)}
                      </div>

                      <div className="min-w-0">
                        <div className="truncate text-sm font-medium">
                          {mode === 'client'
                            ? s.staff?.name ?? 'Unassigned'
                            : s.client
                              ? `${s.client.first_name} ${s.client.last_name}`
                              : 'No client'}
                        </div>

                        <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                          {s.location ? <span>{s.location}</span> : null}
                          {s.status ? <span className="rounded-md border px-2 py-0.5">{s.status}</span> : null}
                        </div>
                      </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                      <Button asChild size="sm" variant="outline">
                        <Link href={`/shifts/${s.id}/edit`}>View</Link>
                      </Button>

                      {(mode === 'staff' || mode === 'manager') && (
                        <Button asChild size="sm">
                          <Link href={`/timesheets/create?shift_id=${s.id}`}>Timesheet</Link>
                        </Button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
}
