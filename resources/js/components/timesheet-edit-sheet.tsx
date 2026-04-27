import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import DictateButton from '@/components/dictate-button';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';

export type InlineTimesheet = {
    id: number;
    client_id: number | null;
    work_date_iso: string | null;
    starts_at: string | null;
    ends_at: string | null;
    break_minutes: number;
    mileage_km: number | null;
    notes: string | null;
    return_notes: string | null;
    can_edit_inline?: boolean;
};

function toLocalInput(value: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export default function TimesheetEditSheet({
    timesheet,
    open,
    onOpenChange,
}: {
    timesheet: InlineTimesheet;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [startsAt, setStartsAt] = useState('');
    const [endsAt, setEndsAt] = useState('');
    const [breakMinutes, setBreakMinutes] = useState(0);
    const [mileageKm, setMileageKm] = useState('');
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!open) return;
        setStartsAt(toLocalInput(timesheet.starts_at));
        setEndsAt(toLocalInput(timesheet.ends_at));
        setBreakMinutes(timesheet.break_minutes ?? 0);
        setMileageKm(
            timesheet.mileage_km === null || timesheet.mileage_km === undefined
                ? ''
                : String(timesheet.mileage_km),
        );
        setNotes(timesheet.notes ?? '');
    }, [open, timesheet]);

    const saveAndSubmit = () => {
        if (!timesheet.can_edit_inline || !timesheet.client_id) return;
        setSubmitting(true);

        router.put(
            `/timesheets/${timesheet.id}`,
            {
                client_id: timesheet.client_id,
                work_date: timesheet.work_date_iso,
                starts_at: startsAt,
                ends_at: endsAt,
                break_minutes: breakMinutes,
                mileage_km: mileageKm === '' ? null : Number(mileageKm),
                notes: notes.trim() || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    router.post(
                        `/timesheets/${timesheet.id}/submit`,
                        {},
                        {
                            preserveScroll: true,
                            onSuccess: () => onOpenChange(false),
                            onFinish: () => setSubmitting(false),
                        },
                    );
                },
                onError: () => setSubmitting(false),
            },
        );
    };

    const disabled =
        submitting || !timesheet.can_edit_inline || !timesheet.client_id;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="max-h-[92vh] overflow-y-auto rounded-t-2xl"
            >
                <SheetHeader className="pr-12">
                    <SheetTitle>Update and resubmit</SheetTitle>
                    <SheetDescription>
                        Fix the returned timesheet without leaving My Day.
                    </SheetDescription>
                </SheetHeader>

                <div className="space-y-4 px-4">
                    {timesheet.return_notes ? (
                        <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning">
                            <div className="font-medium">Manager note</div>
                            <p className="mt-1 whitespace-pre-wrap">
                                {timesheet.return_notes}
                            </p>
                        </div>
                    ) : null}

                    {!timesheet.can_edit_inline ? (
                        <div className="rounded-lg border p-3 text-sm text-muted-foreground">
                            This timesheet is locked for payroll or no longer
                            editable.
                        </div>
                    ) : null}

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <label
                                htmlFor={`timesheet-${timesheet.id}-starts`}
                                className="text-sm font-medium"
                            >
                                Start
                            </label>
                            <Input
                                id={`timesheet-${timesheet.id}-starts`}
                                type="datetime-local"
                                value={startsAt}
                                onChange={(event) =>
                                    setStartsAt(event.target.value)
                                }
                                disabled={disabled}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label
                                htmlFor={`timesheet-${timesheet.id}-ends`}
                                className="text-sm font-medium"
                            >
                                Finish
                            </label>
                            <Input
                                id={`timesheet-${timesheet.id}-ends`}
                                type="datetime-local"
                                value={endsAt}
                                onChange={(event) =>
                                    setEndsAt(event.target.value)
                                }
                                disabled={disabled}
                            />
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <label
                                htmlFor={`timesheet-${timesheet.id}-break`}
                                className="text-sm font-medium"
                            >
                                Break minutes
                            </label>
                            <Input
                                id={`timesheet-${timesheet.id}-break`}
                                type="number"
                                min={0}
                                max={600}
                                value={breakMinutes}
                                onChange={(event) =>
                                    setBreakMinutes(
                                        Math.max(
                                            0,
                                            Math.floor(
                                                Number(event.target.value) || 0,
                                            ),
                                        ),
                                    )
                                }
                                disabled={disabled}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label
                                htmlFor={`timesheet-${timesheet.id}-mileage`}
                                className="text-sm font-medium"
                            >
                                Mileage km
                            </label>
                            <Input
                                id={`timesheet-${timesheet.id}-mileage`}
                                type="number"
                                min={0}
                                max={9999}
                                step="0.1"
                                value={mileageKm}
                                onChange={(event) =>
                                    setMileageKm(event.target.value)
                                }
                                disabled={disabled}
                            />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between gap-2">
                            <label
                                htmlFor={`timesheet-${timesheet.id}-notes`}
                                className="text-sm font-medium"
                            >
                                Notes
                            </label>
                            <DictateButton
                                value={notes}
                                onChange={setNotes}
                                fieldLabel="Timesheet notes"
                                disabled={disabled}
                            />
                        </div>
                        <Textarea
                            id={`timesheet-${timesheet.id}-notes`}
                            rows={3}
                            value={notes}
                            onChange={(event) => setNotes(event.target.value)}
                            disabled={disabled}
                            className="text-base"
                        />
                    </div>
                </div>

                <SheetFooter>
                    <Button
                        type="button"
                        onClick={saveAndSubmit}
                        disabled={disabled || !startsAt || !endsAt}
                    >
                        {submitting ? 'Sending...' : 'Save and resubmit'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
