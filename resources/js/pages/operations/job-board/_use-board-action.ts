import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import {
    actionCommandUrl,
    actionSourceUrl,
    confirmedAction,
    currentActionSummary,
    mayConfirmAction,
    prepareActionIntent,
    readActionSource,
    type ActionConfirmation,
    type ActionIntent,
    type AlertSource,
    type PositionSource,
} from './_action-contract';
import {
    newRequestId,
    readHeaders,
    readJson,
    refusalMessage,
    writeHeaders,
} from './_board-http';

export type PositionAction = 'claim' | 'approve';
export type PositionTarget = { action: PositionAction; positionId: number };

/**
 * Statuses the claim / approve / alert command can only return before
 * anything is saved (validation, a changed shift, an expired session).
 * 403 and 404 can also come from the check the server runs AFTER saving, so
 * those stay "not confirmed", never "nothing saved".
 */
const DEFINITE_REFUSALS = [409, 419, 422] as const;
const CHANGED =
    'This shift changed after you opened it. Its latest details are shown here. Nothing was saved.';

export type BoardActionPhase =
    | { kind: 'loading' }
    | { kind: 'load-error'; message: string }
    | { kind: 'ready'; source: PositionSource; notice: string | null }
    | { kind: 'sending'; intent: ActionIntent }
    | {
          kind: 'confirmed';
          intent: ActionIntent;
          confirmation: ActionConfirmation;
      }
    | {
          kind: 'unknown';
          intent: ActionIntent;
          checking: boolean;
          current: { source: PositionSource; summary: string } | null;
          checkError: string | null;
      };

const readInit = (signal?: AbortSignal): RequestInit => ({
    credentials: 'same-origin',
    cache: 'no-store',
    redirect: 'error',
    signal,
    headers: readHeaders(),
});

async function fetchPositionSource(
    target: PositionTarget,
    actor: number,
    signal?: AbortSignal,
): Promise<PositionSource> {
    const response = await fetch(actionSourceUrl(target), readInit(signal));
    if (response.status === 403 || response.status === 404)
        throw new Error(
            'This shift is no longer available to you, or you no longer have access to it.',
        );
    if (response.status !== 200)
        throw new Error(
            "We couldn't load this shift's details. Check your connection and try again.",
        );
    return readActionSource(
        await response.json(),
        target,
        actor,
    ) as PositionSource;
}

/**
 * The page's one owner for Claim and Approve. It lives in the page (not the
 * dialog) so the dialog can stay mounted, and it holds at most one request.
 *
 * - Every confirm re-reads the shift first and sends that reading's revision
 *   with a new request id; the server refuses a stale one.
 * - A synchronous busy flag blocks a second send while one is in flight.
 * - Only an exactly matching saved result counts as done. A lost, redirected,
 *   mismatched or "not confirmed" reply is shown as not confirmed, with a way
 *   to check the shift's current state. Nothing is ever re-sent on its own.
 */
export function useBoardAction({
    actor,
    onConfirmed,
    onSettled,
}: {
    actor: number;
    /** Runs once, as soon as a result is confirmed. */
    onConfirmed: () => void;
    /** Runs when an unconfirmed result is closed, so the list shows the truth. */
    onSettled: () => void;
}) {
    const [target, setTarget] = useState<PositionTarget | null>(null);
    const [open, setOpen] = useState(false);
    const [phase, setPhase] = useState<BoardActionPhase>({ kind: 'loading' });
    const busy = useRef(false);
    const epoch = useRef(0);
    const mounted = useRef(true);
    const abort = useRef<AbortController | null>(null);

    useEffect(() => {
        mounted.current = true;
        const epochs = epoch;
        const requests = abort;
        return () => {
            mounted.current = false;
            epochs.current++;
            requests.current?.abort();
        };
    }, []);

    const load = useCallback(
        async (next: PositionTarget, notice: string | null = null) => {
            abort.current?.abort();
            const controller = new AbortController();
            abort.current = controller;
            const mine = ++epoch.current;
            const current = () =>
                mounted.current &&
                epoch.current === mine &&
                !controller.signal.aborted;
            setPhase({ kind: 'loading' });
            try {
                const source = await fetchPositionSource(
                    next,
                    actor,
                    controller.signal,
                );
                if (current()) setPhase({ kind: 'ready', source, notice });
            } catch (failure) {
                if (!current()) return;
                const message =
                    failure instanceof Error
                        ? failure.message
                        : "We couldn't load this shift's details.";
                setPhase({
                    kind: 'load-error',
                    message: notice ? `${notice} ${message}` : message,
                });
            }
        },
        [actor],
    );

    // A different signed-in person never sees or finishes the last one's work.
    const lastActor = useRef(actor);
    useEffect(() => {
        if (lastActor.current === actor) return;
        lastActor.current = actor;
        if (busy.current) return;
        epoch.current++;
        abort.current?.abort();
        setOpen(false);
        setTarget(null);
        setPhase({ kind: 'loading' });
    }, [actor]);

    const start = (next: PositionTarget) => {
        const now = phase;
        if (
            busy.current ||
            (open && (now.kind === 'sending' || now.kind === 'unknown'))
        )
            return;
        setTarget(next);
        setOpen(true);
        void load(next);
    };

    const reload = () => {
        if (busy.current || !target) return;
        const now = phase;
        if (now.kind === 'sending' || now.kind === 'unknown') return;
        void load(target);
    };

    const confirm = async () => {
        const now = phase;
        if (
            busy.current ||
            !target ||
            now.kind !== 'ready' ||
            !mayConfirmAction(now.source, target)
        )
            return;
        let headers: Record<string, string>;
        let intent: ActionIntent;
        try {
            headers = writeHeaders();
            intent = prepareActionIntent(
                now.source,
                target,
                actor,
                newRequestId(),
            );
        } catch (failure) {
            setPhase({
                ...now,
                notice:
                    failure instanceof Error
                        ? failure.message
                        : 'This could not be sent. Nothing was saved.',
            });
            return;
        }
        busy.current = true;
        setPhase({ kind: 'sending', intent });
        const unknown = (): BoardActionPhase => ({
            kind: 'unknown',
            intent,
            checking: false,
            current: null,
            checkError: null,
        });
        try {
            const response = await fetch(actionCommandUrl(target), {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                headers,
                body: JSON.stringify(intent.body),
            });
            const value = await readJson(response);
            if (!mounted.current) return;
            const confirmation = confirmedAction(
                response.status,
                value,
                intent,
            );
            if (confirmation) {
                setPhase({ kind: 'confirmed', intent, confirmation });
                onConfirmed();
                return;
            }
            const refusal = refusalMessage(
                response.status,
                value,
                DEFINITE_REFUSALS,
            );
            if (refusal) {
                busy.current = false;
                void load(
                    target,
                    response.status === 409
                        ? CHANGED
                        : response.status === 422
                          ? `${refusal} Nothing was saved.`
                          : refusal,
                );
                return;
            }
            setPhase(unknown());
        } catch {
            if (mounted.current) setPhase(unknown());
        } finally {
            busy.current = false;
        }
    };

    const checkCurrent = async () => {
        const now = phase;
        if (busy.current || now.kind !== 'unknown' || now.checking || !target)
            return;
        const mine = epoch.current;
        setPhase({ ...now, checking: true, checkError: null });
        try {
            const source = await fetchPositionSource(target, actor);
            if (!mounted.current || epoch.current !== mine) return;
            setPhase({
                ...now,
                checking: false,
                checkError: null,
                current: {
                    source,
                    summary: currentActionSummary(source, now.intent),
                },
            });
        } catch {
            if (!mounted.current || epoch.current !== mine) return;
            setPhase({
                ...now,
                checking: false,
                checkError:
                    "We couldn't check right now. Your earlier request is still not confirmed. Try checking again in a moment.",
            });
        }
    };

    /** Only offered after a check shows the action is still possible. */
    const startAgain = () => {
        const now = phase;
        if (
            busy.current ||
            !target ||
            now.kind !== 'unknown' ||
            !now.current ||
            !mayConfirmAction(now.current.source, target)
        )
            return;
        setPhase({
            kind: 'ready',
            source: now.current.source,
            notice: 'Your earlier request was not confirmed. Check the details below before you try again.',
        });
    };

    const close = () => {
        const now = phase;
        if (busy.current || now.kind === 'sending') return false;
        epoch.current++;
        abort.current?.abort();
        setOpen(false);
        if (now.kind === 'unknown') onSettled();
        return true;
    };

    return {
        open,
        target,
        phase,
        pending: phase.kind === 'sending',
        unresolved:
            open && (phase.kind === 'sending' || phase.kind === 'unknown'),
        start,
        reload,
        confirm,
        checkCurrent,
        startAgain,
        close,
    };
}

export type BoardActionController = ReturnType<typeof useBoardAction>;

/**
 * Alerts are a personal on/off choice, so the header button saves it in one
 * step: it reads the current setting, then sends the explicit choice the
 * button showed (never a blind toggle). An unconfirmed reply re-reads the
 * setting and says what it is now.
 */
export function useAlertPreference({
    actor,
    onSettled,
}: {
    actor: number;
    onSettled: () => void;
}) {
    const [pending, setPending] = useState(false);
    const busy = useRef(false);
    const target = { action: 'set_alert_preference' } as const;

    const readSetting = async (): Promise<AlertSource> => {
        const response = await fetch(actionSourceUrl(target), readInit());
        if (response.status !== 200)
            throw new Error('The alert setting could not be read.');
        return readActionSource(
            await response.json(),
            target,
            actor,
        ) as AlertSource;
    };

    const reportUnconfirmed = async () => {
        let description =
            'It may still have saved. The page will refresh to show your current setting.';
        try {
            const now = await readSetting();
            description = `Your alerts are ${now.enabled ? 'on' : 'off'} right now.`;
        } catch {
            // Keep the generic description; the refresh below shows the truth.
        }
        toast.warning("We couldn't confirm the change to your alerts", {
            description,
        });
        onSettled();
    };

    const setAlerts = async (enabled: boolean) => {
        if (busy.current) return;
        busy.current = true;
        setPending(true);
        let sent = false;
        try {
            const headers = writeHeaders();
            const source = await readSetting();
            if (!source.can_update) {
                toast.error("Your alerts can't be changed from this account.");
                return;
            }
            const intent = prepareActionIntent(
                source,
                target,
                actor,
                newRequestId(),
                enabled,
            );
            sent = true;
            const response = await fetch(actionCommandUrl(target), {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                headers,
                body: JSON.stringify(intent.body),
            });
            const value = await readJson(response);
            if (confirmedAction(response.status, value, intent)) {
                toast.success(enabled ? 'Alerts are on' : 'Alerts are off', {
                    description: enabled
                        ? "We'll let you know when matching shifts open."
                        : "You won't get alerts about new shifts.",
                });
                onSettled();
                return;
            }
            const refusal = refusalMessage(
                response.status,
                value,
                DEFINITE_REFUSALS,
            );
            if (refusal) {
                toast.error("Your alerts weren't changed", {
                    description:
                        response.status === 409
                            ? 'Your alert setting changed somewhere else. Check the button and try again.'
                            : refusal,
                });
                onSettled();
                return;
            }
            await reportUnconfirmed();
        } catch (failure) {
            if (sent) await reportUnconfirmed();
            else
                toast.error("Your alerts weren't changed", {
                    description:
                        failure instanceof Error &&
                        failure.message.startsWith('Your session')
                            ? failure.message
                            : "We couldn't read your current setting, so nothing was sent. Try again.",
                });
        } finally {
            busy.current = false;
            setPending(false);
        }
    };

    return { pending, setAlerts };
}
