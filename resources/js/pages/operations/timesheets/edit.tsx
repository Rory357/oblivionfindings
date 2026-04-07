import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';

type Client = { id: number; first_name: string; last_name: string };

type Props = {
    timesheet: any;
    clients: Client[];
    canApprove: boolean;
    canSubmit: boolean;
    canEdit: boolean;
};

export default function TimesheetEdit({
    timesheet,
    clients,
    canApprove,
    canSubmit,
    canEdit,
}: Props) {
    const { labels } = usePage().props as any;
    const timesheetLabel = labels?.['timesheet.singular'] ?? 'Timesheet';

    const form = useForm({
        client_id: timesheet.client_id,
        work_date: timesheet.work_date,
        starts_at: timesheet.starts_at?.slice(0, 16) ?? '',
        ends_at: timesheet.ends_at?.slice(0, 16) ?? '',
        break_minutes: timesheet.break_minutes ?? 0,
        mileage_km: Number(timesheet.mileage_km ?? 0),
        sleepover: !!timesheet.sleepover,
        on_call: !!timesheet.on_call,
        allowance_notes: timesheet.allowance_notes ?? '',
        public_holiday: !!timesheet.public_holiday,
        notes: timesheet.notes ?? '',
        is_residential_billable: !!timesheet.is_residential_billable,
    });

    const decision = useForm({
        decision_notes: timesheet.decision_notes ?? '',
        returned_notes: timesheet.returned_notes ?? '',
    });

    const status: string = timesheet.status ?? 'draft';
    const editable = !!canEdit;

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['timesheet.plural'] ?? 'Timesheets',
                    href: '/timesheets',
                },
                {
                    title: `${timesheetLabel} #${timesheet.id}`,
                    href: `/operations/timesheets/${timesheet.id}/edit`,
                },
            ]}
        >
            <Head title={`${timesheetLabel} #${timesheet.id}`} />

            <div className="max-w-2xl space-y-6 p-4">
                <HeadingSmall
                    title={`${timesheetLabel} #${timesheet.id}`}
                    description={
                        timesheet.shift
                            ? 'Linked to a shift.'
                            : 'Manual timesheet.'
                    }
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!editable) return;
                        form.put(`/operations/timesheets/${timesheet.id}`);
                    }}
                    className="space-y-4"
                >
                    {timesheet.shift ? (
                        <div className="rounded-md border bg-muted/20 p-4 text-sm">
                            <div className="font-medium">
                                Linked shift #{timesheet.shift.id}
                            </div>
                            <div className="mt-1 text-muted-foreground">
                                {String(
                                    timesheet.shift.shift_type ?? 'standard',
                                ).replace('_', ' ')}
                                {timesheet.shift.service_context?.name
                                    ? ` • ${timesheet.shift.service_context.name}`
                                    : ''}
                                {timesheet.shift.location
                                    ? ` • ${timesheet.shift.location}`
                                    : ''}
                            </div>
                            {timesheet.shift.staff?.name ? (
                                <div className="mt-1 text-muted-foreground">
                                    Assigned staff: {timesheet.shift.staff.name}
                                </div>
                            ) : null}
                            {timesheet.shift.is_sleepover ||
                            timesheet.shift.is_on_call ||
                            timesheet.shift.expected_break_minutes ? (
                                <div className="mt-1 text-muted-foreground">
                                    {timesheet.shift.is_sleepover
                                        ? 'Sleepover'
                                        : null}
                                    {timesheet.shift.is_sleepover &&
                                    timesheet.shift.is_on_call
                                        ? ' • '
                                        : null}
                                    {timesheet.shift.is_on_call
                                        ? 'On-call'
                                        : null}
                                    {(timesheet.shift.is_sleepover ||
                                        timesheet.shift.is_on_call) &&
                                    timesheet.shift.expected_break_minutes
                                        ? ' • '
                                        : null}
                                    {timesheet.shift.expected_break_minutes
                                        ? `Planned break ${timesheet.shift.expected_break_minutes}m`
                                        : null}
                                </div>
                            ) : null}
                        </div>
                    ) : null}

                    <div className="space-y-4 rounded-md border p-4">
                        <div className="flex items-center justify-between">
                            <div className="text-sm font-medium">Status</div>
                            <div className="rounded-full border px-2 py-1 text-xs">
                                {status}
                            </div>
                        </div>

                        {status === 'returned' && timesheet.returned_notes ? (
                            <div className="rounded-md border bg-muted/30 p-3">
                                <div className="text-xs font-medium">
                                    Returned notes
                                </div>
                                <div className="text-sm whitespace-pre-wrap text-muted-foreground">
                                    {timesheet.returned_notes}
                                </div>
                            </div>
                        ) : null}

                        {status === 'approved' || status === 'rejected' ? (
                            timesheet.decision_notes ? (
                                <div className="rounded-md border bg-muted/30 p-3">
                                    <div className="text-xs font-medium">
                                        Decision notes
                                    </div>
                                    <div className="text-sm whitespace-pre-wrap text-muted-foreground">
                                        {timesheet.decision_notes}
                                    </div>
                                </div>
                            ) : null
                        ) : null}

                        <div className="space-y-2">
                            <Label>Client</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.client_id}
                                onChange={(e) =>
                                    form.setData('client_id', e.target.value)
                                }
                                disabled={!editable || !!timesheet.shift}
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
                                <Input
                                    type="date"
                                    value={form.data.work_date}
                                    onChange={(e) =>
                                        form.setData(
                                            'work_date',
                                            e.target.value,
                                        )
                                    }
                                    disabled={!editable}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Break (minutes)</Label>
                                <Input
                                    type="number"
                                    value={form.data.break_minutes}
                                    onChange={(e) =>
                                        form.setData(
                                            'break_minutes',
                                            Number(e.target.value),
                                        )
                                    }
                                    disabled={!editable}
                                />
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Start</Label>
                                <Input
                                    type="datetime-local"
                                    value={form.data.starts_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'starts_at',
                                            e.target.value,
                                        )
                                    }
                                    disabled={!editable}
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
                                    disabled={!editable}
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>Notes</Label>
                            <textarea
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                rows={4}
                                disabled={!editable}
                            />
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Mileage (km)</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    step="0.1"
                                    value={form.data.mileage_km}
                                    onChange={(e) =>
                                        form.setData(
                                            'mileage_km',
                                            Number(e.target.value),
                                        )
                                    }
                                    disabled={!editable}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Allowance notes</Label>
                                <Input
                                    value={form.data.allowance_notes}
                                    onChange={(e) =>
                                        form.setData(
                                            'allowance_notes',
                                            e.target.value,
                                        )
                                    }
                                    disabled={!editable}
                                />
                            </div>
                        </div>

                        <div className="grid gap-3 md:grid-cols-3">
                            <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.sleepover}
                                    disabled={!editable || !!timesheet.shift}
                                    onChange={(e) =>
                                        form.setData(
                                            'sleepover',
                                            e.target.checked,
                                        )
                                    }
                                />
                                Sleepover
                            </label>
                            <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.on_call}
                                    disabled={!editable || !!timesheet.shift}
                                    onChange={(e) =>
                                        form.setData(
                                            'on_call',
                                            e.target.checked,
                                        )
                                    }
                                />
                                On-call
                            </label>
                            <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.public_holiday}
                                    disabled={!editable}
                                    onChange={(e) =>
                                        form.setData(
                                            'public_holiday',
                                            e.target.checked,
                                        )
                                    }
                                />
                                Public holiday
                            </label>
                        </div>

                        <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.is_residential_billable}
                                disabled={!editable}
                                onChange={(e) =>
                                    form.setData(
                                        'is_residential_billable',
                                        e.target.checked,
                                    )
                                }
                            />
                            Residential billable
                        </label>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            disabled={form.processing || !editable}
                        >
                            Save
                        </Button>

                        {canSubmit &&
                        (status === 'draft' || status === 'returned') ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/operations/timesheets/${timesheet.id}/submit`,
                                    )
                                }
                            >
                                Submit for approval
                            </Button>
                        ) : null}

                        {canApprove && status === 'submitted' ? (
                            <>
                                <div className="w-full" />
                                <div className="w-full space-y-3 rounded-md border p-4">
                                    <div className="text-sm font-medium">
                                        Manager decision
                                    </div>
                                    <div className="space-y-2">
                                        <Label>
                                            Decision notes (optional for
                                            approve, required for reject)
                                        </Label>
                                        <textarea
                                            className="w-full rounded-md border bg-background p-2 text-sm"
                                            rows={3}
                                            value={decision.data.decision_notes}
                                            onChange={(e) =>
                                                decision.setData(
                                                    'decision_notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>
                                            Return notes (required to return)
                                        </Label>
                                        <textarea
                                            className="w-full rounded-md border bg-background p-2 text-sm"
                                            rows={3}
                                            value={decision.data.returned_notes}
                                            onChange={(e) =>
                                                decision.setData(
                                                    'returned_notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                decision.post(
                                                    `/operations/timesheets/${timesheet.id}/approve`,
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                );
                                            }}
                                        >
                                            Approve
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                decision.post(
                                                    `/operations/timesheets/${timesheet.id}/reject`,
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                );
                                            }}
                                        >
                                            Reject
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                decision.post(
                                                    `/operations/timesheets/${timesheet.id}/return`,
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                );
                                            }}
                                        >
                                            Return for changes
                                        </Button>
                                    </div>
                                </div>
                            </>
                        ) : null}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => history.back()}
                        >
                            Back
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
