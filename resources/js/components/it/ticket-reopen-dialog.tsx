import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Loader2, RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { TicketVersionConflict } from './ticket-version-conflict';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    ticketId: number | null;
    expectedVersion: number | null;
    ticketReference?: string | null;
    audience: 'agent' | 'requester';
    onCompleted?: () => void;
}
const record = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);

/** One reasoned recovery journey for both technicians and requesters. */
export function TicketReopenDialog({ open, ...props }: Props) {
    const actorId = usePage<SharedData>().props.auth.user?.id;
    const [busy, setBusy] = useState(false);
    const [closeRequested, setCloseRequested] = useState(0);
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && !busy) setCloseRequested((value) => value + 1);
            }}
        >
            <DialogContent
                className="max-h-[90vh] min-w-0 overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                {open && (
                    <ReopenBody
                        key={`${actorId ?? 'none'}:${props.ticketId}`}
                        {...props}
                        actorId={actorId}
                        closeRequested={closeRequested}
                        onBusyChange={setBusy}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function ReopenBody({
    ticketId,
    expectedVersion,
    ticketReference,
    audience,
    onOpenChange,
    onCompleted,
    actorId,
    closeRequested,
    onBusyChange,
}: Omit<Props, 'open'> & {
    actorId: number | undefined;
    closeRequested: number;
    onBusyChange: (busy: boolean) => void;
}) {
    const [reason, setReason] = useState('');
    const [version, setVersion] = useState(expectedVersion);
    const [state, setState] = useState<
        | 'editing'
        | 'sending'
        | 'review'
        | 'session'
        | 'access'
        | 'unavailable'
        | 'done'
    >('editing');
    const [message, setMessage] = useState<string | null>(null);
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const request = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const approvedNavigation = useRef(false);
    const alert = useRef<HTMLParagraphElement>(null);
    const form = useRef<HTMLDivElement>(null);
    const closeButton = useRef<HTMLButtonElement>(null);
    const returnFocus = useRef<HTMLElement | null>(null);
    const handledClose = useRef(closeRequested);
    const requester = audience === 'requester';
    const needsReview = ['sending', 'review', 'session'].includes(state);
    const guarded =
        state !== 'done' &&
        state !== 'access' &&
        (reason !== '' || needsReview);
    const concealed = state === 'session' || state === 'access' || !actorId;
    const locked = state !== 'editing' || !actorId;
    const requestClose = () => {
        if (state === 'sending') return;
        if (guarded) setLeave(() => () => onOpenChange(false));
        else onOpenChange(false);
    };
    useEffect(() => {
        onBusyChange(state === 'sending');
        return () => onBusyChange(false);
    }, [state, onBusyChange]);
    useEffect(() => {
        if (handledClose.current === closeRequested) return;
        handledClose.current = closeRequested;
        if (state === 'sending') return;
        if (guarded) setLeave(() => () => onOpenChange(false));
        else onOpenChange(false);
    }, [closeRequested, guarded, state, onOpenChange]);
    useEffect(
        () => () => {
            ++epoch.current;
            request.current?.abort();
        },
        [],
    );
    useEffect(() => {
        if (message) alert.current?.focus();
    }, [message]);
    useEffect(() => {
        if (!guarded) return;
        const unload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', unload);
        const remove = router.on('before', (event) => {
            if (
                approvedNavigation.current ||
                event.detail.visit.method !== 'get'
            )
                return;
            event.preventDefault();
            const visit = event.detail.visit;
            setLeave(() => () => {
                approvedNavigation.current = true;
                router.visit(visit.url, visit);
            });
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            remove();
        };
    }, [guarded]);
    const deny = () => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
        setReason('');
        setLeave(null);
        setState('access');
        setMessage(
            'Your account or ticket access changed. The entered reason has been concealed. Return to the ticket before continuing.',
        );
    };
    const stop = () => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
        setState('review');
        setMessage(
            'The wait stopped. Reopening may still finish. Your reason is retained; review the current ticket before trying again.',
        );
    };
    const submit = async () => {
        const proposed = reason.trim();
        if (
            locked ||
            request.current ||
            ticketId === null ||
            !actorId ||
            !version ||
            !Number.isSafeInteger(version) ||
            version < 1 ||
            proposed.length < 5 ||
            proposed.length > 2000
        )
            return;
        const controller = new AbortController();
        request.current = controller;
        const token = ++epoch.current;
        setState('sending');
        setMessage(null);
        try {
            const response = await axios.post(
                `/it/tickets/${ticketId}/reopen`,
                {
                    actor_user_id: actorId,
                    reason: proposed,
                    expected_version: version,
                },
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                    timeout: 30000,
                },
            );
            if (epoch.current !== token) return;
            const body: unknown = response.data;
            const saved = record(body) && record(body.data) ? body.data : null;
            if (
                response.status !== 200 ||
                !record(body) ||
                body.status !== 'committed' ||
                saved?.id !== ticketId ||
                saved.viewer_user_id !== actorId ||
                saved.operation !== 'ticket.reopen' ||
                saved.status !== 'open' ||
                !Number.isSafeInteger(saved.lock_version) ||
                (saved.lock_version as number) <= version ||
                !Number.isSafeInteger(saved.comment_id) ||
                (saved.comment_id as number) < 1 ||
                saved.reason !== proposed ||
                saved.visibility !== (requester ? 'public' : 'internal')
            ) {
                throw new Error('Unconfirmed reopen response');
            }
            setVersion(saved.lock_version as number);
            setState('done');
            setMessage('The ticket is reopened and your reason is recorded.');
            approvedNavigation.current = true;
            onCompleted?.();
            router.reload({
                onFinish: () => {
                    approvedNavigation.current = false;
                },
            });
        } catch (failure) {
            if (epoch.current !== token) return;
            const response = axios.isAxiosError(failure)
                ? failure.response
                : undefined;
            if (response && [403, 404].includes(response.status)) {
                deny();
                return;
            }
            if (response && [401, 419].includes(response.status)) {
                setState('session');
                setMessage(
                    'Sign in with your original account, then review the current ticket. Your reason is retained but concealed.',
                );
            } else if (
                response?.status === 422 &&
                record(response.data) &&
                record(response.data.errors) &&
                Array.isArray(response.data.errors.reason) &&
                typeof response.data.errors.reason[0] === 'string'
            ) {
                setState('editing');
                setMessage(response.data.errors.reason[0]);
            } else {
                setState('review');
                setMessage(
                    response?.status === 409
                        ? 'This ticket changed after you opened it. Your reason is retained. Review the current ticket before applying it.'
                        : 'Reopening could not be confirmed. Your reason is retained. Review the current ticket before trying again.',
                );
            }
        } finally {
            if (epoch.current === token) request.current = null;
        }
    };

    return (
        <div
            ref={form}
            className="flex min-w-0 flex-col gap-4"
            onFocusCapture={(event) => {
                if (
                    event.target instanceof HTMLElement &&
                    event.currentTarget.contains(event.target)
                )
                    returnFocus.current = event.target;
            }}
        >
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <RotateCcw
                        className="size-4 text-primary"
                        aria-hidden="true"
                    />
                    Reopen {ticketReference ?? 'this ticket'}
                </DialogTitle>
                <DialogDescription>
                    {requester
                        ? 'Tell IT what is still wrong or what changed. Your explanation appears in the conversation and alerts the responsible technicians.'
                        : 'Return this record to the working queue only when more action is required. Your explanation is recorded as an internal note for the next technician.'}
                </DialogDescription>
            </DialogHeader>
            {message && (
                <p
                    ref={alert}
                    tabIndex={-1}
                    role={state === 'done' ? 'status' : 'alert'}
                    className="text-sm"
                >
                    {message}
                </p>
            )}
            {(state === 'review' || state === 'session') && (
                <TicketVersionConflict
                    error="Review the ticket before sending another reopen request."
                    ticketId={ticketId}
                    actorId={actorId}
                    requiredCapability="view"
                    onAccessLost={deny}
                    onSessionLost={() => setState('session')}
                    proposalDescription="Check whether more work is still needed. Continuing keeps your reason and does not reopen the ticket."
                    adoptLabel="Continue with this ticket version"
                    onReviewed={(nextVersion, current) => {
                        setVersion(nextVersion);
                        if (
                            !current ||
                            !['resolved', 'closed'].includes(current.status) ||
                            current.can_reopen !== true
                        ) {
                            setState('unavailable');
                            setMessage(
                                'This ticket cannot be reopened in its current state or with your current permissions. No further reopen request was sent. Review the conversation before leaving this editor.',
                            );
                            return;
                        }
                        setState('editing');
                        setMessage(
                            'Current ticket reviewed. Check your retained reason, then choose Reopen ticket to send it.',
                        );
                    }}
                />
            )}
            {state === 'session' && (
                <Button asChild variant="outline" size="sm">
                    <a href="/login" target="_blank" rel="noopener noreferrer">
                        Sign in again
                    </a>
                </Button>
            )}
            {!concealed && state !== 'done' && (
                <div className="space-y-2">
                    <label
                        htmlFor="ticket-reopen-reason"
                        className="text-sm font-medium"
                    >
                        {requester
                            ? 'What still needs attention?'
                            : 'Reason for reopening'}
                    </label>
                    <Textarea
                        id="ticket-reopen-reason"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        disabled={locked}
                        required
                        minLength={5}
                        maxLength={2000}
                        rows={4}
                        placeholder={
                            requester
                                ? 'For example, the issue returned after I signed in again'
                                : 'For example, monitoring shows the fault returned after validation'
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        Your reason stays in this editor until reopening is
                        confirmed or you choose to discard it.
                    </p>
                </div>
            )}
            <DialogFooter className="flex-wrap sm:flex-wrap">
                <Button
                    ref={closeButton}
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    disabled={state === 'sending'}
                    onClick={requestClose}
                >
                    {state === 'done'
                        ? 'Done'
                        : state === 'editing'
                          ? 'Keep settled'
                          : 'Close editor'}
                </Button>
                {state === 'sending' && (
                    <Button type="button" variant="outline" onClick={stop}>
                        Stop waiting
                    </Button>
                )}
                {!concealed && state !== 'done' && (
                    <Button
                        type="button"
                        className="min-h-11"
                        onClick={() => void submit()}
                        disabled={
                            locked ||
                            ticketId === null ||
                            !version ||
                            reason.trim().length < 5 ||
                            reason.trim().length > 2000
                        }
                    >
                        {state === 'sending' ? (
                            <Loader2
                                className="size-4 animate-spin"
                                aria-hidden="true"
                            />
                        ) : (
                            <RotateCcw className="size-4" aria-hidden="true" />
                        )}
                        {state === 'sending' ? 'Reopening…' : 'Reopen ticket'}
                    </Button>
                )}
            </DialogFooter>
            <ConfirmDialog
                open={leave !== null}
                onClose={() => setLeave(null)}
                title="Discard this reopen reason?"
                confirmText="Discard reason"
                description="Leaving discards this browser copy of the reason. It does not undo any recorded reopen. Cancel to keep editing."
                onCloseAutoFocus={(event) => {
                    if (!form.current?.isConnected) return;
                    event.preventDefault();
                    const target = returnFocus.current;
                    if (
                        target?.isConnected &&
                        form.current.contains(target) &&
                        !target.matches('[disabled], [aria-disabled="true"]')
                    )
                        target.focus({ preventScroll: true });
                    else closeButton.current?.focus({ preventScroll: true });
                }}
                onConfirm={() => {
                    const action = leave;
                    setLeave(null);
                    action?.();
                }}
            />
        </div>
    );
}
