import AppLayout from '@/layouts/app-layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm, usePage } from '@inertiajs/react';

type Client = { id: number; first_name: string; last_name: string };

type Props = {
    timesheet: any;
    clients: Client[];
    canApprove: boolean;
};

export default function TimesheetEdit({ timesheet, clients, canApprove }: Props) {
    const { labels } = usePage().props as any;
    const timesheetLabel = labels?.['timesheet.singular'] ?? 'Timesheet';

    const form = useForm({
        client_id: timesheet.client_id,
        work_date: timesheet.work_date,
        starts_at: timesheet.starts_at?.slice(0, 16) ?? '',
        ends_at: timesheet.ends_at?.slice(0, 16) ?? '',
        break_minutes: timesheet.break_minutes ?? 0,
        notes: timesheet.notes ?? '',
        status: timesheet.status ?? 'draft',
        approve: false,
        reject: false,
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: labels?.['timesheet.plural'] ?? 'Timesheets', href: '/timesheets' },
                { title: `${timesheetLabel} #${timesheet.id}`, href: `/timesheets/${timesheet.id}/edit` },
            ]}
        >
            <Head title={`${timesheetLabel} #${timesheet.id}`} />

            <div className="p-4 max-w-2xl space-y-6">
                <HeadingSmall
                    title={`${timesheetLabel} #${timesheet.id}`}
                    description={timesheet.shift ? 'Linked to a shift.' : 'Manual timesheet.'}
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/timesheets/${timesheet.id}`);
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
                                <Input type="number" value={form.data.break_minutes} onChange={(e) => form.setData('break_minutes', Number(e.target.value))} />
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
                                <option value="approved">approved</option>
                                <option value="rejected">rejected</option>
                            </select>
                        </div>

                        <div className="space-y-2">
                            <Label>Notes</Label>
                            <textarea className="w-full rounded-md border bg-background p-2 text-sm" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={4} />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="submit" disabled={form.processing}>Save</Button>
                        {canApprove && form.data.status === 'submitted' ? (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        form.setData('approve', true);
                                        form.setData('reject', false);
                                        form.put(`/timesheets/${timesheet.id}`, {
                                            onFinish: () => {
                                                form.setData('approve', false);
                                            },
                                        });
                                    }}
                                >
                                    Approve
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        form.setData('reject', true);
                                        form.setData('approve', false);
                                        form.put(`/timesheets/${timesheet.id}`, {
                                            onFinish: () => {
                                                form.setData('reject', false);
                                            },
                                        });
                                    }}
                                >
                                    Reject
                                </Button>
                            </>
                        ) : null}
                        <Button type="button" variant="outline" onClick={() => history.back()}>Back</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
