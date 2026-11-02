import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoadingState } from '@/components/ui/loading-state';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatRelative } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import {
    BellOff,
    ExternalLink,
    MessageCircle,
    Phone,
    Plus,
    Search,
    Settings2,
    ShieldCheck,
    Star,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ChatError } from './api';
import { CallHistory } from './call-history';
import { chatActorRequest } from './chat-actor-request';
import { useChatCalling } from './chat-calling';
import { ChatCollection } from './chat-collection';
import { ChatCreateConversation } from './chat-create-conversation';
import { ChatFamilyInbox } from './chat-family-inbox';
import { ChatInvitations } from './chat-invitations';
import { useChatPreferences } from './chat-personal-preferences';
import { ChatSearch } from './chat-search';
import { ChatShiftHandover } from './chat-shift-handover';
import { ChatThread } from './chat-thread';
import { mergeConversationRows, namedRows } from './live/list-rows';
import { chatSync, type SyncReason } from './live/sync';
import type { ChatConversation, ChatList } from './types';

export function ChatWorkspace(props: ChatWorkspaceProps) {
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    return (
        <ChatWorkspaceContent
            key={`${auth.user.id}:${props.client?.id ?? 'staff'}:${props.family === true}`}
            {...props}
        />
    );
}

type ChatWorkspaceProps = {
    client?: { id: number; name: string } | null;
    family?: boolean;
    initialConversation?: number | null;
    initialMessage?: number | null;
    initialCallHistory?: 'missed' | null;
    initialPerson?: number | null;
    compact?: boolean;
};

function ChatWorkspaceContent({
    client = null,
    family = false,
    initialConversation = null,
    initialMessage = null,
    initialCallHistory = null,
    initialPerson = null,
    compact = false,
}: ChatWorkspaceProps) {
    const { auth } = usePage<{
        auth: {
            user: { id: number };
            portalClients?: { id: number; name: string }[] | null;
        };
    }>().props;
    const portalClients =
        family &&
        client &&
        auth.portalClients?.some((entry) => entry.id === client.id)
            ? auth.portalClients
            : [];
    const Heading = compact ? 'h2' : 'h1';
    const [callsRequest, setCallsRequest] = useState(0);
    const { values: personal } = useChatPreferences(auth.user.id);
    const [data, setData] = useState<ChatList | null>(null);
    const dataRef = useRef<ChatList | null>(null);
    useEffect(() => {
        dataRef.current = data;
    }, [data]);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState<number | null>(
        initialConversation,
    );
    const [filter, setFilter] = useState('all');
    const [messageTarget, setMessageTarget] = useState<{
        conversation: number;
        message: number;
        sequence: number;
    } | null>(null);
    const [query, setQuery] = useState('');
    const [createOpen, setCreateOpen] = useState(false);
    const [creationPerson, setCreationPerson] = useState<number | null>(null);
    const [searchOpen, setSearchOpen] = useState(false);
    const [familyInboxOpen, setFamilyInboxOpen] = useState(false);
    const [searchReturn, setSearchReturn] = useState(false);
    const recipientOpened = useRef<string | null>(null);
    useEffect(() => {
        if (!data || !initialPerson) return;
        const context = `${client?.id ?? 'staff'}:${initialPerson}`;
        if (recipientOpened.current === context) return;
        recipientOpened.current = context;
        if (data.people.some((person) => person.id === initialPerson)) {
            setCreationPerson(initialPerson);
            setCreateOpen(true);
        } else
            toast.error(
                'That person is not currently available to message for this client. Choose the care team or another eligible person.',
            );
    }, [data, initialPerson, client?.id]);
    const clientId = client?.id ?? null;
    const calling = useChatCalling();
    const generation = useRef(0);
    const request = useRef<AbortController | null>(null);
    const refresh = useCallback(async () => {
        const version = generation.current;
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        try {
            const result = await chatActorRequest<ChatList>(
                auth.user.id,
                `/chat/api/conversations${clientId ? `?client=${clientId}` : ''}`,
                { signal: controller.signal },
            );
            if (controller.signal.aborted || version !== generation.current)
                return;
            setData(result);
            setError('');
            return true;
        } catch (failure) {
            if (controller.signal.aborted || version !== generation.current)
                return;
            if (
                failure instanceof ChatError &&
                [401, 403, 404, 419].includes(failure.status)
            ) {
                setData(null);
                setSelected(null);
            }
            setError((failure as Error).message);
            return false;
        }
    }, [clientId, auth.user.id]);
    // A live event names the conversations that changed: fetch just those rows. Returns null
    // when the whole list is needed instead (a row new to this list, or a failed row read).
    const rowRequest = useRef<AbortController | null>(null);
    const refreshRows = useCallback(
        async (ids: number[], raised: number[]): Promise<boolean | null> => {
            const version = generation.current;
            const current = dataRef.current;
            if (!current) return null;
            rowRequest.current?.abort();
            const controller = new AbortController();
            rowRequest.current = controller;
            try {
                const pages = await Promise.all(
                    ids.map((id) =>
                        chatActorRequest<{ conversations: ChatConversation[] }>(
                            auth.user.id,
                            `/chat/api/conversations?only=${id}${clientId ? `&client=${clientId}` : ''}`,
                            { signal: controller.signal },
                        ),
                    ),
                );
                if (controller.signal.aborted || version !== generation.current)
                    return true;
                const latest = dataRef.current ?? current;
                const merged = mergeConversationRows(
                    latest.conversations,
                    ids,
                    pages.flatMap((page) => page.conversations),
                    raised,
                );
                if (!merged) return null;
                const next = { ...latest, conversations: merged };
                dataRef.current = next;
                setData(next);
                return true;
            } catch {
                return controller.signal.aborted ? true : null;
            }
        },
        [clientId, auth.user.id],
    );
    useEffect(() => {
        const version = ++generation.current;
        setData(null);
        setCreateOpen(false);
        setCreationPerson(null);
        setSearchOpen(false);
        setFamilyInboxOpen(false);
        setSearchReturn(false);
        recipientOpened.current = null;
        setSelected(initialConversation);
        setMessageTarget(
            initialConversation && initialMessage
                ? {
                      conversation: initialConversation,
                      message: initialMessage,
                      sequence: 1,
                  }
                : null,
        );
        setQuery('');
        setFilter('all');
        // One sync loop: rows named by live events, the whole list for list-level changes,
        // after a reconnect, and on polls while live updates are down.
        const stopUpdates = chatSync.watch({
            refresh: async (reason: SyncReason) => {
                const named = namedRows(reason);
                if (named) {
                    const done = await refreshRows(named.ids, named.raised);
                    if (done !== null) return done;
                }
                return Boolean(await refresh());
            },
            topics: ['conversation.changed', 'inbox.changed'],
            wakeOn: ['oblivion:chat-refresh'],
            poll: 'fallback',
        });
        return () => {
            generation.current = version + 1;
            request.current?.abort();
            rowRequest.current?.abort();
            stopUpdates();
        };
    }, [
        clientId,
        auth.user.id,
        initialConversation,
        initialMessage,
        refresh,
        refreshRows,
    ]);

    const conversations =
        data?.conversations.filter(
            (conversation) =>
                (conversation.archived || !!conversation.archived_for_me) ===
                    (filter === 'archived') &&
                (!query ||
                    `${conversation.title} ${conversation.client?.name ?? ''}`
                        .toLowerCase()
                        .includes(query.toLowerCase())) &&
                (filter === 'all' ||
                    filter === 'archived' ||
                    (filter === 'unread' && conversation.unread > 0) ||
                    (filter === 'mentions' &&
                        !!conversation.latest_mention_id) ||
                    (filter === 'favourites' && conversation.favourite) ||
                    (filter === 'family' && conversation.type === 'family') ||
                    (filter === 'channels' && conversation.type === 'channel')),
        ) ?? [];

    return (
        <div
            className={cn(
                'flex min-h-0 flex-col overflow-x-hidden overflow-y-auto rounded-2xl border bg-background shadow-sm',
                compact ? 'h-full' : 'h-[calc(100dvh-150px)] min-h-[480px]',
            )}
        >
            {!compact &&
                !searchOpen &&
                !familyInboxOpen &&
                selected === null &&
                !family &&
                calling?.availability === 'off_duty' && (
                    <div className="px-4 pt-3">
                        <ChatShiftHandover
                            actorId={auth.user.id}
                            enabled={calling.enabled}
                            active
                            context="off-duty"
                        />
                    </div>
                )}
            <div
                className={cn(
                    'flex flex-1',
                    !compact &&
                        !searchOpen &&
                        !familyInboxOpen &&
                        selected === null
                        ? 'min-h-[400px]'
                        : 'min-h-0',
                )}
            >
                {familyInboxOpen && !family ? (
                    <ChatFamilyInbox
                        actorId={auth.user.id}
                        clientId={clientId}
                        onExit={() => setFamilyInboxOpen(false)}
                    />
                ) : (
                    <>
                        <ChatSearch
                            key={`search:${auth.user.id}:${clientId}`}
                            actorId={auth.user.id}
                            clientId={clientId}
                            active={searchOpen}
                            onBack={() => {
                                setSearchOpen(false);
                                setSearchReturn(false);
                            }}
                            onSelect={(item) => {
                                setSelected(item.conversation.id);
                                setMessageTarget((previous) =>
                                    item.message
                                        ? {
                                              conversation:
                                                  item.conversation.id,
                                              message: item.message.id,
                                              sequence:
                                                  (previous?.sequence ?? 0) + 1,
                                          }
                                        : null,
                                );
                                setSearchOpen(false);
                                setSearchReturn(true);
                            }}
                        />
                        {!searchOpen && (
                            <>
                                <aside
                                    aria-label="Conversations"
                                    className={cn(
                                        'flex w-full shrink-0 flex-col border-r bg-muted/20 md:w-72 xl:w-80',
                                        selected !== null && 'hidden md:flex',
                                        compact && 'md:w-60',
                                    )}
                                >
                                    <div className="space-y-3 border-b p-4">
                                        <div className="flex items-center justify-between gap-2">
                                            <Heading className="text-section-title">
                                                {client && !family
                                                    ? 'Family conversations'
                                                    : 'Chat'}
                                            </Heading>
                                            <div className="flex items-center gap-1">
                                                {!compact && (
                                                    <Button
                                                        asChild
                                                        variant="ghost"
                                                        size="icon"
                                                        className="frontline-tap"
                                                    >
                                                        <Link
                                                            href="/chat/preferences"
                                                            aria-label="My Chat preferences"
                                                        >
                                                            <Settings2 className="size-5" />
                                                        </Link>
                                                    </Button>
                                                )}
                                                {(!client || family) && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="frontline-tap"
                                                        aria-label="Start a conversation"
                                                        onClick={() => {
                                                            setCreationPerson(
                                                                null,
                                                            );
                                                            setCreateOpen(true);
                                                        }}
                                                    >
                                                        <Plus className="size-5" />
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                        {family &&
                                            client &&
                                            portalClients.length > 1 && (
                                                <div className="space-y-1">
                                                    <Label htmlFor="chat-family-about">
                                                        About
                                                    </Label>
                                                    <Select
                                                        value={String(
                                                            client.id,
                                                        )}
                                                        onValueChange={(
                                                            value,
                                                        ) => {
                                                            const id =
                                                                Number(value);
                                                            if (
                                                                id !== client.id
                                                            )
                                                                router.visit(
                                                                    `/chat?client=${id}`,
                                                                );
                                                        }}
                                                    >
                                                        <SelectTrigger
                                                            id="chat-family-about"
                                                            className="frontline-tap"
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {portalClients.map(
                                                                (entry) => (
                                                                    <SelectItem
                                                                        key={
                                                                            entry.id
                                                                        }
                                                                        value={String(
                                                                            entry.id,
                                                                        )}
                                                                    >
                                                                        {
                                                                            entry.name
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            )}
                                        <div className="relative">
                                            <Search className="absolute top-3 left-3 size-4 text-muted-foreground" />
                                            <Input
                                                aria-label="Find a conversation"
                                                placeholder="Find a conversation"
                                                className="h-11 pl-9"
                                                value={query}
                                                onChange={(event) =>
                                                    setQuery(event.target.value)
                                                }
                                            />
                                        </div>
                                        <Button
                                            variant="outline"
                                            className="min-h-[44px] w-full justify-start gap-2"
                                            onClick={() => setSearchOpen(true)}
                                        >
                                            <Search className="size-4" />
                                            Search Chat
                                        </Button>
                                        <div
                                            className="flex flex-wrap gap-1"
                                            aria-label="Filter conversations"
                                        >
                                            {[
                                                ['all', 'All'],
                                                ['unread', 'Unread'],
                                                ...(!family
                                                    ? [['mentions', 'Mentions']]
                                                    : []),
                                                ['favourites', 'Favourites'],
                                                ['archived', 'Archived'],
                                                ...(!client
                                                    ? [['channels', 'Channels']]
                                                    : []),
                                            ].map(([value, label]) => (
                                                <Button
                                                    variant="ghost"
                                                    key={value}
                                                    type="button"
                                                    aria-pressed={
                                                        filter === value
                                                    }
                                                    onClick={() =>
                                                        setFilter(value)
                                                    }
                                                    className={cn(
                                                        'min-h-11 rounded-lg px-3 text-xs font-medium',
                                                        filter === value
                                                            ? 'bg-primary-fill text-primary-fill-foreground'
                                                            : 'text-muted-foreground hover:bg-muted',
                                                    )}
                                                >
                                                    {label}
                                                </Button>
                                            ))}
                                        </div>
                                    </div>
                                    {error && (
                                        <div
                                            role="alert"
                                            className="m-3 rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning"
                                        >
                                            {error}
                                            <Button
                                                variant="ghost"
                                                onClick={() => void refresh()}
                                            >
                                                Retry
                                            </Button>
                                        </div>
                                    )}
                                    <div className="min-h-0 flex-1 overflow-y-auto p-2">
                                        {!data && !error && (
                                            <LoadingState
                                                message="Loading conversations…"
                                                className="py-8"
                                            />
                                        )}
                                        {data && !conversations.length && (
                                            <div className="space-y-2 p-5 text-sm">
                                                <p className="font-medium">
                                                    {query || filter !== 'all'
                                                        ? 'No matching conversations'
                                                        : data.conversations
                                                                .length
                                                          ? 'No conversations here'
                                                          : 'No conversations yet'}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {query || filter !== 'all'
                                                        ? 'Change your search or filter.'
                                                        : data.conversations
                                                                .length
                                                          ? 'Your archived conversations are still available.'
                                                          : 'Start a conversation to keep in touch.'}
                                                </p>
                                                {query || filter !== 'all' ? (
                                                    <Button
                                                        variant="outline"
                                                        className="min-h-11"
                                                        onClick={() => {
                                                            setQuery('');
                                                            setFilter('all');
                                                        }}
                                                    >
                                                        Clear filters
                                                    </Button>
                                                ) : (
                                                    data.conversations.length >
                                                        0 && (
                                                        <Button
                                                            variant="outline"
                                                            className="min-h-11"
                                                            onClick={() =>
                                                                setFilter(
                                                                    'archived',
                                                                )
                                                            }
                                                        >
                                                            View archived
                                                        </Button>
                                                    )
                                                )}
                                            </div>
                                        )}
                                        {conversations.map((conversation) => (
                                            <Button
                                                variant="ghost"
                                                type="button"
                                                key={conversation.id}
                                                onClick={() => {
                                                    setSelected(
                                                        conversation.id,
                                                    );
                                                    setMessageTarget(
                                                        (previous) =>
                                                            filter ===
                                                                'mentions' &&
                                                            conversation.latest_mention_id
                                                                ? {
                                                                      conversation:
                                                                          conversation.id,
                                                                      message:
                                                                          conversation.latest_mention_id,
                                                                      sequence:
                                                                          (previous?.sequence ??
                                                                              0) +
                                                                          1,
                                                                  }
                                                                : null,
                                                    );
                                                }}
                                                aria-current={
                                                    selected === conversation.id
                                                        ? 'true'
                                                        : undefined
                                                }
                                                className={cn(
                                                    'mb-1 flex h-auto w-full items-start gap-3 rounded-xl border border-transparent p-3 text-left whitespace-normal focus-visible:outline-2 focus-visible:outline-primary',
                                                    selected === conversation.id
                                                        ? 'border-primary/15 bg-primary-fill/10 text-primary'
                                                        : 'hover:bg-muted/60',
                                                )}
                                            >
                                                <span
                                                    className={cn(
                                                        'flex size-10 shrink-0 items-center justify-center rounded-xl text-xs font-semibold',
                                                        conversation.type ===
                                                            'family'
                                                            ? 'bg-status-info-bg text-status-info'
                                                            : 'bg-primary-fill/10 text-primary',
                                                    )}
                                                >
                                                    {conversation.type ===
                                                    'family' ? (
                                                        <Users className="size-4" />
                                                    ) : (
                                                        conversation.title
                                                            .slice(0, 2)
                                                            .toUpperCase()
                                                    )}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center gap-1">
                                                        <span className="truncate text-sm font-semibold">
                                                            {conversation.title ||
                                                                'Conversation'}
                                                        </span>
                                                        {conversation.favourite && (
                                                            <Star className="size-3 shrink-0 fill-current text-status-warning" />
                                                        )}
                                                        {conversation.muted && (
                                                            <BellOff className="size-3 shrink-0 text-muted-foreground" />
                                                        )}
                                                    </span>
                                                    {conversation.client && (
                                                        <span className="block truncate text-[11px] text-muted-foreground">
                                                            {
                                                                conversation
                                                                    .client.name
                                                            }
                                                        </span>
                                                    )}
                                                    <span className="mt-1 block truncate text-xs text-muted-foreground">
                                                        {conversation.latest
                                                            ? personal.show_previews
                                                                ? conversation
                                                                      .latest
                                                                      .content ||
                                                                  'New Chat message'
                                                                : 'New Chat message · Open to read'
                                                            : 'No messages yet'}
                                                    </span>
                                                    <span className="mt-1 block text-[10px] text-muted-foreground">
                                                        {conversation.latest &&
                                                            formatRelative(
                                                                conversation
                                                                    .latest
                                                                    .created_at,
                                                            )}
                                                    </span>
                                                </span>
                                                {conversation.unread > 0 && (
                                                    <span
                                                        className="rounded-full bg-primary-fill px-2 py-0.5 text-[11px] font-semibold text-primary-fill-foreground"
                                                        aria-label={`${conversation.unread} unread`}
                                                    >
                                                        {conversation.unread >
                                                        99
                                                            ? '99+'
                                                            : conversation.unread}
                                                    </span>
                                                )}
                                            </Button>
                                        ))}
                                    </div>
                                    <div className="grid shrink-0 gap-2 border-t p-3">
                                        {!family && (
                                            <Button
                                                variant="outline"
                                                className="frontline-tap w-full justify-start gap-2"
                                                onClick={() =>
                                                    setFamilyInboxOpen(true)
                                                }
                                            >
                                                <Users className="size-4" />
                                                Family inbox
                                            </Button>
                                        )}
                                        {selected !== null && (
                                            <Button
                                                variant="outline"
                                                className="frontline-tap w-full justify-start gap-2"
                                                onClick={() =>
                                                    setCallsRequest(
                                                        (value) => value + 1,
                                                    )
                                                }
                                            >
                                                <Phone className="size-4" />
                                                Calls
                                            </Button>
                                        )}
                                        {family && client && (
                                            <ChatInvitations
                                                actorId={auth.user.id}
                                                key={`${auth.user.id}:${client.id}`}
                                                clientId={client.id}
                                                clientName={client.name}
                                                onAccepted={(id) => {
                                                    setSelected(id);
                                                    setMessageTarget(null);
                                                    void refresh();
                                                }}
                                            />
                                        )}
                                        <ChatCollection
                                            actorId={auth.user.id}
                                            kind="saved"
                                            clientId={clientId}
                                            onSelect={(item) => {
                                                setSelected(
                                                    item.conversation_id,
                                                );
                                                setMessageTarget(
                                                    (previous) => ({
                                                        conversation:
                                                            item.conversation_id,
                                                        message:
                                                            item.message.id,
                                                        sequence:
                                                            (previous?.sequence ??
                                                                0) + 1,
                                                    }),
                                                );
                                            }}
                                        />
                                    </div>
                                    {compact && (
                                        <Link
                                            className="flex min-h-11 items-center justify-center gap-2 border-t p-3 text-sm font-medium"
                                            href={`/chat?${clientId ? `client=${clientId}&` : ''}${selected ? `conversation=${selected}` : ''}`}
                                        >
                                            Open in Chat
                                            <ExternalLink className="size-4" />
                                        </Link>
                                    )}
                                </aside>
                                <div
                                    className={cn(
                                        'min-h-0 min-w-0 flex-1 flex-col',
                                        selected === null
                                            ? 'hidden md:flex'
                                            : 'flex',
                                    )}
                                >
                                    {searchReturn && (
                                        <div className="shrink-0 border-b px-3">
                                            <Button
                                                variant="ghost"
                                                className="min-h-[44px] gap-2"
                                                onClick={() =>
                                                    setSearchOpen(true)
                                                }
                                            >
                                                <Search className="size-4" />
                                                Back to search results
                                            </Button>
                                        </div>
                                    )}
                                    {selected && data ? (
                                        <ChatThread
                                            key={`${auth.user.id}:${clientId}:${selected}:${messageTarget?.conversation === selected ? messageTarget.sequence : 0}`}
                                            conversationId={selected}
                                            actorId={auth.user.id}
                                            initialMessage={
                                                messageTarget?.conversation ===
                                                selected
                                                    ? messageTarget.message
                                                    : null
                                            }
                                            family={family}
                                            policy={data.settings}
                                            onBack={() => setSelected(null)}
                                            onChange={refresh}
                                        />
                                    ) : (
                                        <div className="m-auto max-w-sm space-y-4 p-8 text-center">
                                            <div className="mx-auto flex size-16 items-center justify-center rounded-2xl bg-primary-fill/10 text-primary">
                                                <MessageCircle className="size-8" />
                                            </div>
                                            <h2 className="text-section-title">
                                                A little closer, wherever you
                                                work
                                            </h2>
                                            <p className="text-sm leading-relaxed text-muted-foreground">
                                                Choose a conversation to pick up
                                                where you left off.
                                            </p>
                                            <p className="flex items-center justify-center gap-2 text-xs text-muted-foreground">
                                                <ShieldCheck className="size-4" />
                                                Access follows your current role
                                                and care team
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </>
                        )}
                    </>
                )}
            </div>
            {callsRequest > 0 &&
                selected !== null &&
                data?.conversations.some(
                    (conversation) => conversation.id === selected,
                ) && (
                    <CallHistory
                        key={`calls:${auth.user.id}:${selected}:${callsRequest}`}
                        actorId={auth.user.id}
                        conversationId={selected}
                        initiallyOpen
                        hideTrigger
                    />
                )}
            {initialCallHistory === 'missed' &&
                selected &&
                selected === initialConversation &&
                data?.conversations.some(
                    (conversation) => conversation.id === selected,
                ) && (
                    <CallHistory
                        key={`notice:${auth.user.id}:${selected}`}
                        actorId={auth.user.id}
                        conversationId={selected}
                        initiallyOpen
                        initialFilter="missed"
                        hideTrigger
                    />
                )}
            {data && (
                <ChatCreateConversation
                    actorId={auth.user.id}
                    key={`${auth.user.id}:${clientId}:${creationPerson}`}
                    open={createOpen}
                    onOpenChange={setCreateOpen}
                    family={family}
                    clientId={clientId}
                    people={data.people}
                    sites={data.sites ?? []}
                    canManageChannels={!!data.can_manage_channels}
                    initialPerson={creationPerson}
                    privateContact={family && creationPerson !== null}
                    onCreated={(id) => {
                        setSelected(id);
                        void refresh();
                    }}
                />
            )}
        </div>
    );
}
