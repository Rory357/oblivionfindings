import { ConfirmDialog } from '@/components/confirm-dialog';
import { CSAT_LIT, CsatStars } from '@/components/it/csat-stars';
import type { CurrentTicket } from '@/components/it/ticket-version-conflict';
import { TicketVersionConflict } from '@/components/it/ticket-version-conflict';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { fireConfetti } from '@/lib/confetti';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { CheckCircle2, Loader2, Star } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Props {
    ticketId: number;
    expectedVersion: number;
    actorId: number;
    onClose: () => void;
    onConfirmed: () => void;
    isOpen?: boolean;
}
const record = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);

/** No draft fields: confirmation applies to the exact resolution the requester reviewed. */
export function TicketConfirmResolutionDialog({
    isOpen = true,
    ...props
}: Props) {
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        if (!isOpen) setBusy(false);
    }, [isOpen]);
    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                if (!open && !busy) props.onClose();
            }}
        >
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
            >
                {isOpen && (
                    <ConfirmationBody
                        {...props}
                        busy={busy}
                        setBusy={setBusy}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function ConfirmationBody({
    ticketId,
    expectedVersion,
    actorId,
    onClose,
    onConfirmed,
    busy,
    setBusy,
}: Omit<Props, 'isOpen'> & {
    busy: boolean;
    setBusy: (busy: boolean) => void;
}) {
    const [version, setVersion] = useState(expectedVersion);
    const [state, setState] = useState<
        'ready' | 'review' | 'session' | 'unavailable' | 'done'
    >('ready');
    const [message, setMessage] = useState<string | null>(null);
    const request = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const alert = useRef<HTMLDivElement>(null);
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

    const stop = () => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
        setBusy(false);
        setState('review');
        setMessage(
            'The wait stopped. Confirmation may still finish. Review the current ticket before trying again.',
        );
    };
    const confirm = async () => {
        if (state !== 'ready' || busy || request.current) return;
        const controller = new AbortController();
        request.current = controller;
        const token = ++epoch.current;
        setBusy(true);
        setMessage(null);
        try {
            const response = await axios.post(
                `/it/tickets/${ticketId}/confirm-resolution`,
                {
                    actor_user_id: actorId,
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
                saved.operation !== 'resolution.confirm' ||
                saved.status !== 'closed' ||
                !Number.isSafeInteger(saved.lock_version) ||
                (saved.lock_version as number) <= version
            ) {
                throw new Error('Unconfirmed resolution response');
            }
            setState('done');
            setMessage(
                'Your confirmation is recorded and the ticket is closed.',
            );
            onConfirmed();
        } catch (error) {
            if (epoch.current !== token) return;
            const response = axios.isAxiosError(error)
                ? error.response
                : undefined;
            if (response && [401, 419].includes(response.status)) {
                setState('session');
                setMessage(
                    'Sign in with your original account, then review the current ticket before confirming.',
                );
            } else if (response && [403, 404].includes(response.status)) {
                setState('unavailable');
                setMessage(
                    'This resolution is no longer available to confirm here. Close this form and review the ticket.',
                );
            } else {
                setState('review');
                setMessage(
                    response?.status === 422 &&
                        record(response.data) &&
                        typeof response.data.message === 'string'
                        ? response.data.message
                        : 'Confirmation was not established. Review the current ticket before trying again.',
                );
            }
        } finally {
            if (epoch.current === token) {
                request.current = null;
                setBusy(false);
            }
        }
    };

    return (
        <div className="space-y-4">
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <CheckCircle2
                        className="size-4 shrink-0 text-primary"
                        aria-hidden
                    />
                    Confirm the fix and close this ticket
                </DialogTitle>
                <DialogDescription>
                    Confirm only after checking that the reported issue is
                    fixed. Closing records your confirmation and locks any
                    satisfaction rating already submitted.
                </DialogDescription>
            </DialogHeader>
            {message && (
                <div
                    ref={alert}
                    tabIndex={-1}
                    role={state === 'done' ? 'status' : 'alert'}
                    className="rounded-lg border border-border bg-muted/30 p-3 text-sm"
                >
                    {message}
                </div>
            )}
            {(state === 'review' || state === 'session') && (
                <TicketVersionConflict
                    error="Check the latest resolution before confirming. Reviewing does not submit anything."
                    ticketId={ticketId}
                    actorId={actorId}
                    requiredCapability="confirmResolution"
                    proposalDescription="Open the full ticket and read the latest fix in its conversation before choosing this resolution. Confirmation still requires a separate submit."
                    adoptLabel="Use this resolution"
                    onAccessLost={() => {
                        setState('unavailable');
                        setMessage(
                            'The ticket changed or your access is no longer available. Close this form and review the ticket.',
                        );
                    }}
                    onSessionLost={() => setState('session')}
                    onReviewed={(nextVersion) => {
                        setVersion(nextVersion);
                        setState('ready');
                        setMessage(
                            'Current ticket reviewed. Choose Confirm and close to submit separately.',
                        );
                    }}
                />
            )}
            {state === 'session' && (
                <Button asChild variant="outline">
                    <a href="/login" target="_blank" rel="noopener noreferrer">
                        Sign in again
                    </a>
                </Button>
            )}
            <DialogFooter>
                {busy ? (
                    <Button variant="outline" onClick={stop}>
                        Stop waiting
                    </Button>
                ) : (
                    <Button variant="outline" onClick={onClose}>
                        {state === 'done' ? 'Done' : 'Close'}
                    </Button>
                )}
                {state !== 'done' && state !== 'unavailable' && (
                    <Button
                        disabled={busy || state !== 'ready'}
                        onClick={() => void confirm()}
                    >
                        {busy && (
                            <Loader2
                                className="size-4 animate-spin"
                                aria-hidden
                            />
                        )}
                        {busy ? 'Confirming…' : 'Confirm and close'}
                    </Button>
                )}
            </DialogFooter>
        </div>
    );
}

export interface RatingProps {
    ticketId: number;
    expectedVersion: number;
    score?: number | null;
    comment?: string | null;
    onDone?: () => void;
}
const validScore = (value: unknown): value is number =>
    Number.isInteger(value) && (value as number) >= 1 && (value as number) <= 5;
const readRating = (value: unknown) => {
    if (value === null) return null;
    if (
        !record(value) ||
        !validScore(value.score) ||
        (value.comment !== null && typeof value.comment !== 'string') ||
        typeof value.submitted_at !== 'string' ||
        !Number.isFinite(Date.parse(value.submitted_at))
    )
        return undefined;
    return { score: value.score, comment: value.comment as string | null };
};

export function TicketRatingDialog({
    isOpen,
    onClose,
    ...props
}: RatingProps & { isOpen: boolean; onClose: () => void }) {
    const actorId = usePage<SharedData>().props.auth.user?.id;
    const [busy, setBusy] = useState(false);
    const [closeRequested, setCloseRequested] = useState(0);
    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                if (!open && !busy) setCloseRequested((value) => value + 1);
            }}
        >
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                {isOpen && (
                    <RatingBody
                        key={`${actorId ?? 'none'}:${props.ticketId}`}
                        {...props}
                        actorId={actorId}
                        onClose={onClose}
                        closeRequested={closeRequested}
                        onBusyChange={setBusy}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function RatingBody({
    ticketId,
    expectedVersion,
    actorId,
    score = null,
    comment: initialComment = '',
    onDone,
    onClose,
    closeRequested,
    onBusyChange,
}: RatingProps & {
    actorId: number | undefined;
    onClose: () => void;
    closeRequested: number;
    onBusyChange: (busy: boolean) => void;
}) {
    const [hover, setHover] = useState(0);
    const [picked, setPicked] = useState<number>(score ?? 0);
    const [comment, setComment] = useState(initialComment ?? '');
    const [baseline, setBaseline] = useState({
        score: score ?? 0,
        comment: initialComment ?? '',
    });
    const [version, setVersion] = useState(expectedVersion);
    const [state, setState] = useState<
        'editing' | 'sending' | 'review' | 'session' | 'access' | 'done'
    >('editing');
    const [message, setMessage] = useState<string | null>(null);
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const request = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const approvedNavigation = useRef(false);
    const alert = useRef<HTMLParagraphElement>(null);
    const form = useRef<HTMLDivElement>(null);
    const closeButton = useRef<HTMLButtonElement>(null);
    const returnAfterDiscard = useRef<HTMLElement | null>(null);
    const locked = state !== 'editing' || !actorId;
    const dirty = picked !== baseline.score || comment !== baseline.comment;
    const needsReview = ['sending', 'review', 'session'].includes(state);
    const handledClose = useRef(closeRequested);
    const requestClose = () => {
        if (state === 'sending') return;
        if (state !== 'done' && state !== 'access' && (dirty || needsReview))
            setLeave(() => onClose);
        else onClose();
    };
    useEffect(() => {
        onBusyChange(state === 'sending');
        return () => onBusyChange(false);
    }, [state, onBusyChange]);
    useEffect(() => {
        if (handledClose.current === closeRequested) return;
        handledClose.current = closeRequested;
        if (state === 'sending') return;
        if (state !== 'done' && state !== 'access' && (dirty || needsReview))
            setLeave(() => onClose);
        else onClose();
    }, [closeRequested, dirty, needsReview, state, onClose]);
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
        if (state === 'done' || state === 'access' || (!dirty && !needsReview))
            return;
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
    }, [dirty, needsReview, state]);
    const deny = () => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
        setPicked(0);
        setComment('');
        setBaseline({ score: 0, comment: '' });
        setState('access');
        setMessage(
            'Your account or ticket access changed. Entered feedback is concealed. Review the ticket before continuing.',
        );
    };
    const stop = () => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
        setState('review');
        setMessage(
            'The wait stopped. Your rating may still be saved. Review the current rating before trying again.',
        );
    };

    const shown = hover || picked;

    const submit = async () => {
        if (
            locked ||
            request.current ||
            !validScore(picked) ||
            !actorId ||
            !Number.isSafeInteger(version) ||
            version < 1
        )
            return;
        const controller = new AbortController();
        request.current = controller;
        const token = ++epoch.current;
        const proposed = { score: picked, comment: comment.trim() || null };
        setState('sending');
        setMessage(null);
        try {
            const response = await axios.post(
                `/it/tickets/${ticketId}/csat`,
                {
                    actor_user_id: actorId,
                    expected_version: version,
                    ...proposed,
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
            const rating = readRating(saved);
            if (
                response.status !== 200 ||
                !record(body) ||
                body.status !== 'committed' ||
                saved?.id !== ticketId ||
                saved.viewer_user_id !== actorId ||
                saved.operation !== 'csat.save' ||
                !Number.isSafeInteger(saved.lock_version) ||
                (saved.lock_version as number) < version ||
                !rating ||
                rating.score !== proposed.score ||
                rating.comment !== proposed.comment
            )
                throw new Error('Unconfirmed rating');
            setBaseline({ score: rating.score, comment: rating.comment ?? '' });
            setPicked(rating.score);
            setComment(rating.comment ?? '');
            setVersion(saved.lock_version as number);
            setState('done');
            setMessage('Your rating is saved. Thank you for the feedback.');
            if (rating.score === 5) fireConfetti();
            approvedNavigation.current = true;
            onDone?.();
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
                    'Sign in with your original account, then review the current rating. Your entered feedback is retained but concealed.',
                );
            } else if (response?.status === 422) {
                setState('editing');
                setMessage(
                    'Choose a rating from 1 to 5 and keep the comment within 1,000 characters. Your feedback has been retained.',
                );
            } else {
                setState('review');
                setMessage(
                    'The save could not be confirmed. Your feedback is retained. Review the current rating before trying again.',
                );
            }
        } finally {
            if (epoch.current === token) request.current = null;
        }
    };

    const reviewRating = (current: CurrentTicket) => {
        const saved = readRating(current.csat);
        return saved === undefined ? (
            <p>The current rating could not be confirmed. Review again.</p>
        ) : saved === null ? (
            <p>No rating has been saved on this ticket.</p>
        ) : (
            <div className="space-y-1">
                <CsatStars score={saved.score} />
                {saved.comment && (
                    <p className="break-words whitespace-pre-wrap">
                        {saved.comment}
                    </p>
                )}
            </div>
        );
    };

    return (
        <div
            ref={form}
            className="flex flex-col gap-4"
            onFocusCapture={(event) => {
                // A nested portal bubbles React focus events through this body.
                // Remember only controls physically inside the rating editor.
                if (
                    event.target instanceof HTMLElement &&
                    event.currentTarget.contains(event.target)
                )
                    returnAfterDiscard.current = event.target;
            }}
        >
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Star className="size-4 text-primary" aria-hidden="true" />
                    Rate IT’s help
                </DialogTitle>
                <DialogDescription>
                    Your feedback can be updated while this ticket is resolved.
                    Confirming the fix and closing the ticket locks the rating.
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
                    error="Review the saved rating before applying your feedback."
                    ticketId={ticketId}
                    actorId={actorId}
                    requiredCapability="rate"
                    renderCurrent={reviewRating}
                    onAccessLost={deny}
                    onSessionLost={() => setState('session')}
                    proposalDescription="Compare the saved rating with your retained feedback. Continuing does not save changes."
                    adoptLabel="Keep my feedback against this version"
                    onReviewed={(nextVersion, current) => {
                        const saved = current
                            ? readRating(current.csat)
                            : undefined;
                        if (saved === undefined) {
                            setMessage(
                                'The current rating could not be confirmed. Review again.',
                            );
                            return;
                        }
                        setBaseline({
                            score: saved?.score ?? 0,
                            comment: saved?.comment ?? '',
                        });
                        setVersion(nextVersion);
                        setState('editing');
                        setMessage(null);
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
            {state === 'done' && <CsatStars score={baseline.score} />}
            {state !== 'access' && state !== 'session' && state !== 'done' && (
                <>
                    <p className="text-sm font-medium">Your rating</p>
                    <div
                        className="flex items-center gap-0.5"
                        role="radiogroup"
                        aria-label="Rate IT's help from 1 to 5 stars"
                    >
                        {[1, 2, 3, 4, 5].map((n) => (
                            // The star is a radio control, not a form action.
                            // eslint-disable-next-line no-restricted-syntax
                            <button
                                key={n}
                                type="button"
                                role="radio"
                                aria-checked={picked === n}
                                aria-label={`${n} star${n > 1 ? 's' : ''}`}
                                disabled={locked}
                                tabIndex={n === (picked || 1) ? 0 : -1}
                                onKeyDown={(event) => {
                                    if (
                                        ![
                                            'ArrowRight',
                                            'ArrowUp',
                                            'ArrowLeft',
                                            'ArrowDown',
                                        ].includes(event.key)
                                    )
                                        return;
                                    event.preventDefault();
                                    const next =
                                        ((n -
                                            1 +
                                            (['ArrowRight', 'ArrowUp'].includes(
                                                event.key,
                                            )
                                                ? 1
                                                : 4)) %
                                            5) +
                                        1;
                                    setPicked(next);
                                    event.currentTarget.parentElement
                                        ?.querySelector<HTMLButtonElement>(
                                            `[aria-label="${next} star${next > 1 ? 's' : ''}"]`,
                                        )
                                        ?.focus();
                                }}
                                onMouseEnter={() => setHover(n)}
                                onMouseLeave={() => setHover(0)}
                                onFocus={() => setHover(n)}
                                onBlur={() => setHover(0)}
                                onClick={() => setPicked(n)}
                                className="rounded p-0.5 transition-transform hover:scale-110 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none motion-reduce:transition-none motion-reduce:hover:scale-100"
                            >
                                <Star
                                    className={
                                        n <= shown
                                            ? 'h-6 w-6'
                                            : 'h-6 w-6 text-muted-foreground/40'
                                    }
                                    style={n <= shown ? CSAT_LIT : undefined}
                                />
                            </button>
                        ))}
                    </div>
                    {picked ? (
                        <div className="space-y-2">
                            <div className="flex items-center justify-between gap-3">
                                <Label
                                    htmlFor={`ticket-${ticketId}-rating-comment`}
                                >
                                    Feedback comment{' '}
                                    <span className="font-normal text-muted-foreground">
                                        (optional)
                                    </span>
                                </Label>
                                <span className="text-xs text-muted-foreground">
                                    {comment.length}/1,000
                                </span>
                            </div>
                            <Textarea
                                id={`ticket-${ticketId}-rating-comment`}
                                value={comment}
                                aria-label="Feedback comment"
                                disabled={locked}
                                onChange={(e) => setComment(e.target.value)}
                                placeholder="Anything to add? (optional)"
                                rows={4}
                                maxLength={1000}
                            />
                        </div>
                    ) : null}
                </>
            )}
            <DialogFooter>
                <Button
                    ref={closeButton}
                    variant="outline"
                    onClick={requestClose}
                    disabled={state === 'sending'}
                >
                    {state === 'done' ? 'Done' : 'Close editor'}
                </Button>
                {state === 'editing' && dirty && (
                    <Button
                        variant="outline"
                        onClick={() =>
                            setLeave(() => () => {
                                setPicked(baseline.score);
                                setComment(baseline.comment);
                                setMessage(null);
                            })
                        }
                    >
                        Cancel changes
                    </Button>
                )}
                {state === 'sending' && (
                    <Button variant="outline" onClick={stop}>
                        Stop waiting
                    </Button>
                )}
                {state !== 'access' &&
                    state !== 'session' &&
                    state !== 'done' && (
                        <Button
                            onClick={() => void submit()}
                            disabled={locked || !validScore(picked)}
                        >
                            {state === 'sending' && (
                                <Loader2
                                    className="size-4 animate-spin"
                                    aria-hidden="true"
                                />
                            )}
                            {state === 'sending'
                                ? 'Saving rating…'
                                : baseline.score
                                  ? 'Update rating'
                                  : 'Submit rating'}
                        </Button>
                    )}
            </DialogFooter>
            <ConfirmDialog
                open={leave !== null}
                onClose={() => setLeave(null)}
                onCloseAutoFocus={(event) => {
                    if (!form.current?.isConnected) return;
                    event.preventDefault();
                    const target = returnAfterDiscard.current;
                    if (
                        target?.isConnected &&
                        form.current.contains(target) &&
                        !target.matches('[disabled], [aria-disabled="true"]')
                    )
                        target.focus({ preventScroll: true });
                    else closeButton.current?.focus({ preventScroll: true });
                }}
                title="Discard this feedback?"
                description={
                    needsReview
                        ? 'An earlier save may have finished. Leaving discards this browser copy and does not undo a saved rating.'
                        : 'Your unsaved rating and comment will be discarded.'
                }
                confirmText="Discard feedback"
                onConfirm={() => {
                    const action = leave;
                    setLeave(null);
                    action?.();
                }}
            />
        </div>
    );
}
