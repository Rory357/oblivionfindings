import { router } from '@inertiajs/react';
import { useInsertionEffect, useLayoutEffect, useRef, useState } from 'react';
import type { TimesheetWriteLease, TimesheetWriteScope } from './timesheet-write-scope';

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
/** Omit only for existing independent legacy callers. Coordinated owning pages
 * must pass the one stable page scope explicitly; {scope:null} denies dispatch.
 * Scope lifetime and all other writers/reads remain owning-page obligations. */
export function useTimesheetCommand(context: string, coordination?: {
    scope: TimesheetWriteScope | null;
}) {
    const [pending, setPending] = useState(false);
    const [outcome, setOutcome] = useState<TimesheetOutcome | null>(null);
    const busy = useRef(false),
        held = useRef(false),
        mounted = useRef(true),
        attempted = useRef(false);
    const identity = useRef(context), generation = useRef(0);
    const currentCoordination = useRef(coordination), contextChanged = useRef(false);
    type LocalLease = { scope: TimesheetWriteScope; token: TimesheetWriteLease;
        invoked: boolean; retiredNoSend: boolean };
    const activeLease = useRef<LocalLease | null>(null);
    // Publish only a committed source/coordination frame before child layouts.
    // No subscriber or React state updates occur during insertion.
    useInsertionEffect(() => {
        const previous = currentCoordination.current;
        if (identity.current !== context || previous?.scope !== coordination?.scope ||
            (previous === undefined) !== (coordination === undefined)) {
            identity.current = context;
            generation.current += 1;
            contextChanged.current = true;
        }
        currentCoordination.current = coordination;
    }, [context, coordination]);
    useLayoutEffect(() => {
        if (!contextChanged.current) return;
        contextChanged.current = false;
        if (attempted.current) {
            held.current = true;
            const owner = activeLease.current;
            if (owner) owner.scope.holdUnknown(owner.token);
            setOutcome({ status: 'unknown', message:
                'The record or signed-in user has changed. Check the original record before another action.' });
        }
    }, [context, coordination]);
    useLayoutEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            const owner = activeLease.current;
            if (!owner) return;
            // Commit removal prevents this hook's pending preparation dispatch.
            // An already invoked request is never described as not sent.
            if (!owner.invoked) {
                owner.retiredNoSend = owner.scope.completeNoSend(owner.token, 'preparation_exit');
                if (owner.retiredNoSend) activeLease.current = null;
                else owner.scope.holdUnknown(owner.token);
            } else owner.scope.holdUnknown(owner.token);
        };
    }, []);
    const submit = async (
        method: 'post' | 'put',
        url: string,
        payload: Parameters<typeof router.post>[1],
        expected: TimesheetExpectation,
    ) => {
        if (!mounted.current || busy.current || held.current) return;
        // Capture before tryPrepare's synchronous subscriber notifications.
        const sentContext = context, sentGeneration = generation.current;
        const requestedCoordination = currentCoordination.current;
        let lease: LocalLease | null = null;
        // A reentrant acquisition listener cannot submit through this same hook.
        busy.current = true;
        let result: TimesheetOutcome | null = null,
            finished = false;
        const finish = (knownNotSent = false) => {
            if (finished) return;
            finished = true;
            // Keep this hook busy through externally observable scope release.
            // Only a proven preparation exit or exact synchronous before veto proves no send.
            // A context change cannot make a request exist after that proof.
            let noSendProven = knownNotSent;
            let next: TimesheetOutcome =
                !knownNotSent &&
                (sentContext !== identity.current ||
                    sentGeneration !== generation.current)
                    ? {
                          status: 'unknown' as const,
                          message:
                              'The record or signed-in user has changed. Check the original record before another action.',
                      }
                    : (result ?? {
                          status: 'unknown' as const,
                          message: UNKNOWN_TIMESHEET_RESULT,
                      });
            if (lease) {
                const originalContext = mounted.current && sentContext === identity.current &&
                    sentGeneration === generation.current &&
                    currentCoordination.current?.scope === lease.scope;
                const released = knownNotSent
                    ? lease.retiredNoSend || lease.scope.completeNoSend(lease.token,
                        lease.invoked ? 'exact_before_veto' : 'preparation_exit')
                    : originalContext && next.status === 'confirmed' &&
                        lease.scope.completeMatched(lease.token);
                if (released) {
                    if (knownNotSent) lease.retiredNoSend = true;
                    if (activeLease.current === lease) activeLease.current = null;
                } else {
                    noSendProven = false;
                    // Ordinary field errors do not certify that no write committed.
                    // Preserve them for review, but hold the original uncertain attempt.
                    lease.scope.holdUnknown(lease.token);
                    next = { status: 'unknown', message: UNKNOWN_TIMESHEET_RESULT,
                        ...(next.status === 'rejected' || next.status === 'unknown'
                            ? { errors: next.errors } : {}) };
                }
            }
            // A release/hold notification can itself commit a different context.
            // Never overwrite that new frame with a cached confirmed outcome.
            const sameContextAfterNotification = mounted.current &&
                sentContext === identity.current && sentGeneration === generation.current &&
                (lease === null || currentCoordination.current?.scope === lease.scope);
            if (!sameContextAfterNotification) {
                next = noSendProven
                    ? { status: 'rejected', message: result?.status === 'rejected'
                        ? result.message
                        : 'No request was sent. This view changed; return to the original form before another action.' }
                    : { status: 'unknown', message:
                        'The record or signed-in user has changed. Check the original record before another action.',
                        ...(next.status !== 'confirmed' ? { errors: next.errors } : {}) };
            }
            if (noSendProven) attempted.current = false;
            held.current = next.status !== 'rejected';
            busy.current = false;
            if (mounted.current) {
                setOutcome(next);
                setPending(false);
            }
        };
        if (requestedCoordination !== undefined) {
            const scope = requestedCoordination.scope;
            const token = scope?.tryPrepare(expected.actorId) ?? null;
            if (!scope || !token) {
                busy.current = false;
                if (mounted.current && sentContext === identity.current &&
                    sentGeneration === generation.current)
                    setOutcome({ status: 'rejected', message:
                        'Saving is unavailable in this view. Keep your entries and check any earlier timesheet action.' });
                return;
            }
            lease = { scope, token, invoked: false, retiredNoSend: false };
            activeLease.current = lease;
        }
        if (!mounted.current || sentContext !== identity.current ||
            sentGeneration !== generation.current ||
            (lease !== null && (currentCoordination.current?.scope !== lease.scope ||
                !lease.scope.canDispatch(lease.token)))) {
            result = { status: 'rejected', message:
                'No request was sent because this view changed while preparing the action. Your entries are kept.' };
            finish(true);
            return;
        }
        attempted.current = true;
        setPending(true);
        setOutcome(null);
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
            // Hashing failed before any router dispatch could occur.
            finish(true);
            return;
        }
        if (
            !mounted.current ||
            sentContext !== identity.current ||
            sentGeneration !== generation.current ||
            (lease !== null && (currentCoordination.current?.scope !== lease.scope ||
                lease.retiredNoSend || !lease.scope.canDispatch(lease.token)))
        ) {
            // This preparation never reached router dispatch, even when the
            // actor/source context changed and was restored while hashing.
            result = { status: 'rejected', message:
                'No request was sent because the record or your access changed while preparing this action. Your entries are kept.' };
            finish(true);
            return;
        }
        const observed: { visit: unknown; event: Event | null } = {
            visit: null,
            event: null,
        };
        let removeBefore: (() => void) | undefined;
        let started = false, callbackObserved = false,
            dispatching = false, deferredFinish = false;
        try {
            removeBefore = router.on('before', (event) => {
                if (observed.visit !== null && event.detail.visit === observed.visit)
                    observed.event = event;
            });
            if (lease && !lease.scope.markInvoked(lease.token)) {
                result = { status: 'rejected', message:
                    'No request was sent because this view changed while preparing the action. Your entries are kept.' };
                finish(true);
                return;
            }
            if (lease) lease.invoked = true;
            dispatching = true;
            router[method](url, payload, {
                preserveScroll: true,
                preserveState: true,
                onBefore: (visit) => { observed.visit = visit; },
                onStart: () => {
                    started = true;
                    if (!finished && lease) lease.scope.markStarted(lease.token);
                },
                onSuccess: (page) => {
                    callbackObserved = true;
                    if (!finished && lease) lease.scope.markCallback(lease.token);
                    if (!finished && !result)
                        result = timesheetReceipt(
                            page.props.flash,
                            expected,
                            hash,
                            unchangedHash,
                        );
                },
                onError: (errors) => {
                    callbackObserved = true;
                    if (!finished && lease) lease.scope.markCallback(lease.token);
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
                    callbackObserved = true;
                    if (!finished && lease) lease.scope.markCallback(lease.token);
                    if (!finished && !result)
                        result = {
                            status: 'unknown',
                            message: UNKNOWN_TIMESHEET_RESULT,
                        };
                },
                // Inertia passes a visit to onFinish: never pass it as knownNotSent.
                onFinish: () => {
                    callbackObserved = true;
                    if (!finished && lease) lease.scope.markCallback(lease.token);
                    if (dispatching) deferredFinish = true;
                    else finish();
                },
            });
            dispatching = false;
            // This is the same cancelable event, inspected after every synchronous
            // listener has run. Missing/foreign events and late cancellation are
            // not evidence that the request was never sent.
            if (observed.event?.defaultPrevented) {
                const knownNotSent = !started && !callbackObserved && !deferredFinish;
                result = knownNotSent
                    ? { status: 'rejected', message:
                        'This action was cancelled before sending. Your entries are kept. Review your entries, then choose this action again when you are ready.' }
                    : { status: 'unknown', message: UNKNOWN_TIMESHEET_RESULT };
                finish(knownNotSent);
            } else if (deferredFinish) finish();
        } catch {
            dispatching = false;
            const preparationOnly = lease !== null && !lease.invoked;
            result = preparationOnly
                ? { status: 'rejected', message:
                    'No request was sent because preparation could not finish. Your entries are kept.' }
                : { status: 'unknown', message: UNKNOWN_TIMESHEET_RESULT };
            finish(preparationOnly);
        } finally {
            removeBefore?.();
        }
    };
    return { submit, pending, outcome, busy, held };
}
