import { formatDateTime } from '@/lib/datetime';

/** Captured public conversation evidence; never derive historical roles from today's assignee. */
export interface TicketConversation {
    last_public: {
        comment_id: number;
        at: string | null;
        speaker_side: 'it' | 'requester' | 'observer' | null;
    } | null;
    next_response_party: 'it' | 'requester' | null;
    state: 'awaiting_it' | 'awaiting_requester' | 'unknown' | 'settled';
}

export function TicketConversationSummary({
    conversation,
    ready,
}: {
    conversation?: TicketConversation;
    ready: boolean;
}) {
    if (!ready)
        return (
            <span className="text-caption block">
                Public reply status unavailable
            </span>
        );
    const next =
        conversation?.state === 'settled' &&
        conversation.next_response_party === null
            ? 'Conversation settled'
            : conversation?.state === 'awaiting_it' &&
                conversation.next_response_party === 'it'
              ? 'Awaiting IT'
              : conversation?.state === 'awaiting_requester' &&
                  conversation.next_response_party === 'requester'
                ? 'Awaiting requester'
                : 'Next public reply not recorded';
    const last = conversation?.last_public;
    const speaker =
        last?.speaker_side === 'it'
            ? 'IT'
            : last?.speaker_side === 'requester'
              ? 'Requester'
              : last?.speaker_side === 'observer'
                ? 'Another participant'
                : 'Speaker not recorded';
    return (
        <span className="text-caption block min-w-0 whitespace-normal">
            <span className="block font-medium text-foreground">{next}</span>
            <span className="block">
                {last
                    ? `Last public reply: ${speaker}`
                    : 'Last public reply not recorded'}
            </span>
            {last?.at && (
                <span className="block">
                    {formatDateTime(last.at, 'Reply time unavailable')}
                </span>
            )}
        </span>
    );
}
