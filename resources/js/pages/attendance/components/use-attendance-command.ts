import { router } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export type AttendanceResult = {
    action: 'clock_in' | 'clock_out' | 'correct' | 'end_session';
    session_id: number;
    shift_id: number | null;
    session_status: 'open' | 'closed';
    clock_in_at: string;
    clock_out_at: string | null;
    break_minutes: number;
    worked_hours: number | null;
    timesheet_sync_outcome:
        | 'none'
        | 'created'
        | 'updated'
        | 'skipped_follow_up';
    timesheet_id: number | null;
    timesheet_status: string | null;
    handover_outcome:
        | null
        | 'draft_saved'
        | 'existing_submitted_or_acknowledged'
        | 'no_shift'
        | 'no_payload';
};
export type AttendanceExpectation = {
    action: AttendanceResult['action'];
    sessionId?: number;
    explicitShiftId?: number | null;
    end?: string;
};
export type AttendanceOutcome =
    | { status: 'confirmed'; receipt: AttendanceResult }
    | { status: 'rejected' | 'unknown'; message: string };

const UNKNOWN =
    'The save result could not be confirmed. It may already have saved. Your entries are kept here. Check attendance before starting another attempt.';
const positiveId = (value: unknown) =>
    Number.isSafeInteger(value) && Number(value) > 0;
const instant = (value: unknown): value is string =>
    typeof value === 'string' &&
    /(?:Z|[+-]\d{2}:\d{2})$/.test(value) &&
    Number.isFinite(Date.parse(value));

export function attendanceReceipt(
    flash: unknown,
    expected: AttendanceExpectation,
): AttendanceOutcome {
    const data = flash as {
        error?: unknown;
        warning?: unknown;
        attendance_result?: Partial<AttendanceResult>;
    } | null;
    if (data?.attendance_result != null && (data?.error || data?.warning))
        return { status: 'unknown', message: UNKNOWN };
    if (typeof data?.error === 'string' && data.error.trim())
        return { status: 'rejected', message: data.error };
    const r = data?.attendance_result;
    const synced =
        r?.timesheet_sync_outcome === 'created' ||
        r?.timesheet_sync_outcome === 'updated';
    const open = expected.action === 'clock_in';
    if (
        !data?.error &&
        !data?.warning &&
        r &&
        r.action === expected.action &&
        positiveId(r.session_id) &&
        (expected.sessionId === undefined ||
            r.session_id === expected.sessionId) &&
        (r.shift_id === null || positiveId(r.shift_id)) &&
        (expected.explicitShiftId == null ||
            r.shift_id === expected.explicitShiftId) &&
        r.session_status === (open ? 'open' : 'closed') &&
        instant(r.clock_in_at) &&
        (open
            ? r.clock_out_at === null
            : instant(r.clock_out_at) &&
              Date.parse(r.clock_out_at) > Date.parse(r.clock_in_at)) &&
        (!expected.end ||
            (instant(r.clock_out_at) &&
                Date.parse(r.clock_out_at) === Date.parse(expected.end))) &&
        Number.isSafeInteger(r.break_minutes) &&
        Number(r.break_minutes) >= 0 &&
        (open
            ? r.worked_hours === null
            : typeof r.worked_hours === 'number' &&
              Number.isFinite(r.worked_hours) &&
              r.worked_hours >= 0) &&
        ['none', 'created', 'updated', 'skipped_follow_up'].includes(
            r.timesheet_sync_outcome ?? '',
        ) &&
        (!open || r.timesheet_sync_outcome === 'none') &&
        (synced
            ? positiveId(r.timesheet_id) &&
              typeof r.timesheet_status === 'string' &&
              r.timesheet_status.trim() !== ''
            : r.timesheet_id === null && r.timesheet_status === null) &&
        (expected.action === 'clock_out'
            ? [
                  'draft_saved',
                  'existing_submitted_or_acknowledged',
                  'no_shift',
                  'no_payload',
              ].includes(r.handover_outcome ?? '') &&
              (r.handover_outcome === 'no_shift'
                  ? r.shift_id === null
                  : positiveId(r.shift_id))
            : r.handover_outcome === null)
    )
        return { status: 'confirmed', receipt: r as AttendanceResult };
    return { status: 'unknown', message: UNKNOWN };
}

export function attendanceTimesheetMessage(receipt: AttendanceResult): string {
    switch (receipt.timesheet_sync_outcome) {
        case 'created':
            return (
                'Draft timesheet #' +
                receipt.timesheet_id +
                ' was created for payroll review.'
            );
        case 'updated':
            return (
                'Timesheet #' +
                receipt.timesheet_id +
                ' was updated (' +
                receipt.timesheet_status +
                ') for payroll review.'
            );
        case 'skipped_follow_up':
            return 'Attendance was saved, but the timesheet was not changed. Ask a coordinator to review the payroll follow-up.';
        default:
            return 'No timesheet change was confirmed for this action.';
    }
}

/** A redirect alone is not a command receipt. Uncertain writes are never replayed automatically. */
export function useAttendanceCommand(context: string) {
    const [pending, setPending] = useState(false);
    const [outcome, setOutcome] = useState<AttendanceOutcome | null>(null);
    const busy = useRef(false);
    const held = useRef(false);
    const current = useRef(true);
    const currentContext = useRef(context);
    useLayoutEffect(() => {
        currentContext.current = context;
    }, [context]);
    useEffect(() => {
        current.current = true;
        return () => {
            current.current = false;
        };
    }, []);
    const submit = (
        url: string,
        payload: Parameters<typeof router.post>[1],
        expected: AttendanceExpectation,
    ) => {
        if (!current.current || busy.current || held.current) return;
        busy.current = true;
        setPending(true);
        setOutcome(null);
        const submittedContext = context;
        let result: AttendanceOutcome | null = null;
        let finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            busy.current = false;
            const next: AttendanceOutcome =
                submittedContext !== currentContext.current
                    ? {
                          status: 'unknown',
                          message:
                              'The session shown has changed. Check the original session before taking another action.',
                      }
                    : (result ?? { status: 'unknown', message: UNKNOWN });
            held.current = next.status !== 'rejected';
            if (current.current) {
                setOutcome(next);
                setPending(false);
            }
        };
        try {
            router.post(url, payload, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if (!finished && !result)
                        result = attendanceReceipt(page.props.flash, expected);
                },
                onError: (errors) => {
                    if (finished || result) return;
                    const message = Object.values(errors)
                        .filter(Boolean)
                        .join(' ');
                    result = message
                        ? { status: 'rejected', message }
                        : { status: 'unknown', message: UNKNOWN };
                },
                onCancel: () => {
                    if (!finished && !result)
                        result = { status: 'unknown', message: UNKNOWN };
                },
                onFinish: finish,
            });
        } catch {
            finish();
        }
    };
    return {
        submit,
        clearRejected: () => {
            if (!busy.current && !held.current) setOutcome(null);
        },
        pending,
        outcome,
        busy,
        blocked:
            pending ||
            outcome?.status === 'unknown' ||
            outcome?.status === 'confirmed',
        receipt: outcome?.status === 'confirmed' ? outcome.receipt : null,
    };
}
