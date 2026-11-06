import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Check, MoreHorizontal, Pin, Reply } from 'lucide-react';
import { memo } from 'react';
import { ChatAttachment } from '../chat-attachment';
import { ChatMessageText } from '../chat-mention-input';
import {
    chatReadingClass,
    type ChatPreferences,
} from '../chat-personal-preferences';
import type { ChatMessage } from '../types';

export type ThreadMessageProps = {
    message: ChatMessage;
    actorId: number;
    conversationId: number;
    /** The previous row's day differs, so this row starts with the date. */
    showDay: boolean;
    firstUnread: boolean;
    highlighted: boolean;
    inSearch: boolean;
    spacing: ChatPreferences['spacing'];
    textSize: ChatPreferences['text_size'];
    onJump: (messageId: number) => void;
    onReact: (message: ChatMessage, emoji: string) => void;
    onActions: (message: ChatMessage, trigger: HTMLButtonElement) => void;
};

/**
 * One message row. Memoised: typing in the composer or a change to another message does not
 * re-render it. The callbacks it receives must keep their identity.
 */
export const ThreadMessage = memo(function ThreadMessage({
    message,
    actorId,
    conversationId,
    showDay,
    firstUnread,
    highlighted,
    inSearch,
    spacing,
    textSize,
    onJump,
    onReact,
    onActions,
}: ThreadMessageProps) {
    return (
        <div
            id={`chat-message-${message.id}`}
            data-chat-message={message.id}
            tabIndex={-1}
            className={cn(
                'focus-visible:outline-2 focus-visible:outline-primary',
                highlighted && 'rounded-xl ring-2 ring-primary/30',
            )}
        >
            {showDay && (
                <p className="my-5 text-center text-xs font-medium text-muted-foreground">
                    {formatDate(message.created_at)}
                </p>
            )}
            {!inSearch && firstUnread && (
                <div
                    role="separator"
                    aria-label="New messages"
                    className="my-4 flex items-center gap-3 text-xs font-semibold text-primary"
                >
                    <span className="h-px flex-1 bg-primary/30" />
                    New messages
                    <span className="h-px flex-1 bg-primary/30" />
                </div>
            )}
            <article
                className={cn(
                    'group flex flex-col',
                    spacing === 'compact' ? 'mb-2' : 'mb-4',
                    message.mine ? 'items-end' : 'items-start',
                )}
            >
                <p className="mb-1 px-1 text-xs font-medium text-muted-foreground">
                    {message.mine ? 'You' : message.sender_name}
                </p>
                <div
                    className={cn(
                        'max-w-[92%] rounded-2xl border sm:max-w-[78%]',
                        spacing === 'compact' ? 'px-3 py-2' : 'px-4 py-3',
                        message.mine
                            ? 'rounded-tr-md border-primary/10 bg-primary/10'
                            : 'rounded-tl-md bg-muted/40',
                    )}
                >
                    {message.pinned && (
                        <p className="mb-2 flex items-center gap-1 text-xs font-medium">
                            <Pin className="size-3" />
                            Pinned
                        </p>
                    )}
                    {!message.deleted && message.reply_unavailable && (
                        <p className="mb-2 flex items-center gap-2 rounded-lg border bg-background/60 px-3 py-2 text-xs text-muted-foreground">
                            <Reply
                                className="size-3 shrink-0"
                                aria-hidden="true"
                            />
                            Earlier message not shared with you.
                        </p>
                    )}
                    {!message.deleted && message.reply_to_id && (
                        <Button
                            variant="ghost"
                            disabled={!message.reply}
                            aria-label={
                                message.reply
                                    ? `View original message from ${message.reply.sender_name}`
                                    : 'Original message unavailable'
                            }
                            className="mb-2 h-auto min-h-[44px] max-w-full flex-col items-start gap-1 rounded-lg border bg-background/60 px-2 text-left text-xs whitespace-normal text-muted-foreground"
                            onClick={() =>
                                message.reply && onJump(message.reply.id)
                            }
                        >
                            <span className="flex items-center gap-1">
                                <Reply
                                    className="size-3 shrink-0"
                                    aria-hidden="true"
                                />
                                <strong>
                                    {message.reply?.sender_name ??
                                        'Original message unavailable'}
                                </strong>
                            </span>
                            <span className="line-clamp-2">
                                {message.reply?.deleted
                                    ? 'Message removed'
                                    : message.reply?.content || 'Attachment'}
                            </span>
                        </Button>
                    )}
                    <p
                        className={cn(
                            'leading-relaxed [overflow-wrap:anywhere] break-words whitespace-pre-wrap',
                            chatReadingClass(textSize),
                            message.deleted && 'text-muted-foreground italic',
                        )}
                    >
                        {message.deleted ? (
                            'Message removed'
                        ) : (
                            <ChatMessageText
                                content={message.content}
                                mentions={message.mentions}
                                actorId={actorId}
                            />
                        )}
                    </p>
                    {message.attachments.map((file) => (
                        <ChatAttachment
                            key={file.id}
                            file={file}
                            messageId={message.id}
                            conversationId={conversationId}
                            actorId={actorId}
                        />
                    ))}
                    {message.legacy_attachments.map((file, index) => (
                        <p
                            key={index}
                            className="mt-2 text-xs text-muted-foreground"
                        >
                            {file.url ? (
                                <a
                                    href={file.url}
                                    className="inline-flex min-h-[44px] items-center underline underline-offset-4"
                                >
                                    {file.name} · Open attachment
                                </a>
                            ) : (
                                `${file.name} · Attachment no longer available`
                            )}
                        </p>
                    ))}
                    <p className="mt-2 flex items-center justify-end gap-1 text-[11px] text-muted-foreground">
                        <time
                            dateTime={message.created_at}
                            title={formatDateTime(message.created_at)}
                        >
                            {formatTime(message.created_at)}
                        </time>
                        {message.edited_at && ' · edited'}
                        {message.mine && (
                            <>
                                <Check className="size-3" aria-hidden="true" />
                                <span className="sr-only">Sent</span>
                            </>
                        )}
                    </p>
                </div>
                {!message.deleted && (
                    <div className="flex max-w-full flex-wrap items-center gap-1 px-1">
                        {inSearch && (
                            <Button
                                variant="outline"
                                className="min-h-[44px] text-xs"
                                onClick={() => onJump(message.id)}
                            >
                                View in conversation
                            </Button>
                        )}
                        {message.reactions.map((reaction) => (
                            <Button
                                variant="ghost"
                                type="button"
                                key={reaction.emoji}
                                aria-pressed={reaction.mine}
                                title={
                                    reaction.people?.length
                                        ? reaction.people.join(', ')
                                        : undefined
                                }
                                className={cn(
                                    'min-h-[44px] min-w-[44px] rounded-full px-2 text-xs',
                                    reaction.mine &&
                                        'bg-primary-fill/10 text-primary',
                                )}
                                onClick={() => onReact(message, reaction.emoji)}
                            >
                                {reaction.emoji} {reaction.count}
                            </Button>
                        ))}
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-[44px] text-muted-foreground"
                            aria-label={`Actions for message from ${message.sender_name} at ${formatTime(message.created_at)}`}
                            onClick={(event) =>
                                onActions(message, event.currentTarget)
                            }
                        >
                            <MoreHorizontal className="size-4" />
                        </Button>
                    </div>
                )}
            </article>
        </div>
    );
});
