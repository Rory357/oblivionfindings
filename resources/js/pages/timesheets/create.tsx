import AppLayout from '@/layouts/app-layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm, usePage } from '@inertiajs/react';

type Client = { id: number; first_name: string; last_name: string };

type Props = {
    clients: Client[];
    shift: any | null;
};

export default function TimesheetCreate({ clients, shift }: Props) {
    const { labels } = usePage().props as any;
    const timesheetLabel = labels?.['timesheet.singular'] ?? 'Timesheet';

    const defaultClientId = shift?.client_id ?? clients?.[0]?.id ?? '';
    const start = shift?.starts_at ? shift.starts_at.slice(0, 16) : '';
    const end = shift?.ends_at ? shift.ends_at.slice(0, 16) : '';
    const workDate = shift?.starts_at ? shift.starts_at.slice(0, 10) : new Date().toISOString().slice(0, 10);

    const form = useForm({
        client_id: defaultClientId,
        shift_id: shift?.id ?? null,
        work_date: workDate,
        starts_at: start,
        ends_at: end,
        break_minutes: 0,
        notes: '',
        status: 'draft',
    });

    return (
        <AppLayout breadcrumbs={[{ title: labels?.['timesheet.plural'] ?? 'Timesheets', href: '/timesheets' }, { title: 'Create', href: '/timesheets/create' }]}> 
            <Head title={`Create ${timesheetLabel}`} />
            <div className="p-4 max-w-2xl space-y-6">
                <HeadingSmall
                    title={`Create ${timesheetLabel}`}
                    description={shift ? 'Pre-filled from shift. Adjust actual worked times.' : 'Create a new timesheet.'}
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/timesheets');
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

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Work date</Label>
                                <Input type="date" value={form.data.work_date} onChange={(e) => form.setData('work_date', e.target.value)} />
                            </div>
                            <div className="space-y-2">
                                <Label>Break (minutes)</Label>
                                <Input
                                    type="number"
                                    value={form.data.break_minutes}
                                    onChange={(e) => form.setData('break_minutes', Number(e.target.value))}
                                />
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
                            <Label>Status</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.status}
                                onChange={(e) => form.setData('status', e.target.value)}
                            >
                                <option value="draft">draft</option>
                                <option value="submitted">submitted</option>
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
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>Create</Button>
                        <Button type="button" variant="outline" onClick={() => history.back()}>Cancel</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
