/* Edit timesheet pop-up — replaces the old full-page editor at
 * /operations/timesheets/{id}/edit. Drafts and returned timesheets are
 * editable (server enforces ownership + payroll locks); everything else
 * renders read-only with the workflow handled by the index actions. */
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    submit as submitTimesheet,
    update as updateTimesheet,
} from '@/routes/operations/timesheets';
import type { Page, PageProps } from '@inertiajs/core';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, Loader2, Send } from 'lucide-react';
import { useEffect, useState } from 'react';

import type { ViewTimesheetRow } from './view-timesheet-dialog';

export type EditTimesheetRow = ViewTimesheetRow & {
    client_id?: number | null;
    allowance_notes?: string | null;
    is_residential_billable?: boolean;
};

type ClientOption = { id: number; first_name: string; last_name: string };

/** `back()->with('error')` arrives as a successful Inertia visit — only treat
 *  the save as done when no error flash came back with the fresh props. */
function flashError(page: Page<PageProps>): string | null {
    const flash = (page.props as { flash?: { error?: string | null } }).flash;
    return flash?.error ?? null;
}

export default function EditTimesheetDialog({
    open,
    onOpenChange,
    timesheet,
    clients,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    timesheet: EditTimesheetRow | null;
    clients: ClientOption[];
}) {
    const [submitting, setSubmitting] = useState(false);

    const form = useForm({
        client_id: '' as string,
        work_date: '',
        starts_at: '',
        ends_at: '',
        break_minutes: 0,
        mileage_km: 0,
        sleepover: false,
        on_call: false,
        allowance_notes: '',
        public_holiday: false,
        notes: '',
        is_residential_billable: false,
    });

    // Re-seed whenever a (different) timesheet opens.
    useEffect(() => {
        if (!open || !timesheet) return;
        form.setDefaults();
        form.setData({
            client_id: timesheet.client_id ? String(timesheet.client_id) : '',
            work_date: timesheet.work_date ?? '',
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
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- seed on open only
    }, [open, timesheet?.id]);

    if (!timesheet) return null;

    const editable = ['draft', 'returned'].includes(timesheet.status);
    const shiftLinked = !!timesheet.shift;

    const save = () => {
        if (!editable) return;
        form.put(updateTimesheet.url(timesheet.id), {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!flashError(page)) onOpenChange(false);
            },
        });
    };

    const submitForApproval = () => {
        setSubmitting(true);
        router.post(
            submitTimesheet.url(timesheet.id),
            {},
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (!flashError(page)) onOpenChange(false);
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-[640px]">
                <DialogHeader className="border-b border-border px-5 py-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <DialogTitle className="text-base font-bold">
                            {editable ? 'Edit timesheet' : 'Timesheet'} #
                            {timesheet.id}
                        </DialogTitle>
                        <TimesheetStatusBadge status={timesheet.status} />
                    </div>
                    <DialogDescription className="text-left text-xs">
                        {editable
                            ? 'Adjust the hours, breaks and allowances, then save — or submit for approval when it’s ready.'
                            : 'Only draft or returned timesheets can be edited. Use the list actions for workflow steps.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    {timesheet.status === 'returned' &&
                    timesheet.returned_notes ? (
                        <div className="flex items-start gap-2 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <div>
                                <div className="text-xs font-semibold">
                                    Returned — what needs changing
                                </div>
                                <div className="mt-0.5 whitespace-pre-wrap">
                                    {timesheet.returned_notes}
                                </div>
                            </div>
                        </div>
                    ) : null}

                    {shiftLinked ? (
                        <div className="flex items-center gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                            <CalendarDays className="h-3.5 w-3.5 shrink-0" />
                            Linked to shift #{timesheet.shift?.id} — the client
                            and sleepover/on-call tags follow the shift.
                        </div>
                    ) : null}

                    <div className="space-y-2">
                        <Label>Client</Label>
                        <Select
                            value={form.data.client_id || undefined}
                            onValueChange={(v) => form.setData('client_id', v)}
                            disabled={!editable || shiftLinked}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select client" />
                            </SelectTrigger>
                            <SelectContent>
                                {clients.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.first_name} {c.last_name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Work date</Label>
                            <Input
                                type="date"
                                value={form.data.work_date}
                                onChange={(e) =>
                                    form.setData('work_date', e.target.value)
                                }
                                disabled={!editable}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Break (minutes)</Label>
                            <Input
                                type="number"
                                min={0}
                                max={240}
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
                        <div className="space-y-2">
                            <Label>Start</Label>
                            <Input
                                type="datetime-local"
                                value={form.data.starts_at}
                                onChange={(e) =>
                                    form.setData('starts_at', e.target.value)
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

                    <div className="space-y-2">
                        <Label>Notes</Label>
                        <Textarea
                            rows={3}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                            disabled={!editable}
                        />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="flex items-center gap-2 rounded-lg border p-3 text-sm">
                            <Checkbox
                                checked={form.data.sleepover}
                                onCheckedChange={(v) =>
                                    form.setData('sleepover', Boolean(v))
                                }
                                disabled={!editable || shiftLinked}
                            />
                            Sleepover
                        </label>
                        <label className="flex items-center gap-2 rounded-lg border p-3 text-sm">
                            <Checkbox
                                checked={form.data.on_call}
                                onCheckedChange={(v) =>
                                    form.setData('on_call', Boolean(v))
                                }
                                disabled={!editable || shiftLinked}
                            />
                            On-call
                        </label>
                        <label className="flex items-center gap-2 rounded-lg border p-3 text-sm">
                            <Checkbox
                                checked={form.data.public_holiday}
                                onCheckedChange={(v) =>
                                    form.setData('public_holiday', Boolean(v))
                                }
                                disabled={!editable}
                            />
                            Public holiday
                        </label>
                        <label className="flex items-center gap-2 rounded-lg border p-3 text-sm">
                            <Checkbox
                                checked={form.data.is_residential_billable}
                                onCheckedChange={(v) =>
                                    form.setData(
                                        'is_residential_billable',
                                        Boolean(v),
                                    )
                                }
                                disabled={!editable}
                            />
                            Residential billable
                        </label>
                    </div>

                    {Object.keys(form.errors).length > 0 ? (
                        <div className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical">
                            <p className="font-medium">
                                Please fix the following:
                            </p>
                            <ul className="mt-1 list-disc pl-5">
                                {Object.entries(form.errors).map(
                                    ([field, message]) => (
                                        <li key={field}>{message}</li>
                                    ),
                                )}
                            </ul>
                        </div>
                    ) : null}
                </div>

                <div className="flex flex-wrap items-center justify-end gap-2 border-t border-border bg-muted/30 px-5 py-3.5">
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {editable ? 'Cancel' : 'Close'}
                    </Button>
                    {editable ? (
                        <>
                            <Button
                                variant="secondary"
                                onClick={submitForApproval}
                                disabled={submitting || form.processing}
                                data-test="timesheet-submit"
                            >
                                {submitting ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Send className="h-4 w-4" />
                                )}
                                Submit for approval
                            </Button>
                            <Button
                                onClick={save}
                                disabled={form.processing || submitting}
                                data-test="timesheet-save"
                            >
                                {form.processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : null}
                                Save
                            </Button>
                        </>
                    ) : null}
                </div>
            </DialogContent>
        </Dialog>
    );
}
