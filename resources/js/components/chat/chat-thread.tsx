import { Button } from '@/components/ui/button';
import { LoadingState } from '@/components/ui/loading-state';
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
    type ComponentProps,
    type Ref,
} from 'react';
import { toast } from 'sonner';
import type { ChatRemovalDraft } from './api';
import {
    chatDraftKey,
    conversationPath,
    readChatThreadMemory,
    saveChatThreadMemory,
} from './api';
import { useCallConversation } from './call-conversation-context';
import { chatActorRequest } from './chat-actor-request';
import { ChatAudienceReview } from './chat-audience-review';
import { ChatCallbacks } from './chat-callbacks';
import { forgetChatComposerSession } from './chat-composer-session';
import { ChatDetails } from './chat-details';
import { ChatFamilyWork } from './chat-family-work';
import { ChatMessageRemoval } from './chat-message-removal';
import { useChatPreferences } from './chat-personal-preferences';
import { useChatRealtime } from './chat-realtime';
import { registerChatSoundView } from './chat-sound-views';
import { chatSync } from './live/sync';
import { typingLabel, useTypingNames } from './live/typing';
import { clearChatOutbox } from './thread/chat-outbox';
import { messageMenuItems, ThreadMessageMenu } from './thread/message-menu';
import { ThreadComposer } from './thread/thread-composer';
import {
    ThreadFamilyNotice,
    ThreadHeader,
    ThreadSearchBar,
    ThreadUpdateStatus,
} from './thread/thread-header';
import { ThreadMessageList } from './thread/thread-message-list';
import { ThreadOutboxMessages } from './thread/thread-outbox';
import { ThreadPendingSend } from './thread/thread-pending-send';
import { useOnline } from './thread/use-online';
import { useThreadComposer } from './thread/use-thread-composer';
import {
    useThreadHistory,
    type ThreadHistoryEffects,
} from './thread/use-thread-history';
import { useThreadOutbox } from './thread/use-thread-outbox';
import { useThreadSearch } from './thread/use-thread-search';
import type { ChatMessage, ChatPolicy } from './types';

export type ChatThreadControls = { closeDetails: () => boolean };

export function ChatThread({
    inCall = false,
    ...props
}: ComponentProps<typeof ChatThreadContent> & { inCall?: boolean }) {
    const call = useCallConversation();
    if (
        !inCall &&
        call?.actorId === props.actorId &&
        call.conversationId === props.conversationId
    ) {
        return (
            <section className="flex h-full min-w-0 flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
                <p className="font-semibold">
                    {call.mode === 'followup'
                        ? 'Conversation open in call follow-up'
                        : 'Conversation open in your call'}
                </p>
                <p className="text-sm text-muted-foreground">
                    Your draft stays with the conversation. Close this view to
                    continue here.
                </p>
                <Button
                    variant="outline"
                    onClick={call.returnToCall}
                >
                    {call.mode === 'followup'
                        ? 'Return to call follow-up'
                        : 'Return to call conversation'}
                </Button>
            </section>
        );
    }
    return (
        <ChatThreadContent key={props.actorId} {...props} compact={inCall} />
    );
}

/**
 * One conversation. The parts live in ./thread: the history and reader position
 * (use-thread-history), search (use-thread-search), writing and sending (use-thread-composer),
 * the offline outbox (use-thread-outbox), and the screens built from them. This component wires
 * them together and keeps the order of their resets and restores.
 */
function ChatThreadContent({
    conversationId,
    actorId,
    family = false,
    policy: listPolicy,
    onBack,
    onChange,
    initialMessage = null,
    handoffRequest = 0,
    familyInbox = false,
    compact = false,
    controlsRef,
}: {
    conversationId: number;
    actorId: number;
    family?: boolean;
    policy: ChatPolicy;
    onBack?: () => void;
    onChange?: () => void;
    initialMessage?: number | null;
    handoffRequest?: number;
    familyInbox?: boolean;
    compact?: boolean;
    controlsRef?: Ref<ChatThreadControls>;
}) {
    const { values: personal } = useChatPreferences(actorId);
    const realtime = useChatRealtime();
    const online = useOnline();
    const viewport = useRef<HTMLDivElement>(null);
    const keyRef = useRef('');
    const effects = useRef<ThreadHistoryEffects>({
        messagesChanged: () => undefined,
        snapshotReset: () => undefined,
        accessLost: () => undefined,
        jumped: () => undefined,
    });
    const history = useThreadHistory({
        actorId,
        conversationId,
        keyRef,
        effects,
        viewport,
    });
    const { load, invalidateLoads } = history;
    const conversation = history.history?.conversation;
    const policy = conversation?.policy ?? listPolicy;
    const key = chatDraftKey(
        actorId,
        conversation?.client?.id ?? null,
        conversationId,
    );
    keyRef.current = key;
    const hasConversation = Boolean(conversation);
    const search = useThreadSearch({
        actorId,
        conversationId,
        keyRef,
        hasConversation,
        invalidateLoads,
        dropHistory: history.dropHistory,
    });
    const composer = useThreadComposer({
        actorId,
        conversationId,
        draftKey: key,
        keyRef,
        conversation,
        policy,
        history,
        online,
        onChange,
    });
    const outbox = useThreadOutbox({
        actorId,
        conversationId,
        conversation,
        policy,
        composer,
        history,
        onChange,
    });
    const typingText = typingLabel(useTypingNames(conversationId));
    const [deleting, setDeleting] = useState<ChatRemovalDraft | null>(null);
    // The one message menu (hover bar ⋯, right-click, Shift+F10), at a point on the screen.
    const [menu, setMenu] = useState<{
        message: ChatMessage;
        x: number;
        y: number;
    } | null>(null);
    const [acting, setActing] = useState(false);
    const actionTrigger = useRef<HTMLButtonElement | null>(null);
    const detailsTrigger = useRef<HTMLButtonElement | null>(null);
    const [details, setDetails] = useState(false);
    useImperativeHandle(
        controlsRef,
        () => ({
            closeDetails: () => {
                if (!details) return false;
                setDetails(false);
                requestAnimationFrame(() => detailsTrigger.current?.focus());
                return true;
            },
        }),
        [details],
    );
    const operations = useRef(new AbortController());
    useEffect(() => {
        const controller = new AbortController();
        operations.current = controller;
        return () => controller.abort();
    }, [conversationId]);
    const soundViewAvailable = hasConversation && !history.failure;
    useEffect(() => {
        if (!soundViewAvailable) return;
        return registerChatSoundView(actorId, conversationId);
    }, [actorId, conversationId, soundViewAvailable]);
    useEffect(() => {
        setActing(false);
    }, [actorId, conversationId]);

    effects.current = {
        messagesChanged: (updated) => {
            search.messagesChanged(updated);
            composer.messagesChanged(updated);
            setMenu((current) => {
                const replacement = current && updated.get(current.message.id);
                if (!current || !replacement) return current;
                return replacement.deleted
                    ? null
                    : { ...current, message: replacement };
            });
        },
        snapshotReset: search.revoke,
        accessLost: () => {
            forgetChatComposerSession(actorId, conversationId);
            clearChatOutbox(keyRef.current);
            search.revoke();
            setDeleting(null);
            saveChatThreadMemory(keyRef.current, { removing: null });
        },
        jumped: () => {
            search.dismiss();
            saveChatThreadMemory(keyRef.current, {
                searchOpen: false,
                query: '',
            });
        },
    };

    const resetHistory = history.reset;
    const dismissSearch = search.dismiss;
    const resetComposer = composer.reset;
    const historyRef = history.historyRef;
    useEffect(() => {
        const controller = new AbortController();
        resetHistory();
        dismissSearch();
        resetComposer();
        setDeleting(null);
        // One sync loop: fetch this conversation's changes when a live event names it and
        // its cursor is behind; poll only while live updates are down; catch up after reconnects.
        const stopUpdates = chatSync.watch({
            refresh: async () => Boolean(await load(controller.signal)),
            topics: ['conversation.changed'],
            match: (event) =>
                event.type === 'conversation.changed' &&
                event.conversation_id === conversationId,
            cursor: {
                conversationId,
                position: () =>
                    historyRef.current?.conversation.id === conversationId
                        ? (historyRef.current.sync_change_id ?? null)
                        : null,
            },
            wakeOn: ['oblivion:chat-refresh'],
            poll: 'fallback',
        });
        return () => {
            controller.abort();
            stopUpdates();
            invalidateLoads();
        };
    }, [
        conversationId,
        load,
        invalidateLoads,
        resetHistory,
        dismissSearch,
        resetComposer,
        historyRef,
    ]);

    const restoreComposer = composer.restore;
    const restoreSearch = search.restore;
    const { restoreReadAnchor } = history;
    useEffect(() => {
        const controller = new AbortController();
        if (hasConversation) {
            const remembered = readChatThreadMemory(key);
            const finish = restoreComposer(key, remembered, controller);
            setDeleting(remembered.removing ?? null);
            restoreSearch(remembered);
            if (remembered.readAnchor)
                restoreReadAnchor(remembered.readAnchor, remembered.readOffset);
            finish();
        }
        return () => {
            controller.abort();
        };
    }, [
        key,
        hasConversation,
        actorId,
        conversationId,
        load,
        composer.replyRetry,
        restoreComposer,
        restoreSearch,
        restoreReadAnchor,
    ]);

    const thread = history.history;
    const { atEnd, contextMessage } = history;
    const latestId = thread?.messages.at(-1)?.id;
    const unreadMarker = thread?.conversation.unread_marker;
    // The first unread message is fixed when the conversation opens, so the
    // "New messages" divider stays put after the read receipt clears the count.
    const [firstUnreadId, setFirstUnreadId] = useState<
        number | null | undefined
    >(undefined);
    const [unseenBelow, setUnseenBelow] = useState(false);
    if (thread && firstUnreadId === undefined) {
        const unread = thread.conversation.unread;
        const fromOthers = thread.messages.filter((message) => !message.mine);
        const first =
            unread > 0 && unread <= fromOthers.length
                ? fromOthers[fromOthers.length - unread].id
                : null;
        setFirstUnreadId(first);
        if (first !== null) setUnseenBelow(true);
    }
    if (atEnd && unseenBelow) setUnseenBelow(false);
    // Announce only messages from others that arrive after opening, never
    // the history that was already there.
    const announcedThrough = useRef<number | null>(null);
    const [announcement, setAnnouncement] = useState('');
    useEffect(() => {
        const list = thread?.messages;
        if (!list?.length || !latestId) return;
        const previous = announcedThrough.current;
        announcedThrough.current = Math.max(previous ?? 0, latestId);
        if (previous === null) return;
        const arrived = list.filter(
            (message) =>
                message.id > previous && !message.mine && !message.deleted,
        );
        if (!arrived.length) return;
        setAnnouncement(
            arrived.length === 1
                ? `New message from ${arrived[0].sender_name}`
                : `${arrived.length} new messages`,
        );
        if (!atEnd) setUnseenBelow(true);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- runs per new latest message only
    }, [latestId]);
    const waiting = outbox.entries.length;
    useEffect(() => {
        if (atEnd)
            viewport.current?.scrollTo({ top: viewport.current.scrollHeight });
    }, [latestId, atEnd, composer.sending, composer.checkingSend, waiting]);
    const searchResults = search.results;
    useEffect(() => {
        const remembered = history.restorePosition.current;
        const el = viewport.current;
        if (!remembered || !el || atEnd || searchResults) return;
        const anchor = el.querySelector<HTMLElement>(
            `[data-chat-message="${remembered.id}"]`,
        );
        if (!anchor) return;
        el.scrollTop +=
            anchor.getBoundingClientRect().top -
            el.getBoundingClientRect().top -
            remembered.offset;
        history.restorePosition.current = null;
    }, [thread, atEnd, searchResults, history.restorePosition]);
    const failure = history.failure;
    useEffect(() => {
        if (
            !latestId ||
            !atEnd ||
            searchResults ||
            failure ||
            contextMessage ||
            thread?.has_more_changes
        )
            return;
        let stopped = false;
        const mark = () => {
            if (document.visibilityState === 'visible' && document.hasFocus()) {
                void chatActorRequest(
                    actorId,
                    `${conversationPath(conversationId)}/read`,
                    {
                        method: 'POST',
                        body: JSON.stringify({
                            message_id: latestId,
                            unread_marker: unreadMarker ?? null,
                        }),
                    },
                )
                    .then(() => {
                        if (stopped) return;
                        onChange?.();
                        window.dispatchEvent(
                            new Event('oblivion:chat-unread-changed'),
                        );
                    })
                    .catch(() => undefined);
            }
        };
        const timer = window.setTimeout(mark, 700);
        window.addEventListener('focus', mark);
        document.addEventListener('visibilitychange', mark);
        return () => {
            stopped = true;
            clearTimeout(timer);
            window.removeEventListener('focus', mark);
            document.removeEventListener('visibilitychange', mark);
        };
    }, [
        actorId,
        conversationId,
        latestId,
        atEnd,
        searchResults,
        failure,
        onChange,
        contextMessage,
        unreadMarker,
        thread?.has_more_changes,
    ]);

    const { jumpToMessage } = history;
    const openedMessage = useRef<number | null>(null);
    useEffect(() => {
        if (
            !hasConversation ||
            !initialMessage ||
            openedMessage.current === initialMessage
        )
            return;
        openedMessage.current = initialMessage;
        void jumpToMessage(initialMessage);
    }, [hasConversation, initialMessage, jumpToMessage]);

    async function action(
        message: ChatMessage,
        action: 'save' | 'pin' | 'react',
        emoji?: string,
    ) {
        if (acting) return;
        const id = conversationId;
        const signal = operations.current.signal;
        setActing(true);
        try {
            await chatActorRequest(
                actorId,
                `/chat/api/messages/${message.id}/action`,
                {
                    method: 'POST',
                    signal,
                    body: JSON.stringify({
                        action,
                        emoji,
                        active:
                            action === 'pin'
                                ? !message.pinned
                                : action === 'save'
                                  ? !message.saved
                                  : !message.reactions.some(
                                        (reaction) =>
                                            reaction.emoji === emoji &&
                                            reaction.mine,
                                    ),
                    }),
                },
            );
            if (signal.aborted || history.active.current !== id) return;
            await load(undefined, true);
        } catch (error) {
            if (!signal.aborted) toast.error((error as Error).message);
        } finally {
            if (!signal.aborted) setActing(false);
        }
    }
    async function preference(
        name: 'is_muted' | 'is_favourite',
        value: boolean,
    ) {
        const signal = operations.current.signal;
        try {
            await chatActorRequest(
                actorId,
                `${conversationPath(conversationId)}/preferences`,
                {
                    method: 'PATCH',
                    signal,
                    body: JSON.stringify({ [name]: value }),
                },
            );
            if (signal.aborted) return;
            await load(undefined, true);
            if (!signal.aborted) onChange?.();
        } catch (error) {
            if (!signal.aborted) toast.error((error as Error).message);
        }
    }

    function replyTo(message: ChatMessage) {
        search.setOpen(false);
        composer.setReply(message);
        composer.focusSoon();
    }
    function startEdit(message: ChatMessage) {
        const next = {
            messageId: message.id,
            version: message.edit_version ?? 0,
            content: message.content,
            mentions: message.mentions ?? [],
        };
        saveChatThreadMemory(key, { editing: next });
        search.setOpen(false);
        composer.setEditing(next);
    }
    function startRemove(message: ChatMessage) {
        // Focus goes back to the message when the confirmation closes.
        actionTrigger.current = document.getElementById(
            `chat-message-${message.id}`,
        ) as HTMLButtonElement | null;
        const next = {
            messageId: message.id,
            version: message.edit_version ?? 0,
            content: message.content,
        };
        saveChatThreadMemory(key, { removing: next });
        setDeleting(next);
    }
    function copyText(message: ChatMessage) {
        void navigator.clipboard
            ?.writeText(message.content)
            .then(() => toast.success('Copied'))
            .catch(() => toast.error('The text couldn’t be copied.'));
    }

    // Message rows are memoised, so the callbacks they get keep their identity.
    const latest = useRef({ jumpToMessage, action, replyTo });
    latest.current = { jumpToMessage, action, replyTo };
    const onJump = useCallback((messageId: number) => {
        void latest.current.jumpToMessage(messageId);
    }, []);
    const onReact = useCallback((message: ChatMessage, emoji: string) => {
        void latest.current.action(message, 'react', emoji);
    }, []);
    const onReply = useCallback((message: ChatMessage) => {
        latest.current.replyTo(message);
    }, []);
    const onMenu = useCallback(
        (message: ChatMessage, x: number, y: number) =>
            setMenu({ message, x, y }),
        [],
    );
    const closeMenu = useCallback(() => setMenu(null), []);

    function rememberPosition() {
        const el = viewport.current;
        if (!el || history.restorePosition.current) return;
        const nearEnd =
            el.scrollHeight - el.scrollTop - el.clientHeight < 60 &&
            history.contextRef.current === null;
        history.setAtEnd(nearEnd);
        if (search.open) return;
        const top = el.getBoundingClientRect().top;
        const anchor = Array.from(
            el.querySelectorAll<HTMLElement>('[data-chat-message]'),
        ).find((message) => message.getBoundingClientRect().bottom > top);
        saveChatThreadMemory(keyRef.current, {
            readAnchor: nearEnd
                ? null
                : anchor
                  ? Number(anchor.dataset.chatMessage)
                  : history.contextRef.current,
            readOffset:
                nearEnd || !anchor
                    ? 0
                    : anchor.getBoundingClientRect().top - top,
        });
    }

    if (history.loading)
        return (
            <div
                className="flex h-full items-center justify-center"
                role="status"
            >
                <LoadingState message="Opening conversation…" />
            </div>
        );
    if (!conversation || !thread)
        return (
            <div className="m-auto max-w-sm space-y-3 p-6 text-center">
                <h2 className="font-semibold">Conversation unavailable</h2>
                <p className="text-sm text-muted-foreground">
                    {failure || 'Choose another conversation.'}
                </p>
                <Button
                    variant="outline"
                    onClick={() => void load(undefined, true)}
                >
                    Try again
                </Button>
            </div>
        );
    const messages = searchResults ?? thread.messages;
    const pendingBody = composer.pending;
    const familyWork = !family && conversation.type === 'family' && (
        <ChatFamilyWork
            inbox={familyInbox}
            handoffRequest={handoffRequest}
            actorId={actorId}
            conversationId={conversationId}
            clientName={conversation.client?.name ?? conversation.title}
            onChange={() => {
                void load(undefined, true);
                onChange?.();
            }}
        />
    );

    return (
        <div className="flex h-full min-h-0 min-w-0 flex-1">
            <section
                className={cn(
                    'h-full min-h-0 min-w-0 flex-1 flex-col bg-background',
                    details ? (compact ? 'hidden' : 'hidden md:flex') : 'flex',
                )}
                aria-label={`Conversation: ${conversation.title}`}
            >
                {familyInbox && familyWork}
                <ThreadHeader
                    conversation={conversation}
                    actorId={actorId}
                    family={family}
                    detailsTrigger={detailsTrigger}
                    onBack={onBack}
                    onDetails={() => setDetails(true)}
                    onToggleSearch={() => search.setOpen(!search.open)}
                    onFavourite={() =>
                        void preference('is_favourite', !conversation.favourite)
                    }
                />
                {conversation.type === 'family' && (
                    <ThreadFamilyNotice
                        conversation={conversation}
                        actorId={actorId}
                        family={family}
                        policy={policy}
                    />
                )}
                {!familyInbox && familyWork}
                {conversation.type === 'family' && (!familyInbox || family) && (
                    <div className="flex shrink-0 justify-end border-b px-3 py-1">
                        <ChatCallbacks
                            conversationId={conversationId}
                            actorId={actorId}
                            family={family}
                        />
                    </div>
                )}
                <ThreadSearchBar
                    search={search}
                    onClose={() => {
                        search.setOpen(false);
                        search.setQuery('');
                        composer.focusSoon();
                    }}
                />
                <ThreadUpdateStatus
                    failure={failure}
                    recovering={realtime === 'recovering'}
                    catchingUp={!!thread.has_more_changes}
                    onRetry={() => void load(undefined, true)}
                />
                <p className="sr-only" role="status" aria-live="polite">
                    {announcement}
                </p>
                <ThreadMessageList
                    viewport={viewport}
                    messages={messages}
                    hasMore={thread.has_more}
                    inSearch={!!searchResults}
                    searchPending={search.busy || !!search.error}
                    contextMessage={contextMessage}
                    firstUnreadId={firstUnreadId}
                    personal={personal}
                    actorId={actorId}
                    conversationId={conversationId}
                    older={history.older}
                    loadOlder={history.loadOlder}
                    onScroll={rememberPosition}
                    onReturnToLatest={history.returnToLatest}
                    onJump={onJump}
                    onReact={onReact}
                    canReact={conversation.can_send}
                    onReply={onReply}
                    onMenu={onMenu}
                >
                    {!search.open &&
                        !contextMessage &&
                        pendingBody &&
                        !thread.messages.some(
                            (message) =>
                                message.mine &&
                                message.mutation_id ===
                                    pendingBody.get('mutation_id'),
                        ) && (
                            <ThreadPendingSend
                                body={pendingBody}
                                files={composer.files}
                                captions={composer.fileCaptions}
                                sending={composer.sending}
                                checking={composer.checkingSend}
                                onRetry={
                                    conversation.can_send && policy.enabled
                                        ? () => void composer.send()
                                        : undefined
                                }
                            />
                        )}
                    {!search.open && !contextMessage && (
                        <ThreadOutboxMessages
                            outbox={outbox}
                            online={online}
                            composerEmpty={composer.empty}
                        />
                    )}
                </ThreadMessageList>
                {typingText && !search.open && (
                    <p className="text-caption flex shrink-0 items-center gap-2 px-4 pb-1 text-muted-foreground sm:px-6">
                        <span className="flex gap-0.5" aria-hidden="true">
                            {[0, 150, 300].map((delay) => (
                                <span
                                    key={delay}
                                    className="size-1 rounded-full bg-muted-foreground motion-safe:animate-pulse"
                                    style={{ animationDelay: `${delay}ms` }}
                                />
                            ))}
                        </span>
                        {typingText}
                    </p>
                )}
                {!atEnd && !searchResults && (
                    <div className="relative h-0">
                        <Button
                            className="absolute right-4 bottom-3 shadow-lg"
                            size="sm"
                            onClick={() => history.setAtEnd(true)}
                        >
                            <ChevronDown className="size-4" />
                            {unseenBelow ? 'New messages' : 'Latest messages'}
                        </Button>
                    </div>
                )}
                <ThreadComposer
                    composer={composer}
                    outbox={outbox}
                    conversation={conversation}
                    policy={policy}
                    personal={personal}
                    actorId={actorId}
                    conversationId={conversationId}
                    hidden={search.open}
                    onEdited={(message) => {
                        history.setHistory((current) =>
                            current
                                ? {
                                      ...current,
                                      messages: current.messages.map((item) =>
                                          item.id === message.id
                                              ? message
                                              : item,
                                      ),
                                  }
                                : current,
                        );
                        void load(undefined, true);
                        onChange?.();
                    }}
                />
                {menu && (
                    <ThreadMessageMenu
                        message={menu.message}
                        x={menu.x}
                        y={menu.y}
                        canReact={conversation.can_send && !acting}
                        onReact={onReact}
                        onClose={closeMenu}
                        items={messageMenuItems(menu.message, {
                            canSend: conversation.can_send && !acting,
                            composerBusy: composer.sending || !!pendingBody,
                            editing: !!composer.editing,
                            onReply: replyTo,
                            onCopy: copyText,
                            onPin: (message) => void action(message, 'pin'),
                            onSave: (message) => void action(message, 'save'),
                            onEdit: startEdit,
                            onRemove: startRemove,
                        })}
                    />
                )}
                {deleting && (
                    <ChatMessageRemoval
                        actorId={actorId}
                        key={`${key}:${deleting.messageId}`}
                        memoryKey={key}
                        conversationId={conversationId}
                        initial={deleting}
                        canSend={conversation.can_send && policy.enabled}
                        onClose={() => setDeleting(null)}
                        onRemoved={() => {
                            // The action button disappears with the removed message.
                            actionTrigger.current = null;
                            setDeleting(null);
                            void load(undefined, true);
                            onChange?.();
                        }}
                        onReturnFocus={() => {
                            if (actionTrigger.current?.isConnected)
                                actionTrigger.current.focus();
                            else composer.focus();
                        }}
                    />
                )}
            </section>
            {composer.audienceReview && (
                <ChatAudienceReview
                    actorId={actorId}
                    key={`${actorId}:${composer.audienceReview.id}`}
                    conversation={composer.audienceReview}
                    onClose={() => composer.setAudienceReview(null)}
                    onReviewed={(updated) => {
                        history.setHistory((current) =>
                            current
                                ? { ...current, conversation: updated }
                                : current,
                        );
                        composer.setSendError('');
                        toast.success(
                            'Audience confirmed. Your message is ready to send.',
                        );
                    }}
                    onReturnFocus={composer.focus}
                />
            )}
            {details && (
                <ChatDetails
                    compact={compact}
                    conversation={conversation}
                    actorId={actorId}
                    onClose={() => {
                        setDetails(false);
                        requestAnimationFrame(() =>
                            detailsTrigger.current?.focus(),
                        );
                    }}
                    onMessage={(id) => {
                        setDetails(false);
                        void jumpToMessage(id);
                    }}
                    onChanged={() => {
                        void load(undefined, true);
                        onChange?.();
                    }}
                    onExit={() => {
                        setDetails(false);
                        onBack?.();
                    }}
                />
            )}
        </div>
    );
}
