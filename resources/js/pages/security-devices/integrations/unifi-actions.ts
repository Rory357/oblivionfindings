import type { Page, RequestPayload, VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { UnifiProps } from './unifi';

export type UnifiActionPage = UnifiProps & {
    auth?: {
        user?: { id: number };
        can?: {
            securityDevices?: {
                viewAny?: boolean;
                integrationsManage?: boolean;
            };
        };
    };
    flash?: { error?: unknown; warning?: unknown };
};
type ActionRequest = {
    method: 'post' | 'put' | 'delete';
    path: string;
    payload?: RequestPayload;
    /** Names the action in messages, e.g. "Saving the key did not complete." */
    label: string;
    confirmed?: (page: UnifiActionPage) => boolean;
    onConfirmed?: (page: UnifiActionPage) => void;
    onValidation?: () => void;
    onUnconfirmed?: () => void;
    /** Shown when the server acknowledges the request but nothing changed. */
    unchangedNotice?: string;
};
const actionHeader = 'X-Obli-UniFi-Action';
const unifiPath = '/security-devices/integrations/unifi';
/** Server validation text is shown as plain text, trimmed and bounded. */
export function validationMessage(errors: unknown): string | null {
    if (!errors || typeof errors !== 'object') return null;
    for (const value of Object.values(errors as Record<string, unknown>)) {
        const first = Array.isArray(value) ? value[0] : value;
        if (typeof first === 'string' && first.trim() !== '')
            return first.trim().slice(0, 300);
    }
    return null;
}
export function useUnifiAction(actor: number, onDenied: () => void) {
    const [sourceActor] = useState(actor);
    const alive = useRef(true);
    const active = useRef<{
        marker: string;
        path: string;
        method: string;
        label: string;
        /** A read made after a write was refused, to recheck access. */
        recovery: boolean;
        settled: boolean;
        onUnconfirmed?: () => void;
    } | null>(null);
    const [pending, setPending] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const uncertainRef = useRef(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            active.current = null;
        };
    }, []);
    const unavailable = (onUnconfirmed?: () => void) => {
        uncertainRef.current = true;
        setUncertain(true);
        setError(
            'The reply was not confirmed. Refresh the saved status before repeating this action.',
        );
        onUnconfirmed?.();
    };
    const newMarker = () =>
        Array.from(crypto.getRandomValues(new Uint32Array(4)), (part) =>
            part.toString(16).padStart(8, '0'),
        ).join('');
    const isCurrent = (marker: string) =>
        alive.current &&
        active.current?.marker === marker &&
        actor === sourceActor;
    const authorised = (page: Page) => {
        const next = page.props as unknown as UnifiActionPage;
        return (
            page.component === 'security-devices/integrations/unifi' &&
            next.actor_id === sourceActor &&
            next.auth?.user?.id === sourceActor &&
            next.can?.manage === true &&
            next.auth.can?.securityDevices?.viewAny === true &&
            next.auth.can.securityDevices?.integrationsManage === true
        );
    };
    /**
     * A 404 from a write means the record it changes has gone (or moved out
     * of reach), not necessarily that access was removed. Re-read the page
     * before deciding: a permitted read keeps the page and explains that
     * nothing was saved; a refused or unpermitted read still clears it.
     */
    const recover = (label: string) => {
        const marker = newMarker();
        const target = new URL(
            unifiPath + window.location.search,
            window.location.origin,
        );
        active.current = {
            marker,
            path: target.href,
            method: 'get',
            label,
            recovery: true,
            settled: false,
        };
        setPending(true);
        router.visit(target.pathname + target.search, {
            method: 'get',
            headers: { [actionHeader]: marker },
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if (!isCurrent(marker)) return;
                active.current!.settled = true;
                if (!authorised(page)) {
                    onDenied();
                    return;
                }
                setError(
                    label +
                        ' did not complete. The record it changes is no longer available, so nothing was saved. Review the refreshed records and try again.',
                );
            },
            onFinish: () => {
                if (!isCurrent(marker)) return;
                if (!active.current!.settled)
                    setError(
                        label +
                            ' did not complete, and the records could not be refreshed. Use Refresh before trying again.',
                    );
                active.current = null;
                setPending(false);
            },
        });
    };
    useEffect(
        () =>
            router.on('invalid', (event) => {
                const owned = active.current;
                if (!alive.current || !owned) return;
                const response = event.detail.response;
                const headers = response.config.headers;
                const marker =
                    typeof headers?.get === 'function'
                        ? headers.get(actionHeader)
                        : headers?.[actionHeader];
                if (
                    marker !== owned.marker ||
                    response.config.method?.toLowerCase() !== owned.method ||
                    typeof response.config.url !== 'string'
                )
                    return;
                let url: URL;
                try {
                    url = new URL(response.config.url, window.location.origin);
                } catch {
                    return;
                }
                if (url.href !== owned.path) return;
                event.preventDefault();
                active.current = null;
                setPending(false);
                if (owned.recovery) {
                    if ([401, 403, 404].includes(response.status)) onDenied();
                    else
                        setError(
                            owned.label +
                                ' did not complete, and the records could not be refreshed. Use Refresh before trying again.',
                        );
                } else if ([401, 403].includes(response.status)) onDenied();
                else if (response.status === 404) recover(owned.label);
                else if ([422, 429].includes(response.status))
                    // Refused before anything was saved, so a retry is safe.
                    setError(
                        owned.label +
                            (response.status === 429
                                ? ' did not complete because too many requests were sent. Wait a minute and try again.'
                                : ' did not complete. Check the entries and try again.'),
                    );
                else unavailable(owned.onUnconfirmed);
            }),
        // recover() only uses refs, state setters and onDenied, so the
        // listener does not need to be re-subscribed when it is recreated.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [onDenied],
    );
    const execute = (request: ActionRequest) => {
        if (
            !alive.current ||
            actor !== sourceActor ||
            active.current ||
            uncertainRef.current
        )
            return;
        const url = new URL(request.path, window.location.origin);
        if (
            url.origin !== window.location.origin ||
            !url.pathname.startsWith(unifiPath + '/')
        )
            return;
        const marker = newMarker();
        active.current = {
            marker,
            path: url.href,
            method: request.method,
            label: request.label,
            recovery: false,
            settled: false,
            onUnconfirmed: request.onUnconfirmed,
        };
        setPending(true);
        setError('');
        setNotice('');
        const current = () => isCurrent(marker);
        const options: VisitOptions = {
            preserveScroll: true,
            headers: { [actionHeader]: marker },
            onSuccess: (page) => {
                if (!current()) return;
                active.current!.settled = true;
                const next = page.props as unknown as UnifiActionPage;
                if (!authorised(page)) {
                    onDenied();
                    return;
                }
                if (next.flash?.error) {
                    setError(
                        request.label +
                            ' did not complete. Your unsaved entries are retained.',
                    );
                    return;
                }
                const acknowledgement = next.actionOutcome;
                if (
                    (request.confirmed || request.onConfirmed) &&
                    ((acknowledgement?.outcome !== 'saved' &&
                        acknowledgement?.outcome !== 'unchanged') ||
                        acknowledgement.actor_id !== sourceActor ||
                        acknowledgement.request_id !== marker ||
                        acknowledgement.method !== request.method ||
                        acknowledgement.path !== url.pathname)
                ) {
                    unavailable(request.onUnconfirmed);
                    return;
                }
                if (request.confirmed && !request.confirmed(next)) {
                    unavailable(request.onUnconfirmed);
                    return;
                }
                if (acknowledgement?.outcome === 'unchanged')
                    setNotice(
                        request.unchangedNotice ??
                            request.label +
                                ' made no change: the saved record was already up to date.',
                    );
                else if (next.flash?.warning)
                    setNotice(
                        'Some records need attention. Review the latest saved run.',
                    );
                request.onConfirmed?.(next);
            },
            onError: (errors) => {
                if (current()) {
                    active.current!.settled = true;
                    const message = validationMessage(errors);
                    setError(
                        message
                            ? request.label + ' did not complete: ' + message
                            : request.label +
                                  ' did not complete. Check the entries and try again.',
                    );
                    request.onValidation?.();
                }
            },
            onCancel: () => {
                if (current()) {
                    active.current!.settled = true;
                    unavailable(request.onUnconfirmed);
                }
            },
            onFinish: () => {
                if (!current()) return;
                if (!active.current!.settled)
                    unavailable(request.onUnconfirmed);
                active.current = null;
                setPending(false);
            },
        };
        if (request.method === 'delete') router.delete(request.path, options);
        else if (request.method === 'put')
            router.put(request.path, request.payload ?? {}, options);
        else router.post(request.path, request.payload ?? {}, options);
    };
    const clearMessage = () => {
        if (!pending && !uncertain) {
            setError('');
            setNotice('');
        }
    };
    return { execute, pending, uncertain, error, notice, clearMessage };
}
