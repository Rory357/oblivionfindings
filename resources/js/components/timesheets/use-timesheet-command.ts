import { router } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export type TimesheetAction =
    | 'create'
    | 'update'
    | 'submit'
    | 'resubmit'
    | 'approve'
    | 'reject'
    | 'return';
export type TimesheetReceipt = {
    action: TimesheetAction;
    actor_id: number;
    timesheet_id: number;
    user_id: number;
    shift_id: number | null;
    effective_site_id: number | null;
    client_id: number | null;
    site_id: number | null;
    shift_site_id: number | null;
    sleepover: boolean;
    on_call: boolean;
    status: string;
    changed: boolean;
    outcome: 'saved' | 'already_in_state';
    values_hash: string;
    work_date: string;
    starts_at: string;
    ends_at: string;
    break_minutes: number;
    total_hours: string;
    submitted_by: number | null;
    submitted_at: string | null;
    approved_by: number | null;
    approved_at: string | null;
    returned_by: number | null;
    returned_at: string | null;
    submit_requested?: boolean;
};
export type TimesheetExpectation = {
    action: TimesheetAction;
    actorId: number;
    timesheetId?: number;
    ownerId?: number;
    clientId?: number | null;
    sourceFlags?: { sleepover: boolean; on_call: boolean };
    shiftId: number | null;
    status: string;
    submitRequested?: boolean;
    values: Record<string, unknown>;
};
export type TimesheetOutcome =
    | { status: 'confirmed'; receipt: TimesheetReceipt }
    | {
          status: 'rejected' | 'unknown';
          message: string;
          errors?: Record<string, string>;
      };
export const UNKNOWN_TIMESHEET_RESULT =
    'The result could not be confirmed. This may already have saved. Your entries are kept here. Check the current record before starting another attempt.';
const edges = String.raw`[\s\u0000\u0085\u00ad\u034f\u061c\u115f\u1160\u17b4\u17b5\u180e\u200b-\u200f\u2060-\u2065\u206a-\u206f\u2800\u3164\uffa0\u{1d159}\u{1d173}-\u{1d17a}\u{e0020}]`;
export function timesheetText(value: string | null | undefined): string | null {
    // eslint-disable-next-line no-misleading-character-class -- Exact Laravel edge-trim code points.
    const expression = new RegExp(`^${edges}+|${edges}+$`, 'gu');
    return (value ?? '').replace(expression, '') || null;
}
export function timesheetInstant(value: string): string {
    const at = new Date(value);
    if (!Number.isFinite(at.getTime()))
        throw new Error('Choose a valid work date and time.');
    at.setUTCMilliseconds(0);
    return at.toISOString();
}
/** Decimal half-up normalization happens before dispatch, so transport and receipt agree. */
export function timesheetMileage(value: string | number | null): string | null {
    if (value === null) return null;
    const text = String(value);
    if (!/^\d+(?:\.\d+)?$/.test(text))
        throw new Error('Enter a valid non-negative mileage.');
    const [whole, fraction = ''] = text.split('.');
    let cents = BigInt(whole) * 100n + BigInt((fraction + '00').slice(0, 2));
    if (Number(fraction[2] ?? '0') >= 5) cents += 1n;
    return `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
}
export type TimesheetEditableValues = {
    work_date: string;
    starts_at: string;
    ends_at: string;
    break_minutes: number;
    mileage_km: string | null;
    allowance_notes: string | null;
    public_holiday: boolean;
    notes: string | null;
    is_residential_billable: boolean;
    client_id: number | null;
    site_id?: number | null;
    activity_type?: string | null;
    sleepover: boolean;
    on_call: boolean;
    activity_items?: string[];
};
export function timesheetEditProjection(
    action: 'create' | 'update' | 'resubmit',
    values: TimesheetEditableValues,
    linked: boolean,
) {
    const manual = linked
        ? null
        : action === 'create'
          ? {
                client_id: values.client_id,
                site_id: values.site_id ?? null,
                activity_type: values.activity_type ?? null,
                sleepover: values.sleepover,
                on_call: values.on_call,
            }
          : {
                client_id: values.client_id,
                sleepover: values.sleepover,
                on_call: values.on_call,
            };
    return {
        work_date: values.work_date,
        starts_at: timesheetInstant(values.starts_at),
        ends_at: timesheetInstant(values.ends_at),
        break_minutes: values.break_minutes,
        mileage_km: values.mileage_km,
        allowance_notes: values.allowance_notes,
        public_holiday: values.public_holiday,
        notes: values.notes,
        is_residential_billable: values.is_residential_billable,
        manual,
        ...(action === 'create'
            ? { activity_items: linked ? null : (values.activity_items ?? []) }
            : {}),
    };
}
export async function timesheetHash(values: Record<string, unknown>) {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(JSON.stringify(values)),
    );
    return Array.from(new Uint8Array(digest), (value) =>
        value.toString(16).padStart(2, '0'),
    ).join('');
}
const positive = (value: unknown) =>
    Number.isSafeInteger(value) && Number(value) > 0;
const nullableId = (value: unknown) => value === null || positive(value);
const instant = (value: unknown) =>
    typeof value === 'string' &&
    /(?:Z|[+-]\d{2}:\d{2})$/.test(value) &&
    Number.isFinite(Date.parse(value));
export function timesheetReceipt(
    flash: unknown,
    expected: TimesheetExpectation,
    hash: string,
    unchangedHash?: string,
): TimesheetOutcome {
    const f = flash as {
        error?: unknown;
        warning?: unknown;
        timesheet_result?: Partial<TimesheetReceipt>;
    } | null;
    const r = f?.timesheet_result;
    const review = ['approve', 'reject', 'return'].includes(expected.action);
    const provenance =
        r &&
        ['submitted', 'approved', 'returned'].every((prefix) => {
            const by = r[`${prefix}_by` as keyof TimesheetReceipt];
            const at = r[`${prefix}_at` as keyof TimesheetReceipt];
            return nullableId(by) && (at === null || instant(at));
        });
    const decisionPrefix =
        expected.action === 'approve' || expected.action === 'reject'
            ? 'approved'
            : expected.action === 'return'
              ? 'returned'
              : expected.action === 'submit' ||
                  expected.action === 'resubmit' ||
                  (expected.action === 'create' && expected.submitRequested)
                ? 'submitted'
                : null;
    // A changed decision must carry the persisted attribution, not just a status label.
    const actionProvenance =
        !r?.changed ||
        !decisionPrefix ||
        (r[`${decisionPrefix}_by` as keyof TimesheetReceipt] ===
            expected.actorId &&
            instant(r[`${decisionPrefix}_at` as keyof TimesheetReceipt]));
    if (
        !f?.error &&
        !f?.warning &&
        r &&
        provenance &&
        actionProvenance &&
        positive(expected.actorId) &&
        r.actor_id === expected.actorId &&
        r.action === expected.action &&
        positive(r.timesheet_id) &&
        positive(r.user_id) &&
        (expected.action === 'create'
            ? expected.timesheetId === undefined
            : positive(expected.timesheetId) &&
              r.timesheet_id === expected.timesheetId) &&
        (expected.ownerId === undefined || r.user_id === expected.ownerId) &&
        (expected.clientId === undefined ||
            r.client_id === expected.clientId) &&
        (!expected.sourceFlags ||
            (r.sleepover === expected.sourceFlags.sleepover &&
                r.on_call === expected.sourceFlags.on_call)) &&
        r.shift_id === expected.shiftId &&
        nullableId(r.shift_id) &&
        nullableId(r.client_id) &&
        nullableId(r.site_id) &&
        nullableId(r.shift_site_id) &&
        nullableId(r.effective_site_id) &&
        r.status === expected.status &&
        typeof r.changed === 'boolean' &&
        r.outcome === (r.changed ? 'saved' : 'already_in_state') &&
        (expected.action !== 'create' ||
            (r.changed && r.submit_requested === expected.submitRequested)) &&
        (['update', 'resubmit', 'submit'].includes(expected.action)
            ? r.changed
            : true) &&
        r.values_hash === (!r.changed && review ? unchangedHash : hash) &&
        typeof r.values_hash === 'string' &&
        /^[a-f0-9]{64}$/.test(r.values_hash) &&
        typeof r.work_date === 'string' &&
        /^\d{4}-\d{2}-\d{2}$/.test(r.work_date) &&
        instant(r.starts_at) &&
        instant(r.ends_at) &&
        Number.isInteger(r.break_minutes) &&
        Number(r.break_minutes) >= 0 &&
        typeof r.total_hours === 'string' &&
        /^\d+(?:\.\d+)?$/.test(r.total_hours)
    )
        return { status: 'confirmed', receipt: r as TimesheetReceipt };
    return { status: 'unknown', message: UNKNOWN_TIMESHEET_RESULT };
}
const fields = new Set([
    'mode',
    'shift_id',
    'activity_type',
    'activity_items',
    'client_id',
    'site_id',
    'work_date',
    'starts_at',
    'ends_at',
    'break_minutes',
    'mileage_km',
    'sleepover',
    'on_call',
    'allowance_notes',
    'public_holiday',
    'notes',
    'is_residential_billable',
    'submit',
    'decision_notes',
    'returned_notes',
]);
/** A missing or interrupted confirmation never automatically replays a write. */
export function useTimesheetCommand(context: string) {
    const [pending, setPending] = useState(false);
    const [outcome, setOutcome] = useState<TimesheetOutcome | null>(null);
    const busy = useRef(false),
        held = useRef(false),
        mounted = useRef(true),
        attempted = useRef(false);
    const identity = useRef(context),
        generation = useRef(0);
    useLayoutEffect(() => {
        if (identity.current === context) return;
        identity.current = context;
        generation.current += 1;
        if (attempted.current) {
            held.current = true;
            setOutcome({
                status: 'unknown',
                message:
                    'The record or signed-in user has changed. Check the original record before another action.',
            });
        }
    }, [context]);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    const submit = async (
        method: 'post' | 'put',
        url: string,
        payload: Parameters<typeof router.post>[1],
        expected: TimesheetExpectation,
    ) => {
        if (!mounted.current || busy.current || held.current) return;
        busy.current = true;
        attempted.current = true;
        setPending(true);
        setOutcome(null);
        const sentContext = context,
            sentGeneration = generation.current;
        let result: TimesheetOutcome | null = null,
            finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            busy.current = false;
            const next =
                sentContext !== identity.current ||
                sentGeneration !== generation.current
                    ? {
                          status: 'unknown' as const,
                          message:
                              'The record or signed-in user has changed. Check the original record before another action.',
                      }
                    : (result ?? {
                          status: 'unknown' as const,
                          message: UNKNOWN_TIMESHEET_RESULT,
                      });
            held.current = next.status !== 'rejected';
            if (mounted.current) {
                setOutcome(next);
                setPending(false);
            }
        };
        let hash: string, unchangedHash: string | undefined;
        try {
            hash = await timesheetHash(expected.values);
            if (['approve', 'reject', 'return'].includes(expected.action))
                unchangedHash = await timesheetHash({
                    timesheet_id: expected.timesheetId,
                    action: expected.action,
                });
        } catch {
            result = {
                status: 'rejected',
                message:
                    'Save confirmation is unavailable in this browser. No request was sent. Keep your draft and use a secure browser connection.',
            };
            finish();
            return;
        }
        if (
            !mounted.current ||
            sentContext !== identity.current ||
            sentGeneration !== generation.current
        ) {
            finish();
            return;
        }
        try {
            router[method](url, payload, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if (!finished && !result)
                        result = timesheetReceipt(
                            page.props.flash,
                            expected,
                            hash,
                            unchangedHash,
                        );
                },
                onError: (errors) => {
                    if (finished || result) return;
                    const entries = Object.entries(errors);
                    const validation =
                        entries.length > 0 &&
                        entries.every(
                            ([key, value]) =>
                                fields.has(key.split('.')[0]) &&
                                typeof value === 'string' &&
                                value.length > 0,
                        );
                    result = validation
                        ? {
                              status: 'rejected',
                              message:
                                  'Review the highlighted fields. Your entries are kept.',
                              errors: errors as Record<string, string>,
                          }
                        : {
                              status: 'unknown',
                              message: UNKNOWN_TIMESHEET_RESULT,
                          };
                },
                onCancel: () => {
                    if (!finished && !result)
                        result = {
                            status: 'unknown',
                            message: UNKNOWN_TIMESHEET_RESULT,
                        };
                },
                onFinish: finish,
            });
        } catch {
            finish();
        }
    };
    return { submit, pending, outcome, busy, held };
}
