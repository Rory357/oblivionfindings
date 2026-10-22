import type { RequestPayload, VisitOptions } from '@inertiajs/core';
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
    label: string;
    confirmed?: (page: UnifiActionPage) => boolean;
    onConfirmed?: (page: UnifiActionPage) => void;
    onValidation?: () => void;
    onUnconfirmed?: () => void;
};
const actionHeader = 'X-Obli-UniFi-Action';
export function useUnifiAction(actor: number, onDenied: () => void) {
    const [sourceActor] = useState(actor);
    const alive = useRef(true);
    const active = useRef<{
        marker: string;
        path: string;
        method: string;
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
                if ([401, 403, 404].includes(response.status)) onDenied();
                else unavailable(owned.onUnconfirmed);
            }),
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
            !url.pathname.startsWith('/security-devices/integrations/unifi/')
        )
            return;
        const marker = Array.from(
            crypto.getRandomValues(new Uint32Array(4)),
            (part) => part.toString(16).padStart(8, '0'),
        ).join('');
        active.current = {
            marker,
            path: url.href,
            method: request.method,
            settled: false,
            onUnconfirmed: request.onUnconfirmed,
        };
        setPending(true);
        setError('');
        setNotice('');
        const current = () =>
            alive.current &&
            active.current?.marker === marker &&
            actor === sourceActor;
        const options: VisitOptions = {
            preserveScroll: true,
            headers: { [actionHeader]: marker },
            onSuccess: (page) => {
                if (!current()) return;
                active.current!.settled = true;
                const next = page.props as unknown as UnifiActionPage;
                if (
                    page.component !== 'security-devices/integrations/unifi' ||
                    next.actor_id !== sourceActor ||
                    next.auth?.user?.id !== sourceActor ||
                    next.can?.manage !== true ||
                    next.auth.can?.securityDevices?.viewAny !== true ||
                    next.auth.can.securityDevices?.integrationsManage !== true
                ) {
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
                    (acknowledgement?.outcome !== 'saved' ||
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
                if (next.flash?.warning)
                    setNotice(
                        'Some records need attention. Review the latest saved run.',
                    );
                request.onConfirmed?.(next);
            },
            onError: () => {
                if (current()) {
                    active.current!.settled = true;
                    setError('Check the selected entries and try again.');
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
