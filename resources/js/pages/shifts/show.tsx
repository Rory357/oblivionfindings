import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Task = {
  id: number;
  label: string;
  is_completed: boolean;
};

type Note = {
  id: number;
  type: string;
  occurred_at?: string | null;
  subject?: string | null;
  body?: string | null;
  meta?: any;
  actor?: { id: number; name: string } | null;
};

type Props = {
  shift: {
    id: number;
    client_id: number;
    user_id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    location?: string | null;
    notes?: string | null;
    client: { id: number; first_name: string; last_name: string };
    staff: { id: number; name: string; email?: string };
    tasks: Task[];
  };
  handover: Note[];
  notes: Note[];
  can: {
    add_note: boolean;
  };
};

const templates = [
  { key: 'shift_note', label: 'Shift note', body: '' },
  { key: 'progress_note', label: 'Progress note', body: 'Goal/outcome:\n\nWhat happened:\n\nNext steps:' },
  { key: 'handover', label: 'Handover', body: 'Key points for next shift:\n-\n-\n\nRisks/alerts:\n-\n\nActions needed:\n-' },
];

export default function ShiftShow({ shift, handover, notes, can }: Props) {
  const { auth } = usePage().props as any;
  const canMarkTasks = auth?.can?.shifts?.update || auth?.can?.shifts?.tasksUpdateSelf || auth?.can?.shifts?.manageAny;

  const [tasks, setTasks] = useState<Task[]>(shift.tasks ?? []);
  const name = `${shift.client.first_name} ${shift.client.last_name}`.trim();

  const noteForm = useForm<{ type: string; subject: string; goal: string; body: string; visibility: string; pin: boolean; shift_id: number }>(
    {
      type: 'shift_note',
      subject: '',
      goal: '',
      body: '',
      visibility: 'internal',
      pin: false,
      shift_id: shift.id,
    },
  );

  const activeTemplate = useMemo(() => templates.find((t) => t.key === noteForm.data.type), [noteForm.data.type]);

  function getXsrfTokenFromCookie(): string | null {
    // Laravel sets an URL-encoded XSRF-TOKEN cookie. Using it avoids stale meta-tag CSRF issues in SPA navigations.
    const pair = document.cookie
      .split('; ')
      .find((row) => row.startsWith('XSRF-TOKEN='));
    if (!pair) return null;
    const value = pair.split('=')[1];
    if (!value) return null;
    try {
      return decodeURIComponent(value);
    } catch {
      return value;
    }
  }

  async function toggleTask(task: Task, next: boolean) {
    // optimistic
    setTasks((prev) => prev.map((t) => (t.id === task.id ? { ...t, is_completed: next } : t)));
    try {
      const res = await fetch(`/shifts/${shift.id}/tasks/${task.id}`, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          // Prefer cookie-based XSRF token (kept in sync by Laravel) but fall back to meta tag.
          ...(getXsrfTokenFromCookie()
            ? { 'X-XSRF-TOKEN': getXsrfTokenFromCookie() as string }
            : {
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content,
              }),
        },
        body: JSON.stringify({ is_completed: next }),
      });
      if (!res.ok) throw new Error('Request failed');
    } catch (e) {
      // revert
      setTasks((prev) => prev.map((t) => (t.id === task.id ? { ...t, is_completed: !next } : t)));
    }
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: 'Shifts', href: '/shifts' },
        { title: `${name} (${new Date(shift.starts_at).toLocaleDateString()})`, href: `/shifts/${shift.id}` },
      ]}
    >
      <Head title={`Shift — ${name}`} />

      <div className="space-y-4 p-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <div className="text-lg font-semibold">Shift</div>
            <div className="text-sm text-muted-foreground">
              {new Date(shift.starts_at).toLocaleString()} – {new Date(shift.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
              {shift.location ? <> • {shift.location}</> : null}
            </div>
            <div className="mt-1 text-sm">
              Client:{' '}
              <Link className="underline" href={`/clients/${shift.client.id}`}>
                {name}
              </Link>
              <span className="mx-2">•</span>
              Staff: <span className="font-medium">{shift.staff?.name ?? '—'}</span>
              <span className="mx-2">•</span>
              Status: <span className="font-medium">{shift.status}</span>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Link className="rounded-md border px-3 py-2 text-xs hover:bg-muted" href={`/timesheets/create?shift_id=${shift.id}`}>
              Timesheet
            </Link>
            {auth?.can?.shifts?.update ? (
              <Link className="rounded-md border px-3 py-2 text-xs hover:bg-muted" href={`/shifts/${shift.id}/edit`}>
                Edit
              </Link>
            ) : null}
          </div>
        </div>

        {handover.length ? (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Pinned handover</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              {handover.map((h) => (
                <div key={h.id} className="rounded-md border p-3">
                  <div className="flex items-center justify-between gap-2">
                    <div className="text-sm font-medium">{h.subject || 'Handover'}</div>
                    <div className="text-xs text-muted-foreground">{h.occurred_at ? new Date(h.occurred_at).toLocaleString() : ''}</div>
                  </div>
                  {h.body ? <div className="mt-2 whitespace-pre-wrap text-sm">{h.body}</div> : null}
                  <div className="mt-2 text-xs text-muted-foreground">{h.actor?.name ? `By ${h.actor.name}` : ''}</div>
                </div>
              ))}
            </CardContent>
          </Card>
        ) : null}

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Tasks</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {tasks.map((t) => (
              <div key={t.id} className="flex items-center gap-3 rounded-md border p-3">
                <Checkbox
                  checked={t.is_completed}
                  disabled={!canMarkTasks}
                  onCheckedChange={(v) => toggleTask(t, Boolean(v))}
                />
                <div className={`text-sm ${t.is_completed ? 'line-through text-muted-foreground' : ''}`}>{t.label}</div>
              </div>
            ))}
            {!tasks.length ? <div className="text-sm text-muted-foreground">No tasks added for this shift.</div> : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Shift notes</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {can.add_note ? (
              <div className="rounded-md border p-3">
                <div className="text-sm font-medium">Add note</div>
                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div>
                    <Label>Type</Label>
                    <Select
                      value={noteForm.data.type}
                      onValueChange={(v) => {
                        noteForm.setData('type', v);
                        const tpl = templates.find((t) => t.key === v);
                        if (tpl && noteForm.data.body.trim() === '') {
                          noteForm.setData('body', tpl.body);
                        }
                        // pin default for handover
                        noteForm.setData('pin', v === 'handover');
                      }}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {templates.map((t) => (
                          <SelectItem key={t.key} value={t.key}>
                            {t.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label>Subject (optional)</Label>
                    <Input value={noteForm.data.subject} onChange={(e) => noteForm.setData('subject', e.target.value)} />
                  </div>
                </div>

                {noteForm.data.type === 'progress_note' ? (
                  <div className="mt-3">
                    <Label>Goal/outcome (optional)</Label>
                    <Input value={noteForm.data.goal} onChange={(e) => noteForm.setData('goal', e.target.value)} />
                  </div>
                ) : null}

                <div className="mt-3">
                  <Label>Note</Label>
                  <Textarea rows={5} value={noteForm.data.body} onChange={(e) => noteForm.setData('body', e.target.value)} />
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-3">
                  <div className="flex items-center gap-2 text-xs">
                    <Checkbox checked={noteForm.data.visibility === 'portal'} onCheckedChange={(v) => noteForm.setData('visibility', v ? 'portal' : 'internal')} />
                    <span>Share in portal</span>
                  </div>
                  {noteForm.data.type === 'handover' ? (
                    <div className="flex items-center gap-2 text-xs">
                      <Checkbox checked={noteForm.data.pin} onCheckedChange={(v) => noteForm.setData('pin', Boolean(v))} />
                      <span>Pin as handover</span>
                    </div>
                  ) : null}

                  <Button
                    onClick={() =>
                      noteForm.post(`/clients/${shift.client.id}/notes`, {
                        preserveScroll: true,
                        onSuccess: () => noteForm.reset({ type: 'shift_note', subject: '', goal: '', body: '', visibility: 'internal', pin: false, shift_id: shift.id }),
                      })
                    }
                    disabled={noteForm.processing || !noteForm.data.body}
                  >
                    Add
                  </Button>
                </div>
                {activeTemplate?.body && noteForm.data.body.trim() === '' ? (
                  <div className="mt-2 text-xs text-muted-foreground">Tip: selecting a type will insert a quick template.</div>
                ) : null}
              </div>
            ) : null}

            {notes.map((n) => (
              <div key={n.id} className="rounded-md border p-3">
                <div className="flex items-center justify-between gap-2">
                  <div className="text-sm font-medium">{n.subject || n.type}</div>
                  <div className="text-xs text-muted-foreground">{n.occurred_at ? new Date(n.occurred_at).toLocaleString() : ''}</div>
                </div>
                {n.meta?.goal ? <div className="mt-1 text-xs text-muted-foreground">Goal: {n.meta.goal}</div> : null}
                {n.body ? <div className="mt-2 whitespace-pre-wrap text-sm">{n.body}</div> : null}
                <div className="mt-2 text-xs text-muted-foreground">{n.actor?.name ? `By ${n.actor.name}` : ''}</div>
              </div>
            ))}
            {!notes.length ? <div className="text-sm text-muted-foreground">No notes for this shift yet.</div> : null}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
