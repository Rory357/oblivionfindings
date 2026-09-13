import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell, WizardSuccessPane } from '@/components/wizard/shell';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { CheckCircle2, Clock, FileText, Users } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { taskRequest } from '../lib/task-api';
import {
    allocationTimeChoices,
    isAllocationBalanced,
    splitHoursEvenly,
} from '../lib/timesheet-allocation';
import type { MyDayTimesheet, TimesheetAllocationMethod } from '../lib/types';

type Row = {
    client_id: number;
    name: string;
    hours: string;
    starts_at: string;
    ends_at: string;
    notes: string;
};
const steps = [
    {
        key: 'people',
        label: 'People supported',
        blurb: 'Choose everyone you supported',
        icon: Users,
    },
    {
        key: 'time',
        label: 'Share the time',
        blurb: 'Split your paid hours',
        icon: Clock,
    },
    {
        key: 'review',
        label: 'Review and send',
        blurb: 'Check the full breakdown',
        icon: CheckCircle2,
    },
] as const;

export function TimesheetReviewDialog({
    timesheet,
    open,
    onOpenChange,
}: {
    timesheet: MyDayTimesheet | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return open && timesheet ? (
        <TimesheetReview
            key={timesheet.id}
            sheet={timesheet}
            onClose={() => onOpenChange(false)}
        />
    ) : null;
}

function TimesheetReview({
    sheet,
    onClose,
}: {
    sheet: MyDayTimesheet;
    onClose: () => void;
}) {
    const candidates = useMemo(
        () => sheet.clients_candidates ?? [],
        [sheet.clients_candidates],
    );
    const initialRows = useMemo<Row[]>(
        () =>
            (sheet.client_allocations ?? []).map((row) => ({
                client_id: row.client_id,
                name:
                    candidates.find((person) => person.id === row.client_id)
                        ?.name ?? 'Person no longer available',
                hours: Number(row.hours).toFixed(2),
                starts_at: row.starts_at ?? '',
                ends_at: row.ends_at ?? '',
                notes: row.notes ?? '',
            })),
        [sheet.client_allocations, candidates],
    );
    const [rows, setRows] = useState(initialRows);
    const [method, setMethod] = useState<TimesheetAllocationMethod>(
        sheet.allocation_method ?? 'manual',
    );
    const [step, setStep] = useState(sheet.status === 'submitted' ? 2 : 0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const [closing, setClosing] = useState(false);
    const [success, setSuccess] = useState(false);
    const revision = useRef(sheet.allocation_revision ?? '');
    const baseline = useRef(
        JSON.stringify({
            rows: initialRows,
            method: sheet.allocation_method ?? 'manual',
        }),
    );
    const dirty = JSON.stringify({ rows, method }) !== baseline.current;
    const editable =
        sheet.status !== 'submitted' &&
        (sheet.can_save_allocation !== false || sheet.can_submit !== false);
    const total = Number(sheet.hours) || 0;
    const allocated = rows.reduce(
        (sum, row) => sum + (Number(row.hours) || 0),
        0,
    );
    const balanced = isAllocationBalanced(method, allocated, total);
    const equal = method === 'equal_split' || method === 'residential_house';
    const segmented = method === 'time_segmented';
    const valid =
        rows.length > 0 &&
        balanced &&
        rows.every(
            (row) =>
                Number(row.hours) > 0 &&
                (!segmented ||
                    (/Z$|[+-]\d\d:\d\d$/.test(row.starts_at) &&
                        /Z$|[+-]\d\d:\d\d$/.test(row.ends_at))),
        );

    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (dirty || busy) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty, busy]);

    const rebalance = (next: Row[]) => {
        const split = splitHoursEvenly(total, next.length);
        return next.map((row, index) => ({
            ...row,
            hours: split[index] ?? '0.00',
        }));
    };
    const choosePeople = (ids: number[]) => {
        const next = ids.map(
            (id) =>
                rows.find((row) => row.client_id === id) ?? {
                    client_id: id,
                    name:
                        candidates.find((person) => person.id === id)?.name ??
                        '',
                    hours: '0.00',
                    starts_at: '',
                    ends_at: '',
                    notes: '',
                },
        );
        if (next.length === 1) {
            setMethod('single');
            setRows([{ ...next[0], hours: total.toFixed(2) }]);
        } else {
            if (method === 'single') setMethod('manual');
            setRows(equal ? rebalance(next) : next);
        }
        setSaved(false);
    };
    const changeMethod = (next: TimesheetAllocationMethod) => {
        setMethod(next);
        setSaved(false);
        setError('');
        if (next === 'equal_split' || next === 'residential_house')
            setRows(rebalance(rows));
    };
    const update = (id: number, patch: Partial<Row>) => {
        setRows((current) =>
            current.map((row) => {
                if (row.client_id !== id) return row;
                const next = { ...row, ...patch };
                if (
                    segmented &&
                    /Z$|[+-]\d\d:\d\d$/.test(next.starts_at) &&
                    /Z$|[+-]\d\d:\d\d$/.test(next.ends_at)
                ) {
                    const hours =
                        (Date.parse(next.ends_at) -
                            Date.parse(next.starts_at)) /
                        3_600_000;
                    next.hours = hours >= 0 ? hours.toFixed(2) : '0.00';
                }
                return next;
            }),
        );
        setSaved(false);
    };
    const finishClose = () => {
        onClose();
        router.reload({ only: ['timesheets', 'clock'] });
    };
    const save = async (submit: boolean, closeAfter = false) => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const result = await taskRequest<{
                revision: string;
                status: string;
            }>(
                submit
                    ? `/my-tasks/timesheet/${sheet.id}/submit`
                    : `/my-day/timesheets/${sheet.id}/allocations`,
                submit ? 'POST' : 'PUT',
                {
                    expected_revision: revision.current,
                    client_allocations: rows.map((row, index) => ({
                        client_id: row.client_id,
                        hours: Number(row.hours) || 0,
                        allocation_method: method,
                        starts_at: segmented ? row.starts_at || null : null,
                        ends_at: segmented ? row.ends_at || null : null,
                        notes: row.notes || null,
                        sort_order: index,
                    })),
                },
            );
            revision.current = result.revision;
            baseline.current = JSON.stringify({ rows, method });
            setSaved(true);
            setClosing(false);
            if (submit) setSuccess(true);
            else if (closeAfter) finishClose();
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Could not save your time split. Your answers are still here.',
            );
        } finally {
            setBusy(false);
        }
    };
    const close = () => {
        if (busy) return;
        if (dirty && editable && !success) setClosing(true);
        else finishClose();
    };
    const context = (
        <div className="rounded-lg border bg-muted/30 p-4 text-sm">
            <p className="font-semibold">
                {sheet.site_name ?? 'Your shift'} · {sheet.work_date}
            </p>
            <p className="mt-1">
                {formatDateTime(sheet.starts_at)} –{' '}
                {formatDateTime(sheet.ends_at)}
            </p>
            <p className="mt-1">
                <strong>
                    {total.toFixed(2)}{' '}
                    {sheet.clock_running
                        ? 'planned hours (draft)'
                        : 'paid hours'}
                </strong>{' '}
                · {sheet.break_minutes} minutes of unpaid breaks
            </p>
            <p className="mt-2 text-muted-foreground">
                Share these hours between the people you supported. The split
                does not add to your paid hours.
            </p>
        </div>
    );
    return (
        <WizardShell
            open
            onClose={close}
            title="Review timesheet"
            description="Choose people, share time, then review your timesheet."
            railIcon={FileText}
            railTitle="Your timesheet"
            railSub={sheet.work_date}
            steps={steps.map((item) => ({ ...item, disabled: busy }))}
            stepIndex={step}
            onStepClick={(index) => {
                if (!busy && (index <= step || rows.length > 0)) setStep(index);
            }}
            railExtra={
                <p role="status" className="mt-4 text-sm text-muted-foreground">
                    {saved
                        ? 'Time split saved to your draft.'
                        : dirty
                          ? 'You have unsaved changes.'
                          : sheet.status === 'submitted'
                            ? 'Submitted for approval'
                            : 'Draft timesheet'}
                </p>
            }
            footerStart={
                <Button variant="outline" disabled={busy} onClick={close}>
                    Close
                </Button>
            }
            footerEnd={
                <>
                    {editable && sheet.can_save_allocation !== false && (
                        <Button
                            variant="outline"
                            disabled={busy || rows.length === 0}
                            onClick={() => void save(false)}
                        >
                            {busy ? 'Saving…' : 'Save draft'}
                        </Button>
                    )}
                    {step > 0 && (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setStep(step - 1)}
                        >
                            Back
                        </Button>
                    )}
                    {step < 2 ? (
                        <Button
                            disabled={busy || rows.length === 0}
                            onClick={() => setStep(step + 1)}
                        >
                            Continue
                        </Button>
                    ) : (
                        editable && (
                            <Button
                                disabled={
                                    busy || !valid || sheet.can_submit === false
                                }
                                onClick={() => void save(true)}
                            >
                                {sheet.status === 'returned'
                                    ? 'Resubmit for approval'
                                    : 'Submit for approval'}
                            </Button>
                        )
                    )}
                </>
            }
            success={
                success ? (
                    <WizardSuccessPane
                        title="Timesheet submitted"
                        blurb={`${total.toFixed(2)} paid hours shared between ${rows.length} ${rows.length === 1 ? 'person' : 'people'}. Your timesheet is ready for approval.`}
                        actions={
                            <Button onClick={finishClose}>
                                Back to My Day
                            </Button>
                        }
                    />
                ) : undefined
            }
        >
            <div className="space-y-5">
                {context}
                {sheet.clock_running && (
                    <p className="rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning">
                        You are still clocked in. You can save a draft split
                        now. Finish your shift, then review the final hours
                        before submitting.
                    </p>
                )}
                {sheet.return_notes && (
                    <p className="rounded-lg bg-status-warning-bg p-3 text-sm">
                        Changes requested: {sheet.return_notes}
                    </p>
                )}
                {error && (
                    <p
                        role="alert"
                        className="rounded-lg bg-status-critical-bg p-3 text-sm text-status-critical"
                    >
                        {error}
                    </p>
                )}
                {closing && (
                    <div
                        role="alert"
                        className="space-y-3 rounded-lg border p-4"
                    >
                        <h3 className="font-semibold">Keep your changes?</h3>
                        <p className="text-sm">
                            Save this split to your draft so you can return to
                            it later.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {sheet.can_save_allocation !== false && (
                                <Button
                                    disabled={busy || !rows.length}
                                    onClick={() => void save(false, true)}
                                >
                                    Save draft & close
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setClosing(false)}
                            >
                                Keep editing
                            </Button>
                            <Button
                                variant="ghost"
                                disabled={busy}
                                onClick={finishClose}
                            >
                                Discard changes
                            </Button>
                        </div>
                    </div>
                )}
                <fieldset
                    disabled={!editable || busy || closing}
                    className="space-y-4"
                >
                    {step === 0 && (
                        <>
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-semibold">
                                        Who did you support?
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Select everyone you supported during
                                        this shift.
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        choosePeople(
                                            candidates.map(
                                                (person) => person.id,
                                            ),
                                        )
                                    }
                                    disabled={!candidates.length}
                                >
                                    Select everyone
                                </Button>
                            </div>
                            {candidates.map((person) => (
                                <label
                                    key={person.id}
                                    className="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border p-3"
                                >
                                    <Checkbox
                                        checked={rows.some(
                                            (row) =>
                                                row.client_id === person.id,
                                        )}
                                        onCheckedChange={(checked) =>
                                            choosePeople(
                                                checked
                                                    ? [
                                                          ...rows.map(
                                                              (row) =>
                                                                  row.client_id,
                                                          ),
                                                          person.id,
                                                      ]
                                                    : rows
                                                          .filter(
                                                              (row) =>
                                                                  row.client_id !==
                                                                  person.id,
                                                          )
                                                          .map(
                                                              (row) =>
                                                                  row.client_id,
                                                          ),
                                            )
                                        }
                                    />
                                    <span>{person.name}</span>
                                </label>
                            ))}
                            {!candidates.length && (
                                <p className="text-sm text-muted-foreground">
                                    No people are available on this timesheet’s
                                    roster. Check the shift details with your
                                    coordinator.
                                </p>
                            )}
                            {rows
                                .filter(
                                    (row) =>
                                        !candidates.some(
                                            (person) =>
                                                person.id === row.client_id,
                                        ),
                                )
                                .map((row) => (
                                    <div
                                        key={row.client_id}
                                        className="rounded-lg border p-3 text-sm"
                                    >
                                        A saved person is no longer eligible for
                                        this timesheet.{' '}
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                choosePeople(
                                                    rows
                                                        .filter(
                                                            (item) =>
                                                                item.client_id !==
                                                                row.client_id,
                                                        )
                                                        .map(
                                                            (item) =>
                                                                item.client_id,
                                                        ),
                                                )
                                            }
                                        >
                                            Remove unavailable person
                                        </Button>
                                    </div>
                                ))}
                            <p className="text-sm font-medium">
                                {rows.length}{' '}
                                {rows.length === 1 ? 'person' : 'people'}{' '}
                                selected
                            </p>
                        </>
                    )}
                    {step === 1 && (
                        <>
                            <h2 className="text-lg font-semibold">
                                How was your time shared?
                            </h2>
                            {rows.length > 1 && (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant={equal ? 'default' : 'outline'}
                                        onClick={() =>
                                            changeMethod(
                                                sheet.is_residential_billable
                                                    ? 'residential_house'
                                                    : 'equal_split',
                                            )
                                        }
                                    >
                                        Split evenly
                                    </Button>
                                    <Button
                                        variant={
                                            method === 'manual'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => changeMethod('manual')}
                                    >
                                        Enter time per person
                                    </Button>
                                    <Button
                                        variant={
                                            segmented ? 'default' : 'outline'
                                        }
                                        onClick={() =>
                                            changeMethod('time_segmented')
                                        }
                                    >
                                        Separate support periods
                                    </Button>
                                </div>
                            )}
                            <p className="text-sm text-muted-foreground">
                                {segmented
                                    ? 'For separate one-to-one periods. Leave unpaid breaks out. Periods must not overlap. For repeated visits to the same person, use “Enter time per person” and add their hours together.'
                                    : equal
                                      ? 'The same paid time is shared evenly, including any rounding remainder.'
                                      : 'Enter each person’s share of the paid time. For shared support, divide the time between the people supported.'}
                            </p>
                            {rows.map((row) => (
                                <section
                                    key={row.client_id}
                                    className="space-y-3 rounded-lg border p-4"
                                    aria-label={`Time for ${row.name}`}
                                >
                                    <h3 className="font-semibold">
                                        {row.name}
                                    </h3>
                                    {segmented && (
                                        <div className="grid grid-cols-2 gap-3">
                                            <AllocationTime
                                                label={`Start for ${row.name}`}
                                                value={row.starts_at}
                                                onChange={(value) =>
                                                    update(row.client_id, {
                                                        starts_at: value,
                                                    })
                                                }
                                            />
                                            <AllocationTime
                                                label={`End for ${row.name}`}
                                                value={row.ends_at}
                                                onChange={(value) =>
                                                    update(row.client_id, {
                                                        ends_at: value,
                                                    })
                                                }
                                            />
                                        </div>
                                    )}
                                    <div>
                                        <Label
                                            htmlFor={`hours-${row.client_id}`}
                                        >
                                            {sheet.clock_running
                                                ? 'Draft hours'
                                                : 'Paid hours'}{' '}
                                            for {row.name}
                                        </Label>
                                        <Input
                                            id={`hours-${row.client_id}`}
                                            type="number"
                                            min="0"
                                            max={total}
                                            step="0.01"
                                            value={row.hours}
                                            disabled={
                                                equal ||
                                                segmented ||
                                                rows.length === 1
                                            }
                                            onChange={(event) =>
                                                update(row.client_id, {
                                                    hours: event.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label
                                            htmlFor={`allocation-note-${row.client_id}`}
                                        >
                                            Note (optional)
                                        </Label>
                                        <Textarea
                                            id={`allocation-note-${row.client_id}`}
                                            rows={2}
                                            maxLength={2000}
                                            value={row.notes}
                                            onChange={(event) =>
                                                update(row.client_id, {
                                                    notes: event.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                </section>
                            ))}
                        </>
                    )}
                </fieldset>
                {step === 2 && (
                    <section className="space-y-3">
                        <h2 className="text-lg font-semibold">
                            Check your time split
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {rows.length}{' '}
                            {rows.length === 1 ? 'person' : 'people'} ·{' '}
                            {equal
                                ? 'Split evenly'
                                : segmented
                                  ? 'Separate support periods'
                                  : rows.length === 1
                                    ? 'One person'
                                    : 'Time entered per person'}
                        </p>
                        {rows.map((row) => (
                            <div
                                key={row.client_id}
                                className="rounded-lg border p-3"
                            >
                                <div className="flex justify-between gap-3 text-sm">
                                    <strong>{row.name}</strong>
                                    <span>
                                        {(Number(row.hours) || 0).toFixed(2)}h
                                    </span>
                                </div>
                                {segmented && (
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {formatDateTime(row.starts_at)} –{' '}
                                        {formatDateTime(row.ends_at)}
                                    </p>
                                )}
                                {row.notes && (
                                    <p className="mt-2 text-sm whitespace-pre-wrap">
                                        {row.notes}
                                    </p>
                                )}
                            </div>
                        ))}
                    </section>
                )}
                {step > 0 && (
                    <p
                        role="status"
                        className={`rounded-lg p-3 text-sm ${balanced ? 'bg-status-success-bg text-status-success' : 'bg-status-warning-bg text-status-warning'}`}
                    >
                        {allocated.toFixed(2)} of {total.toFixed(2)} paid hours
                        allocated.
                        {!balanced &&
                            ` ${Math.abs(total - allocated).toFixed(2)}h ${total > allocated ? 'still to share' : 'over the total'}.`}
                    </p>
                )}
            </div>
        </WizardShell>
    );
}

function AllocationTime({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    const isInstant = /Z$|[+-]\d\d:\d\d$/.test(value);
    const wall = isInstant ? toDatetimeLocal(value) : value;
    const choices = allocationTimeChoices(wall);
    return (
        <div className="space-y-2">
            <Label>{label} · NZ time</Label>
            <Input
                type="datetime-local"
                aria-label={label}
                value={wall}
                onChange={(event) => {
                    const possible = allocationTimeChoices(event.target.value);
                    onChange(
                        possible.length === 1
                            ? possible[0]
                            : event.target.value,
                    );
                }}
            />
            {wall && !choices.length && (
                <p className="text-sm text-status-warning">
                    This local time does not exist. Check the daylight-saving
                    change.
                </p>
            )}
            {choices.length > 1 && (
                <div className="space-y-2 text-sm">
                    <p>The clock repeats this time. Choose which occurrence:</p>
                    {choices.map((iso, index) => (
                        <Button
                            key={iso}
                            type="button"
                            size="sm"
                            variant={value === iso ? 'default' : 'outline'}
                            onClick={() => onChange(iso)}
                        >
                            {index === 0
                                ? 'First occurrence'
                                : 'Second occurrence'}
                        </Button>
                    ))}
                </div>
            )}
        </div>
    );
}
