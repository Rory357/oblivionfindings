import { Button } from '@/components/ui/button';
import { Phone, RefreshCw } from 'lucide-react';
import { useLayoutEffect, useRef } from 'react';

export function CallStartRecovery({
    title,
    reviewRequired = false,
    reviewedRecipients,
    state,
    cancelling,
    working,
    permissionPending,
    error,
    onCheck,
    onRetry,
    onJoin,
    onStop,
    onDismiss,
}: {
    title: string;
    reviewRequired?: boolean;
    reviewedRecipients?: string;
    state: 'unconfirmed' | 'started' | 'finished' | 'cancelled';
    cancelling: boolean;
    working: boolean;
    permissionPending: boolean;
    error: string;
    onCheck: () => void;
    onRetry: () => void;
    onJoin: () => void;
    onStop: () => void;
    onDismiss: () => void;
}) {
    const finished = state === 'finished' || state === 'cancelled';
    const root = useRef<HTMLElement>(null);
    const restoreFocus = useRef(false);
    const act = (action: () => void) => {
        restoreFocus.current = true;
        action();
    };
    useLayoutEffect(() => {
        if (working || !restoreFocus.current) return;
        restoreFocus.current = false;
        // Restore keyboard position after replacing a control, unless the person
        // has deliberately returned to work elsewhere while the request ran.
        if (
            document.activeElement !== document.body &&
            !root.current?.contains(document.activeElement)
        )
            return;
        root.current
            ?.querySelector<HTMLButtonElement>(
                '[data-recovery-primary]:not(:disabled)',
            )
            ?.focus({ preventScroll: true });
    }, [working, state, cancelling]);
    return (
        <section
            ref={root}
            aria-label="Call recovery"
            className="space-y-3 rounded-2xl border bg-card p-4 text-card-foreground"
        >
            <div className="flex items-start gap-3">
                <Phone className="mt-1 size-5 shrink-0 text-primary" />
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold">
                        {state === 'cancelled'
                            ? 'Call attempt stopped'
                            : state === 'finished'
                              ? 'This call attempt has finished'
                              : cancelling
                                ? 'Checking cancellation'
                                : state === 'started'
                                  ? 'Your call was created'
                                  : working
                                    ? 'Checking call confirmation…'
                                    : 'Call confirmation interrupted'}
                    </h2>
                    <p className="mt-1 text-sm break-words text-muted-foreground">
                        {title}
                    </p>
                </div>
            </div>
            <p role="status" className="text-sm">
                {permissionPending
                    ? 'Allow microphone access to continue, or stop this attempt.'
                    : working
                      ? 'Checking this same call attempt…'
                      : state === 'cancelled'
                        ? 'This device will not join that attempt. Other connected people may continue their call.'
                        : state === 'finished'
                          ? 'This attempt can no longer connect. You can dismiss this card and start a new call if needed.'
                          : cancelling
                            ? 'Stopping is not confirmed yet. Check again or retry stopping; this device will not join.'
                            : state === 'started'
                              ? 'You have not joined the audio. Join the existing call or stop your attempt.'
                              : 'The request may have reached the server. Check its status, or retry this same call safely. Your microphone is off.'}
            </p>
            {reviewRequired && state === 'unconfirmed' && !cancelling && (
                <p className="text-sm text-muted-foreground">
                    {reviewedRecipients
                        ? `Retry calls: ${reviewedRecipients}.`
                        : 'Check the call to review who would be called before retrying.'}
                </p>
            )}
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            <div className="flex flex-wrap gap-2 [&>button]:min-h-[44px] [&>button]:whitespace-normal">
                {finished ? (
                    <Button
                        data-recovery-primary
                        variant="outline"
                        onClick={onDismiss}
                    >
                        Dismiss
                    </Button>
                ) : (
                    <>
                        <Button
                            variant="outline"
                            disabled={working}
                            data-recovery-primary={
                                state !== 'started' && !cancelling
                                    ? ''
                                    : undefined
                            }
                            onClick={() => act(onCheck)}
                        >
                            <RefreshCw className="size-4" />
                            Check call
                        </Button>
                        {!cancelling && (
                            <Button
                                variant="outline"
                                disabled={
                                    working ||
                                    (state !== 'started' &&
                                        reviewRequired &&
                                        !reviewedRecipients)
                                }
                                data-recovery-primary={
                                    state === 'started' ? '' : undefined
                                }
                                onClick={() =>
                                    act(state === 'started' ? onJoin : onRetry)
                                }
                            >
                                {state === 'started'
                                    ? 'Join existing call'
                                    : 'Retry same call'}
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            disabled={working && !permissionPending}
                            data-recovery-primary={cancelling ? '' : undefined}
                            onClick={() => act(onStop)}
                        >
                            {cancelling ? 'Retry stopping' : 'Stop my attempt'}
                        </Button>
                    </>
                )}
            </div>
        </section>
    );
}
