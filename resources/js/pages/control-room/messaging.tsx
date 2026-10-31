import { ChatLauncher } from '@/components/chat/chat-launcher';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import {
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
} from '@/components/page';
import { PopupCallout, PopupField, PopupForm } from '@/components/popup';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { SelectInput } from '@/components/wizard/primitives';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    Check,
    CheckCheck,
    MessageSquare,
    MessageSquarePlus,
    MessagesSquare,
    Plus,
    Send,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    type FormEvent,
    type KeyboardEvent,
} from 'react';

interface StaffMember {
    id: number;
    name: string;
}

interface Thread {
    id: string;
    type: 'alert' | 'direct';
    alert_id: number | null;
    user_id: number | null;
    title: string;
    last_message: string;
    last_message_at: string | null;
    unread_count: number;
    message_count: number;
}

interface Message {
    id: number;
    direction: 'outbound' | 'inbound';
    content: string;
    sender_name: string;
    sent_at: string | null;
    delivered_at: string | null;
    status: string;
}

interface Props {
    threads: Thread[];
    staff: StaffMember[];
    can: {
        manage: boolean;
    };
}

type View = 'all' | 'alert' | 'direct';

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

const SEND_FAILED = "The message wasn't sent. Check it and try again.";
const SEND_UNCONFIRMED =
    "We couldn't confirm the message was sent — the connection may have dropped. The conversation has been refreshed; check it before sending again.";

/**
 * Communications › Conversations — Control Room's messages to field staff,
 * direct or linked to an alert, until the shared Chat becomes the place for
 * them (Stephan, 23 Oct 2026). When the shared Chat is on, "Open Chat" goes
 * there; this page never creates a second inbox for it.
 */
export default function ControlRoomMessaging({ threads, staff, can }: Props) {
    const { chatSharedExperience } = usePage<{ chatSharedExperience?: boolean }>()
        .props;

    const [view, setView] = useState<View>('all');
    const [unreadOnly, setUnreadOnly] = useState(false);
    const [search, setSearch] = useState('');

    const [activeThread, setActiveThread] = useState<Thread | null>(null);
    const [messages, setMessages] = useState<Message[]>([]);
    const [loadingMessages, setLoadingMessages] = useState(false);
    const [composeText, setComposeText] = useState('');
    const [sendTo, setSendTo] = useState('');
    const [sending, setSending] = useState(false);
    const [sendError, setSendError] = useState<string | null>(null);

    const [newOpen, setNewOpen] = useState(false);
    const [newUser, setNewUser] = useState('');
    const [newAlertId, setNewAlertId] = useState('');

    const scrollRef = useRef<HTMLDivElement>(null);

    const counts = useMemo(
        () => ({
            all: threads.length,
            unread: threads.reduce((sum, thread) => sum + thread.unread_count, 0),
            alert: threads.filter((thread) => thread.type === 'alert').length,
            direct: threads.filter((thread) => thread.type === 'direct').length,
        }),
        [threads],
    );

    const visibleThreads = useMemo(() => {
        const q = search.trim().toLowerCase();
        return threads.filter(
            (thread) =>
                (view === 'all' || thread.type === view) &&
                (!unreadOnly || thread.unread_count > 0) &&
                (!q ||
                    thread.title.toLowerCase().includes(q) ||
                    thread.last_message.toLowerCase().includes(q)),
        );
    }, [threads, view, unreadOnly, search]);

    const fetchMessages = useCallback(
        async (thread: Thread) => {
            const params = new URLSearchParams();
            if (thread.alert_id) {
                params.set('alert_id', String(thread.alert_id));
            } else if (thread.user_id) {
                params.set('user_id', String(thread.user_id));
            }

            try {
                const response = await fetch(
                    `/control-room/messaging/thread?${params.toString()}`,
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                );
                if (!response.ok) return;
                const data = (await response.json()) as { messages: Message[] };
                setMessages(data.messages);

                // Mark inbound unread messages as read.
                if (can.manage) {
                    const unread = data.messages.filter(
                        (msg) => msg.direction === 'inbound' && !msg.delivered_at,
                    );
                    await Promise.all(
                        unread.map((msg) =>
                            fetch(`/control-room/messaging/${msg.id}/read`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': csrfToken(),
                                },
                            }).catch(() => undefined),
                        ),
                    );
                    if (unread.length)
                        router.reload({ only: ['threads'], async: true });
                }
            } catch {
                // A failed background refresh keeps what is on screen.
            }
        },
        [can.manage],
    );

    const openThread = useCallback(
        (thread: Thread, load = true) => {
            setActiveThread(thread);
            setMessages([]);
            setSendError(null);
            setSendTo(thread.user_id ? String(thread.user_id) : '');
            if (!load) return;
            setLoadingMessages(true);
            fetchMessages(thread).finally(() => setLoadingMessages(false));
        },
        [fetchMessages],
    );

    // Refresh the open conversation every 15 seconds.
    useEffect(() => {
        if (!activeThread) return;
        const timer = window.setInterval(() => {
            if (!document.hidden) fetchMessages(activeThread);
        }, 15000);
        return () => window.clearInterval(timer);
    }, [activeThread, fetchMessages]);

    // Keep the newest message in view inside the pane (never scroll the page).
    useEffect(() => {
        const pane = scrollRef.current;
        if (pane) pane.scrollTop = pane.scrollHeight;
    }, [messages]);

    const recipientId = activeThread?.user_id ?? (sendTo ? Number(sendTo) : null);

    const send = async (event?: FormEvent) => {
        event?.preventDefault();
        const content = composeText.trim();
        if (!content || !activeThread || !recipientId || sending) return;

        setSending(true);
        setSendError(null);
        try {
            const response = await fetch('/control-room/messaging/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    content,
                    target_user_id: recipientId,
                    alert_id: activeThread.alert_id,
                }),
            });

            if (response.ok) {
                const data = (await response.json()) as { message: Message };
                setMessages((prev) => [...prev, data.message]);
                setComposeText('');
                router.reload({ only: ['threads'], async: true });
            } else {
                setSendError(SEND_FAILED);
            }
        } catch {
            setSendError(SEND_UNCONFIRMED);
            fetchMessages(activeThread);
        } finally {
            setSending(false);
        }
    };

    const onComposeKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void send();
        }
    };

    const alertIdInvalid = newAlertId.trim() !== '' && !/^[1-9]\d*$/.test(newAlertId.trim());

    const closeNew = () => {
        setNewOpen(false);
        setNewUser('');
        setNewAlertId('');
    };

    const startConversation = () => {
        if (!newUser || alertIdInvalid) return;
        const userId = Number(newUser);
        const alertId = newAlertId.trim() ? Number(newAlertId.trim()) : null;
        const person = staff.find((s) => s.id === userId);
        const threadId = alertId ? `alert-${alertId}` : `user-${userId}`;

        const existing = threads.find((t) => t.id === threadId);
        if (existing) {
            openThread(existing);
        } else {
            // A conversation that has no messages yet.
            openThread(
                {
                    id: threadId,
                    type: alertId ? 'alert' : 'direct',
                    alert_id: alertId,
                    user_id: userId,
                    title: alertId ? `Alert #${alertId}` : (person?.name ?? 'Staff member'),
                    last_message: '',
                    last_message_at: null,
                    unread_count: 0,
                    message_count: 0,
                },
                false,
            );
        }
        // An alert conversation goes to the person just chosen.
        setSendTo(String(userId));
        closeNew();
    };

    const meter = (
        label: string,
        count: number,
        caption: string,
        pressed: boolean,
        onClick: () => void,
        tone: 'brand' | 'warning' = 'brand',
    ) => (
        <PageHeaderMeterBlock
            label={label}
            tone={count > 0 ? tone : 'brand'}
            onClick={onClick}
            pressed={pressed}
            ariaLabel={`Show ${label.toLowerCase()}`}
        >
            <PageHeaderMeterBig>{count}</PageHeaderMeterBig>
            <PageHeaderMeterCaption>{caption}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );

    return (
        <ControlRoomPage
            headTitle="Conversations"
            header={{
                subline:
                    'Message field staff · Alert-linked conversations stay with their alert',
                actions: (
                    <>
                        <PageHeaderSearch
                            value={search}
                            onChange={setSearch}
                            placeholder="Search conversations"
                        />
                        {chatSharedExperience ? (
                            <PageHeaderGlassButton icon={MessagesSquare} asChild>
                                <Link href="/chat">Open Chat</Link>
                            </PageHeaderGlassButton>
                        ) : null}
                        {can.manage ? (
                            <PageHeaderPrimaryButton
                                icon={Plus}
                                onClick={() => setNewOpen(true)}
                            >
                                New conversation
                            </PageHeaderPrimaryButton>
                        ) : null}
                    </>
                ),
                meters: (
                    <>
                        {meter('Conversations', counts.all, 'direct and alert-linked', view === 'all' && !unreadOnly, () => {
                            setView('all');
                            setUnreadOnly(false);
                        })}
                        {meter('Unread', counts.unread, 'messages from staff', unreadOnly, () => setUnreadOnly(true), 'warning')}
                        {meter('Alert-linked', counts.alert, 'kept with the alert', view === 'alert', () => setView('alert'))}
                        {meter('Direct', counts.direct, 'with one staff member', view === 'direct', () => setView('direct'))}
                    </>
                ),
                filters: (
                    <>
                        <PageHeaderFilterSelect
                            icon={MessageSquare}
                            label="All conversations"
                            value={view}
                            onChange={(value) => setView(value as View)}
                            options={[
                                { value: 'all', label: 'All conversations' },
                                { value: 'alert', label: 'Alert-linked' },
                                { value: 'direct', label: 'Direct' },
                            ]}
                        />
                        <PageHeaderFilterCheck
                            label="Unread only"
                            checked={unreadOnly}
                            onChange={setUnreadOnly}
                        />
                    </>
                ),
            }}
        >
            <div
                className={
                    threads.length === 0
                        ? 'grid min-w-0 gap-5'
                        : 'grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(320px,0.42fr)_minmax(0,1fr)]'
                }
            >
                <Card className="min-w-0 gap-0 overflow-hidden py-0">
                    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                        <div className="min-w-0">
                            <h2 className="text-section-title">Conversations</h2>
                            <p className="text-subtle mt-0.5">Newest message first.</p>
                        </div>
                        <StatusBadge variant="neutral" className="tabular-nums">
                            {visibleThreads.length} of {threads.length}
                        </StatusBadge>
                    </div>
                    {visibleThreads.length === 0 ? (
                        <div className="px-5 py-10">
                            <EmptyState
                                icon={MessageSquarePlus}
                                title={threads.length ? 'No conversations match' : 'No conversations yet'}
                                description={
                                    threads.length
                                        ? 'Clear the search or a filter to see more.'
                                        : can.manage
                                          ? 'Start a new conversation to message a staff member.'
                                          : 'Conversations with field staff show here.'
                                }
                            />
                        </div>
                    ) : (
                        <ol className="max-h-[640px] divide-y divide-border overflow-y-auto scrollbar-pretty">
                            {visibleThreads.map((thread) => (
                                <ThreadRow
                                    key={thread.id}
                                    thread={thread}
                                    selected={activeThread?.id === thread.id}
                                    onOpen={() => openThread(thread)}
                                />
                            ))}
                        </ol>
                    )}
                </Card>

                {/* No conversations at all: one message, not two empty boxes. */}
                {threads.length > 0 ? (
                <Card className="flex h-[640px] min-w-0 flex-col gap-0 overflow-hidden py-0">
                    {activeThread ? (
                        <>
                            <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
                                <ThreadMark thread={activeThread} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-section-title truncate">
                                            {activeThread.title}
                                        </h2>
                                        <StatusBadge
                                            variant={activeThread.type === 'alert' ? 'warning' : 'neutral'}
                                            size="sm"
                                        >
                                            {activeThread.type === 'alert' ? 'Alert-linked' : 'Direct'}
                                        </StatusBadge>
                                    </div>
                                    {activeThread.alert_id ? (
                                        <Link
                                            href={`/control-room/alerts/${activeThread.alert_id}`}
                                            className="text-sm font-medium text-primary underline-offset-2 hover:underline"
                                        >
                                            Open alert #{activeThread.alert_id}
                                        </Link>
                                    ) : null}
                                </div>
                                {activeThread.type === 'direct' && activeThread.user_id ? (
                                    <ChatLauncher
                                        key={activeThread.user_id}
                                        personId={activeThread.user_id}
                                        label="Open staff Chat"
                                        outline
                                    />
                                ) : null}
                            </div>

                            <div
                                ref={scrollRef}
                                className="min-h-0 flex-1 overflow-y-auto px-5 py-4 scrollbar-pretty"
                            >
                                {loadingMessages ? (
                                    <LoadingState message="Loading messages…" />
                                ) : messages.length === 0 ? (
                                    <EmptyState
                                        icon={MessageSquarePlus}
                                        title="No messages yet"
                                        description={
                                            can.manage
                                                ? 'Send a message to start the conversation.'
                                                : 'Messages in this conversation show here.'
                                        }
                                    />
                                ) : (
                                    <ol className="grid gap-4" aria-label="Messages">
                                        {messages.map((msg) => (
                                            <MessageBubble key={msg.id} message={msg} />
                                        ))}
                                    </ol>
                                )}
                            </div>

                            {can.manage ? (
                                <form
                                    onSubmit={send}
                                    className="grid gap-2.5 border-t border-border px-5 py-4"
                                >
                                    {sendError ? (
                                        <PopupCallout
                                            tone={sendError === SEND_FAILED ? 'critical' : 'warning'}
                                        >
                                            {sendError}
                                        </PopupCallout>
                                    ) : null}
                                    {activeThread.user_id ? null : (
                                        <PopupField label="Send to" required>
                                            <SelectInput
                                                value={sendTo}
                                                onChange={setSendTo}
                                                placeholder="Choose a staff member"
                                                ariaLabel="Send to"
                                                options={staff.map((person) => ({
                                                    value: String(person.id),
                                                    label: person.name,
                                                }))}
                                            />
                                        </PopupField>
                                    )}
                                    <div className="flex items-end gap-2">
                                        <Textarea
                                            value={composeText}
                                            onChange={(e) => setComposeText(e.target.value)}
                                            onKeyDown={onComposeKeyDown}
                                            placeholder="Type a message… (Enter sends, Shift+Enter adds a line)"
                                            aria-label="Message"
                                            rows={2}
                                            maxLength={2000}
                                            disabled={sending}
                                            className="min-h-0 flex-1 resize-none"
                                        />
                                        <Button
                                            type="submit"
                                            disabled={!composeText.trim() || !recipientId || sending}
                                        >
                                            <Send aria-hidden="true" />
                                            {sending ? 'Sending…' : 'Send'}
                                        </Button>
                                    </div>
                                </form>
                            ) : (
                                <p className="text-caption border-t border-border px-5 py-3">
                                    You can read these conversations. Sending messages needs
                                    Control Room manage access — ask a Control Room manager.
                                </p>
                            )}
                        </>
                    ) : (
                        <div className="flex flex-1 items-center justify-center px-5 py-10">
                            <EmptyState
                                icon={MessageSquare}
                                title="Choose a conversation"
                                description={
                                    can.manage
                                        ? 'Pick one from the list, or start a new conversation.'
                                        : 'Pick one from the list to read it.'
                                }
                            />
                        </div>
                    )}
                </Card>
                ) : null}
            </div>

            {can.manage ? (
                <PopupForm
                    open={newOpen}
                    onClose={closeNew}
                    size="sm"
                    icon={MessageSquarePlus}
                    title="New conversation"
                    description="Message a staff member directly, or link the conversation to an alert."
                    dirty={Boolean(newUser || newAlertId.trim())}
                    submitLabel="Start conversation"
                    submitDisabled={!newUser || alertIdInvalid}
                    onSubmit={startConversation}
                >
                    <PopupField label="Staff member" required>
                        <SelectInput
                            value={newUser}
                            onChange={setNewUser}
                            placeholder="Choose a staff member"
                            ariaLabel="Staff member"
                            options={staff.map((person) => ({
                                value: String(person.id),
                                label: person.name,
                            }))}
                        />
                    </PopupField>
                    <PopupField
                        label="Link to an alert"
                        htmlFor="new-conversation-alert"
                        hint="Optional. Leave empty for a direct message."
                        error={alertIdInvalid ? 'Enter the alert number, for example 418.' : undefined}
                    >
                        <Input
                            id="new-conversation-alert"
                            inputMode="numeric"
                            placeholder="Alert number, for example 418"
                            value={newAlertId}
                            onChange={(e) => setNewAlertId(e.target.value)}
                            aria-invalid={alertIdInvalid || undefined}
                        />
                    </PopupField>
                </PopupForm>
            ) : null}
        </ControlRoomPage>
    );
}

function ThreadMark({ thread }: { thread: Thread }) {
    if (thread.type === 'alert')
        return (
            <span
                aria-hidden="true"
                className="flex size-9 shrink-0 items-center justify-center rounded-full bg-status-warning-bg text-status-warning"
            >
                <AlertTriangle className="size-4" />
            </span>
        );
    return (
        <Avatar className="size-9" aria-hidden="true">
            <AvatarFallback className="bg-muted text-sm font-semibold text-foreground">
                {initials(thread.title)}
            </AvatarFallback>
        </Avatar>
    );
}

function ThreadRow({
    thread,
    selected,
    onOpen,
}: {
    thread: Thread;
    selected: boolean;
    onOpen: () => void;
}) {
    const items: ControlRoomRowAction[] = [
        {
            key: 'open',
            label: 'Open conversation',
            icon: MessageSquare,
            onSelect: onOpen,
        },
    ];
    if (thread.alert_id)
        items.push({
            key: 'alert',
            label: 'Open alert',
            icon: Bell,
            onSelect: () => router.visit(`/control-room/alerts/${thread.alert_id}`),
        });

    return (
        <ControlRoomRowActions label={`Actions for ${thread.title}`} items={items}>
            {({ rowProps, overflowButton }) => (
                <li
                    onContextMenu={rowProps.onContextMenu}
                    className={cn(
                        'flex items-start gap-3 px-5 py-3 transition-colors',
                        selected
                            ? 'bg-primary-fill/10 shadow-[inset_3px_0_0_var(--primary)]'
                            : 'hover:bg-muted/40',
                    )}
                >
                    <ThreadMark thread={thread} />
                    <Button
                        unstyled
                        type="button"
                        onClick={onOpen}
                        aria-current={selected ? 'true' : undefined}
                        className="min-w-0 flex-1 rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <span className="flex items-start justify-between gap-3">
                            <span className="min-w-0 truncate font-semibold text-foreground">
                                {thread.title}
                            </span>
                            {thread.last_message_at ? (
                                <span
                                    className="text-caption shrink-0 tabular-nums"
                                    title={formatDateTime(thread.last_message_at)}
                                >
                                    {formatRelative(thread.last_message_at)}
                                </span>
                            ) : null}
                        </span>
                        <span className="mt-1 flex items-center justify-between gap-2">
                            <span className="text-caption min-w-0 truncate">
                                {thread.last_message || 'No messages yet'}
                            </span>
                            {thread.unread_count > 0 ? (
                                <StatusBadge variant="critical" size="sm" className="shrink-0 tabular-nums">
                                    {thread.unread_count} unread
                                </StatusBadge>
                            ) : null}
                        </span>
                        <span className="mt-1.5 flex flex-wrap gap-1.5">
                            <StatusBadge
                                variant={thread.type === 'alert' ? 'warning' : 'neutral'}
                                size="sm"
                            >
                                {thread.type === 'alert' ? 'Alert-linked' : 'Direct'}
                            </StatusBadge>
                        </span>
                    </Button>
                    <div className="shrink-0">{overflowButton}</div>
                </li>
            )}
        </ControlRoomRowActions>
    );
}

function MessageBubble({ message }: { message: Message }) {
    const outbound = message.direction === 'outbound';
    return (
        <li className={cn('flex', outbound ? 'justify-end' : 'justify-start')}>
            <div
                className={cn(
                    'max-w-[75%] rounded-xl px-4 py-2.5',
                    outbound
                        ? 'bg-primary-fill text-primary-fill-foreground'
                        : 'bg-muted text-foreground',
                )}
            >
                <p className="text-sm whitespace-pre-wrap">{message.content}</p>
                <p
                    className={cn(
                        'mt-1 flex items-center gap-1.5 text-xs',
                        outbound
                            ? 'justify-end text-primary-fill-foreground/80'
                            : 'text-muted-foreground',
                    )}
                >
                    <span>{message.sender_name}</span>
                    {message.sent_at ? (
                        <>
                            <span aria-hidden="true">·</span>
                            <span title={formatDateTime(message.sent_at)}>
                                {formatRelative(message.sent_at)}
                            </span>
                        </>
                    ) : null}
                    {outbound ? (
                        message.delivered_at ? (
                            <>
                                <CheckCheck className="size-3.5" aria-hidden="true" />
                                <span className="sr-only">Delivered</span>
                            </>
                        ) : (
                            <>
                                <Check className="size-3.5" aria-hidden="true" />
                                <span className="sr-only">Sent</span>
                            </>
                        )
                    ) : null}
                </p>
            </div>
        </li>
    );
}
