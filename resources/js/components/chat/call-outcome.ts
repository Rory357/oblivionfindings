import { useCallback, useEffect, useRef, useState } from 'react';
import { chatRequest } from './api';
import type { CallSummaryTarget } from './call-summary';
import type { ChatConversation, ChatPolicy } from './types';

export type CallTarget = Pick<
    ChatConversation,
    'id' | 'title' | 'can_send' | 'can_call'
> &
    Partial<Pick<ChatConversation, 'type'>>;
export type CallOutcomeData = {
    call: CallSummaryTarget & {
        conversation_id: number;
        title: string;
        outcome: string;
        ended_at: string | null;
    };
    followup: { conversation: CallTarget; family: boolean; callbacks: boolean };
};
export const waitingCallPolicy: ChatPolicy = {
    enabled: false,
    family_enabled: false,
    files_enabled: false,
    voice_notes_enabled: false,
    max_file_mb: 0,
    max_files: 0,
    response_expectation: '',
};
export const callOutcomeLabels: Record<string, string> = {
    missed: 'Missed call',
    declined: 'You declined this call',
    declined_by_others: 'Call declined',
    unanswered: 'No answer',
    not_connected: 'Call did not connect',
    ended: 'Call ended',
    ongoing: 'Call still in progress',
    left: 'You left the call',
    access_ended: 'Call access ended',
    removed: 'Removed from the call',
    cancelled: 'Call cancelled',
    answered_elsewhere: 'Answered by another care-team member',
    answered_on_another_device: 'Answered on another device',
};
type Target = {
    id: string;
    conversation_id: number;
    my_invitation_id?: string;
    device: string;
};
type State = {
    actor: number;
    target: Target;
    data: CallOutcomeData | null;
    loading: boolean;
    denied: boolean;
    error: string;
};

export function useCallOutcome(actorId: number | null, enabled: boolean) {
    const [state, setState] = useState<State | null>(null);
    const [action, setAction] = useState<'message' | 'callback' | null>(null);
    const request = useRef<AbortController | null>(null);
    const generation = useRef(0);
    const mounted = useRef(false);
    const identity = useRef({ actorId, enabled });
    identity.current = { actorId, enabled };
    const clear = useCallback(() => {
        generation.current++;
        request.current?.abort();
        setState(null);
        setAction(null);
    }, []);
    useEffect(() => {
        mounted.current = true;
        clear();
        return () => {
            mounted.current = false;
            request.current?.abort();
        };
    }, [actorId, enabled, clear]);
    const show = useCallback(
        async (target: Target) => {
            if (!actorId || !enabled) return;
            const current = ++generation.current;
            request.current?.abort();
            const controller = new AbortController();
            request.current = controller;
            const owns = () =>
                mounted.current &&
                current === generation.current &&
                identity.current.actorId === actorId &&
                identity.current.enabled;
            setAction(null);
            setState({
                actor: actorId,
                target,
                data: null,
                loading: true,
                denied: false,
                error: '',
            });
            const timeout = window.setTimeout(() => controller.abort(), 15000);
            try {
                const query = new URLSearchParams({ device_id: target.device });
                if (target.my_invitation_id)
                    query.set('invitation_id', target.my_invitation_id);
                const data = await chatRequest<CallOutcomeData>(
                    `/chat/api/calls/${target.id}/outcome?${query}`,
                    {
                        signal: controller.signal,
                        headers: { 'X-Chat-Actor': String(actorId) },
                    },
                );
                if (!owns()) return;
                if (
                    !data?.call ||
                    data.call.id !== target.id ||
                    data.call.conversation_id !== target.conversation_id ||
                    (target.my_invitation_id &&
                        (data.call as { my_invitation_id?: string })
                            .my_invitation_id !== target.my_invitation_id) ||
                    data.followup?.conversation.id !== target.conversation_id ||
                    typeof data.followup.family !== 'boolean'
                )
                    throw new Error('Unconfirmed outcome');
                setState({
                    actor: actorId,
                    target,
                    data,
                    loading: false,
                    denied: false,
                    error: '',
                });
            } catch (error) {
                if (!owns()) return;
                const status = (error as { status?: number }).status;
                if (status === 409) {
                    clear();
                    return;
                }
                const denied = [401, 403, 404, 419].includes(status ?? 0);
                setState({
                    actor: actorId,
                    target,
                    data: null,
                    loading: false,
                    denied,
                    error: denied
                        ? 'Call details are no longer available to this account.'
                        : 'The call result could not be confirmed. Check again when your connection is available.',
                });
            } finally {
                window.clearTimeout(timeout);
            }
        },
        [actorId, enabled, clear],
    );
    return {
        state: enabled && state?.actor === actorId ? state : null,
        action,
        setAction,
        show,
        clear,
    };
}
