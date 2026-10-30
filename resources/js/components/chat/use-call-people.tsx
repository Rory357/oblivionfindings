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
import { X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { chatRequest } from './api';
import type { CallParticipant } from './call-participants';

type Options = {
    people: { id: number; name: string; available: boolean }[];
    reserved: number;
    max_participants: number;
};
type Review = { kind: 'invite' } | { kind: 'remove'; person: CallParticipant };
type Command = { kind: Review['kind']; body: string };

/** The hook stays mounted with the call card, including while either dialog is closed. */
export function useCallPeople({
    callId,
    actorId,
    deviceId,
    invitationId,
    isHost,
    onRefresh,
}: {
    callId?: string;
    actorId: number;
    deviceId?: string;
    invitationId?: string;
    isHost: boolean;
    onRefresh?: () => Promise<void>;
}) {
    const [review, setReview] = useState<Review>({ kind: 'invite' });
    const [open, setOpen] = useState(false);
    const [options, setOptions] = useState<Options | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [loading, setLoading] = useState(false);
    const [working, setWorking] = useState(false);
    const [error, setError] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const [reload, setReload] = useState(0);
    const pending = useRef<Command | null>(null);
    const busy = useRef(false);
    const lifetime = useRef(0);
    const request = useRef<AbortController | null>(null);
    const trigger = useRef<HTMLElement | null>(null);
    const identity = `${actorId}:${callId}:${invitationId}`;
    const currentIdentity = useRef(identity);
    currentIdentity.current = identity;
    useEffect(() => {
        const epoch = ++lifetime.current;
        pending.current = null;
        busy.current = false;
        setOpen(false);
        setWorking(false);
        setUncertain(false);
        setError('');
        return () => {
            lifetime.current = epoch + 1;
            request.current?.abort();
        };
    }, [identity]);
    useEffect(() => {
        if (
            !open ||
            review.kind !== 'invite' ||
            pending.current ||
            !callId ||
            !deviceId
        )
            return;
        const controller = new AbortController();
        setLoading(true);
        setOptions(null);
        setSelected([]);
        setError('');
        void chatRequest<Options>(
            `/chat/api/calls/${callId}/people?device_id=${encodeURIComponent(deviceId)}`,
            {
                headers: { 'X-Chat-Actor': String(actorId) },
                signal: controller.signal,
            },
        )
            .then((result) => {
                if (!controller.signal.aborted) setOptions(result);
            })
            .catch((failure) => {
                if (!controller.signal.aborted)
                    setError((failure as Error).message);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [open, review.kind, callId, deviceId, actorId, reload]);

    function show(next: Review) {
        if (busy.current) return;
        trigger.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        if (!pending.current) {
            setReview(next);
            setError('');
            setUncertain(false);
        }
        setOpen(true);
    }
    async function submit() {
        if (busy.current || !callId || !deviceId || !invitationId) return;
        const command = pending.current ?? {
            kind: review.kind,
            body: JSON.stringify({
                mutation_id: crypto.randomUUID(),
                device_id: deviceId,
                actor_invitation_id: invitationId,
                ...(review.kind === 'invite'
                    ? { participant_ids: selected }
                    : {
                          participant_id: review.person.id,
                          invitation_id: review.person.invitation_id,
                      }),
            }),
        };
        pending.current = command;
        busy.current = true;
        setWorking(true);
        setError('');
        const epoch = lifetime.current;
        const ownedIdentity = identity;
        const controller = new AbortController();
        request.current = controller;
        const deadline = window.setTimeout(() => controller.abort(), 30000);
        const current = () =>
            lifetime.current === epoch &&
            currentIdentity.current === ownedIdentity;
        try {
            await chatRequest(`/chat/api/calls/${callId}/${command.kind}`, {
                method: 'POST',
                body: command.body,
                headers: { 'X-Chat-Actor': String(actorId) },
                signal: controller.signal,
            });
            if (!current()) return;
            pending.current = null;
            setUncertain(false);
            setOpen(false);
            toast(
                command.kind === 'invite'
                    ? 'Invitations sent.'
                    : 'Participant removed.',
            );
            // A confirmed command must not stay busy behind a slow roster refresh.
            void onRefresh?.().catch(() => undefined);
        } catch (failure) {
            if (!current()) return;
            const status = (failure as { status?: number }).status;
            const definitive =
                status !== undefined &&
                [400, 401, 403, 404, 409, 419, 422].includes(status);
            if (definitive) {
                pending.current = null;
                setUncertain(false);
            } else setUncertain(true);
            setError(
                definitive
                    ? (failure as Error).message
                    : 'The result is not confirmed. Retry this same action to check it safely.',
            );
        } finally {
            window.clearTimeout(deadline);
            if (current()) {
                busy.current = false;
                setWorking(false);
                request.current = null;
            }
        }
    }
    const available = !!callId && !!deviceId && !!invitationId;
    return {
        available,
        working,
        uncertain,
        invite: () => show({ kind: 'invite' }),
        remove: (person: CallParticipant) => show({ kind: 'remove', person }),
        dialog: (
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!busy.current) setOpen(value);
                }}
            >
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    showCloseButton={false}
                    className="flex max-h-[calc(100dvh-2rem)] flex-col overflow-hidden rounded-2xl bg-card p-4 text-card-foreground sm:p-6"
                    onCloseAutoFocus={(event) => {
                        if (trigger.current?.isConnected) {
                            event.preventDefault();
                            trigger.current.focus({ preventScroll: true });
                        }
                    }}
                >
                    <Button
                        className="absolute top-2 right-2 size-[44px]"
                        size="icon"
                        variant="ghost"
                        aria-label="Close call people review"
                        disabled={working}
                        onClick={() => setOpen(false)}
                    >
                        <X className="size-4" />
                    </Button>
                    <DialogHeader className="shrink-0 pr-10 text-left">
                        <DialogTitle>
                            {review.kind === 'invite'
                                ? 'Invite to this call'
                                : 'Remove from this call?'}
                        </DialogTitle>
                        <DialogDescription>
                            {review.kind === 'invite'
                                ? 'Choose approved conversation members. Everyone in the call can see who is invited.'
                                : `${review.person.name} will lose access to this call. Their conversation membership stays the same.`}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 space-y-3 overflow-y-auto">
                        {review.kind === 'invite' && (
                            <>
                                {loading && (
                                    <p role="status" className="text-sm">
                                        Checking available people…
                                    </p>
                                )}
                                {options && (
                                    <>
                                        <p className="text-sm">
                                            {options.reserved + selected.length}{' '}
                                            / {options.max_participants} places,
                                            including invitations
                                        </p>
                                        {!options.people.length && (
                                            <p className="text-sm text-muted-foreground">
                                                Everyone eligible is already in
                                                the call or has an invitation.
                                            </p>
                                        )}
                                        {options.people.map((person) => (
                                            <label
                                                key={person.id}
                                                className="flex min-h-[44px] items-start gap-3 rounded-lg border p-3 text-sm"
                                            >
                                                <Checkbox
                                                    className="mt-1 size-4 shrink-0"
                                                    checked={selected.includes(
                                                        person.id,
                                                    )}
                                                    disabled={
                                                        working ||
                                                        uncertain ||
                                                        !person.available ||
                                                        (!selected.includes(
                                                            person.id,
                                                        ) &&
                                                            options.reserved +
                                                                selected.length >=
                                                                options.max_participants)
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setSelected((ids) =>
                                                            checked === true
                                                                ? [
                                                                      ...ids,
                                                                      person.id,
                                                                  ]
                                                                : ids.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          person.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                <span className="min-w-0 break-words">
                                                    {person.name}
                                                    {!person.available && (
                                                        <span className="block text-xs text-muted-foreground">
                                                            Unavailable
                                                        </span>
                                                    )}
                                                </span>
                                            </label>
                                        ))}
                                    </>
                                )}
                            </>
                        )}
                        {error && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {error}
                            </p>
                        )}
                        {error && !uncertain && review.kind === 'invite' && (
                            <Button
                                variant="outline"
                                className="min-h-[44px]"
                                disabled={working}
                                onClick={() => setReload((value) => value + 1)}
                            >
                                Refresh people
                            </Button>
                        )}
                        {uncertain && (
                            <p className="text-sm text-muted-foreground">
                                You can close this view and return to the
                                pending action from the call controls.
                            </p>
                        )}
                    </div>
                    <DialogFooter className="shrink-0 flex-wrap gap-2 border-t pt-3">
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            disabled={working}
                            onClick={() => setOpen(false)}
                        >
                            {uncertain ? 'Close for now' : 'Cancel'}
                        </Button>
                        <Button
                            variant={
                                review.kind === 'remove'
                                    ? 'destructive'
                                    : 'default'
                            }
                            className="min-h-[44px] whitespace-normal"
                            disabled={
                                working ||
                                !available ||
                                (!uncertain &&
                                    (review.kind === 'invite'
                                        ? loading ||
                                          !options ||
                                          selected.length === 0
                                        : !isHost))
                            }
                            onClick={() => void submit()}
                        >
                            {working
                                ? 'Checking…'
                                : uncertain
                                  ? 'Retry same action'
                                  : review.kind === 'invite'
                                    ? 'Invite selected people'
                                    : 'Remove from call'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        ),
    };
}
