import { chatActorRequest } from '@/components/chat/chat-actor-request';

export type InvestigableConversation = {
    id: number;
    type: 'direct' | 'group' | 'channel';
    title: string | null;
    members: string[];
    created_at: string | null;
    held: boolean;
};

export type InvestigationGrant = {
    id: number;
    case_reference: string;
    conversation: InvestigableConversation | null;
    expires_at: string;
    revoked_at: string | null;
    active: boolean;
};

export type PreservationHold = {
    id: number;
    conversation: InvestigableConversation | null;
    case_reference: string;
    reason: string;
    placed_by: string;
    placed_at: string;
    released_by: string | null;
    released_at: string | null;
    active: boolean;
};

export type InvestigationEvent = {
    id: number;
    action: string;
    actor: string;
    case_reference: string | null;
    conversation_id: number | null;
    created_at: string;
};

export type InvestigationOverview = {
    grants: InvestigationGrant[];
    holds: PreservationHold[];
    events: InvestigationEvent[];
    durations: number[];
};

export const INVESTIGATION_API = '/settings/chat/investigations/api';
export const ADMINISTRATION_API = '/settings/chat/administration';

export function governanceRequest<T>(
    actorId: number,
    path: string,
    init: RequestInit = {},
): Promise<T> {
    return chatActorRequest<T>(actorId, path, init);
}

export function conversationLabel(conversation: InvestigableConversation) {
    const kind =
        conversation.type === 'direct'
            ? 'Direct chat'
            : conversation.type === 'channel'
              ? 'Team channel'
              : 'Private group';
    const name = conversation.title?.trim() || conversation.members.join(', ');
    return `${kind} · ${name}`;
}

export function durationLabel(hours: number) {
    if (hours < 24) return hours === 1 ? '1 hour' : `${hours} hours`;
    const days = hours / 24;
    return days === 1 ? '24 hours' : `${days} days`;
}

export const investigationActionLabels: Record<string, string> = {
    conversation_lookup: 'Searched private conversations',
    access_granted: 'Case access logged',
    conversation_viewed: 'Opened the case view',
    messages_viewed: 'Loaded earlier messages',
    access_ended: 'Ended case access',
    access_refused_expired: 'Refused: case access expired or ended',
    hold_placed: 'Preservation hold placed',
    hold_released: 'Preservation hold released',
};
