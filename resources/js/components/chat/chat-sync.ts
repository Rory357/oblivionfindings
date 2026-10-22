import { conversationPath } from './api';
import { chatActorRequest } from './chat-actor-request';
import type { ChatChanges, ChatHistory, ChatMessage } from './types';

// Bound a recovery pass so a long outage cannot monopolise the shared API limit.
// The next pass resumes from the last fully received page, including empty pages.
export async function readChatChanges(
    actorId: number,
    conversationId: number,
    cursor: string,
    signal?: AbortSignal,
): Promise<ChatChanges> {
    const messages = new Map<number, ChatMessage>();
    const created = new Set<number>();
    let result: ChatChanges;
    for (let page = 0; page < 5; page++) {
        result = await chatActorRequest<ChatChanges>(
            actorId,
            `${conversationPath(conversationId)}/changes`,
            { method: 'POST', body: JSON.stringify({ cursor }), signal },
        );
        for (const message of result.messages)
            messages.set(message.id, message);
        for (const id of result.new_message_ids) created.add(id);
        cursor = result.sync_cursor;
        if (!result.has_more_changes) break;
    }
    return {
        ...result!,
        messages: [...messages.values()],
        new_message_ids: [...created],
    };
}

export function applyChatChanges(
    history: ChatHistory,
    changes: ChatChanges,
    readingContext: boolean,
): ChatHistory {
    const messages = new Map(
        history.messages.map((message) => [message.id, message]),
    );
    const created = new Set(changes.new_message_ids);
    for (const message of changes.messages) {
        if (
            messages.has(message.id) ||
            (!readingContext && created.has(message.id))
        )
            messages.set(message.id, message);
        // Quotes always reflect the current authorised original, including edits.
        for (const [id, reply] of messages) {
            if (reply.reply?.id === message.id)
                messages.set(id, {
                    ...reply,
                    reply: {
                        ...reply.reply,
                        content: message.deleted
                            ? ''
                            : Array.from(message.content)
                                  .slice(0, 280)
                                  .join(''),
                        deleted: message.deleted,
                    },
                });
        }
    }
    return {
        ...history,
        conversation: changes.conversation,
        messages: [...messages.values()].sort((a, b) => a.id - b.id),
        sync_cursor: changes.sync_cursor,
        sync_change_id: changes.sync_change_id ?? history.sync_change_id,
        has_more_changes: changes.has_more_changes,
    };
}
