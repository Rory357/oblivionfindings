import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

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
import { useMyDayLabels } from '@/hooks/use-my-day-labels';
import { resubmit as resubmitTimesheet } from '@/routes/operations/timesheets';

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
    const t = useMyDayLabels();
    const page = usePage<{
        flash?: { error?: string | null };
    }>();
    const [startsAt, setStartsAt] = useState('');
    const [endsAt, setEndsAt] = useState('');
    const [breakMinutes, setBreakMinutes] = useState(0);
    const [mileageKm, setMileageKm] = useState('');
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);

    // Sync once when the sheet opens (or switches to a different timesheet).
    // Don't depend on the full `timesheet` reference — Inertia re-renders the
    // page on a 422 with the same row, which would reset the user's typed
    // values and force them to re-enter everything before retrying.
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
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, timesheet.id]);

    const errorMessages = useMemo(() => {
        const messages: string[] = [];
        const errors = (page.props as { errors?: Record<string, unknown> })
            .errors;
        if (errors) {
            for (const value of Object.values(errors)) {
                if (typeof value === 'string' && value !== '') {
                    messages.push(value);
                }
            }
        }
        const flashError = page.props.flash?.error;
        if (typeof flashError === 'string' && flashError !== '') {
            messages.unshift(flashError);
        }
        return messages;
    }, [page.props]);

    const saveAndSubmit = () => {
        if (!timesheet.can_edit_inline || !timesheet.client_id) return;
        setSubmitting(true);

        router.post(
            resubmitTimesheet.url(timesheet.id),
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
                onSuccess: () => onOpenChange(false),
                onFinish: () => setSubmitting(false),
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
                    <SheetTitle>{t('update_and_resubmit')}</SheetTitle>
                    <SheetDescription>
                        {t('fix_returned_timesheet')}
                    </SheetDescription>
                </SheetHeader>

                <div className="space-y-4 px-4">
                    {timesheet.return_notes ? (
                        <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning">
                            <div className="font-medium">
                                {t('manager_note')}
                            </div>
                            <p className="mt-1 whitespace-pre-wrap">
                                {timesheet.return_notes}
                            </p>
                        </div>
                    ) : null}

                    {!timesheet.can_edit_inline ? (
                        <div className="rounded-lg border p-3 text-sm text-muted-foreground">
                            {t('locked_for_payroll')}
                        </div>
                    ) : null}

                    {errorMessages.length > 0 ? (
                        <div
                            role="alert"
                            className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
                        >
                            <div className="font-medium">
                                {t('couldnt_resubmit')}
                            </div>
                            <ul className="mt-1 list-disc space-y-0.5 pl-5">
                                {errorMessages.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </div>
                    ) : null}

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <label
                                htmlFor={`timesheet-${timesheet.id}-starts`}
                                className="text-sm font-medium"
                            >
                                {t('start')}
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
                                {t('finish')}
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
                                {t('break_minutes')}
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
                                {t('mileage_km')}
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
                                {t('notes')}
                            </label>
                            <DictateButton
                                value={notes}
                                onChange={setNotes}
                                fieldLabel={t('timesheet_notes_label')}
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
                        {submitting
                            ? t('sending')
                            : t('update_and_resubmit_action')}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
