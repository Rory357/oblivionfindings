import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { cn } from '@/lib/utils';
import { Bookmark, Copy, Pencil, Pin, Reply, Trash2 } from 'lucide-react';
import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import type { ChatMessage } from '../types';

/** The quick bar of six (C3.6). An organisation setting to choose them comes later. */
export const QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

export type MessageMenuActions = {
    canSend: boolean;
    /** A send is in flight or unconfirmed, so edit and delete wait. */
    composerBusy: boolean;
    editing: boolean;
    onReply: (message: ChatMessage) => void;
    onCopy: (message: ChatMessage) => void;
    onPin: (message: ChatMessage) => void;
    onSave: (message: ChatMessage) => void;
    onEdit: (message: ChatMessage) => void;
    onRemove: (message: ChatMessage) => void;
};

/**
 * One set of actions for a message (DESIGN.md "Chat — the Chat v3 boards"): the hover bar, the ⋯
 * button, right-click and Shift+F10 all show these. Items for features still to come (threads,
 * share, turn into, message info, report) join this list when they are built.
 */
export function messageMenuItems(
    message: ChatMessage,
    actions: MessageMenuActions,
): MenuItem[] {
    if (message.deleted) return [];
    const cannotSend = actions.canSend
        ? undefined
        : 'You can’t send messages in this conversation';
    const changeBlocked = (allowed: boolean | undefined, fallback: string) =>
        cannotSend ??
        (actions.editing
            ? 'Finish the edit you’re making first'
            : actions.composerBusy
              ? 'Wait until your message has sent'
              : allowed
                ? undefined
                : message.change_unavailable_reason || fallback);
    return compactMenu([
        {
            label: 'Reply',
            icon: Reply,
            onClick: () => actions.onReply(message),
            disabled: cannotSend,
        },
        !!message.content && {
            label: 'Copy text',
            icon: Copy,
            onClick: () => actions.onCopy(message),
        },
        { separator: true },
        {
            label: message.pinned ? 'Unpin' : 'Pin',
            icon: Pin,
            onClick: () => actions.onPin(message),
            disabled: cannotSend,
        },
        {
            label: message.saved ? 'Unsave' : 'Save for me',
            icon: Bookmark,
            onClick: () => actions.onSave(message),
        },
        message.mine && { separator: true },
        message.mine && {
            label: 'Edit',
            icon: Pencil,
            onClick: () => actions.onEdit(message),
            disabled: changeBlocked(
                message.can_edit,
                'This message can no longer be edited',
            ),
        },
        message.mine && {
            label: 'Delete for everyone',
            icon: Trash2,
            danger: true,
            onClick: () => actions.onRemove(message),
            disabled: changeBlocked(
                message.can_remove,
                'This message can no longer be deleted',
            ),
        },
    ]);
}

/**
 * The message menu: the quick reactions, then the actions. Opens at the pointer (right-click), at
 * the ⋯ button, or at the message (Shift+F10, the Menu key). Arrow keys move, Escape closes and
 * returns focus; a click elsewhere or a scroll closes it.
 */
export function ThreadMessageMenu({
    message,
    x,
    y,
    items,
    quickReactions = QUICK_REACTIONS,
    canReact,
    onReact,
    onClose,
}: {
    message: ChatMessage;
    x: number;
    y: number;
    items: MenuItem[];
    quickReactions?: string[];
    canReact: boolean;
    onReact: (message: ChatMessage, emoji: string) => void;
    onClose: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const idBase = useId();
    const [position, setPosition] = useState({ x, y });
    const returnFocus = useRef<HTMLElement | null>(null);

    useEffect(() => {
        returnFocus.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        ref.current
            ?.querySelector<HTMLButtonElement>('[role^="menuitem"]')
            ?.focus();
    }, []);

    useLayoutEffect(() => {
        const element = ref.current;
        if (!element) return;
        const bounds = element.getBoundingClientRect();
        setPosition({
            x: Math.max(8, Math.min(x, window.innerWidth - bounds.width - 8)),
            y: Math.max(8, Math.min(y, window.innerHeight - bounds.height - 8)),
        });
    }, [x, y]);

    useEffect(() => {
        const outside = (event: Event) => {
            if (!ref.current?.contains(event.target as Node)) onClose();
        };
        window.addEventListener('mousedown', outside);
        window.addEventListener('scroll', outside, true);
        window.addEventListener('resize', onClose);
        return () => {
            window.removeEventListener('mousedown', outside);
            window.removeEventListener('scroll', outside, true);
            window.removeEventListener('resize', onClose);
        };
    }, [onClose]);

    const close = (restore = true) => {
        onClose();
        if (restore && returnFocus.current?.isConnected)
            returnFocus.current.focus();
    };

    const move = (step: number | 'first' | 'last') => {
        const entries = Array.from(
            ref.current?.querySelectorAll<HTMLElement>('[role^="menuitem"]') ??
                [],
        );
        if (!entries.length) return;
        const current = entries.indexOf(document.activeElement as HTMLElement);
        const next =
            step === 'first'
                ? 0
                : step === 'last'
                  ? entries.length - 1
                  : (current + step + entries.length) % entries.length;
        entries[next].focus();
    };

    return (
        <div
            ref={ref}
            role="menu"
            aria-label={`Message from ${message.mine ? 'you' : message.sender_name}`}
            className="fixed z-50 min-w-56 rounded-xl border bg-popover p-1 text-popover-foreground shadow-lg"
            style={{ left: position.x, top: position.y }}
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    close();
                } else if (
                    event.key === 'ArrowDown' ||
                    event.key === 'ArrowRight'
                ) {
                    event.preventDefault();
                    move(1);
                } else if (
                    event.key === 'ArrowUp' ||
                    event.key === 'ArrowLeft'
                ) {
                    event.preventDefault();
                    move(-1);
                } else if (event.key === 'Home') {
                    event.preventDefault();
                    move('first');
                } else if (event.key === 'End') {
                    event.preventDefault();
                    move('last');
                } else if (event.key === 'Tab') {
                    close(false);
                }
            }}
        >
            {canReact && (
                <div
                    role="group"
                    aria-label="React"
                    className="flex items-center gap-0.5 border-b px-1 pb-1"
                >
                    {quickReactions.map((emoji) => {
                        const mine = message.reactions.some(
                            (reaction) =>
                                reaction.emoji === emoji && reaction.mine,
                        );
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- a menu row (role menuitem), laid out like EntityContextMenu
                            <button
                                key={emoji}
                                type="button"
                                role="menuitemcheckbox"
                                aria-checked={mine}
                                aria-label={`React with ${emoji}`}
                                className={cn(
                                    'flex size-8 items-center justify-center rounded-lg text-lg transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:outline-none',
                                    mine && 'bg-primary/10',
                                )}
                                onClick={() => {
                                    onReact(message, emoji);
                                    close();
                                }}
                            >
                                {emoji}
                            </button>
                        );
                    })}
                </div>
            )}
            <div className={cn(canReact && 'pt-1')}>
                {items.map((item, index) =>
                    item.separator ? (
                        <div
                            key={`separator-${index}`}
                            role="separator"
                            className="-mx-1 my-1 h-px bg-border"
                        />
                    ) : (
                        // eslint-disable-next-line no-restricted-syntax -- a menu row (role menuitem), laid out like EntityContextMenu
                        <button
                            key={item.label}
                            type="button"
                            role="menuitem"
                            aria-disabled={item.disabled ? true : undefined}
                            aria-describedby={
                                item.disabled
                                    ? `${idBase}-${index}-reason`
                                    : undefined
                            }
                            className={cn(
                                'flex w-full items-start gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:outline-none',
                                item.danger &&
                                    !item.disabled &&
                                    'text-destructive',
                                item.disabled &&
                                    'cursor-not-allowed text-muted-foreground',
                            )}
                            onClick={() => {
                                if (item.disabled) return;
                                close(false);
                                item.onClick?.();
                            }}
                        >
                            {item.icon && (
                                <item.icon className="mt-0.5 size-4 shrink-0" />
                            )}
                            <span className="min-w-0">
                                <span className="block">{item.label}</span>
                                {item.disabled && (
                                    <span
                                        id={`${idBase}-${index}-reason`}
                                        className="text-caption block"
                                    >
                                        {item.disabled}
                                    </span>
                                )}
                            </span>
                        </button>
                    ),
                )}
            </div>
        </div>
    );
}
