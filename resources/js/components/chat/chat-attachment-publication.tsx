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
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    ChatError,
    readPublicationMemory,
    savePublicationMemory,
    type ChatPublicationMemory,
} from './api';
import { chatActorRequest } from './chat-actor-request';
import { watchChatUpdates } from './chat-update-watch';

type Options = {
    clients: { id: number; name: string }[];
    has_more: boolean;
    locked_client_id: number | null;
    destinations: ('documents' | 'photos')[];
};
type Review = {
    client: { id: number; name: string };
    file_name: string;
    destination: 'documents' | 'photos';
    family_visible: boolean;
    family_readers: { id: number; name: string }[];
    record_audience: string;
    audience_token: string;
};

export function ChatAttachmentPublication(
    props: Parameters<typeof PublicationSession>[0],
) {
    return (
        <PublicationSession
            key={`${props.actorId}:${props.attachmentId}:${props.memoryKey}`}
            {...props}
        />
    );
}
function PublicationSession({
    actorId,
    memoryKey,
    attachmentId,
    onClose,
    onReturnFocus,
}: {
    actorId: number;
    memoryKey: string;
    attachmentId: number;
    onClose: () => void;
    onReturnFocus: () => void;
}) {
    const path = `/chat/api/attachments/${attachmentId}/publication`;
    const fieldId = useId();
    const [draft, setDraft] = useState(() => readPublicationMemory(memoryKey));
    const [options, setOptions] = useState<Options | null>(null);
    const [query, setQuery] = useState(draft.query);
    const [review, setReview] = useState<Review | null>(null);
    const [confirmed, setConfirmed] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [writeError, setWriteError] = useState('');
    const [denied, setDenied] = useState(false);
    const [writing, setWriting] = useState(false);
    const [refresh, setRefresh] = useState(0);
    const mounted = useRef(true);
    const mutation = useRef<AbortController | null>(null);
    const snapshot = useRef<string | null>(null);
    const inFlight = useRef(false);
    const title = useRef<HTMLHeadingElement>(null);
    function remember(change: Partial<ChatPublicationMemory>) {
        savePublicationMemory(memoryKey, change);
        setDraft((current) => ({ ...current, ...change }));
    }
    function revoke() {
        setDenied(true);
        setOptions(null);
        setReview(null);
        setConfirmed(null);
        remember({
            query: '',
            client: '',
            familyVisible: false,
            pending: null,
        });
        setQuery('');
        title.current?.focus();
    }
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            mutation.current?.abort();
        };
    }, []);
    useEffect(() => {
        if (writing || draft.pending) return;
        let active = true;
        const controller = new AbortController();
        setOptions(null);
        setReview(null);
        setConfirmed(null);
        const timer = setTimeout(async () => {
            try {
                const next = await chatActorRequest<Options>(
                    actorId,
                    `${path}?q=${encodeURIComponent(query)}`,
                    {
                        signal: AbortSignal.any([
                            controller.signal,
                            AbortSignal.timeout(15000),
                        ]),
                    },
                );
                if (!active) return;
                setOptions(next);
                setError('');
                setDenied(false);
                if (next.locked_client_id)
                    remember({ client: String(next.locked_client_id) });
                if (
                    !next.destinations.includes(draft.destination) &&
                    next.destinations[0]
                )
                    remember({ destination: next.destinations[0] });
            } catch (failure) {
                if (!active) return;
                if (
                    failure instanceof ChatError &&
                    [401, 403, 404, 410, 419].includes(failure.status)
                )
                    revoke();
                setError((failure as Error).message);
            }
        }, 200);
        return () => {
            active = false;
            clearTimeout(timer);
            controller.abort();
        };
        // Options refresh independently from the reviewed selection.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [path, query, refresh, writing, draft.pending]);
    useEffect(() => {
        setReview(null);
        setConfirmed(null);
        snapshot.current = null;
        if (
            !draft.client ||
            !options?.clients.some(
                (client) => String(client.id) === draft.client,
            ) ||
            !options.destinations.includes(draft.destination) ||
            writing ||
            draft.pending ||
            denied
        )
            return;
        let active = true;
        const controller = new AbortController();
        const stop = watchChatUpdates(
            async () => {
                try {
                    const next = await chatActorRequest<Review>(
                        actorId,
                        `${path}/review`,
                        {
                            method: 'POST',
                            body: JSON.stringify({
                                client_id: Number(draft.client),
                                destination: draft.destination,
                                family_visible: draft.familyVisible,
                            }),
                            signal: AbortSignal.any([
                                controller.signal,
                                AbortSignal.timeout(15000),
                            ]),
                        },
                    );
                    if (!active || !navigator.onLine) return false;
                    if (
                        next.client.id !== Number(draft.client) ||
                        next.destination !== draft.destination ||
                        next.family_visible !== draft.familyVisible
                    )
                        throw new ChatError(
                            'The publication details changed. Review them again.',
                            409,
                        );
                    if (snapshot.current !== next.audience_token)
                        setConfirmed(null);
                    snapshot.current = next.audience_token;
                    setReview(next);
                    setError('');
                    return true;
                } catch (failure) {
                    if (!active) return false;
                    setReview(null);
                    setConfirmed(null);
                    if (
                        failure instanceof ChatError &&
                        [401, 403, 404, 410, 419].includes(failure.status)
                    )
                        revoke();
                    setError((failure as Error).message);
                    return false;
                }
            },
            {
                interval: () => 10000,
                whenHidden: true,
                events: ['focus', 'oblivion:chat-refresh'],
            },
        );
        const offline = () => {
            setReview(null);
            setConfirmed(null);
            setError('Check your connection, then review the audience again.');
        };
        window.addEventListener('offline', offline);
        if (!navigator.onLine) offline();
        return () => {
            active = false;
            stop();
            controller.abort();
            window.removeEventListener('offline', offline);
        };
        // Memory writes are account scoped; review polling never changes the selection.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        path,
        draft.client,
        draft.destination,
        draft.familyVisible,
        draft.pending,
        options,
        writing,
        denied,
        refresh,
    ]);
    function edit(change: Partial<ChatPublicationMemory>) {
        setReview(null);
        setConfirmed(null);
        setWriteError('');
        remember(change);
    }
    async function submit(body: string) {
        if (inFlight.current) return;
        inFlight.current = true;
        remember({ pending: body });
        setWriting(true);
        setWriteError('');
        const controller = new AbortController();
        mutation.current = controller;
        try {
            const result = await chatActorRequest<{
                client_id: number;
                destination: string;
                href: string | null;
            }>(actorId, path, {
                method: 'POST',
                body,
                signal: AbortSignal.any([
                    controller.signal,
                    AbortSignal.timeout(15000),
                ]),
            });
            if (!mounted.current) return;
            remember({ pending: null });
            window.dispatchEvent(
                new CustomEvent('oblivion:chat-attachment-published', {
                    detail: {
                        clientId: result.client_id,
                        destination: result.destination,
                    },
                }),
            );
            onClose();
            toast.success(
                result.href
                    ? 'Reviewed copy added to client records.'
                    : 'This copy was already published and its record is no longer available.',
                {
                    action: result.href
                        ? {
                              label: 'View record',
                              onClick: () => router.visit(result.href!),
                          }
                        : undefined,
                },
            );
        } catch (failure) {
            if (!mounted.current) return;
            if (
                failure instanceof ChatError &&
                [401, 403, 404, 419].includes(failure.status)
            )
                revoke();
            else if (
                failure instanceof ChatError &&
                [409, 422].includes(failure.status)
            ) {
                remember({ pending: null });
                setConfirmed(null);
                setReview(null);
                setRefresh((value) => value + 1);
            }
            setWriteError(
                failure instanceof ChatError
                    ? failure.message
                    : 'The result is not confirmed. Retry the same publication to check it safely.',
            );
        } finally {
            inFlight.current = false;
            if (mounted.current) setWriting(false);
        }
    }
    const canPublish =
        !!review &&
        !error &&
        !denied &&
        !writing &&
        !draft.pending &&
        confirmed === review.audience_token;
    const clientUnavailable =
        !!options &&
        !!draft.client &&
        !options.clients.some((client) => String(client.id) === draft.client);
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
                className="flex max-h-[85dvh] flex-col gap-0 overflow-hidden p-0 [&_button]:min-h-[44px] [&_button]:min-w-[44px]"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    onReturnFocus();
                }}
            >
                <DialogHeader className="shrink-0 border-b p-5 pr-12">
                    <DialogTitle ref={title} tabIndex={-1}>
                        Review publication
                    </DialogTitle>
                    <DialogDescription>
                        A separate client record has its own audience. The
                        original attachment stays in this conversation.
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 space-y-4 overflow-y-auto p-5">
                    {draft.pending && !denied ? (
                        <p
                            role="status"
                            className="rounded-lg border bg-muted p-3 text-sm"
                        >
                            A publication result is not confirmed. Retry the
                            same request before starting another copy.
                        </p>
                    ) : null}
                    {!denied && !draft.pending && (
                        <>
                            {!options?.locked_client_id && (
                                <label className="block space-y-1 text-sm">
                                    Find client
                                    <Input
                                        className="min-h-[44px]"
                                        value={query}
                                        onChange={(event) => {
                                            setQuery(event.target.value);
                                            edit({
                                                query: event.target.value,
                                                client: '',
                                            });
                                        }}
                                        placeholder="Search by name"
                                    />
                                </label>
                            )}
                            <div className="block space-y-1 text-sm">
                                <label htmlFor={`${fieldId}-client`}>
                                    Client
                                </label>
                                {/* An empty client shows the placeholder. */}
                                <Select
                                    value={draft.client}
                                    disabled={
                                        !options || !!options.locked_client_id
                                    }
                                    onValueChange={(client) => edit({ client })}
                                >
                                    <SelectTrigger
                                        id={`${fieldId}-client`}
                                        className="min-h-[44px] w-full bg-background"
                                    >
                                        <SelectValue placeholder="Select client" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options?.clients.map((client) => (
                                            <SelectItem
                                                key={client.id}
                                                value={String(client.id)}
                                            >
                                                {client.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            {options?.has_more && (
                                <p className="text-sm text-muted-foreground">
                                    More clients match. Refine the search to
                                    find yours.
                                </p>
                            )}
                            {options && options.clients.length === 0 && (
                                <p className="text-sm">
                                    No matching clients are available to you.
                                </p>
                            )}
                            <div className="block space-y-1 text-sm">
                                <label htmlFor={`${fieldId}-destination`}>
                                    Destination
                                </label>
                                <Select
                                    disabled={!options?.destinations.length}
                                    value={draft.destination}
                                    onValueChange={(destination) =>
                                        edit({
                                            destination: destination as
                                                | 'documents'
                                                | 'photos',
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id={`${fieldId}-destination`}
                                        className="min-h-[44px] w-full bg-background"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(
                                            options?.destinations ?? [
                                                'documents',
                                                'photos',
                                            ]
                                        ).map((destination) => (
                                            <SelectItem
                                                key={destination}
                                                value={destination}
                                            >
                                                Client{' '}
                                                {destination === 'photos'
                                                    ? 'photos'
                                                    : 'documents'}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            {options && !options.destinations.length && (
                                <p className="text-sm">
                                    This file cannot be published to client
                                    records. Photos allow supported images up to
                                    10 MB; documents allow supported files up to
                                    20 MB.
                                </p>
                            )}
                            <div className="block space-y-1 text-sm">
                                <label htmlFor={`${fieldId}-audience`}>
                                    Record audience
                                </label>
                                <Select
                                    value={
                                        draft.familyVisible ? 'family' : 'staff'
                                    }
                                    onValueChange={(audience) =>
                                        edit({
                                            familyVisible:
                                                audience === 'family',
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id={`${fieldId}-audience`}
                                        className="min-h-[44px] w-full bg-background"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="staff">
                                            Authorised staff only
                                        </SelectItem>
                                        <SelectItem value="family">
                                            Staff and approved family
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </>
                    )}
                    {review && !denied && !draft.pending && (
                        <div className="space-y-2 rounded-lg border bg-muted/40 p-3 text-sm [overflow-wrap:anywhere]">
                            <p className="font-medium">
                                {review.file_name} → {review.client.name} ·{' '}
                                {review.destination === 'photos'
                                    ? 'Photos'
                                    : 'Documents'}
                            </p>
                            <p>{review.record_audience}</p>
                            {review.family_visible && (
                                <>
                                    <p>
                                        Approved family now:{' '}
                                        {review.family_readers
                                            .map((reader) => reader.name)
                                            .join(', ') || 'None currently'}
                                        . This may include people outside this
                                        conversation.
                                    </p>
                                    <p>
                                        Later approved family members with the
                                        required consent may also see this
                                        record.
                                    </p>
                                </>
                            )}
                            <p>
                                The original file is copied, including any
                                location or camera details it contains.
                            </p>
                            <label className="flex min-h-[44px] cursor-pointer items-start gap-3 py-2">
                                <Checkbox
                                    className="mt-1 size-4 shrink-0"
                                    checked={
                                        confirmed === review.audience_token
                                    }
                                    onCheckedChange={(checked) =>
                                        setConfirmed(
                                            checked === true
                                                ? review.audience_token
                                                : null,
                                        )
                                    }
                                />
                                <span>
                                    I have permission to publish this file to
                                    the selected client record and audience.
                                </span>
                            </label>
                        </div>
                    )}
                    {!review &&
                        !error &&
                        !draft.pending &&
                        !denied &&
                        !!options?.destinations.length && (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                {clientUnavailable
                                    ? 'The selected client is no longer in these results. Search and select an available client.'
                                    : draft.client
                                      ? 'Checking file and audience…'
                                      : 'Choose a client to review the audience.'}
                            </p>
                        )}
                    {(error || writeError) && (
                        <div
                            role="alert"
                            className="space-y-2 text-sm text-destructive"
                        >
                            <p>{writeError || error}</p>
                            {!draft.pending && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setDenied(false);
                                        setRefresh((value) => value + 1);
                                    }}
                                >
                                    Review again
                                </Button>
                            )}
                        </div>
                    )}
                </div>
                <DialogFooter className="shrink-0 border-t p-4 sm:justify-between">
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    <Button
                        className="h-auto whitespace-normal"
                        disabled={
                            draft.pending ? writing || denied : !canPublish
                        }
                        onClick={() => {
                            if (draft.pending) void submit(draft.pending);
                            else if (review && canPublish)
                                void submit(
                                    JSON.stringify({
                                        client_id: review.client.id,
                                        destination: review.destination,
                                        family_visible: review.family_visible,
                                        audience_token: review.audience_token,
                                        audience_confirmed: true,
                                        mutation_id: crypto.randomUUID(),
                                    }),
                                );
                        }}
                    >
                        {writing
                            ? 'Publishing…'
                            : draft.pending
                              ? 'Retry same publication'
                              : 'Publish reviewed copy'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
