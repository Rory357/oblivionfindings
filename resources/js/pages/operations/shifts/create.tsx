import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, router, useForm, usePage } from '@inertiajs/react';

type Client = { id: number; first_name: string; last_name: string; service_context_id?: number | null };
type Staff = { id: number; name: string; email: string };

type ServiceContext = { id: number; name: string; type: string; is_active: boolean };

type Props = { clients: Client[]; staff: Staff[]; serviceContexts: ServiceContext[]; defaultServiceContextId?: number | null; defaultClientId?: number | string | null };

export default function ShiftCreate({ clients, staff, serviceContexts, defaultServiceContextId = null, defaultClientId = null }: Props) {
    const { labels } = usePage().props as any;
    const shiftLabel = labels?.['shift.singular'] ?? 'Shift';

    const initialClient = (() => {
        if (defaultClientId) {
            const found = clients.find((c) => String(c.id) === String(defaultClientId));
            if (found) return found;
        }
        return clients?.[0] ?? null;
    })();

    const form = useForm({
        client_id: initialClient?.id ?? '',
        service_context_id: (initialClient?.service_context_id ?? defaultServiceContextId ?? '') as any,
        // Allow creating an open/unassigned shift for roster planning
        user_id: '',
        starts_at: '',
        ends_at: '',
        location: '',
        notes: '',
        status: 'scheduled',
        tasks: [] as Array<{ label: string }>,
        repeat_weekly: false,
        repeat_end_date: '',
        repeat_by_weekday: ['mon'] as Array<'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun'>,
    });

    function addTask() {
        form.setData('tasks', [...form.data.tasks, { label: '' }]);
    }

    function removeTask(idx: number) {
        form.setData('tasks', form.data.tasks.filter((_, i) => i !== idx));
    }

    function toggleWeekday(d: any) {
        const set = new Set(form.data.repeat_by_weekday);
        if (set.has(d)) set.delete(d);
        else set.add(d);
        form.setData('repeat_by_weekday', Array.from(set) as any);
    }

    return (
        <AppLayout breadcrumbs={[{ title: labels?.['shift.plural'] ?? 'Shifts', href: '/shifts' }, { title: 'Create', href: '/shifts/create' }]}>
            <Head title={`Create ${shiftLabel}`} />
            <PageShell>
                <div className="max-w-2xl">
                    <PageHeader
                        title={`Create ${shiftLabel}`}
                        backHref="/shifts"
                        description="Create an appointment / rostered shift. Add tasks and (optionally) repeat weekly."
                    />
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!form.data.repeat_weekly) {
                            form.post('/shifts');
                            return;
                        }

                        // Recurring weekly series payload
                        const starts = form.data.starts_at;
                        const ends = form.data.ends_at;
                        const startDate = starts?.slice(0, 10);
                        const startsTime = starts?.slice(11, 16);
                        const endsTime = ends?.slice(11, 16);

                        router.post('/operations/shifts/series', {
                            client_id: form.data.client_id,
                            service_context_id: form.data.service_context_id,
                            user_id: form.data.user_id,
                            start_date: startDate,
                            end_date: form.data.repeat_end_date || startDate,
                            by_weekday: form.data.repeat_by_weekday,
                            starts_time: startsTime,
                            ends_time: endsTime,
                            location: form.data.location,
                            notes: form.data.notes,
                            status: form.data.status,
                            tasks: form.data.tasks,
                        });
                    }}
                    className="space-y-4"
                >
                    <div className="rounded-md border p-4 space-y-4">
                        <div className="space-y-2">
                            <Label>Client</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.client_id}
                                onChange={(e) => {
                                    const nextId = e.target.value;
                                    form.setData('client_id', nextId);

                                    // If service context not manually selected yet, inherit from client
                                    if (!form.data.service_context_id) {
                                        const client = clients.find((c) => String(c.id) === String(nextId));
                                        if (client?.service_context_id) {
                                            form.setData('service_context_id', client.service_context_id as any);
                                        }
                                    }
                                }}
                            >
                                {clients.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.first_name} {c.last_name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="space-y-2">
                            <Label>Service context</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={String(form.data.service_context_id ?? '')}
                                onChange={(e) => form.setData('service_context_id', e.target.value)}
                            >
                                <option value="">Inherit from client (recommended)</option>
                                {serviceContexts
                                    .filter((sc) => sc.is_active || String(sc.id) === String(form.data.service_context_id))
                                    .map((sc) => (
                                        <option key={sc.id} value={sc.id}>
                                            {sc.name}
                                            {!sc.is_active ? ' (inactive)' : ''}
                                        </option>
                                    ))}
                            </select>
                            <div className="text-xs text-slate-500">
                                If left blank, the shift will inherit the selected client’s service context (if set).
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>Staff</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.user_id}
                                onChange={(e) => form.setData('user_id', e.target.value)}
                            >
                                <option value="">Unassigned (open shift)</option>
                                {staff.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} ({s.email})
                                    </option>
                                ))}
                            </select>
                            <div className="text-xs text-slate-500">
                                Leave blank to create an open shift that can be assigned later from the Rostering module.
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Start</Label>
                                <Input type="datetime-local" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} />
                            </div>
                            <div className="space-y-2">
                                <Label>End</Label>
                                <Input type="datetime-local" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>Location</Label>
                            <Input value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} />
                        </div>

                        <div className="space-y-2">
                            <Label>Status</Label>
                            <select className="w-full rounded-md border bg-background p-2 text-sm" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                <option value="draft">draft</option>
                                <option value="scheduled">scheduled</option>
                                <option value="in_progress">in_progress</option>
                                <option value="completed">completed</option>
                                <option value="cancelled">cancelled</option>
                            </select>
                        </div>

                        <div className="space-y-2">
                            <Label>Notes</Label>
                            <textarea
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                rows={4}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label>Shift tasks (checklist)</Label>
                            <div className="space-y-2">
                                {form.data.tasks.map((t, idx) => (
                                    <div key={idx} className="flex items-center gap-2">
                                        <Input
                                            value={t.label}
                                            onChange={(e) => {
                                                const next = [...form.data.tasks];
                                                next[idx] = { ...next[idx], label: e.target.value };
                                                form.setData('tasks', next);
                                            }}
                                            placeholder="Task label"
                                        />
                                        <Button type="button" variant="outline" onClick={() => removeTask(idx)}>
                                            Remove
                                        </Button>
                                    </div>
                                ))}
                                <Button type="button" variant="outline" onClick={addTask}>
                                    Add task
                                </Button>
                            </div>
                        </div>

                        <div className="rounded-md border p-3 space-y-3">
                            <div className="flex items-center justify-between">
                                <div>
                                    <div className="text-sm font-medium">Repeat weekly</div>
                                    <div className="text-xs text-slate-500">Create a recurring series (weekly) until an end date.</div>
                                </div>
                                <input
                                    type="checkbox"
                                    checked={form.data.repeat_weekly}
                                    onChange={(e) => form.setData('repeat_weekly', e.target.checked)}
                                />
                            </div>

                            {form.data.repeat_weekly && (
                                <div className="space-y-3">
                                    <div className="space-y-2">
                                        <Label>Repeat on</Label>
                                        <div className="flex flex-wrap gap-2 text-sm">
                                            {(['mon','tue','wed','thu','fri','sat','sun'] as const).map((d) => (
                                                <button
                                                    type="button"
                                                    key={d}
                                                    onClick={() => toggleWeekday(d)}
                                                    className={`rounded-md border px-3 py-1 ${form.data.repeat_by_weekday.includes(d) ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : ''}`}
                                                >
                                                    {d.toUpperCase()}
                                                </button>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <Label>Repeat end date</Label>
                                        <Input
                                            type="date"
                                            value={form.data.repeat_end_date}
                                            onChange={(e) => form.setData('repeat_end_date', e.target.value)}
                                        />
                                        <div className="text-xs text-slate-500">
                                            Tip: starts/ends time are taken from the Start/End fields above.
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>Create</Button>
                        <Button type="button" variant="outline" onClick={() => history.back()}>Cancel</Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
