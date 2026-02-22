import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
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
    service_context_id?: number | null;
    user_id: number;
    starts_at: string;
    ends_at: string;
    actual_starts_at?: string | null;
    actual_ends_at?: string | null;
    status: string;
    location?: string | null;
    notes?: string | null;
    client: { id: number; first_name: string; last_name: string };
    staff: { id: number; name: string; email?: string };
    service_context?: { id: number; name: string; type: string; is_active: boolean } | null;
    tasks: Task[];
  };
  handover: Note[];
  notes: Note[];
  incidents: any[];
  incidentTemplates: any[];
  can: {
    add_note: boolean;
    create_incident: boolean;
  };
};

const templates = [
  { key: 'shift_note', label: 'Shift note', body: '' },
  { key: 'progress_note', label: 'Progress note', body: 'Goal/outcome:\n\nWhat happened:\n\nNext steps:' },
  { key: 'handover', label: 'Handover', body: 'Key points for next shift:\n-\n-\n\nRisks/alerts:\n-\n\nActions needed:\n-' },
];

export default function ShiftShow({ shift, handover, notes, incidents, incidentTemplates, can }: Props) {
  const { auth } = usePage().props as any;
  const canMarkTasks = auth?.can?.shifts?.update || auth?.can?.shifts?.tasksUpdateSelf || auth?.can?.shifts?.manageAny;
  const canActShift = auth?.can?.shifts?.update || auth?.can?.shifts?.manageAny;
  const canCreateTimesheet = auth?.can?.timesheets?.create || auth?.can?.timesheets?.manageAny;
  const canStartShift = canActShift && shift.status === 'scheduled';
  const canCompleteShift = canActShift && (shift.status === 'scheduled' || shift.status === 'in_progress');
  const [tasks, setTasks] = useState<Task[]>(shift.tasks ?? []);
  const [completeOpen, setCompleteOpen] = useState(() => {
    try {
      return new URLSearchParams(window.location.search).get('complete') === '1';
    } catch {
      return false;
    }
  });
  const [incidentOpen, setIncidentOpen] = useState(false);
  const incidentForm = useForm({
    template_id: '',
    type: 'injury',
    severity: 'low',
    occurred_at: '',
    description: '',
    requires_followup: false,
    immediate_action_taken: '',
    witnesses: '',
  });

  const applyIncidentTemplate = (id: string) => {
    incidentForm.setData('template_id', id);
    const t = (incidentTemplates || []).find((x: any) => String(x.id) === String(id));
    if (!t) return;
    if (t.type) incidentForm.setData('type', t.type);
    if (t.severity) incidentForm.setData('severity', t.severity);
    if (t.default_description && !incidentForm.data.description) incidentForm.setData('description', t.default_description);
  };

  const name = `${shift.client.first_name} ${shift.client.last_name}`.trim();

  const incompleteCount = useMemo(() => tasks.filter((t) => !t.is_completed).length, [tasks]);
  const hasProgressOrShiftNotes = useMemo(
    () => (notes ?? []).some((n) => n.type === 'progress_note' || n.type === 'shift_note'),
    [notes],
  );

  const completeForm = useForm<{
    final_note_subject: string;
    final_note_body: string;
    allow_incomplete_tasks: boolean;
    incomplete_tasks_reason: string;
    create_timesheet: boolean;
  }>({
    final_note_subject: 'Shift summary',
    final_note_body: '',
    allow_incomplete_tasks: false,
    incomplete_tasks_reason: '',
    create_timesheet: true,
  });

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
              {shift.actual_starts_at ? (
                <>
                  <span className="mx-2">•</span>
                  Actual: <span className="font-medium">{new Date(shift.actual_starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                  {shift.actual_ends_at ? (
                    <>–<span className="font-medium">{new Date(shift.actual_ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span></>
                  ) : null}
                </>
              ) : null}
              {shift.service_context ? (
                <>
                  <span className="mx-2">•</span>
                  Service context: <span className="font-medium">{shift.service_context.name}</span>
                </>
              ) : null}
            </div>
          </div>

          <div className="flex items-center gap-2">
            {canStartShift ? (
              <Button
                size="sm"
                onClick={() => router.patch(`/shifts/${shift.id}/start`, {}, { preserveScroll: true })}
              >
                Start
              </Button>
            ) : null}

            {canCompleteShift ? (
              <Button size="sm" variant="outline" onClick={() => setCompleteOpen(true)}>
                Complete
              </Button>
            ) : null}

            {can.create_incident ? (
              <Button size="sm" variant="outline" onClick={() => setIncidentOpen(true)}>
                Report incident
              </Button>
            ) : null}

            {(auth?.can?.timesheets?.create || auth?.can?.timesheets?.manageAny) ? (
              <Link
                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                href={`/timesheets/create?shift_id=${shift.id}`}
              >
                Timesheet
              </Link>
            ) : null}

            {auth?.can?.shifts?.update ? (
              <Link className="rounded-md border px-3 py-2 text-xs hover:bg-muted" href={`/shifts/${shift.id}/edit`}>
                Edit
              </Link>
            ) : null}
          </div>
        </div>

        <Dialog open={completeOpen} onOpenChange={setCompleteOpen}>
          <DialogContent className="sm:max-w-2xl">
            <DialogHeader>
              <DialogTitle>Complete shift</DialogTitle>
            </DialogHeader>

            <div className="space-y-4">
              <div className="rounded-lg border p-3">
                <div className="text-sm font-medium">Checklist</div>
                <div className="mt-2 text-sm text-muted-foreground">
                  {incompleteCount === 0 ? (
                    <>All shift tasks are completed.</>
                  ) : (
                    <>
                      {incompleteCount} task{incompleteCount === 1 ? '' : 's'} still incomplete.
                    </>
                  )}
                </div>

                {incompleteCount > 0 ? (
                  <div className="mt-3 space-y-3">
                    <div className="flex items-center gap-2">
                      <Checkbox
                        checked={completeForm.data.allow_incomplete_tasks}
                        onCheckedChange={(v) => completeForm.setData('allow_incomplete_tasks', Boolean(v))}
                      />
                      <div className="text-sm">Allow completion with incomplete tasks</div>
                    </div>

                    {completeForm.data.allow_incomplete_tasks ? (
                      <div>
                        <Label>Reason (required)</Label>
                        <Textarea
                          className="mt-1"
                          value={completeForm.data.incomplete_tasks_reason}
                          onChange={(e) => completeForm.setData('incomplete_tasks_reason', e.target.value)}
                          placeholder="Why are tasks incomplete?"
                        />
                        {completeForm.errors.incomplete_tasks_reason ? (
                          <div className="mt-1 text-xs text-red-600">{completeForm.errors.incomplete_tasks_reason}</div>
                        ) : null}
                      </div>
                    ) : null}

                    {completeForm.errors.allow_incomplete_tasks ? (
                      <div className="text-xs text-red-600">{completeForm.errors.allow_incomplete_tasks}</div>
                    ) : null}
                  </div>
                ) : null}
              </div>

              <div className="rounded-lg border p-3">
                <div className="text-sm font-medium">Shift summary note</div>
                <div className="mt-2 grid gap-3 sm:grid-cols-2">
                  <div>
                    <Label>Subject</Label>
                    <Input
                      className="mt-1"
                      value={completeForm.data.final_note_subject}
                      onChange={(e) => completeForm.setData('final_note_subject', e.target.value)}
                    />
                    {completeForm.errors.final_note_subject ? (
                      <div className="mt-1 text-xs text-red-600">{completeForm.errors.final_note_subject}</div>
                    ) : null}
                  </div>
                </div>

                <div className="mt-3">
                  <Label>Note {hasProgressOrShiftNotes ? '(optional if notes already added)' : '(required)'}</Label>
                  {hasProgressOrShiftNotes ? (
                    <div className="mt-1 text-xs text-muted-foreground">
                      You already have notes recorded for this shift. You can leave this blank to auto-generate a short completion summary.
                    </div>
                  ) : (
                    <div className="mt-1 text-xs text-muted-foreground">
                      Provide a short summary to complete the shift, or add a progress note first.
                    </div>
                  )}
                  <Textarea
                    className="mt-1"
                    value={completeForm.data.final_note_body}
                    onChange={(e) => completeForm.setData('final_note_body', e.target.value)}
                    placeholder="Summarise what happened during the shift, outcomes, any concerns, and handover items."
                  />
                  {completeForm.errors.final_note_body ? (
                    <div className="mt-1 text-xs text-red-600">{completeForm.errors.final_note_body}</div>
                  ) : null}
                </div>
              </div>

              {canCreateTimesheet ? (
                <div className="flex items-center justify-between rounded-lg border p-3">
                  <div>
                    <div className="text-sm font-medium">Create timesheet</div>
                    <div className="text-xs text-muted-foreground">
                      Creates a draft timesheet for this shift automatically.
                    </div>
                  </div>
                  <Checkbox
                    checked={completeForm.data.create_timesheet}
                    onCheckedChange={(v) => completeForm.setData('create_timesheet', Boolean(v))}
                  />
                </div>
              ) : null}
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setCompleteOpen(false)}>
                Cancel
              </Button>
              <Button
                type="button"
                disabled={completeForm.processing}
                onClick={() =>
                  completeForm.patch(`/shifts/${shift.id}/complete`, {
                    preserveScroll: true,
                    onSuccess: () => {
                      setCompleteOpen(false);
                      // clean query param if present
                      try {
                        const url = new URL(window.location.href);
                        url.searchParams.delete('complete');
                        window.history.replaceState({}, '', url.toString());
                      } catch {}
                    },
                  })
                }
              >
                Complete shift
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        <Dialog open={incidentOpen} onOpenChange={setIncidentOpen}>
          <DialogContent className="sm:max-w-2xl">
            <DialogHeader>
              <DialogTitle>Report incident</DialogTitle>
            </DialogHeader>

            <div className="space-y-3">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="space-y-1">
                  <Label>Template (optional)</Label>
                  <Select
                    value={incidentForm.data.template_id || '__none__'}
                    onValueChange={(v) => applyIncidentTemplate(v === '__none__' ? '' : v)}
                  >
                    <SelectTrigger><SelectValue placeholder="Pick a template" /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="__none__">None</SelectItem>
                      {(incidentTemplates || []).map((t: any) => (
                        <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-1">
                  <Label>Type</Label>
                  <Input value={incidentForm.data.type} onChange={(e) => incidentForm.setData('type', e.target.value)} />
                </div>

                <div className="space-y-1">
                  <Label>Severity</Label>
                  <Select value={incidentForm.data.severity} onValueChange={(v) => incidentForm.setData('severity', v)}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {['low','medium','high'].map((s) => (
                        <SelectItem key={s} value={s}>{s}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                  <Label>Occurred at</Label>
                  <Input type="datetime-local" value={incidentForm.data.occurred_at} onChange={(e) => incidentForm.setData('occurred_at', e.target.value)} />
                </div>

                <div className="flex items-center gap-2 pt-6">
                  <Checkbox checked={!!incidentForm.data.requires_followup} onCheckedChange={(v) => incidentForm.setData('requires_followup', !!v)} />
                  <Label>Requires follow-up</Label>
                </div>
              </div>

              <div className="space-y-1">
                <Label>Description</Label>
                <Textarea value={incidentForm.data.description} onChange={(e) => incidentForm.setData('description', e.target.value)} />
              </div>

              <div className="space-y-1">
                <Label>Immediate action taken</Label>
                <Textarea value={incidentForm.data.immediate_action_taken} onChange={(e) => incidentForm.setData('immediate_action_taken', e.target.value)} />
              </div>

              <div className="space-y-1">
                <Label>Witnesses</Label>
                <Textarea value={incidentForm.data.witnesses} onChange={(e) => incidentForm.setData('witnesses', e.target.value)} />
              </div>
            </div>

            <DialogFooter>
              <Button
                disabled={incidentForm.processing}
                onClick={() =>
                  incidentForm.post(`/shifts/${shift.id}/incidents`, {
                    onSuccess: () => {
                      incidentForm.reset();
                      setIncidentOpen(false);
                    },
                  })
                }
              >
                Submit (shift-linked)
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

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
                        onSuccess: () => {
                          noteForm.reset();
                          noteForm.setData({
                            type: 'shift_note',
                            subject: '',
                            goal: '',
                            body: '',
                            visibility: 'internal',
                            pin: false,
                            shift_id: shift.id,
                          });
                        },
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
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Shift incidents</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {(incidents || []).map((i: any) => (
              <div key={i.id} className="flex items-center justify-between rounded-md border p-3">
                <div>
                  <div className="text-sm font-medium">{i.type} • {i.severity}</div>
                  <div className="mt-1 text-xs text-muted-foreground">{i.status} • {i.occurred_at}</div>
                </div>
                <Link href={`/incidents/${i.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">Open</Link>
              </div>
            ))}
            {!(incidents || []).length && <div className="text-sm text-muted-foreground">No incidents for this shift.</div>}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
