import { router } from '@inertiajs/react';
import axios from 'axios';
import { useLayoutEffect, useRef, useState } from 'react';

export type BreakContext = {
    actorId: number | null;
    sessionId: number | null;
    shiftId: number | null;
    canAct: boolean;
    onBreak: boolean;
};
type BreakAction = 'start_break' | 'end_break';
export type BreakResult = {
    schema_version: 1;
    scope: 'attendance_break_recorded_result';
    action: BreakAction;
    actor_id: number;
    session: {
        id: number;
        shift_id: number | null;
        status: 'open';
        break_started_at: string | null;
        break_minutes: number;
        break_count: number;
    };
    break_event: {
        id: number;
        session_id: number;
        started_at: string;
        ended_at: string | null;
        minutes: number | null;
    };
};
export type BreakOutcome =
    | { status: 'confirmed'; receipt: BreakResult; refreshed: boolean }
    | { status: 'unknown' | 'rejected' | 'unavailable'; message: string };

const UNKNOWN =
    'The break result could not be confirmed. It may already have saved. Check your current attendance before trying again.';
const UNAVAILABLE =
    'Your session or access has changed. Check your current attendance before taking another break action.';
const id = (value: unknown) => Number.isSafeInteger(value) && Number(value) > 0;
const count = (value: unknown) =>
    Number.isSafeInteger(value) && Number(value) >= 0;
const instant = (value: unknown): value is string =>
    typeof value === 'string' &&
    /(?:Z|[+-]\d{2}:\d{2})$/.test(value) &&
    Number.isFinite(Date.parse(value));
const key = (context: BreakContext) =>
    JSON.stringify([
        context.actorId,
        context.sessionId,
        context.shiftId,
        context.canAct,
    ]);

export function readBreakResult(
    value: unknown,
    expected: BreakContext,
    action: BreakAction,
): BreakResult | null {
    const r = value as Partial<BreakResult> | null;
    const s = r?.session;
    const e = r?.break_event;
    if (
        r?.schema_version !== 1 ||
        r.scope !== 'attendance_break_recorded_result' ||
        r.action !== action ||
        !id(r.actor_id) ||
        r.actor_id !== expected.actorId ||
        !s ||
        !id(s.id) ||
        s.id !== expected.sessionId ||
        s.shift_id !== expected.shiftId ||
        !(s.shift_id === null || id(s.shift_id)) ||
        s.status !== 'open' ||
        !count(s.break_minutes) ||
        !count(s.break_count) ||
        !e ||
        !id(e.id) ||
        e.session_id !== s.id ||
        !instant(e.started_at)
    )
        return null;
    if (action === 'start_break') {
        if (
            !instant(s.break_started_at) ||
            Date.parse(s.break_started_at) !== Date.parse(e.started_at) ||
            e.ended_at !== null ||
            e.minutes !== 0 ||
            !id(s.break_count)
        )
            return null;
    } else if (
        s.break_started_at !== null ||
        !instant(e.ended_at) ||
        Date.parse(e.ended_at) < Date.parse(e.started_at) ||
        !count(e.minutes) ||
        Number(e.minutes) > s.break_minutes
    ) {
        return null;
    }
    return r as BreakResult;
}

/** One request owner; a current-state refresh never proves a lost write. */
export function useAttendanceBreak(
    context: BreakContext,
    readRefresh: (props: unknown) => BreakContext | null,
) {
    const [pending, setPending] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [outcome, setOutcome] = useState<BreakOutcome | null>(null);
    const busy = useRef(false);
    const held = useRef(false);
    const alive = useRef(false);
    const revision = useRef(0);
    const currentKey = useRef(key(context));
    const ownerKey = useRef<string | null>(null);
    const ownerSessionId = useRef<number | null>(null);
    const retired = useRef(false);
    const refreshBusy = useRef(false);
    const contextKey = key(context);
    useLayoutEffect(() => {
        // A counter ref, not a DOM node: unmount retires any reply still in flight.
        const attempts = revision;
        alive.current = true;
        return () => {
            alive.current = false;
            attempts.current++;
        };
    }, []);
    useLayoutEffect(() => {
        if (currentKey.current !== contextKey) {
            currentKey.current = contextKey;
            revision.current++;
            if (ownerKey.current !== null && (busy.current || held.current)) {
                retired.current = true;
                held.current = true;
                setOutcome({ status: 'unavailable', message: UNAVAILABLE });
            } else if (!retired.current) {
                ownerKey.current = null;
                ownerSessionId.current = null;
                setOutcome(null);
            }
        }
    }, [contextKey]);

    const refresh = (receipt: BreakResult) => {
        if (!alive.current || retired.current || refreshBusy.current) return;
        refreshBusy.current = true;
        setRefreshing(true);
        const submittedRevision = revision.current;
        const expectedKey = currentKey.current;
        let finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            refreshBusy.current = false;
            if (alive.current) setRefreshing(false);
        };
        try {
            router.reload({
                onSuccess: (page) => {
                    if (
                        finished ||
                        !alive.current ||
                        retired.current ||
                        revision.current !== submittedRevision
                    )
                        return;
                    let fresh: BreakContext | null = null;
                    try {
                        fresh = readRefresh(page.props);
                    } catch {
                        // A malformed/changed page is not a usable current read.
                    }
                    if (
                        fresh &&
                        key(fresh) === expectedKey &&
                        fresh.onBreak === (receipt.action === 'start_break')
                    ) {
                        held.current = false;
                        setOutcome({ status: 'confirmed', receipt, refreshed: true });
                    }
                },
                onFinish: finish,
                onCancel: finish,
                onError: finish,
            });
        } catch {
            finish();
        }
    };
    const submit = async () => {
        if (
            !alive.current ||
            busy.current ||
            held.current ||
            retired.current ||
            contextKey !== currentKey.current ||
            !context.canAct ||
            !id(context.actorId) ||
            !id(context.sessionId)
        )
            return;
        busy.current = true;
        held.current = true;
        ownerKey.current = contextKey;
        ownerSessionId.current = context.sessionId;
        setPending(true);
        setOutcome(null);
        const submittedRevision = revision.current;
        const action: BreakAction = context.onBreak ? 'end_break' : 'start_break';
        let next: BreakOutcome = { status: 'unknown', message: UNKNOWN };
        try {
            const response = await axios.post(
                context.onBreak ? '/attendance/break/end' : '/attendance/break/start',
                { session_id: context.sessionId },
                { headers: { Accept: 'application/json' }, timeout: 30_000 },
            );
            const receipt =
                response.status === 200
                    ? readBreakResult(response.data, context, action)
                    : null;
            if (receipt) next = { status: 'confirmed', receipt, refreshed: false };
        } catch (error) {
            if (
                axios.isAxiosError(error) &&
                error.response?.status === 422 &&
                error.response.data?.code === 'break_rejected'
            ) {
                const errors = error.response.data?.errors;
                const entries =
                    errors && typeof errors === 'object' && !Array.isArray(errors)
                        ? Object.entries(errors)
                        : [];
                if (
                    entries.length > 0 &&
                    entries.every(([name, value]) =>
                        name.trim().length > 0 &&
                        Array.isArray(value) && value.length > 0 &&
                        value.every((entry) => typeof entry === 'string' && entry.trim()),
                    )
                ) {
                    next = {
                        status: 'rejected',
                        message: entries.flatMap(([, value]) => value as string[]).join(' '),
                    };
                }
            }
        } finally {
            busy.current = false;
        }
        if (!alive.current) return;
        setPending(false);
        if (retired.current || revision.current !== submittedRevision) {
            setOutcome({ status: 'unavailable', message: UNAVAILABLE });
            return;
        }
        held.current = next.status !== 'rejected';
        setOutcome(next);
        if (next.status === 'confirmed') refresh(next.receipt);
    };
    const unavailable =
        retired.current ||
        (ownerKey.current !== null && ownerKey.current !== contextKey);
    return {
        submit,
        pending,
        refreshing,
        isBlocked: () =>
            busy.current || held.current || retired.current ||
            contextKey !== currentKey.current || !context.canAct,
        blocksSession: (sessionId: number) =>
            ownerSessionId.current === sessionId &&
            (busy.current || held.current || retired.current),
        outcome: unavailable
            ? ({ status: 'unavailable', message: UNAVAILABLE } as const)
            : outcome,
        blocked: pending || held.current || unavailable || !context.canAct,
        refresh: () => {
            if (outcome?.status === 'confirmed' && !outcome.refreshed)
                refresh(outcome.receipt);
        },
    };
}
