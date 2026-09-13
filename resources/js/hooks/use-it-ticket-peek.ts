import type { TicketRoutingDetails } from '@/components/it/ticket-routing-summary';
import type {
    ThreadAttachment,
    ThreadComment,
    ThreadEvent,
    ThreadKbHint,
} from '@/components/it/ticket-thread';
import type { TicketWaitingDetails } from '@/components/it/ticket-waiting-dialog';
import type { TicketWatcher } from '@/hooks/use-ticket-watcher-command';
import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface TicketPeekPayload {
    viewer_user_id: number;
    conversation_ready?: boolean;
    draftRecovery?: { enabled: boolean };
    ticket: {
        id: number;
        lock_version?: number;
        reference: string | null;
        title: string;
        description: string | null;
        priority: string;
        status: string;
        waiting: TicketWaitingDetails | null;
        requester: { id: number | null; name: string; role: string | null };
        assignee: { id: number; name: string } | null;
        routing?: TicketRoutingDetails;
        watchers: TicketWatcher[];
        attachments: ThreadAttachment[];
        created_human: string | null;
    };
    comments: ThreadComment[];
    events: ThreadEvent[];
    kbSuggestions?: ThreadKbHint[];
    can: { internal: boolean; comment: boolean; manageWatchers?: boolean };
    watcherOptions?: { id: number; name: string }[];
    replyUnavailableReason: string | null;
}

export type TicketPeekError = 'session' | 'access' | 'actor' | 'network';
interface Snapshot {
    scope: string;
    data: TicketPeekPayload | null;
    loading: boolean;
    error: TicketPeekError | null;
    access: Exclude<TicketPeekError, 'network'> | null;
}

/** Reject a different record, login HTML, or an incomplete Inertia response. */
function isPayload(
    value: unknown,
    ticketId: number,
): value is TicketPeekPayload {
    if (!value || typeof value !== 'object') return false;
    const data = value as Partial<TicketPeekPayload>;
    return (
        data.ticket?.id === ticketId &&
        (data.conversation_ready !== true ||
            (Number.isSafeInteger(data.ticket.lock_version) &&
                data.ticket.lock_version! > 0)) &&
        typeof data.ticket.title === 'string' &&
        typeof data.ticket.status === 'string' &&
        typeof data.ticket.priority === 'string' &&
        typeof data.ticket.requester?.name === 'string' &&
        Array.isArray(data.ticket.watchers) &&
        Array.isArray(data.ticket.attachments) &&
        Array.isArray(data.comments) &&
        Array.isArray(data.events) &&
        typeof data.can?.internal === 'boolean' &&
        typeof data.can?.comment === 'boolean'
    );
}

/** Request-local, actor/record-scoped conversation snapshots; no browser storage. */
export function useItTicketPeek(
    ticketId: number | null,
    actorId: number | undefined,
) {
    const scope = `${actorId ?? 'none'}:${ticketId ?? 'none'}`;
    const [snapshot, setSnapshot] = useState<Snapshot>({
        scope,
        data: null,
        loading: false,
        error: null,
        access: null,
    });
    const operation = useRef<{
        epoch: number;
        controller: AbortController | null;
        timer?: ReturnType<typeof setTimeout>;
    }>({ epoch: 0, controller: null });

    const refresh = useCallback(() => {
        operation.current.controller?.abort();
        clearTimeout(operation.current.timer);
        const epoch = ++operation.current.epoch;
        if (ticketId === null || actorId === undefined) {
            setSnapshot({
                scope,
                data: null,
                loading: false,
                error: ticketId === null ? null : 'session',
                access: ticketId === null ? null : 'session',
            });
            return;
        }
        const controller = new AbortController();
        operation.current.controller = controller;
        setSnapshot((current) => ({
            scope,
            data: current.scope === scope ? current.data : null,
            loading: true,
            error: null,
            access: current.scope === scope ? current.access : null,
        }));
        operation.current.timer = setTimeout(() => {
            if (operation.current.epoch !== epoch || controller.signal.aborted)
                return;
            ++operation.current.epoch;
            controller.abort();
            setSnapshot((current) => ({
                ...current,
                scope,
                data: current.scope === scope ? current.data : null,
                loading: false,
                error: 'network',
            }));
        }, 20000);
        void axios
            .get<unknown>(`/it/tickets/${ticketId}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
            .then((response) => {
                if (
                    controller.signal.aborted ||
                    operation.current.epoch !== epoch
                )
                    return;
                clearTimeout(operation.current.timer);
                if (!isPayload(response.data, ticketId))
                    throw new Error('Invalid ticket response');
                if (response.data.viewer_user_id !== actorId) {
                    setSnapshot({
                        scope,
                        data: null,
                        loading: false,
                        error: 'actor',
                        access: 'actor',
                    });
                    return;
                }
                setSnapshot({
                    scope,
                    data: response.data,
                    loading: false,
                    error: null,
                    access: null,
                });
            })
            .catch((error: unknown) => {
                if (
                    controller.signal.aborted ||
                    operation.current.epoch !== epoch
                )
                    return;
                clearTimeout(operation.current.timer);
                const status = axios.isAxiosError(error)
                    ? error.response?.status
                    : undefined;
                const kind: TicketPeekError =
                    status === 401 || status === 419
                        ? 'session'
                        : status === 403 || status === 404
                          ? 'access'
                          : 'network';
                setSnapshot((current) => ({
                    scope,
                    data:
                        kind === 'network' && current.scope === scope
                            ? current.data
                            : null,
                    loading: false,
                    error: kind,
                    access: kind === 'network' ? current.access : kind,
                }));
            });
    }, [actorId, scope, ticketId]);

    useEffect(() => {
        const currentOperation = operation.current;
        refresh();
        return () => {
            ++currentOperation.epoch;
            clearTimeout(currentOperation.timer);
            currentOperation.controller?.abort();
        };
    }, [refresh]);

    const current =
        snapshot.scope === scope
            ? snapshot
            : {
                  scope,
                  data: null,
                  loading: ticketId !== null,
                  error: null,
                  access: null,
              };
    return { ...current, refresh };
}
