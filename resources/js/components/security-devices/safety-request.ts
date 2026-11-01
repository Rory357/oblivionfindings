/**
 * Sending an arm, disarm or unlock from its pop-up (S&D rebuild Phase E). One request key per opening,
 * reused if the person tries again, so a lost answer never sends the command twice: the server hands
 * back the request it already has. When no answer comes back at all, the pop-up says "Result not
 * confirmed" and offers a check, not a second send.
 */
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

export const UNCONFIRMED_WORDS = 'Result not confirmed. Oblivion didn’t hear back, so check the page before you try again; nothing will be sent twice.';

function newKey(prefix: string): string {
    const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.round(Math.random() * 1e9)}`;
    return `${prefix}-${random}`.replace(/[^A-Za-z0-9:_-]/g, '').slice(0, 100);
}

export type SafetyRequestState = {
    processing: boolean;
    error: string | undefined;
    fields: Record<string, string>;
    unconfirmed: boolean;
};

export function useSafetyRequest(open: boolean, prefix: string) {
    const key = useMemo(() => (open ? newKey(prefix) : ''), [open, prefix]);
    const [state, setState] = useState<SafetyRequestState>({ processing: false, error: undefined, fields: {}, unconfirmed: false });

    useEffect(() => {
        if (open) setState({ processing: false, error: undefined, fields: {}, unconfirmed: false });
    }, [open]);

    const send = useCallback(
        (url: string, data: Record<string, unknown>, onDone: () => void) => {
            setState((current) => ({ ...current, processing: true, error: undefined, fields: {} }));
            let answered = false;
            router.post(
                url,
                { ...data, idempotency_key: key },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        answered = true;
                        onDone();
                    },
                    onError: (errors) => {
                        answered = true;
                        const fields = errors as Record<string, string>;
                        const first = Object.values(fields)[0];
                        setState((current) => ({ ...current, fields, error: first ?? 'That didn’t work. Nothing was sent.' }));
                    },
                    onFinish: () => {
                        setState((current) => ({ ...current, processing: false, unconfirmed: answered ? current.unconfirmed : true }));
                    },
                },
            );
        },
        [key],
    );

    /** After "Result not confirmed": read the page again to see what happened. */
    const check = useCallback((only: string[], onDone: () => void) => {
        setState((current) => ({ ...current, processing: true }));
        router.reload({
            only,
            onSuccess: () => onDone(),
            onFinish: () => setState((current) => ({ ...current, processing: false })),
        });
    }, []);

    return { key, state, send, check };
}

/** "Confirm it's you" (step-up): the password page, then back to this device's page. */
export function confirmIdentity(url: string) {
    router.visit(url);
}
