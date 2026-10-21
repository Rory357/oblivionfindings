import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useEffect, useId, useRef, useState } from 'react';
import {
    ChatError,
    chatRequest,
    isChatAccessDenied,
    readPendingChatCreation,
    savePendingChatCreation,
    setChatDraftActor,
} from './api';

type Person = { id: number; name: string };
export type ChatCreatedSelection = {
    participantIds: number[];
    privateContact: boolean;
};
// Radix Select items cannot hold an empty value; "no site" / "care team inbox" map to this.
const NO_OPTION = '__none';
function ChatCreateConversationSession({
    open,
    actorId,
    onOpenChange,
    family,
    clientId,
    people,
    sites,
    canManageChannels,
    initialPerson,
    privateContact = false,
    onCreated,
}: {
    open: boolean;
    actorId: number;
    onOpenChange: (open: boolean) => void;
    family: boolean;
    clientId: number | null;
    people: Person[];
    sites: Person[];
    canManageChannels: boolean;
    initialPerson: number | null;
    privateContact?: boolean;
    onCreated: (id: number, selection?: ChatCreatedSelection) => void;
}) {
    const memoryKey = `${actorId}:${family ? clientId : 'staff'}:creation`;
    const [recovered] = useState(() => {
        setChatDraftActor(actorId);
        return readPendingChatCreation(memoryKey);
    });
    const previous = recovered
        ? (JSON.parse(recovered.body) as {
              conversation_kind: 'direct' | 'group' | 'channel' | 'family';
              participant_ids: number[];
              title: string | null;
              site_id: number | null;
          })
        : null;
    const [kind, setKind] = useState<'direct' | 'group' | 'channel'>(
        previous?.conversation_kind === 'family'
            ? 'direct'
            : (previous?.conversation_kind ?? 'direct'),
    );
    const [selected, setSelected] = useState<number[]>(
        previous?.participant_ids ?? (initialPerson ? [initialPerson] : []),
    );
    const [name, setName] = useState(previous?.title ?? '');
    const fieldId = useId();
    const [site, setSite] = useState(
        previous?.site_id ? String(previous.site_id) : '',
    );
    const [query, setQuery] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(
        recovered ? 'A previous request still needs its result checked.' : '',
    );
    const [uncertain, setUncertain] = useState(!!recovered);
    const [denied, setDenied] = useState(false);
    const pending = useRef(recovered);
    const mounted = useRef(true);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    const locked = busy || uncertain || denied;
    const group = !family && kind !== 'direct';
    const limit = kind === 'channel' ? 30 : 19;
    const candidates = denied ? [] : (pending.current?.people ?? people);
    const visible = candidates.filter((person) =>
        person.name.toLocaleLowerCase().includes(query.toLocaleLowerCase()),
    );
    const invalidPeople = selected.some(
        (id) => !people.some((person) => person.id === id),
    );
    async function submit() {
        if (busy || denied) return;
        pending.current ??= {
            people: [...people],
            body: JSON.stringify({
                mutation_id: crypto.randomUUID(),
                conversation_kind: family ? 'family' : kind,
                ...(family && privateContact ? { reuse_contact: true } : {}),
                participant_ids: selected,
                client_id: family ? clientId : null,
                managed_channel: !family && kind === 'channel',
                site_id:
                    !family && kind === 'channel' && site ? Number(site) : null,
                title:
                    family && privateContact
                        ? null
                        : name.trim() ||
                          (family && selected.length === 1
                              ? `Chat with ${people.find((person) => person.id === selected[0])?.name ?? 'care team'}`
                              : null),
            }),
        };
        savePendingChatCreation(memoryKey, pending.current);
        setBusy(true);
        setError('');
        try {
            const result = await chatRequest<{ conversation: { id: number } }>(
                '/chat/api/conversations',
                {
                    method: 'POST',
                    body: pending.current.body,
                    headers: { 'X-Chat-Actor': String(actorId) },
                },
            );
            if (!mounted.current) return;
            const submitted = JSON.parse(pending.current.body) as {
                participant_ids: number[];
                reuse_contact?: boolean;
            };
            pending.current = null;
            savePendingChatCreation(memoryKey, null);
            setUncertain(false);
            setSelected([]);
            setName('');
            setSite('');
            setQuery('');
            setKind('direct');
            onOpenChange(false);
            if (privateContact)
                onCreated(result.conversation.id, {
                    participantIds: submitted.participant_ids,
                    privateContact: submitted.reuse_contact === true,
                });
            else onCreated(result.conversation.id);
        } catch (failure) {
            if (!mounted.current) return;
            const rejected =
                failure instanceof ChatError &&
                failure.status >= 400 &&
                failure.status < 500 &&
                ![408, 429].includes(failure.status);
            // An absent/failed response may follow a committed write. Retry its exact bytes.
            if (rejected) {
                pending.current = null;
                savePendingChatCreation(memoryKey, null);
            }
            if (isChatAccessDenied(failure)) {
                setDenied(true);
                setSelected([]);
                setName('');
                setSite('');
                setQuery('');
            }
            setUncertain(!rejected);
            setError((failure as Error).message);
        } finally {
            if (mounted.current) setBusy(false);
        }
    }
    function changeKind(next: typeof kind) {
        setKind(next);
        setSelected(
            next === 'direct'
                ? selected.slice(0, 1)
                : selected.slice(0, next === 'channel' ? 30 : 19),
        );
        setName('');
        setSite('');
        setError('');
    }
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
                className="flex max-h-[85dvh] flex-col gap-0 overflow-hidden p-0"
            >
                <DialogHeader className="shrink-0 border-b p-5 pr-12">
                    <DialogTitle>
                        {family
                            ? privateContact
                                ? 'Confirm your contact'
                                : 'Message the care team'
                            : kind === 'group'
                              ? 'Create a private group'
                              : kind === 'channel'
                                ? 'Create a managed channel'
                                : 'Start a chat'}
                    </DialogTitle>
                    <DialogDescription>
                        {family
                            ? 'Your conversation starts separately from other relatives. Authorised care-team cover can read and respond.'
                            : group
                              ? 'Choose a name and the people who belong here.'
                              : 'Choose a colleague. If you already have a direct chat, we will open it.'}
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 space-y-4 overflow-y-auto p-5">
                    {!family && (
                        <div className="flex flex-wrap gap-2">
                            {kind !== 'direct' && (
                                <Button
                                    className="frontline-tap whitespace-normal"
                                    variant="outline"
                                    disabled={locked}
                                    onClick={() => changeKind('direct')}
                                >
                                    Start a direct chat instead
                                </Button>
                            )}
                            {kind !== 'group' && (
                                <Button
                                    className="frontline-tap whitespace-normal"
                                    variant="outline"
                                    disabled={locked}
                                    onClick={() => changeKind('group')}
                                >
                                    Create a private group instead
                                </Button>
                            )}
                            {canManageChannels && kind !== 'channel' && (
                                <Button
                                    className="frontline-tap whitespace-normal"
                                    variant="outline"
                                    disabled={locked}
                                    onClick={() => changeKind('channel')}
                                >
                                    Create a managed channel
                                </Button>
                            )}
                        </div>
                    )}
                    {(family || group) && (!privateContact || recovered) && (
                        <label className="block space-y-2 text-sm font-medium">
                            <span>
                                {family
                                    ? 'Conversation name (optional)'
                                    : kind === 'channel'
                                      ? 'Channel name'
                                      : 'Group name'}
                            </span>
                            <Input
                                className="frontline-tap text-base sm:text-sm"
                                value={name}
                                maxLength={200}
                                disabled={locked}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                placeholder={
                                    family
                                        ? 'What would you like to discuss?'
                                        : 'Give it a clear name'
                                }
                            />
                        </label>
                    )}
                    {!family && kind === 'channel' && (
                        <div className="block space-y-2 text-sm font-medium">
                            <label htmlFor={`${fieldId}-site`}>
                                Site scope
                            </label>
                            <Select
                                disabled={locked}
                                value={site || NO_OPTION}
                                onValueChange={(next) =>
                                    setSite(next === NO_OPTION ? '' : next)
                                }
                            >
                                <SelectTrigger
                                    id={`${fieldId}-site`}
                                    aria-describedby={`${fieldId}-site-help`}
                                    className="frontline-tap w-full rounded-lg bg-background"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NO_OPTION}>
                                        Selected team members
                                    </SelectItem>
                                    {sites.map((item) => (
                                        <SelectItem
                                            key={item.id}
                                            value={String(item.id)}
                                        >
                                            {item.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <span
                                id={`${fieldId}-site-help`}
                                className="block text-xs font-normal text-muted-foreground"
                            >
                                Members must also have current access to the
                                selected site.
                            </span>
                        </div>
                    )}
                    {family ? (
                        <div className="block space-y-2 text-sm font-medium">
                            <label htmlFor={`${fieldId}-contact`}>
                                Care team or named worker
                            </label>
                            <Select
                                disabled={locked || privateContact}
                                value={
                                    selected[0]
                                        ? String(selected[0])
                                        : NO_OPTION
                                }
                                onValueChange={(next) =>
                                    setSelected(
                                        next !== NO_OPTION
                                            ? [Number(next)]
                                            : [],
                                    )
                                }
                            >
                                <SelectTrigger
                                    id={`${fieldId}-contact`}
                                    className="frontline-tap w-full rounded-lg bg-background"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NO_OPTION}>
                                        Care team inbox
                                    </SelectItem>
                                    {candidates.map((person) => (
                                        <SelectItem
                                            key={person.id}
                                            value={String(person.id)}
                                        >
                                            {person.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    ) : (
                        <>
                            <Input
                                className="frontline-tap text-base sm:text-sm"
                                aria-label="Find a colleague"
                                placeholder="Find a colleague"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                            <div
                                className="space-y-1"
                                role="group"
                                aria-label={
                                    group ? 'Group members' : 'Colleagues'
                                }
                            >
                                {visible.map((person) => (
                                    <label
                                        key={person.id}
                                        className="frontline-tap flex cursor-pointer items-center gap-3 rounded-lg border p-3 has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                                    >
                                        <input
                                            type={group ? 'checkbox' : 'radio'}
                                            name="chat-recipient"
                                            className="size-4 accent-primary"
                                            checked={selected.includes(
                                                person.id,
                                            )}
                                            disabled={
                                                locked ||
                                                (group &&
                                                    !selected.includes(
                                                        person.id,
                                                    ) &&
                                                    selected.length >= limit)
                                            }
                                            onChange={(event) =>
                                                setSelected(
                                                    group
                                                        ? event.target.checked
                                                            ? [
                                                                  ...selected,
                                                                  person.id,
                                                              ]
                                                            : selected.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      person.id,
                                                              )
                                                        : [person.id],
                                                )
                                            }
                                        />
                                        <span className="min-w-0 text-sm break-words">
                                            {person.name}
                                        </span>
                                    </label>
                                ))}
                                {!visible.length && (
                                    <p className="py-3 text-sm text-muted-foreground">
                                        {people.length
                                            ? 'No colleagues match your search.'
                                            : 'No eligible colleagues are currently available.'}
                                    </p>
                                )}
                            </div>
                            {group && (
                                <p className="text-xs text-muted-foreground">
                                    {selected.length + 1} of {limit + 1} people,
                                    including you. This new conversation starts
                                    with no earlier messages.
                                </p>
                            )}
                        </>
                    )}
                    {invalidPeople && !locked && (
                        <div role="alert" className="space-y-2 text-sm">
                            <p>
                                Someone selected is no longer available. Review
                                your selection before continuing.
                            </p>
                            <Button
                                className="frontline-tap whitespace-normal"
                                variant="outline"
                                onClick={() =>
                                    setSelected(
                                        selected.filter((id) =>
                                            people.some(
                                                (person) => person.id === id,
                                            ),
                                        ),
                                    )
                                }
                            >
                                Remove unavailable people
                            </Button>
                        </div>
                    )}
                    {error && (
                        <div
                            role="alert"
                            className="space-y-2 rounded-lg border p-3 text-sm"
                        >
                            <p>{error}</p>
                            {uncertain && (
                                <p>
                                    We could not confirm the result. Your name
                                    and people are kept. Retry the same request
                                    to safely find or create your conversation.
                                </p>
                            )}
                        </div>
                    )}
                </div>
                <DialogFooter className="shrink-0 border-t p-4">
                    <Button
                        className="frontline-tap whitespace-normal"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {locked ? 'Close' : 'Cancel'}
                    </Button>
                    <Button
                        className="frontline-tap whitespace-normal"
                        disabled={
                            busy ||
                            denied ||
                            (!uncertain &&
                                (invalidPeople ||
                                    (!family &&
                                        (!selected.length ||
                                            (group && !name.trim()) ||
                                            (group &&
                                                selected.length > limit)))))
                        }
                        onClick={() => void submit()}
                    >
                        {busy
                            ? 'Opening…'
                            : uncertain
                              ? 'Retry same request'
                              : group
                                ? kind === 'channel'
                                    ? 'Create channel'
                                    : 'Create group'
                                : 'Continue'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function ChatCreateConversation(
    props: Parameters<typeof ChatCreateConversationSession>[0],
) {
    return (
        <ChatCreateConversationSession
            key={`${props.actorId}:${props.family ? props.clientId : 'staff'}`}
            {...props}
        />
    );
}
