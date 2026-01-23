import AppLayout from '@/layouts/app-layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Client = { id: number; first_name: string; last_name: string };
type Staff = { id: number; name: string; email: string };

type Props = { shift: any; clients: Client[]; staff: Staff[] };

export default function ShiftEdit({ shift, clients, staff }: Props) {
    const { labels } = usePage().props as any;
    const shiftLabel = labels?.['shift.singular'] ?? 'Shift';

    const [taskBusyId, setTaskBusyId] = useState<number | null>(null);
    const [taskError, setTaskError] = useState<string | null>(null);

    const form = useForm({
        client_id: shift.client_id,
        user_id: shift.user_id,
        starts_at: shift.starts_at?.slice(0, 16) ?? '',
        ends_at: shift.ends_at?.slice(0, 16) ?? '',
        location: shift.location ?? '',
        notes: shift.notes ?? '',
        status: shift.status ?? 'scheduled',
        tasks: (shift.tasks ?? []).map((t: any) => ({ id: t.id, label: t.label, is_completed: !!t.is_completed })),
    });

    function addTask() {
        form.setData('tasks', [...form.data.tasks, { label: '', is_completed: false }] as any);
    }

    function removeTask(idx: number) {
        form.setData('tasks', (form.data.tasks as any[]).filter((_, i) => i !== idx) as any);
    }

    async function toggleComplete(task: any, checked: boolean) {
        if (!task.id) return; // new tasks must be saved first
        setTaskBusyId(task.id);
        setTaskError(null);
        try {
            const res = await fetch(`/shifts/${shift.id}/tasks/${task.id}`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content,
                },
                body: JSON.stringify({ is_completed: checked }),
                credentials: 'same-origin',
            });
            if (!res.ok) {
                const data = await res.json().catch(() => null);
                throw new Error(data?.message ?? 'Failed to update task');
            }
            const data = await res.json();
            const updated = data?.task;
            form.setData(
                'tasks',
                (form.data.tasks as any[]).map((t) => (t.id === updated.id ? { ...t, is_completed: !!updated.is_completed } : t)) as any,
            );
        } catch (e: any) {
            setTaskError(e?.message ?? 'Failed');
        } finally {
            setTaskBusyId(null);
        }
    }

    return (
        <AppLayout breadcrumbs={[{ title: labels?.['shift.plural'] ?? 'Shifts', href: '/shifts' }, { title: `Edit`, href: `/shifts/${shift.id}/edit` }]}> 
            <Head title={`Edit ${shiftLabel}`} />
            <div className="p-4 max-w-2xl space-y-6">
                <HeadingSmall title={`Edit ${shiftLabel}`} description="Update an appointment / shift." />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/shifts/${shift.id}`);
                    }}
                    className="space-y-4"
                >
                    <div className="rounded-md border p-4 space-y-4">
                        <div className="space-y-2">
                            <Label>Client</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.client_id}
                                onChange={(e) => form.setData('client_id', e.target.value)}
                            >
                                {clients.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.first_name} {c.last_name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="space-y-2">
                            <Label>Staff</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.user_id}
                                onChange={(e) => form.setData('user_id', e.target.value)}
                            >
                                {staff.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} ({s.email})
                                    </option>
                                ))}
                            </select>
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
                                <option value="scheduled">scheduled</option>
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
                            {taskError && <div className="text-sm text-red-500">{taskError}</div>}
                            <div className="space-y-2">
                                {(form.data.tasks as any[]).map((t, idx) => (
                                    <div key={t.id ?? idx} className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={!!t.is_completed}
                                            disabled={!t.id || taskBusyId === t.id}
                                            onChange={(e) => toggleComplete(t, e.target.checked)}
                                        />
                                        <Input
                                            value={t.label}
                                            onChange={(e) => {
                                                const next = [...(form.data.tasks as any[])];
                                                next[idx] = { ...next[idx], label: e.target.value };
                                                form.setData('tasks', next as any);
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
                                <div className="text-xs text-slate-500">
                                    Tip: Save to persist new tasks. Completed state can be toggled for saved tasks.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>Save</Button>
                        <Button type="button" variant="outline" onClick={() => history.back()}>Cancel</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
