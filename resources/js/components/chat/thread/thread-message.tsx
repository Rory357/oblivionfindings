import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Check, MoreHorizontal, Pin, Reply, SmilePlus } from 'lucide-react';
import { memo, type KeyboardEvent, type MouseEvent } from 'react';
import { ChatAttachment } from '../chat-attachment';
import { ChatMessageText } from '../chat-mention-input';
import {
    chatReadingClass,
    type ChatPreferences,
} from '../chat-personal-preferences';
import type { ChatMessage } from '../types';
import { QUICK_REACTIONS } from './message-menu';

export type ThreadMessageProps = {
    message: ChatMessage;
    actorId: number;
    conversationId: number;
    /** The previous row's day differs, so this row starts with the date. */
    showDay: boolean;
    /** The first of a run of messages from one sender: show their photo and name. */
    groupStart: boolean;
    firstUnread: boolean;
    highlighted: boolean;
    inSearch: boolean;
    canReact: boolean;
    spacing: ChatPreferences['spacing'];
    textSize: ChatPreferences['text_size'];
    onJump: (messageId: number) => void;
    onReact: (message: ChatMessage, emoji: string) => void;
    onReply: (message: ChatMessage) => void;
    /** Opens the message menu at a point on the screen. */
    onMenu: (message: ChatMessage, x: number, y: number) => void;
};

export function initials(name: string) {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    return (
        (parts.length > 1
            ? `${parts[0][0]}${parts[parts.length - 1][0]}`
            : (parts[0] ?? '?').slice(0, 2)
        ).toUpperCase() || '?'
    );
}

/**
 * One message, drawn to the Chat v3 boards: your own on the right in the brand tint, others on
 * the left on the card surface, one photo and name per run of messages, the time inline, reactions
 * under the bubble, and a hover bar. Memoised: typing in the composer or a change to another
 * message does not re-render it, so the callbacks it receives must keep their identity.
 */
export const ThreadMessage = memo(function ThreadMessage({
    message,
    actorId,
    conversationId,
    showDay,
    groupStart,
    firstUnread,
    highlighted,
    inSearch,
    canReact,
    spacing,
    textSize,
    onJump,
    onReact,
    onReply,
    onMenu,
}: ThreadMessageProps) {
    const sender = message.mine ? 'You' : message.sender_name;
    const actions = !message.deleted;
    const openAt = (event: MouseEvent<HTMLElement>) => {
        // Links keep the browser's own menu (open in a new tab, copy link).
        if (event.target instanceof Element && event.target.closest('a[href]'))
            return;
        if (!actions) return;
        event.preventDefault();
        onMenu(message, event.clientX, event.clientY);
    };
    const openFromKeyboard = (event: KeyboardEvent<HTMLElement>) => {
        if (
            !actions ||
            !(
                (event.shiftKey && event.key === 'F10') ||
                event.key === 'ContextMenu'
            )
        )
            return;
        event.preventDefault();
        const bounds = event.currentTarget.getBoundingClientRect();
        onMenu(message, bounds.left + 48, bounds.top + 16);
    };
    return (
        <div
            id={`chat-message-${message.id}`}
            data-chat-message={message.id}
            tabIndex={-1}
            onContextMenu={openAt}
            onKeyDown={openFromKeyboard}
            className={cn(
                'rounded-xl focus-visible:outline-2 focus-visible:outline-primary',
                highlighted && 'ring-2 ring-primary/30',
            )}
        >
            {showDay && (
                <div className="my-4 flex justify-center">
                    <span className="rounded-full border bg-card px-3 py-0.5 text-xs font-medium text-muted-foreground">
                        {formatDate(message.created_at)}
                    </span>
                </div>
            )}
            {!inSearch && firstUnread && (
                <div
                    role="separator"
                    aria-label="New messages"
                    className="my-3 flex items-center gap-3 text-xs font-semibold text-primary"
                >
                    <span className="h-px flex-1 bg-primary/30" />
                    New messages
                    <span className="h-px flex-1 bg-primary/30" />
                </div>
            )}
            <article
                className={cn(
                    'group relative flex gap-2',
                    groupStart
                        ? spacing === 'compact'
                            ? 'mt-2'
                            : 'mt-3'
                        : 'mt-0.5',
                    message.mine && 'flex-row-reverse',
                )}
            >
                {!message.mine &&
                    (groupStart ? (
                        <Avatar className="mt-5 size-8 shrink-0">
                            <AvatarFallback className="bg-muted text-xs font-semibold">
                                {initials(message.sender_name)}
                            </AvatarFallback>
                        </Avatar>
                    ) : (
                        <span className="w-8 shrink-0" aria-hidden="true" />
                    ))}
                <div
                    className={cn(
                        'flex max-w-[78%] min-w-0 flex-col',
                        message.mine ? 'items-end' : 'items-start',
                    )}
                >
                    {groupStart && !message.mine && (
                        <p className="mb-0.5 px-1 text-xs text-muted-foreground">
                            <span className="font-semibold text-foreground">
                                {message.sender_name}
                            </span>{' '}
                            {formatTime(message.created_at)}
                        </p>
                    )}
                    <div className="relative max-w-full">
                        <div
                            className={cn(
                                'rounded-2xl',
                                spacing === 'compact'
                                    ? 'px-3 py-1.5'
                                    : 'px-3 py-2',
                                message.mine
                                    ? 'bg-primary/10'
                                    : 'border bg-card',
                                message.deleted && 'opacity-70',
                            )}
                        >
                            {(message.mine || !groupStart) && (
                                <span className="sr-only">{sender}: </span>
                            )}
                            {message.pinned && (
                                <p className="mb-1 flex items-center gap-1 text-xs font-medium text-muted-foreground">
                                    <Pin
                                        className="size-3"
                                        aria-hidden="true"
                                    />
                                    Pinned
                                </p>
                            )}
                            {!message.deleted && message.reply_unavailable && (
                                <p className="mb-1.5 flex items-center gap-2 rounded-lg border bg-background/60 px-2 py-1.5 text-xs text-muted-foreground">
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
                                    className="mb-1.5 h-auto max-w-full flex-col items-start gap-0.5 rounded-lg border bg-background/60 px-2 py-1.5 text-left text-xs whitespace-normal text-muted-foreground"
                                    onClick={() =>
                                        message.reply &&
                                        onJump(message.reply.id)
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
                                            : message.reply?.content ||
                                              'Attachment'}
                                    </span>
                                </Button>
                            )}
                            <p
                                className={cn(
                                    'leading-relaxed [overflow-wrap:anywhere] break-words whitespace-pre-wrap',
                                    chatReadingClass(textSize),
                                    message.deleted &&
                                        'text-muted-foreground italic',
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
                                <span className="float-right mt-1.5 ml-3 inline-flex items-center gap-1 text-[11px] leading-none text-muted-foreground">
                                    <time
                                        dateTime={message.created_at}
                                        title={formatDateTime(
                                            message.created_at,
                                        )}
                                    >
                                        {formatTime(message.created_at)}
                                    </time>
                                    {message.edited_at && ' · edited'}
                                    {message.mine && (
                                        <>
                                            <Check
                                                className="size-3"
                                                aria-hidden="true"
                                            />
                                            <span className="sr-only">
                                                Sent
                                            </span>
                                        </>
                                    )}
                                </span>
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
                                    className="mt-1.5 text-xs text-muted-foreground"
                                >
                                    {file.url ? (
                                        <a
                                            href={file.url}
                                            className="underline underline-offset-4"
                                        >
                                            {file.name} · Open attachment
                                        </a>
                                    ) : (
                                        `${file.name} · Attachment no longer available`
                                    )}
                                </p>
                            ))}
                        </div>
                        {actions && (
                            <div
                                className={cn(
                                    'absolute -top-4 z-10 flex items-center gap-0.5 rounded-xl border bg-popover p-0.5 shadow-sm',
                                    'opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100',
                                    message.mine ? 'left-0' : 'right-0',
                                )}
                            >
                                {canReact &&
                                    QUICK_REACTIONS.slice(0, 3).map((emoji) => (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            key={emoji}
                                            type="button"
                                            aria-label={`React with ${emoji}`}
                                            className="flex size-7 items-center justify-center rounded-lg text-base hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                                            onClick={() =>
                                                onReact(message, emoji)
                                            }
                                        >
                                            {emoji}
                                        </Button>
                                    ))}
                                {canReact && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        type="button"
                                        aria-label="More reactions"
                                        className="flex size-7 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                                        onClick={(event) => {
                                            const bounds =
                                                event.currentTarget.getBoundingClientRect();
                                            onMenu(
                                                message,
                                                bounds.left,
                                                bounds.bottom + 4,
                                            );
                                        }}
                                    >
                                        <SmilePlus className="size-4" />
                                    </Button>
                                )}
                                {canReact && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        type="button"
                                        aria-label={`Reply to ${sender}`}
                                        className="flex size-7 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                                        onClick={() => onReply(message)}
                                    >
                                        <Reply className="size-4" />
                                    </Button>
                                )}
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    type="button"
                                    aria-label={`Actions for message from ${message.sender_name} at ${formatTime(message.created_at)}`}
                                    aria-haspopup="menu"
                                    className="flex size-7 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                                    onClick={(event) => {
                                        const bounds =
                                            event.currentTarget.getBoundingClientRect();
                                        onMenu(
                                            message,
                                            bounds.left,
                                            bounds.bottom + 4,
                                        );
                                    }}
                                >
                                    <MoreHorizontal className="size-4" />
                                </Button>
                            </div>
                        )}
                    </div>
                    {actions && (message.reactions.length > 0 || inSearch) && (
                        <div
                            className={cn(
                                'mt-1 flex max-w-full flex-wrap items-center gap-1',
                                message.mine && 'justify-end',
                            )}
                        >
                            {inSearch && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-7 text-xs"
                                    onClick={() => onJump(message.id)}
                                >
                                    View in conversation
                                </Button>
                            )}
                            {message.reactions.map((reaction) => (
                                <Button
                                    variant="outline"
                                    size="xs"
                                    type="button"
                                    key={reaction.emoji}
                                    aria-pressed={reaction.mine}
                                    disabled={!canReact}
                                    title={
                                        reaction.people?.length
                                            ? reaction.people.join(', ')
                                            : undefined
                                    }
                                    className={cn(
                                        'inline-flex h-6 items-center gap-1 rounded-full border bg-card px-2 text-xs transition-colors hover:bg-muted disabled:cursor-default disabled:hover:bg-card',
                                        reaction.mine &&
                                            'border-primary/30 bg-primary/10 text-primary hover:bg-primary/15',
                                    )}
                                    onClick={() =>
                                        onReact(message, reaction.emoji)
                                    }
                                >
                                    {reaction.emoji} {reaction.count}
                                </Button>
                            ))}
                        </div>
                    )}
                </div>
            </article>
        </div>
    );
});
