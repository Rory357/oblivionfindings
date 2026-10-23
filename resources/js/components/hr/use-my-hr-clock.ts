import { router } from '@inertiajs/react';
import axios from 'axios';
import { useLayoutEffect, useRef, useState } from 'react';
import {
    myHrClockRequest,
    readMyHrClockResult,
    type MyHrClockExpectation,
    type MyHrClockResult,
} from './my-hr-clock-result';

export type MyHrClockOutcome =
    | { status: 'confirmed'; receipt: MyHrClockResult; refreshed: boolean }
    | { status: 'rejected' | 'unknown' | 'unavailable'; message: string };

const UNKNOWN = 'The clock result could not be confirmed. It may already have saved. Check your current attendance before another clock action.';
const UNAVAILABLE = 'Your clock record or access has changed. Check your current attendance before another clock action.';
const key = (context: MyHrClockExpectation | null) => JSON.stringify(context);

function matchesRecordedRead(receipt: MyHrClockResult, current: MyHrClockExpectation | null): boolean {
    if (!current || current.actorId !== receipt.actor_id) return false;
    if (receipt.action === 'clock_out') return current.target.kind === 'idle';
    return receipt.target_kind === 'attendance_session' &&
        current.target.kind === 'attendance_session' &&
        current.target.id === receipt.attendance_result.session_id &&
        current.target.shiftId === receipt.attendance_result.shift_id;
}

/** Own one clock attempt. Page refreshes may update the view, never prove a lost reply. */
export function useMyHrClock(context: MyHrClockExpectation | null) {
    const [pending, setPending] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [outcome, setOutcome] = useState<MyHrClockOutcome | null>(null);
    const alive = useRef(false);
    const busy = useRef(false);
    const held = useRef(false);
    const retired = useRef(false);
    const refreshBusy = useRef(false);
    const owned = useRef(false);
    const receipt = useRef<MyHrClockResult | null>(null);
    const currentKey = useRef(key(context));
    const contextKey = key(context);
    const revision = useRef(0);

    useLayoutEffect(() => {
        alive.current = true;
        return () => { alive.current = false; revision.current++; };
    }, []);
    useLayoutEffect(() => {
        if (contextKey === currentKey.current) return;
        currentKey.current = contextKey;
        revision.current++;
        if (owned.current && (busy.current || held.current)) {
            if (!retired.current && receipt.current && matchesRecordedRead(receipt.current, context)) {
                held.current = false;
                setOutcome({ status: 'confirmed', receipt: receipt.current, refreshed: true });
            } else {
                retired.current = true;
                held.current = true;
                receipt.current = null;
                setOutcome({ status: 'unavailable', message: UNAVAILABLE });
            }
        } else if (!retired.current) {
            receipt.current = null;
            owned.current = false;
            setOutcome(null);
        }
    }, [context, contextKey]);

    const refresh = () => {
        if (!alive.current || retired.current || !receipt.current || refreshBusy.current) return;
        refreshBusy.current = true;
        setRefreshing(true);
        let finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            refreshBusy.current = false;
            if (alive.current) setRefreshing(false);
        };
        try {
            router.reload({ onFinish: finish, onError: finish, onCancel: finish });
        } catch {
            finish();
        }
    };

    const submit = async (breakMinutes?: number) => {
        if (!alive.current || busy.current || held.current || retired.current || refreshBusy.current ||
            contextKey !== currentKey.current || !context) return;
        if (breakMinutes !== undefined &&
            (!Number.isSafeInteger(breakMinutes) || breakMinutes < 0 || breakMinutes > 240)) {
            setOutcome({ status: 'rejected', message: 'Enter break minutes from 0 to 240.' });
            return;
        }
        busy.current = true;
        held.current = true;
        owned.current = true;
        receipt.current = null;
        setPending(true);
        setOutcome(null);
        const submittedRevision = revision.current;
        let next: MyHrClockOutcome = { status: 'unknown', message: UNKNOWN };
        const request = myHrClockRequest(context, breakMinutes);
        try {
            const response = await axios.post(request.url, request.data, {
                headers: { Accept: 'application/json' }, timeout: 30_000,
            });
            const result = response.status === 200 ? readMyHrClockResult(response.data, context) : null;
            if (result) next = { status: 'confirmed', receipt: result, refreshed: false };
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 422 &&
                error.response.data?.code === 'my_hr_clock_rejected') {
                const errors = error.response.data.errors;
                const entries = errors && typeof errors === 'object' && !Array.isArray(errors)
                    ? Object.entries(errors) : [];
                if (entries.length && entries.every(([name, messages]) =>
                    name.trim() && Array.isArray(messages) && messages.length &&
                    messages.every((message) => typeof message === 'string' && message.trim()))) {
                    next = { status: 'rejected', message: entries.flatMap(([, messages]) => messages as string[]).join(' ') };
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
        if (next.status === 'confirmed') receipt.current = next.receipt;
        setOutcome(next);
        if (next.status === 'confirmed') refresh();
    };

    return {
        submit, refresh, pending, refreshing, outcome,
        blocked: pending || refreshing || held.current || retired.current || !context,
        isBlocked: () => busy.current || refreshBusy.current || held.current || retired.current ||
            contextKey !== currentKey.current || !context,
    };
}
