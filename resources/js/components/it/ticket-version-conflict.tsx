import { Button } from '@/components/ui/button';
import { AlertCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export type TicketVersions = Record<number, number>;

/** Keep the versions shown when a dialog opened, even after a partial reload. */
export function useTicketVersionSnapshot(
    open: boolean,
    versions: TicketVersions,
) {
    const [snapshot, setSnapshot] = useState<TicketVersions>(versions);
    const wasOpen = useRef(open);
    useEffect(() => {
        if (open && !wasOpen.current) setSnapshot({ ...versions });
        wasOpen.current = open;
    }, [open, versions]);
    return [snapshot, setSnapshot] as const;
}

export interface CurrentTicket {
    id: number;
    reference: string;
    lock_version: number;
    title: string;
    status: string;
    priority: string;
    assignee: { name: string } | null;
    /** Current command eligibility, derived from the authorized response. */
    can_reopen?: boolean;
    csat?: {
        score: number;
        comment: string | null;
        submitted_at: string;
    } | null;
}

/** Reviewing is read-only. Accepting a version never submits the retained draft. */
export function TicketVersionConflict({
    error,
    ticketId,
    onReviewed,
    actorId,
    onAccessLost,
    onSessionLost,
    requiredCapability = 'manage',
    requireInternal = false,
    proposalDescription = 'Your draft has been kept. Check the current ticket, then choose whether to apply your changes.',
    adoptLabel = 'Use this version and keep my draft',
    renderCurrent,
}: {
    error?: string;
    ticketId: number | null;
    onReviewed: (version: number, current?: CurrentTicket) => void;
    actorId?: number;
    onAccessLost?: () => void;
    onSessionLost?: () => void;
    requiredCapability?:
        | 'view'
        | 'manage'
        | 'comment'
        | 'confirmResolution'
        | 'rate';
    requireInternal?: boolean;
    proposalDescription?: string;
    adoptLabel?: string;
    renderCurrent?: (ticket: CurrentTicket) => import('react').ReactNode;
}) {
    const [current, setCurrent] = useState<CurrentTicket | null>(null);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const controller = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const scope = `${actorId ?? 'legacy'}:${ticketId}:${requiredCapability}:${requireInternal}:${error ?? ''}`;
    const currentScope = useRef(scope);
    currentScope.current = scope;
    const acceptedScope = useRef(scope);

    useEffect(() => {
        setCurrent(null);
        setLoadError(null);
        setLoading(false);
        acceptedScope.current = scope;
        return () => {
            // This is an operation epoch, not a captured DOM ref; invalidate the latest request.
            // eslint-disable-next-line react-hooks/exhaustive-deps
            ++epoch.current;
            controller.current?.abort();
            controller.current = null;
        };
    }, [scope]);

    const review = async () => {
        if (ticketId === null || loading) return;
        const request = new AbortController();
        const token = ++epoch.current;
        controller.current = request;
        setLoading(true);
        setLoadError(null);
        setCurrent(null);
        const timer = window.setTimeout(() => request.abort(), 30000);
        const same = () =>
            epoch.current === token && currentScope.current === scope;
        try {
            const response = await fetch(`/it/tickets/${ticketId}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: request.signal,
            });
            if (!same()) return;
            if (!response.ok) {
                if ([403, 404].includes(response.status)) onAccessLost?.();
                if ([401, 419].includes(response.status)) onSessionLost?.();
                throw new Error(
                    [401, 403, 404, 419].includes(response.status)
                        ? 'This ticket is no longer available to you. Close this form and return to IT & Support.'
                        : 'Current details could not be loaded. Your draft is still here. Try again.',
                );
            }
            const payload = (await response.json()) as {
                ticket?: CurrentTicket;
                viewer_user_id?: number;
                can?: {
                    manage?: boolean;
                    comment?: boolean;
                    internal?: boolean;
                    confirmResolution?: boolean;
                    rate?: boolean;
                    reopen?: boolean;
                };
            };
            if (!same() || request.signal.aborted) return;
            if (
                !payload ||
                typeof payload !== 'object' ||
                Array.isArray(payload)
            ) {
                throw new Error(
                    'Current details could not be confirmed. Your draft is still here. Try again.',
                );
            }
            if (
                actorId !== undefined &&
                (payload.viewer_user_id !== actorId ||
                    (requiredCapability !== 'view' &&
                        payload.can?.[requiredCapability] !== true) ||
                    (requireInternal && payload.can?.internal !== true))
            ) {
                onAccessLost?.();
                throw new Error(
                    'Your account or ticket access changed. The current details are concealed.',
                );
            }
            if (
                payload.ticket?.id !== ticketId ||
                !Number.isSafeInteger(payload.ticket.lock_version) ||
                payload.ticket.lock_version < 1 ||
                typeof payload.ticket.title !== 'string' ||
                typeof payload.ticket.reference !== 'string' ||
                typeof payload.ticket.status !== 'string' ||
                typeof payload.ticket.priority !== 'string' ||
                !(
                    payload.ticket.assignee === null ||
                    (typeof payload.ticket.assignee === 'object' &&
                        typeof payload.ticket.assignee?.name === 'string')
                )
            ) {
                throw new Error(
                    'Current details could not be confirmed. Your draft is still here. Try again.',
                );
            }
            if (!request.signal.aborted && same())
                setCurrent({
                    ...payload.ticket,
                    ...(requiredCapability === 'view'
                        ? { can_reopen: payload.can?.reopen === true }
                        : {}),
                });
        } catch (failure) {
            if (same()) {
                setLoadError(
                    request.signal.aborted
                        ? 'Loading stopped. Your proposal is retained; review again before applying.'
                        : failure instanceof Error
                          ? failure.message
                          : 'Current details could not be loaded. Try again.',
                );
            }
        } finally {
            window.clearTimeout(timer);
            if (same()) {
                controller.current = null;
                setLoading(false);
            }
        }
    };

    if (!error) return null;

    return (
        <div
            className="space-y-3 rounded-lg border border-border bg-muted/30 p-3 text-sm"
            role="alert"
        >
            <p className="flex items-start gap-2">
                <AlertCircle
                    className="mt-0.5 size-4 shrink-0 text-status-warning"
                    aria-hidden="true"
                />
                <span>{error}</span>
            </p>
            {loadError ? <p className="text-destructive">{loadError}</p> : null}
            {current && acceptedScope.current === scope ? (
                <>
                    <div className="space-y-1">
                        <p className="font-medium">
                            Current ticket: {current.reference}
                        </p>
                        <p>{current.title}</p>
                        <p className="text-muted-foreground">
                            {current.status.replaceAll('_', ' ')} ·{' '}
                            {current.priority} priority ·{' '}
                            {current.assignee?.name ?? 'Unassigned'}
                        </p>
                        <a
                            href={`/it/tickets/${current.id}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="frontline-focus inline-flex min-h-11 items-center text-primary underline"
                        >
                            Open full ticket in a new tab
                        </a>
                    </div>
                    {renderCurrent?.(current)}
                    <p>{proposalDescription}</p>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            onReviewed(current.lock_version, current)
                        }
                    >
                        {adoptLabel}
                    </Button>
                </>
            ) : (
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={loading || ticketId === null}
                        onClick={() => void review()}
                    >
                        {loading
                            ? 'Loading current ticket…'
                            : 'Review current ticket'}
                    </Button>
                    {loading ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => controller.current?.abort()}
                        >
                            Cancel loading
                        </Button>
                    ) : null}
                </div>
            )}
        </div>
    );
}
