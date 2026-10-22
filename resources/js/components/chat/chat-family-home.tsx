import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDurationMinutes } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import {
    Clock,
    MessageCircle,
    Phone,
    ShieldCheck,
    UserPlus,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ChatError, chatRequest } from './api';
import { callbackStatus } from './chat-callback-sync';
import { ChatCallbacks } from './chat-callbacks';
import { ChatCallButton } from './chat-calling';
import {
    ChatCreateConversation,
    type ChatCreatedSelection,
} from './chat-create-conversation';
import { ChatPeopleInvite } from './chat-people-invite';
import type { ResponseClock } from './chat-response-clock';
import { watchChatUpdates } from './chat-update-watch';
import { ChatUrgentHelp } from './chat-urgent-contact';
import type { ChatConversation } from './types';

export type FamilyHomeData = {
    actor_id: number;
    client_id: number;
    clients: { id: number; name: string }[];
    enabled: boolean;
    care_team: {
        conversation_id: number | null;
        calling_enabled: boolean;
        available: boolean;
    };
    response: ResponseClock;
    response_expectation: string;
    contacts: {
        id: number;
        name: string;
        role: string;
        available: boolean;
        off_duty?: boolean;
        conversation_id: number | null;
    }[];
    conversations: { id: number; title: string }[];
    callbacks: {
        id: number;
        conversation_id: number;
        status: string;
        preferred_time: string | null;
        agreed_time: string | null;
    }[];
};
type Intent = {
    kind: 'message' | 'call' | 'callback' | 'invite';
    person: number | null;
    sequence: number;
};
type Action = Intent & { conversation: number };
type HomeConversation = Pick<
    ChatConversation,
    | 'id'
    | 'title'
    | 'type'
    | 'client'
    | 'version'
    | 'can_manage_people'
    | 'can_call'
    | 'can_send'
>;
const initials = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join('');
const chatUrl = (client: number, conversation?: number) =>
    `/chat?client=${client}${conversation ? `&conversation=${conversation}` : ''}`;

export function ChatFamilyHome(props: {
    actorId: number;
    client: { id: number; name: string };
}) {
    return (
        <FamilyHomeSession
            key={`${props.actorId}:${props.client.id}`}
            {...props}
        />
    );
}

function FamilyHomeSession({
    actorId,
    client,
}: {
    actorId: number;
    client: { id: number; name: string };
}) {
    const [data, setData] = useState<FamilyHomeData | null>(null);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const [creation, setCreation] = useState<Intent | null>(null);
    const [action, setAction] = useState<Action | null>(null);
    const sequence = useRef(0);
    const fresh = useRef<FamilyHomeData | null>(null);
    const originatingControl = useRef<HTMLElement | null>(null);
    useEffect(() => {
        const controller = new AbortController();
        const clear = () => {
            fresh.current = null;
            setData(null);
        };
        const offline = () => {
            clear();
            setError(
                'Your care-team information could not be checked. Check your connection and try again.',
            );
        };
        window.addEventListener('offline', offline);
        if (!navigator.onLine) offline();
        const stop = watchChatUpdates(
            async () => {
                try {
                    const result = await chatRequest<FamilyHomeData>(
                        `/chat/api/clients/${client.id}/home`,
                        {
                            headers: { 'X-Chat-Actor': String(actorId) },
                            signal: AbortSignal.any([
                                controller.signal,
                                AbortSignal.timeout(10000),
                            ]),
                        },
                    );
                    if (controller.signal.aborted || !navigator.onLine)
                        return false;
                    if (
                        result.actor_id !== actorId ||
                        result.client_id !== client.id ||
                        !Array.isArray(result.clients) ||
                        !Array.isArray(result.contacts) ||
                        !Array.isArray(result.conversations) ||
                        !Array.isArray(result.callbacks) ||
                        !result.care_team ||
                        !result.response
                    )
                        throw new Error(
                            'The selected client could not be confirmed.',
                        );
                    fresh.current = result;
                    setData(result);
                    setError('');
                    return true;
                } catch (failure) {
                    if (controller.signal.aborted) return false;
                    clear();
                    const denied =
                        failure instanceof ChatError &&
                        [401, 403, 404, 419].includes(failure.status);
                    if (denied) {
                        setCreation(null);
                        setAction(null);
                    }
                    setError(
                        denied
                            ? 'Your access to this family contact information has changed. Return to the portal to choose an available client.'
                            : 'Your care-team information could not be checked. Check your connection and try again.',
                    );
                    return false;
                }
            },
            // Live: refreshed when one of this person's conversations, their list or a
            // call changes; polled only while live updates are down.
            {
                topics: [
                    'conversation.changed',
                    'inbox.changed',
                    'call.changed',
                ],
            },
        );
        return () => {
            controller.abort();
            stop();
            window.removeEventListener('offline', offline);
            fresh.current = null;
        };
    }, [actorId, client.id, retry]);

    const restoreFocus = () => {
        if (originatingControl.current?.isConnected)
            originatingControl.current.focus({ preventScroll: true });
    };
    const finish = (intent: Intent, conversation: number) => {
        if (intent.kind === 'message')
            router.visit(chatUrl(client.id, conversation));
        else setAction({ ...intent, conversation });
    };
    const begin = (kind: Intent['kind'], person: number | null = null) => {
        const current = fresh.current;
        if (!current?.enabled) return;
        originatingControl.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        setAction(null);
        const contact =
            person === null
                ? current.care_team
                : current.contacts.find((entry) => entry.id === person);
        if (!contact) return;
        const intent = { kind, person, sequence: ++sequence.current };
        if (contact.conversation_id) finish(intent, contact.conversation_id);
        else setCreation(intent);
    };
    const created = (id: number, selection?: ChatCreatedSelection) => {
        const intent = creation;
        setCreation(null);
        setRetry((value) => value + 1);
        if (!intent) return;
        const matches =
            selection?.privateContact &&
            selection.participantIds.length ===
                (intent.person === null ? 0 : 1) &&
            (intent.person === null ||
                selection.participantIds[0] === intent.person);
        // Recovering an older request must not initiate a newer call/invitation
        // for a different audience. Open that reviewed conversation instead.
        if (!matches) router.visit(chatUrl(client.id, id));
        else finish(intent, id);
    };
    const enabled = Boolean(data?.enabled && !error);
    const available = Boolean(
        data?.care_team.calling_enabled && data.care_team.available,
    );
    return (
        <section className="min-w-0 space-y-6" aria-label="Family contact home">
            <header className="flex flex-wrap items-start justify-between gap-5">
                <div className="min-w-0">
                    <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Your family portal
                    </p>
                    <h1 className="text-page-title break-words">
                        Stay in touch about {client.name.split(' ')[0]}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        A familiar care team, whenever you need to get in touch.
                    </p>
                </div>
                <div className="w-full space-y-1 text-sm sm:w-auto sm:min-w-[200px]">
                    <Label htmlFor="chat-family-home-about">About</Label>
                    <Select
                        value={String(client.id)}
                        disabled={!data}
                        onValueChange={(value) => {
                            const id = Number(value);
                            if (
                                id !== client.id &&
                                fresh.current?.clients.some(
                                    (entry) => entry.id === id,
                                )
                            )
                                router.visit(`/portal/clients/${id}/dashboard`);
                        }}
                    >
                        <SelectTrigger
                            id="chat-family-home-about"
                            className="frontline-tap"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(data?.clients ?? [client]).map((entry) => (
                                <SelectItem
                                    key={entry.id}
                                    value={String(entry.id)}
                                >
                                    {entry.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </header>
            {error && (
                <div role="alert" className="space-y-2 rounded-xl border p-4">
                    <p>{error}</p>
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        onClick={() => setRetry((value) => value + 1)}
                    >
                        Try again
                    </Button>
                </div>
            )}
            <section
                className="flex min-w-0 flex-col gap-5 rounded-[14px] border bg-card p-5 sm:flex-row sm:p-7"
                aria-label="Care team"
            >
                <Avatar className="size-16 shrink-0">
                    <AvatarFallback className="text-lg">
                        {initials(client.name)}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1 space-y-2">
                    <h2 className="text-section-title break-words">
                        {client.name}’s care team
                    </h2>
                    <p
                        className={cn(
                            'text-sm',
                            data && available
                                ? 'font-medium text-status-success'
                                : 'text-muted-foreground',
                        )}
                        role="status"
                    >
                        {!data
                            ? error
                                ? 'Availability could not be checked'
                                : 'Checking your care team…'
                            : !data.care_team.calling_enabled
                              ? 'Voice calling is not available for this service'
                              : available
                                ? 'Available to call'
                                : 'No team member is currently available'}
                    </p>
                    {data && (
                        <p className="text-sm text-muted-foreground">
                            {data.response.state === 'unconfigured' ||
                            data.response.working_minutes == null
                                ? 'Response time has not been configured. Your care team will reply here.'
                                : `Your care team aims to reply within ${formatDurationMinutes(data.response.working_minutes)} during service hours.`}
                            {data.response_expectation
                                ? ` ${data.response_expectation}`
                                : ''}
                        </p>
                    )}
                    {data && !data.enabled && (
                        <p className="text-sm">
                            New Chat contact is unavailable. You can still open
                            conversations you are authorised to view.
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2 pt-2">
                        <Button
                            disabled={!enabled}
                            className="frontline-tap whitespace-normal"
                            onClick={() => begin('message')}
                        >
                            <MessageCircle className="size-4 shrink-0" />
                            Message care team
                        </Button>
                        <Button
                            disabled={!enabled}
                            variant="outline"
                            className="frontline-tap whitespace-normal"
                            onClick={() =>
                                begin(available ? 'call' : 'callback')
                            }
                        >
                            {available ? (
                                <Phone className="size-4 shrink-0" />
                            ) : (
                                <Clock className="size-4 shrink-0" />
                            )}
                            {available ? 'Call care team' : 'Request callback'}
                        </Button>
                    </div>
                </div>
            </section>
            <div className="grid min-w-0 gap-5 lg:grid-cols-2">
                <section
                    className="min-w-0 space-y-4 rounded-xl border bg-card p-5"
                    aria-label="Your contacts"
                >
                    <h2 className="text-section-title">Your contacts</h2>
                    {!data ? (
                        <p className="text-sm text-muted-foreground">
                            {error
                                ? 'Contacts could not be checked.'
                                : 'Loading your contacts…'}
                        </p>
                    ) : data.contacts.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No named contacts are currently available here. You
                            can message the care team.
                        </p>
                    ) : (
                        data.contacts.map((person) => (
                            <div
                                key={person.id}
                                className="flex min-w-0 flex-wrap items-center gap-3 border-b py-3"
                            >
                                <Avatar className="size-10">
                                    <AvatarFallback>
                                        {initials(person.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="min-w-0 flex-1">
                                    <h3 className="text-sm font-semibold break-words">
                                        {person.name}
                                    </h3>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {person.role} ·{' '}
                                        {person.off_duty
                                            ? 'Off duty'
                                            : person.available
                                              ? 'Available to call'
                                              : 'Unavailable to call'}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    disabled={!enabled}
                                    className="frontline-tap"
                                    aria-label={`Message ${person.name}`}
                                    onClick={() => begin('message', person.id)}
                                >
                                    Message
                                </Button>
                            </div>
                        ))
                    )}
                    <p className="flex gap-2 rounded-lg bg-muted p-3 text-sm text-muted-foreground">
                        <ShieldCheck className="size-5 shrink-0" />
                        Named-worker messages can also be answered by authorised
                        cover. Other relatives are only included when invited.
                    </p>
                </section>
                <section
                    className="min-w-0 space-y-4 rounded-xl border bg-card p-5"
                    aria-label="Conversations and requests"
                >
                    <h2 className="text-section-title">
                        Conversations and requests
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Your own conversations stay separate from other
                        relatives’ conversations.
                    </p>
                    {data?.callbacks.map((row) => (
                        <Button
                            variant="outline"
                            key={row.id}
                            className="frontline-tap block h-auto w-full space-y-1 rounded-lg p-3 text-left text-sm whitespace-normal"
                            onClick={() => {
                                originatingControl.current =
                                    document.activeElement instanceof
                                    HTMLElement
                                        ? document.activeElement
                                        : null;
                                setCreation(null);
                                setAction({
                                    kind: 'callback',
                                    person: null,
                                    sequence: ++sequence.current,
                                    conversation: row.conversation_id,
                                });
                            }}
                        >
                            <span className="block font-medium">
                                Callback ·{' '}
                                {callbackStatus[row.status] ?? 'Request'}
                            </span>
                            <span className="block break-words text-muted-foreground">
                                Preferred:{' '}
                                {row.preferred_time ?? 'Not specified'} ·
                                Agreed: {row.agreed_time ?? 'Not agreed'}
                            </span>
                        </Button>
                    ))}
                    {data?.conversations.map((conversation) => (
                        <Link
                            className="frontline-tap flex items-center gap-2 rounded-lg border p-3 text-sm font-medium break-words"
                            key={conversation.id}
                            href={chatUrl(client.id, conversation.id)}
                        >
                            <MessageCircle className="size-4 shrink-0" />
                            <span className="min-w-0 flex-1 break-words">
                                {conversation.title}
                            </span>
                        </Link>
                    ))}
                    {data &&
                        data.conversations.length === 0 &&
                        data.callbacks.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Your conversations and callback requests will
                                appear here.
                            </p>
                        )}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            className="frontline-tap whitespace-normal"
                            disabled={!enabled}
                            onClick={() => begin('invite')}
                        >
                            <UserPlus className="size-4 shrink-0" />
                            Invite approved relative
                        </Button>
                        <ChatUrgentHelp
                            actorId={actorId}
                            clientId={client.id}
                        />
                    </div>
                </section>
            </div>
            {creation && (
                <ChatCreateConversation
                    key={creation.sequence}
                    open
                    actorId={actorId}
                    family
                    clientId={client.id}
                    people={data?.contacts ?? []}
                    sites={[]}
                    canManageChannels={false}
                    initialPerson={creation.person}
                    privateContact
                    onOpenChange={(open) => {
                        if (!open) {
                            setCreation(null);
                            restoreFocus();
                        }
                    }}
                    onCreated={created}
                />
            )}
            {action && (
                <FamilyHomeAction
                    key={`${action.conversation}:${action.sequence}`}
                    actorId={actorId}
                    client={client}
                    action={action}
                    onClose={() => {
                        setAction(null);
                        restoreFocus();
                        setRetry((value) => value + 1);
                    }}
                />
            )}
        </section>
    );
}

function FamilyHomeAction({
    actorId,
    client,
    action,
    onClose,
}: {
    actorId: number;
    client: { id: number; name: string };
    action: Action;
    onClose: () => void;
}) {
    const call = useRef<{ open: () => boolean }>(null);
    const invite = useRef<{ open: () => void }>(null);
    const [conversation, setConversation] = useState<HomeConversation | null>(
        null,
    );
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const opened = useRef(false);
    useEffect(() => {
        if (action.kind === 'callback') return;
        const controller = new AbortController();
        setError('');
        setConversation(null);
        opened.current = false;
        void chatRequest<{
            actor_id: number;
            client_id: number;
            conversation: HomeConversation;
        }>(
            `/chat/api/clients/${client.id}/home/conversations/${action.conversation}`,
            {
                headers: { 'X-Chat-Actor': String(actorId) },
                signal: AbortSignal.any([
                    controller.signal,
                    AbortSignal.timeout(10000),
                ]),
            },
        )
            .then((result) => {
                if (controller.signal.aborted) return;
                if (
                    result.actor_id !== actorId ||
                    result.client_id !== client.id ||
                    result.conversation.id !== action.conversation ||
                    result.conversation.client?.id !== client.id ||
                    result.conversation.type !== 'family'
                )
                    throw new Error(
                        'This family conversation could not be confirmed.',
                    );
                setConversation(result.conversation);
            })
            .catch((failure) => {
                if (!controller.signal.aborted)
                    setError(
                        failure instanceof ChatError
                            ? failure.message
                            : 'This family conversation could not be checked. Try again.',
                    );
            });
        return () => controller.abort();
    }, [action.conversation, action.kind, actorId, client.id, retry]);
    useEffect(() => {
        if (!conversation || opened.current) return;
        opened.current = true;
        if (action.kind === 'call' && !call.current?.open())
            setError(
                'Calling is not available right now. Close this check to message the care team or request a callback.',
            );
        if (action.kind === 'invite') {
            if (conversation.can_manage_people) invite.current?.open();
            else setError('You cannot invite people to this conversation now.');
        }
    }, [conversation, action.kind]);
    if (action.kind === 'callback')
        return (
            <ChatCallbacks
                conversationId={action.conversation}
                actorId={actorId}
                family
                hideTrigger
                controlledOpen
                onOpenChange={(open) => {
                    if (!open) onClose();
                }}
            />
        );
    return (
        <>
            {(!conversation || error) && (
                <div className="space-y-2 rounded-xl border p-4">
                    <p role={error ? 'alert' : 'status'}>
                        {error || 'Checking conversation access…'}
                    </p>
                    {error && (
                        <Button
                            variant="outline"
                            className="frontline-tap"
                            onClick={() => setRetry((value) => value + 1)}
                        >
                            Try again
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        className="frontline-tap"
                        onClick={onClose}
                    >
                        Close check
                    </Button>
                </div>
            )}
            {conversation && action.kind === 'call' && (
                <ChatCallButton
                    ref={call}
                    actorId={actorId}
                    conversation={conversation}
                    hideTrigger
                    family
                />
            )}
            {conversation && action.kind === 'invite' && (
                <ChatPeopleInvite
                    actorId={actorId}
                    ref={invite}
                    conversation={conversation}
                    hideTrigger
                    onChanged={onClose}
                    onRefresh={() =>
                        window.dispatchEvent(new Event('oblivion:chat-refresh'))
                    }
                />
            )}
        </>
    );
}
