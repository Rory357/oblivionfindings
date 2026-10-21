import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { chatMentionQuery, moveChatMentions } from './mentions';
import type { ChatMention, ChatPerson } from './types';

export function ChatMentionInput({
    value,
    mentions,
    people,
    disabled,
    onChange,
    onSend,
    enterSends = true,
    textSize,
    placeholder = 'Write a message…',
}: {
    value: string;
    mentions: ChatMention[];
    people: ChatPerson[];
    disabled: boolean;
    onChange: (value: string, mentions: ChatMention[]) => void;
    onSend: () => void;
    enterSends?: boolean;
    textSize?: string;
    placeholder?: string;
}) {
    const input = useRef<HTMLTextAreaElement>(null);
    const listId = useId();
    const [caret, setCaret] = useState(0);
    const [focused, setFocused] = useState(false);
    const [dismissed, setDismissed] = useState('');
    const [index, setIndex] = useState(0);
    const [composing, setComposing] = useState(false);
    const query =
        focused && !disabled && !composing && dismissed !== `${value}:${caret}`
            ? chatMentionQuery(value, caret)
            : null;
    const candidates = query
        ? people.filter((person) =>
              person.name
                  .toLocaleLowerCase()
                  .includes(query.query.toLocaleLowerCase()),
          )
        : [];
    const selected = Math.min(index, Math.max(0, candidates.length - 1));
    const canChoose = candidates.length > 0 && mentions.length < 30;
    const activeOption =
        query && canChoose ? `${listId}-${candidates[selected].id}` : undefined;

    useEffect(() => {
        if (activeOption)
            document
                .getElementById(activeOption)
                ?.scrollIntoView?.({ block: 'nearest' });
    }, [activeOption]);

    function choose(person: ChatPerson) {
        if (!query) return;
        const label = `@${person.name.trim()}`;
        const next =
            value.slice(0, query.start) + label + ' ' + value.slice(query.end);
        if (next.length > 10000 || mentions.length >= 30) return;
        const replaceStart = Array.from(value.slice(0, query.start)).length;
        const replaceEnd = Array.from(value.slice(0, query.end)).length;
        const preserved = moveChatMentions(
            value,
            next,
            mentions.filter(
                (mention) =>
                    mention.start + mention.length <= replaceStart ||
                    mention.start >= replaceEnd,
            ),
        );
        onChange(
            next,
            [
                ...preserved,
                {
                    user_id: person.id,
                    start: Array.from(value.slice(0, query.start)).length,
                    length: Array.from(label).length,
                    label,
                },
            ].sort((a, b) => a.start - b.start),
        );
        const position = query.start + label.length + 1;
        setCaret(position);
        setIndex(0);
        requestAnimationFrame(() => {
            input.current?.focus();
            input.current?.setSelectionRange(position, position);
        });
    }

    return (
        <div
            className="relative min-w-0"
            onBlur={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget))
                    setFocused(false);
            }}
        >
            <textarea
                ref={input}
                aria-label="Message"
                aria-autocomplete="list"
                aria-haspopup="listbox"
                aria-controls={query ? listId : undefined}
                aria-activedescendant={activeOption}
                aria-describedby={`${listId}-hint`}
                placeholder={placeholder}
                rows={2}
                maxLength={10000}
                value={value}
                disabled={disabled}
                className={cn(
                    'max-h-36 min-h-[72px] w-full min-w-0 resize-y bg-transparent px-2 py-2 leading-relaxed outline-none',
                    textSize || 'text-base sm:text-[15px]',
                )}
                onFocus={(event) => {
                    setFocused(true);
                    setCaret(event.currentTarget.selectionStart);
                }}
                onSelect={(event) =>
                    setCaret(event.currentTarget.selectionStart)
                }
                onCompositionStart={() => setComposing(true)}
                onCompositionEnd={() => setComposing(false)}
                onChange={(event) => {
                    setCaret(event.target.selectionStart);
                    setIndex(0);
                    onChange(
                        event.target.value,
                        moveChatMentions(value, event.target.value, mentions),
                    );
                }}
                onKeyDown={(event) => {
                    if (event.nativeEvent.isComposing || composing) return;
                    if (
                        query &&
                        canChoose &&
                        ['ArrowDown', 'ArrowUp'].includes(event.key)
                    ) {
                        event.preventDefault();
                        setIndex(
                            (selected +
                                (event.key === 'ArrowDown' ? 1 : -1) +
                                candidates.length) %
                                candidates.length,
                        );
                        return;
                    }
                    if (query && event.key === 'Escape') {
                        event.preventDefault();
                        event.stopPropagation();
                        setDismissed(`${value}:${caret}`);
                        return;
                    }
                    if (event.key === 'Enter' && !event.shiftKey) {
                        if (query && canChoose) {
                            event.preventDefault();
                            choose(candidates[selected]);
                        } else if (enterSends) {
                            event.preventDefault();
                            onSend();
                        }
                    }
                }}
            />
            <span id={`${listId}-hint`} className="sr-only">
                Type @ to mention a conversation member. Use arrow keys to
                choose and Enter to insert. Escape closes suggestions.{' '}
                {enterSends
                    ? 'Shift and Enter starts a new line.'
                    : 'Enter starts a new line. Use the Send button to send.'}
            </span>
            {query && (
                <div
                    className="absolute right-0 bottom-full left-0 z-20 mb-2 max-h-48 overflow-y-auto rounded-xl border bg-popover p-1 shadow-lg"
                    role="listbox"
                    id={listId}
                    aria-label="Mention eligible members"
                >
                    {candidates.map((person, position) => (
                        <Button
                            key={person.id}
                            id={`${listId}-${person.id}`}
                            type="button"
                            variant="ghost"
                            role="option"
                            aria-selected={position === selected}
                            tabIndex={-1}
                            disabled={mentions.length >= 30}
                            className={cn(
                                'min-h-11 w-full justify-start text-left whitespace-normal',
                                position === selected && 'bg-accent',
                            )}
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => choose(person)}
                        >
                            @{person.name}
                            {person.role && (
                                <span className="ml-auto text-xs text-muted-foreground">
                                    {person.role}
                                </span>
                            )}
                        </Button>
                    ))}
                    {!candidates.length && (
                        <p
                            role="status"
                            className="px-3 py-2 text-sm text-muted-foreground"
                        >
                            No eligible members match. You can keep this as
                            plain text.
                        </p>
                    )}
                    {mentions.length >= 30 && (
                        <p
                            role="status"
                            className="px-3 py-2 text-sm text-muted-foreground"
                        >
                            Up to 30 mentions per message.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

export function ChatMessageText({
    content,
    mentions = [],
    actorId,
}: {
    content: string;
    mentions?: ChatMention[];
    actorId: number;
}) {
    const characters = Array.from(content);
    const parts: ReactNode[] = [];
    let offset = 0;
    for (const mention of [...mentions].sort((a, b) => a.start - b.start)) {
        if (
            mention.start < offset ||
            characters
                .slice(mention.start, mention.start + mention.length)
                .join('') !== mention.label
        )
            continue;
        parts.push(characters.slice(offset, mention.start).join(''));
        parts.push(
            <span
                key={mention.start}
                className="rounded bg-primary/10 px-0.5 font-medium text-primary"
                title={
                    mention.user_id === actorId ? 'Mentioned you' : undefined
                }
            >
                {mention.label}
            </span>,
        );
        offset = mention.start + mention.length;
    }
    parts.push(characters.slice(offset).join(''));
    return <>{parts}</>;
}
