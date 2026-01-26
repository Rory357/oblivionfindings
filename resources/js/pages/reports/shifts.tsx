import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type ShiftRow = {
  id: number;
  status: string;
  starts_at: string | null;
  ends_at: string | null;
  client: string | null;
  staff: string | null;
  notes_count: number;
  tasks_total: number;
  tasks_done: number;
};

export default function ShiftReport() {
  const { shifts, filters } = usePage().props as any as {
    shifts: ShiftRow[];
    filters: { from: string; to: string };
  };

    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

  function fmt(dt?: string | null) {
    if (!dt) return '';
    try {
      return new Date(dt).toLocaleString();
    } catch {
      return dt;
    }
  }

  return (
    <AppLayout breadcrumbs={[{ title: 'Reports', href: '/reports' }, { title: 'Shifts', href: '/reports/shifts' }]}>
      <Head title="Shift report" />
      <div className="space-y-4 p-4">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Shift completeness</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-wrap items-end gap-3">
            <div>
              <div className="mb-1 text-xs text-muted-foreground">From</div>
              <Input
                type="date"
                value={from}
                onChange={(e) => setFrom(e.target.value)}
              />
            </div>
            <div>
              <div className="mb-1 text-xs text-muted-foreground">To</div>
              <Input
                type="date"
                value={to}
                onChange={(e) => setTo(e.target.value)}
              />
            </div>
            <Button
              onClick={() => {
                router.get('/reports/shifts', { from, to }, { preserveScroll: true });
              }}
            >
              Apply
            </Button>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Shifts</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {shifts.length === 0 ? (
              <div className="text-sm text-muted-foreground">No shifts in this range.</div>
            ) : (
              <div className="space-y-2">
                {shifts.map((s) => {
                  const tasksLabel = s.tasks_total > 0 ? `${s.tasks_done}/${s.tasks_total}` : '—';
                  return (
                    <div key={s.id} className="rounded-md border p-3">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="font-medium">
                          <a className="hover:underline" href={`/shifts/${s.id}`}>
                            Shift #{s.id}
                          </a>
                          <span className="text-muted-foreground"> · {s.client ?? 'Unknown client'} · {s.staff ?? 'Unassigned'}</span>
                        </div>
                        <div className="text-sm text-muted-foreground">{s.status}</div>
                      </div>
                      <div className="mt-1 text-sm text-muted-foreground">
                        {fmt(s.starts_at)} → {fmt(s.ends_at)}
                      </div>
                      <div className="mt-2 flex flex-wrap gap-4 text-sm">
                        <div>
                          <span className="text-muted-foreground">Notes:</span> {s.notes_count}
                        </div>
                        <div>
                          <span className="text-muted-foreground">Tasks:</span> {tasksLabel}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
