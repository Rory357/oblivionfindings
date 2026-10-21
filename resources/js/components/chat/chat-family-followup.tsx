import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
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
import { router } from '@inertiajs/react';
import { ListTodo } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    ChatError,
    chatDraftKey,
    conversationPath,
    readFollowupMemory,
    saveFollowupMemory,
    type ChatFollowupMemory,
} from './api';
import { chatActorRequest } from './chat-actor-request';
import { watchChatUpdates } from './chat-update-watch';

export type FollowupReview = {
    client: { id: number; name: string };
    audience: { id: number; name: string }[];
    audience_token: string;
    owners: { id: number; name: string }[];
    can_create: boolean;
};
export function ChatFamilyFollowup(props: {
    actorId: number;
    conversationId: number;
    compact?: boolean;
}) {
    return (
        <FollowupSession
            key={`${props.actorId}:${props.conversationId}`}
            {...props}
        />
    );
}
function FollowupSession({
    actorId,
    conversationId,
    compact = false,
}: {
    actorId: number;
    conversationId: number;
    compact?: boolean;
}) {
    const fieldId = useId();
    const key = chatDraftKey(actorId, null, conversationId);
    const path = `${conversationPath(conversationId)}/followups`;
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(() => readFollowupMemory(key));
    const [review, setReview] = useState<FollowupReview | null>(null);
    const [confirmed, setConfirmed] = useState<string | null>(null);
    const [readError, setReadError] = useState('');
    const [writeError, setWriteError] = useState('');
    const [denied, setDenied] = useState(false);
    const [writing, setWriting] = useState(false);
    const [refresh, setRefresh] = useState(0);
    const mounted = useRef(true);
    const reading = useRef<AbortController | null>(null);
    const mutation = useRef<AbortController | null>(null);
    const snapshot = useRef<FollowupReview | null>(null);
    const retry = useRef<HTMLButtonElement>(null);
    const title = useRef<HTMLHeadingElement>(null);
    function remember(change: Partial<ChatFollowupMemory>) {
        saveFollowupMemory(key, change);
        setDraft((current) => ({ ...current, ...change }));
    }
    function accessEnded() {
        snapshot.current = null;
        setReview(null);
        setConfirmed(null);
        setDenied(true);
        remember({ action: '', owner: '', due: '', pending: null });
        title.current?.focus();
    }
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            reading.current?.abort();
            mutation.current?.abort();
        };
    }, []);
    useEffect(() => {
        if (!open || writing) return;
        let active = true;
        const stop = watchChatUpdates(
            async () => {
                const controller = new AbortController();
                reading.current = controller;
                const timeout = setTimeout(() => controller.abort(), 10000);
                try {
                    const next = await chatActorRequest<FollowupReview>(
                        actorId,
                        path,
                        {
                            signal: controller.signal,
                        },
                    );
                    if (!active || !mounted.current) return true;
                    if (
                        snapshot.current?.audience_token !== next.audience_token
                    )
                        setConfirmed(null);
                    snapshot.current = next;
                    setReview(next);
                    setReadError('');
                    setDenied(false);
                    return true;
                } catch (failure) {
                    if (!active || !mounted.current) return true;
                    if (
                        failure instanceof ChatError &&
                        [401, 403, 404, 419].includes(failure.status)
                    )
                        accessEnded();
                    setReadError(
                        controller.signal.aborted
                            ? 'The audience could not be checked. Please retry.'
                            : (failure as Error).message,
                    );
                    return false;
                } finally {
                    clearTimeout(timeout);
                }
            },
            { interval: () => 10000 },
        );
        return () => {
            active = false;
            stop();
            reading.current?.abort();
        };
        // Draft state is intentionally independent of refreshed audience information.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [actorId, open, writing, path, refresh]);
    useEffect(() => {
        if (open && draft.pending && !writing) retry.current?.focus();
    }, [open, draft.pending, writing]);
    function edit(change: Partial<ChatFollowupMemory>) {
        remember(change);
        setConfirmed(null);
        setWriteError('');
    }
    async function submit(body: string) {
        if (writing) return;
        reading.current?.abort();
        remember({ pending: body });
        setWriting(true);
        setWriteError('');
        const clientId = snapshot.current?.client.id;
        const controller = new AbortController();
        mutation.current = controller;
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const result = await chatActorRequest<{
                family_note_id: number;
                already_created: boolean;
                href: string | null;
            }>(actorId, path, {
                method: 'POST',
                body,
                signal: controller.signal,
            });
            if (!mounted.current) return;
            remember({ action: '', owner: '', due: '', pending: null });
            setConfirmed(null);
            setOpen(false);
            window.dispatchEvent(
                new CustomEvent('oblivion:family-followup-created', {
                    detail: { clientId },
                }),
            );
            toast.success(
                result.href
                    ? 'Follow-up created. Family chat remains open.'
                    : 'This follow-up was already created and is no longer available.',
                {
                    action: result.href
                        ? {
                              label: 'View Family Notes',
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
                accessEnded();
            else if (
                failure instanceof ChatError &&
                [409, 422].includes(failure.status)
            ) {
                remember({ pending: null });
                setConfirmed(null);
                setRefresh((value) => value + 1);
            }
            setWriteError(
                controller.signal.aborted
                    ? 'The result is not confirmed. Retry the same follow-up to check it safely.'
                    : (failure as Error).message,
            );
        } finally {
            clearTimeout(timeout);
            if (mounted.current) setWriting(false);
        }
    }
    const canSubmit =
        review?.can_create &&
        !readError &&
        !denied &&
        !writing &&
        !draft.pending &&
        draft.action.trim() &&
        draft.due &&
        review.owners.some((owner) => String(owner.id) === draft.owner) &&
        confirmed === review.audience_token;
    return (
        <>
            <Button
                variant="ghost"
                className={compact ? 'size-[44px]' : 'min-h-[44px]'}
                aria-label="Create follow-up"
                title="Create follow-up"
                onClick={() => {
                    snapshot.current = null;
                    setReview(null);
                    setConfirmed(null);
                    setReadError('');
                    setDenied(false);
                    setOpen(true);
                }}
            >
                <ListTodo className="size-4" />
                {!compact && 'Create follow-up'}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    className="flex max-h-[85dvh] flex-col overflow-hidden"
                >
                    <DialogHeader className="shrink-0">
                        <DialogTitle ref={title} tabIndex={-1}>
                            Create a family follow-up
                        </DialogTitle>
                        <DialogDescription>
                            Family Notes are a shared workflow. Review the
                            wording and audience before creating an item. Chat
                            reply status and task completion stay separate.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 space-y-4 overflow-y-auto">
                        {!review && !readError && (
                            <p role="status" className="text-sm">
                                Checking Family Notes access…
                            </p>
                        )}
                        {readError && (
                            <div
                                role="alert"
                                className="space-y-2 text-sm text-status-critical"
                            >
                                <p>{readError}</p>
                                <Button
                                    className="min-h-[44px]"
                                    variant="outline"
                                    onClick={() =>
                                        setRefresh((value) => value + 1)
                                    }
                                >
                                    Retry audience check
                                </Button>
                            </div>
                        )}
                        {review && !denied && (
                            <>
                                <div className="rounded-lg bg-muted/40 p-3 text-sm">
                                    <strong>About {review.client.name}</strong>
                                    <p className="mt-2">
                                        Shared with authorised care-team staff
                                        and all approved people with this
                                        client’s Family Notes access, including
                                        people outside this chat. Later approved
                                        people may also see this record.
                                    </p>
                                    <p className="mt-2">
                                        Currently approved:{' '}
                                        {review.audience
                                            .map((person) => person.name)
                                            .join(', ') ||
                                            'No family members currently have Family Notes access.'}
                                    </p>
                                </div>
                                {!review.can_create && (
                                    <p role="status" className="text-sm">
                                        This conversation is currently
                                        read-only.
                                    </p>
                                )}
                                {review.can_create && !review.owners.length && (
                                    <p role="status" className="text-sm">
                                        No eligible care-team member is
                                        available to own this follow-up.
                                    </p>
                                )}
                                <fieldset
                                    disabled={
                                        writing ||
                                        !!draft.pending ||
                                        !review.can_create
                                    }
                                    className="space-y-4"
                                >
                                    <label className="grid gap-2 text-sm font-medium">
                                        Family-visible action
                                        <textarea
                                            rows={4}
                                            maxLength={5000}
                                            value={draft.action}
                                            onChange={(event) =>
                                                edit({
                                                    action: event.target.value,
                                                })
                                            }
                                            className="w-full rounded-lg border bg-background p-3 font-normal"
                                        />
                                    </label>
                                    <div className="grid gap-2 text-sm font-medium">
                                        <label htmlFor={`${fieldId}-owner`}>
                                            Assigned to
                                        </label>
                                        {/* An empty owner shows the placeholder. */}
                                        <Select
                                            value={draft.owner}
                                            onValueChange={(owner) =>
                                                edit({ owner })
                                            }
                                        >
                                            <SelectTrigger
                                                id={`${fieldId}-owner`}
                                                className="min-h-[44px] min-w-0 rounded-lg bg-background font-normal"
                                            >
                                                <SelectValue placeholder="Choose a care-team member" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {draft.owner &&
                                                    !review.owners.some(
                                                        (owner) =>
                                                            String(owner.id) ===
                                                            draft.owner,
                                                    ) && (
                                                        <SelectItem
                                                            value={draft.owner}
                                                            disabled
                                                        >
                                                            Previous choice is
                                                            no longer eligible
                                                        </SelectItem>
                                                    )}
                                                {review.owners.map((owner) => (
                                                    <SelectItem
                                                        key={owner.id}
                                                        value={String(owner.id)}
                                                    >
                                                        {owner.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid gap-2 text-sm font-medium">
                                        <label htmlFor={`${fieldId}-due`}>
                                            Due date
                                        </label>
                                        <DatePicker
                                            id={`${fieldId}-due`}
                                            label="Due date"
                                            value={draft.due}
                                            onChange={(due) => edit({ due })}
                                            allowClear
                                            compact
                                        />
                                    </div>
                                    <label className="flex min-h-[44px] cursor-pointer items-start gap-3 text-sm">
                                        <Checkbox
                                            className="mt-1 size-4 shrink-0"
                                            checked={
                                                confirmed ===
                                                review.audience_token
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
                                            I have reviewed the shared Family
                                            Notes audience.
                                        </span>
                                    </label>
                                </fieldset>
                            </>
                        )}
                        {writeError && (
                            <p
                                role="alert"
                                className="text-sm text-status-critical"
                            >
                                {writeError}
                            </p>
                        )}
                        {draft.pending && !writing && !denied && (
                            <div className="space-y-2 rounded-lg border p-3 text-sm">
                                <p>
                                    The result is not confirmed. Retry the same
                                    request before creating another follow-up.
                                </p>
                                <Button
                                    ref={retry}
                                    variant="outline"
                                    className="min-h-[44px]"
                                    onClick={() => void submit(draft.pending!)}
                                >
                                    Retry same follow-up
                                </Button>
                            </div>
                        )}
                    </div>
                    <DialogFooter className="shrink-0">
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            onClick={() => setOpen(false)}
                        >
                            Close
                        </Button>
                        <Button
                            className="min-h-[44px]"
                            disabled={!canSubmit}
                            onClick={() =>
                                void submit(
                                    JSON.stringify({
                                        mutation_id: crypto.randomUUID(),
                                        action: draft.action.trim(),
                                        assigned_to: Number(draft.owner),
                                        due_date: draft.due,
                                        audience_token: confirmed,
                                        audience_confirmed: true,
                                    }),
                                )
                            }
                        >
                            {writing
                                ? 'Creating…'
                                : 'Create reviewed follow-up'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
