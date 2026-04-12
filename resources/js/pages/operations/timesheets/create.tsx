import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { Clock } from 'lucide-react';

type Client = { id: number; first_name: string; last_name: string };
type LinkedShift = {
    id: number;
    client_id: number;
    starts_at: string;
    ends_at: string;
    location?: string | null;
    status?: string;
    shift_type?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    expected_break_minutes?: number | null;
    service_context?: { id: number; name: string } | null;
    staff?: { id: number; name: string; email?: string | null } | null;
};

type Props = {
    clients: Client[];
    shift: LinkedShift | null;
};

export default function TimesheetCreate({ clients, shift }: Props) {
    const { labels } = usePage().props as any;
    const timesheetLabel = labels?.['timesheet.singular'] ?? 'Timesheet';

    const defaultClientId = shift?.client_id ?? clients?.[0]?.id ?? '';
    const start = shift?.starts_at ? shift.starts_at.slice(0, 16) : '';
    const end = shift?.ends_at ? shift.ends_at.slice(0, 16) : '';
    const workDate = shift?.starts_at
        ? shift.starts_at.slice(0, 10)
        : new Date().toISOString().slice(0, 10);

    const form = useForm({
        client_id: defaultClientId,
        shift_id: shift?.id ?? null,
        work_date: workDate,
        starts_at: start,
        ends_at: end,
        break_minutes: shift?.expected_break_minutes ?? 0,
        mileage_km: 0,
        sleepover: !!shift?.is_sleepover,
        on_call: !!shift?.is_on_call,
        allowance_notes: '',
        public_holiday: false,
        notes: '',
        is_residential_billable: false,
    });

    const liveHours = useMemo(() => {
        if (!form.data.starts_at || !form.data.ends_at) return null;
        const startMs = new Date(form.data.starts_at).getTime();
        const endMs = new Date(form.data.ends_at).getTime();
        if (isNaN(startMs) || isNaN(endMs) || endMs <= startMs) return null;
        const netMinutes = (endMs - startMs) / 60000 - (Number(form.data.break_minutes) || 0);
        return netMinutes > 0 ? (netMinutes / 60).toFixed(2) : '0.00';
    }, [form.data.starts_at, form.data.ends_at, form.data.break_minutes]);

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['timesheet.plural'] ?? 'Timesheets',
                    href: '/timesheets',
                },
                { title: 'Create', href: '/timesheets/create' },
            ]}
        >
            <Head title={`Create ${timesheetLabel}`} />
            <div className="max-w-2xl space-y-6 p-4">
                <HeadingSmall
                    title={`Create ${timesheetLabel}`}
                    description={
                        shift
                            ? 'Pre-filled from shift. Adjust actual worked times.'
                            : 'Create a new timesheet.'
                    }
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/timesheets');
                    }}
                    className="space-y-4"
                >
                    {shift ? (
                        <div className="rounded-md border bg-muted/20 p-4 text-sm">
                            <div className="font-medium">
                                Linked shift #{shift.id}
                            </div>
                            <div className="mt-1 text-muted-foreground">
                                {String(shift.shift_type ?? 'standard').replace(
                                    '_',
                                    ' ',
                                )}
                                {shift.service_context?.name
                                    ? ` • ${shift.service_context.name}`
                                    : ''}
                                {shift.location ? ` • ${shift.location}` : ''}
                            </div>
                            {shift.staff?.name ? (
                                <div className="mt-1 text-muted-foreground">
                                    Assigned staff: {shift.staff.name}
                                </div>
                            ) : null}
                            {shift.is_sleepover ||
                            shift.is_on_call ||
                            shift.expected_break_minutes ? (
                                <div className="mt-1 text-muted-foreground">
                                    {shift.is_sleepover ? 'Sleepover' : null}
                                    {shift.is_sleepover && shift.is_on_call
                                        ? ' • '
                                        : null}
                                    {shift.is_on_call ? 'On-call' : null}
                                    {(shift.is_sleepover || shift.is_on_call) &&
                                    shift.expected_break_minutes
                                        ? ' • '
                                        : null}
                                    {shift.expected_break_minutes
                                        ? `Planned break ${shift.expected_break_minutes}m`
                                        : null}
                                </div>
                            ) : null}
                        </div>
                    ) : null}

                    <div className="space-y-4 rounded-md border p-4">
                        {/* Work date */}
                        <div className="space-y-2">
                            <Label>Work date</Label>
                            <Input
                                type="date"
                                value={form.data.work_date}
                                onChange={(e) =>
                                    form.setData('work_date', e.target.value)
                                }
                            />
                        </div>

                        {/* Start / End times */}
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Start</Label>
                                <Input
                                    type="datetime-local"
                                    value={form.data.starts_at}
                                    onChange={(e) =>
                                        form.setData('starts_at', e.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>End</Label>
                                <Input
                                    type="datetime-local"
                                    value={form.data.ends_at}
                                    onChange={(e) =>
                                        form.setData('ends_at', e.target.value)
                                    }
                                />
                            </div>
                        </div>

                        {/* Live hours preview */}
                        {liveHours !== null ? (
                            <div className="flex items-center gap-2 rounded-lg border bg-muted/30 px-3 py-2 text-sm">
                                <Clock className="h-4 w-4 text-muted-foreground" />
                                Estimated hours: <span className="font-semibold tabular-nums">{liveHours}h</span>
                            </div>
                        ) : null}

                        {/* Break */}
                        <div className="space-y-2">
                            <Label>Break (minutes)</Label>
                            <Input
                                type="number"
                                value={form.data.break_minutes}
                                onChange={(e) =>
                                    form.setData('break_minutes', Number(e.target.value))
                                }
                            />
                        </div>

                        {/* Client */}
                        <div className="space-y-2">
                            <Label>Client</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.client_id}
                                disabled={!!shift}
                                onChange={(e) =>
                                    form.setData('client_id', Number(e.target.value))
                                }
                            >
                                {clients.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.first_name} {c.last_name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Notes */}
                        <div className="space-y-2">
                            <Label>Notes</Label>
                            <textarea
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                rows={4}
                            />
                        </div>

                        {/* Mileage / Allowance */}
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Mileage (km)</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    step="0.1"
                                    value={form.data.mileage_km}
                                    onChange={(e) =>
                                        form.setData('mileage_km', Number(e.target.value))
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Allowance notes</Label>
                                <Input
                                    value={form.data.allowance_notes}
                                    onChange={(e) =>
                                        form.setData('allowance_notes', e.target.value)
                                    }
                                    placeholder="Travel, sleepover or other allowance notes"
                                />
                            </div>
                        </div>

                        {/* All checkboxes grouped */}
                        <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                            <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.sleepover}
                                    disabled={!!shift}
                                    onChange={(e) =>
                                        form.setData('sleepover', e.target.checked)
                                    }
                                />
                                Sleepover
                            </label>
                            <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.on_call}
                                    disabled={!!shift}
                                    onChange={(e) =>
                                        form.setData('on_call', e.target.checked)
                                    }
                                />
                                On-call
                            </label>
                            <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.public_holiday}
                                    onChange={(e) =>
                                        form.setData('public_holiday', e.target.checked)
                                    }
                                />
                                Public holiday
                            </label>
                            <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_residential_billable}
                                    onChange={(e) =>
                                        form.setData('is_residential_billable', e.target.checked)
                                    }
                                />
                                Residential billable
                            </label>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>
                            Create
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => history.back()}
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
