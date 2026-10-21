import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { UserPlus } from 'lucide-react';
import {
    useEffect,
    useId,
    useImperativeHandle,
    useRef,
    useState,
    type Ref,
} from 'react';
import { toast } from 'sonner';
import {
    ChatError,
    chatRequest,
    conversationPath,
    isChatAccessDenied,
} from './api';
import type {
    ChatConversation,
    ChatHistory,
    ChatMessage,
    ChatPerson,
} from './types';

function ChatPeopleInviteSession({
    actorId,
    conversation,
    onChanged,
    onRefresh = onChanged,
    ref,
    hideTrigger = false,
}: {
    actorId: number;
    conversation: Pick<
        ChatConversation,
        'id' | 'title' | 'type' | 'client' | 'version' | 'can_manage_people'
    > &
        Partial<Pick<ChatConversation, 'participants'>>;
    onChanged: () => void;
    onRefresh?: () => void;
    ref?: Ref<{ open: (personId?: number) => void }>;
    hideTrigger?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [people, setPeople] = useState<ChatPerson[]>([]);
    const [maxAdditional, setMaxAdditional] = useState(19);
    const [expiryDays, setExpiryDays] = useState(7);
    const [pending, setPending] = useState<{ id: string; name: string }[]>([]);
    const [selected, setSelected] = useState<number[]>([]);
    const [history, setHistory] = useState('from_joining');
    const historyId = useId();
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [messageIds, setMessageIds] = useState<number[]>([]);
    const [more, setMore] = useState(false);
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(false);
    const [loadFailure, setLoadFailure] = useState<
        'people' | 'history' | 'older' | null
    >(null);
    const [error, setError] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const [stale, setStale] = useState(false);
    const [denied, setDenied] = useState(false);
    const version = useRef(conversation.version);
    const payload = useRef<string | null>(null);
    const active = useRef(true);
    const request = useRef<AbortController | null>(null);
    useEffect(() => {
        active.current = true;
        return () => {
            active.current = false;
            request.current?.abort();
        };
    }, []);
    useImperativeHandle(ref, () => ({
        open: (personId) => {
            void show(personId);
        },
    }));
    if (!conversation.can_manage_people) return null;
    // A private family conversation that already has a relative needs that
    // relative's agreement before a staff invitation takes effect. A relative
    // inviting someone gives their own agreement by inviting.
    const participants = conversation.participants ?? [];
    const needsFamilyAgreement =
        conversation.type === 'family' &&
        participants.some(
            (person) => person.role === 'family' && person.id !== actorId,
        ) &&
        !participants.some(
            (person) => person.id === actorId && person.role === 'family',
        );
    const selectedNames = people
        .filter((person) => selected.includes(person.id))
        .map((person) => person.name)
        .join(', ');

    function clearDenied(failure: unknown) {
        if (!isChatAccessDenied(failure)) return;
        setDenied(true);
        setPeople([]);
        setPending([]);
        setSelected([]);
        setMessages([]);
        setMessageIds([]);
        setConfirmed(false);
        setStale(true);
        setUncertain(false);
        payload.current = null;
    }

    async function show(personId?: number) {
        setOpen(true);
        if (uncertain && payload.current) return;
        setSelected(personId ? [personId] : []);
        setHistory('from_joining');
        setMessageIds([]);
        setMessages([]);
        setConfirmed(false);
        setError('');
        setPending([]);
        setPeople([]);
        setStale(false);
        setDenied(false);
        setUncertain(false);
        payload.current = null;
        version.current = conversation.version;
        await loadPeople();
    }
    async function loadPeople() {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setLoadFailure(null);
        setError('');
        setLoading(true);
        try {
            const result = await chatRequest<{
                people: ChatPerson[];
                pending?: { id: string; name: string }[];
                max_additional?: number;
                invitation_expiry_days?: number;
            }>(`${conversationPath(conversation.id)}/people`, {
                signal: controller.signal,
                headers: { 'X-Chat-Actor': String(actorId) },
            });
            if (controller.signal.aborted || !active.current) return;
            setPeople(result.people);
            setMaxAdditional(Math.min(19, result.max_additional ?? 19));
            setExpiryDays(result.invitation_expiry_days ?? 7);
            setSelected((ids) =>
                ids.filter((id) =>
                    result.people.some((person) => person.id === id),
                ),
            );
            setPending(result.pending ?? []);
        } catch (failure) {
            if (!controller.signal.aborted && active.current) {
                clearDenied(failure);
                setLoadFailure('people');
                setError((failure as Error).message);
            }
        } finally {
            if (!controller.signal.aborted && active.current) setLoading(false);
        }
    }
    async function loadHistory(older = false) {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        setLoadFailure(null);
        setError('');
        const before = older ? messages.at(-1)?.id : null;
        if (!older) setMessages([]);
        try {
            const result = await chatRequest<ChatHistory>(
                `${conversationPath(conversation.id)}/people/history${before ? `?before=${before}` : ''}`,
                {
                    signal: controller.signal,
                    headers: { 'X-Chat-Actor': String(actorId) },
                },
            );
            if (controller.signal.aborted || !active.current) return;
            const page = [...result.messages].reverse();
            setMessages((items) => (older ? [...items, ...page] : page));
            setMore(result.has_more);
        } catch (failure) {
            if (!controller.signal.aborted && active.current) {
                clearDenied(failure);
                setLoadFailure(older ? 'older' : 'history');
                setError((failure as Error).message);
            }
        } finally {
            if (!controller.signal.aborted && active.current) setLoading(false);
        }
    }
    async function add() {
        setBusy(true);
        setError('');
        payload.current ??= JSON.stringify({
            user_ids: selected,
            version: version.current,
            mutation_id: crypto.randomUUID(),
            history_policy: history,
            message_ids: history === 'selected' ? messageIds : [],
            ...(history === 'selected' ? { history_confirmed: confirmed } : {}),
        });
        try {
            const result = await chatRequest<{ pending: boolean }>(
                `${conversationPath(conversation.id)}/people`,
                {
                    method: 'POST',
                    headers: { 'X-Chat-Actor': String(actorId) },
                    body: payload.current,
                },
            );
            if (!active.current) return;
            setOpen(false);
            onChanged();
            toast.success(
                result.pending
                    ? 'Invitation pending. History stays private until accepted.'
                    : conversation.type === 'family'
                      ? 'Invitation confirmed.'
                      : 'Members added with the reviewed history access.',
            );
        } catch (failure) {
            if (!active.current) return;
            clearDenied(failure);
            const definitive =
                failure instanceof ChatError &&
                failure.status >= 400 &&
                failure.status < 500;
            setUncertain(!definitive);
            if (definitive) payload.current = null;
            if (
                failure instanceof ChatError &&
                [403, 409].includes(failure.status)
            ) {
                setStale(true);
                onRefresh();
            }
            setError((failure as Error).message);
        } finally {
            if (active.current) setBusy(false);
        }
    }
    async function cancel(id: string) {
        setBusy(true);
        setError('');
        try {
            await chatRequest(`/chat/api/invitations/${id}`, {
                method: 'POST',
                headers: { 'X-Chat-Actor': String(actorId) },
                body: JSON.stringify({ action: 'cancel' }),
            });
            if (!active.current) return;
            onChanged();
            setOpen(false);
            toast.success('Invitation cancelled.');
        } catch (failure) {
            if (active.current) {
                clearDenied(failure);
                setError((failure as Error).message);
            }
        } finally {
            if (active.current) setBusy(false);
        }
    }
    return (
        <>
            {!hideTrigger && (
                <Button
                    variant="outline"
                    className="frontline-tap justify-start"
                    onClick={() => void show()}
                >
                    <UserPlus className="size-4" />
                    {conversation.type === 'family'
                        ? 'Invite approved relatives'
                        : 'Invite people'}
                </Button>
            )}
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!busy) {
                        setOpen(value);
                        if (!value) request.current?.abort();
                    }
                }}
            >
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    className="flex max-h-[85dvh] flex-col overflow-hidden p-0"
                >
                    <DialogHeader className="shrink-0 px-6 pt-6 pr-12">
                        <DialogTitle>
                            {conversation.type === 'family'
                                ? 'Invite approved relatives'
                                : 'Invite eligible colleagues'}
                        </DialogTitle>
                        <DialogDescription>
                            {conversation.client
                                ? `About ${conversation.client.name}`
                                : conversation.title}{' '}
                            · Review people and history access.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 space-y-4 overflow-x-hidden overflow-y-auto px-6 pb-1">
                        {error && (
                            <p
                                role="alert"
                                className="text-sm text-status-critical"
                            >
                                {error}
                                {stale &&
                                    !denied &&
                                    ' Close and reopen to review the current conversation.'}
                                {uncertain &&
                                    ' Your reviewed invitation is kept. Retry to confirm its result.'}
                            </p>
                        )}
                        {loadFailure && (
                            <Button
                                variant="outline"
                                className="frontline-tap"
                                disabled={loading || busy}
                                onClick={() =>
                                    void (loadFailure === 'people'
                                        ? loadPeople()
                                        : loadHistory(loadFailure === 'older'))
                                }
                            >
                                {loadFailure === 'people'
                                    ? 'Retry loading people'
                                    : 'Retry loading messages'}
                            </Button>
                        )}
                        <fieldset
                            disabled={
                                busy ||
                                loading ||
                                stale ||
                                uncertain ||
                                loadFailure === 'people'
                            }
                            className="min-w-0 space-y-4"
                        >
                            <div
                                className="max-h-40 space-y-1 overflow-y-auto"
                                aria-label="Eligible people"
                            >
                                {people.map((person) => (
                                    <label
                                        key={person.id}
                                        className="frontline-tap flex items-center gap-3 rounded-lg border p-3 text-sm"
                                    >
                                        <Checkbox
                                            checked={selected.includes(
                                                person.id,
                                            )}
                                            disabled={
                                                !selected.includes(person.id) &&
                                                selected.length >= maxAdditional
                                            }
                                            onCheckedChange={(checked) => {
                                                setSelected((ids) =>
                                                    checked === true
                                                        ? [...ids, person.id]
                                                        : ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  person.id,
                                                          ),
                                                );
                                                setConfirmed(false);
                                            }}
                                        />
                                        {person.name}
                                    </label>
                                ))}
                                {!people.length &&
                                    !loading &&
                                    loadFailure !== 'people' && (
                                        <p className="text-sm text-muted-foreground">
                                            {denied
                                                ? 'Contact access could not be confirmed.'
                                                : 'No eligible people to add.'}
                                        </p>
                                    )}
                            </div>
                            {conversation.type === 'group' && (
                                <p className="text-xs text-muted-foreground">
                                    Private groups have up to 20 people.{' '}
                                    {maxAdditional === 0
                                        ? 'This group is full.'
                                        : `${maxAdditional} ${maxAdditional === 1 ? 'place' : 'places'} remaining.`}
                                </p>
                            )}
                            <div className="block space-y-2 text-sm font-medium">
                                <label htmlFor={historyId}>
                                    History access
                                </label>
                                <Select
                                    value={history}
                                    onValueChange={(next) => {
                                        setHistory(next);
                                        setConfirmed(false);
                                        setMessageIds([]);
                                        setError('');
                                        setLoadFailure(null);
                                        if (next === 'selected')
                                            void loadHistory();
                                    }}
                                >
                                    <SelectTrigger
                                        id={historyId}
                                        className="frontline-tap w-full rounded-lg bg-background"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="from_joining">
                                            From joining onward
                                        </SelectItem>
                                        <SelectItem value="selected">
                                            Share selected earlier messages
                                            after review
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            {history === 'selected' && (
                                <>
                                    <p className="text-xs text-muted-foreground">
                                        Only selected messages and their
                                        attachments will be shared. Other
                                        earlier messages and unselected reply
                                        originals stay private. Choose up to 50
                                        messages.
                                    </p>
                                    <div
                                        className="max-h-48 space-y-2 overflow-y-auto"
                                        aria-label="Review earlier messages"
                                    >
                                        {messages
                                            .filter(
                                                (message) => !message.deleted,
                                            )
                                            .map((message) => (
                                                <label
                                                    key={message.id}
                                                    className="flex items-start gap-3 rounded-lg border p-3 text-sm"
                                                >
                                                    <Checkbox
                                                        aria-label={`Share message from ${message.sender_name}: ${message.content.slice(0, 60)}`}
                                                        className="mt-1"
                                                        checked={messageIds.includes(
                                                            message.id,
                                                        )}
                                                        disabled={
                                                            !messageIds.includes(
                                                                message.id,
                                                            ) &&
                                                            messageIds.length >=
                                                                50
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) => {
                                                            setMessageIds(
                                                                (ids) =>
                                                                    checked ===
                                                                    true
                                                                        ? [
                                                                              ...ids,
                                                                              message.id,
                                                                          ]
                                                                        : ids.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  message.id,
                                                                          ),
                                                            );
                                                            setConfirmed(false);
                                                        }}
                                                    />
                                                    <span className="min-w-0 break-words">
                                                        <strong>
                                                            {
                                                                message.sender_name
                                                            }
                                                        </strong>
                                                        <span className="block whitespace-pre-wrap">
                                                            {message.content}
                                                        </span>
                                                        {[
                                                            ...message.attachments,
                                                            ...message.legacy_attachments,
                                                        ].map((file, i) => (
                                                            <span
                                                                key={i}
                                                                className="block text-xs text-muted-foreground"
                                                            >
                                                                Attachment:{' '}
                                                                {file.name}
                                                            </span>
                                                        ))}
                                                    </span>
                                                </label>
                                            ))}
                                    </div>
                                    {!loading &&
                                        !loadFailure &&
                                        !messages.length && (
                                            <p className="text-sm text-muted-foreground">
                                                No earlier messages to share.
                                            </p>
                                        )}
                                    {more && (
                                        <Button
                                            variant="outline"
                                            className="frontline-tap w-full"
                                            onClick={() =>
                                                void loadHistory(true)
                                            }
                                        >
                                            Load earlier messages
                                        </Button>
                                    )}
                                    <label className="frontline-tap flex items-center gap-3 text-sm">
                                        <Checkbox
                                            checked={confirmed}
                                            onCheckedChange={(checked) =>
                                                setConfirmed(checked === true)
                                            }
                                        />
                                        I have reviewed the selected messages
                                        and attachments for these people.
                                    </label>
                                </>
                            )}
                            <p className="rounded-lg bg-muted p-3 text-sm">
                                {conversation.type === 'family'
                                    ? `Only approved relationships for this client are shown. Invitations expire after ${expiryDays} days and remain pending until accepted. Earlier history is never included automatically.`
                                    : 'New members see messages from when they join, plus only the earlier messages you explicitly select here.'}
                            </p>
                            {needsFamilyAgreement && selectedNames && (
                                <p className="text-sm text-muted-foreground">
                                    A relative already in this conversation must
                                    agree before {selectedNames} can join.
                                </p>
                            )}
                        </fieldset>
                        {loading && (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                Loading…
                            </p>
                        )}
                        {pending.length > 0 && (
                            <div className="space-y-2 border-t pt-3">
                                <h3 className="text-sm font-medium">
                                    Pending invitations
                                </h3>
                                {pending.map((item) => (
                                    <div
                                        key={item.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <span className="min-w-0 flex-1 break-words">
                                            {item.name}
                                        </span>
                                        <Button
                                            variant="outline"
                                            className="frontline-tap"
                                            disabled={
                                                busy ||
                                                uncertain ||
                                                stale ||
                                                loading
                                            }
                                            onClick={() => void cancel(item.id)}
                                        >
                                            Cancel invitation
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                    <DialogFooter className="shrink-0 border-t px-6 py-4">
                        <Button
                            variant="outline"
                            className="frontline-tap"
                            disabled={busy}
                            onClick={() => {
                                request.current?.abort();
                                setOpen(false);
                            }}
                        >
                            Close
                        </Button>
                        <Button
                            className="frontline-tap"
                            disabled={
                                busy ||
                                loading ||
                                stale ||
                                (!uncertain &&
                                    (!selected.length ||
                                        selected.length > maxAdditional ||
                                        (history === 'selected' &&
                                            (!confirmed ||
                                                !messageIds.length))))
                            }
                            onClick={() => void add()}
                        >
                            {busy
                                ? 'Saving…'
                                : uncertain
                                  ? 'Retry invitation'
                                  : conversation.type === 'family'
                                    ? 'Send invitation'
                                    : 'Add members'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

export function ChatPeopleInvite(
    props: Parameters<typeof ChatPeopleInviteSession>[0],
) {
    return (
        <ChatPeopleInviteSession
            key={`${props.actorId}:${props.conversation.id}`}
            {...props}
        />
    );
}
