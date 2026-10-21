import { Button } from '@/components/ui/button';
import { Phone, RefreshCw } from 'lucide-react';
import { useLayoutEffect, useRef } from 'react';

export function CallJoinRecovery({
    title,
    restored = false,
    endingEveryone = false,
    working,
    permissionPending,
    stopping,
    checked,
    closed,
    error,
    onCheck,
    onJoin,
    onStop,
    onDismiss,
}: {
    title: string;
    restored?: boolean;
    endingEveryone?: boolean;
    working: boolean;
    permissionPending: boolean;
    stopping: boolean;
    checked: boolean;
    closed: boolean;
    error: string;
    onCheck: () => void;
    onJoin: () => void;
    onStop: () => void;
    onDismiss?: () => void;
}) {
    const root = useRef<HTMLElement>(null);
    const restore = useRef(true);
    const act = (action: () => void) => {
        restore.current = true;
        action();
    };
    useLayoutEffect(() => {
        if (working || !restore.current) return;
        restore.current = false;
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
    }, [working, checked, closed, stopping]);
    return (
        <section
            ref={root}
            aria-label="Call connection recovery"
            className="space-y-3 rounded-2xl border bg-card p-4 text-card-foreground"
        >
            <div className="flex items-start gap-3">
                <Phone className="mt-1 size-5 shrink-0 text-primary" />
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold">
                        {closed
                            ? 'Call access changed'
                            : stopping
                              ? endingEveryone
                                  ? 'Checking call end'
                                  : 'Checking cancellation'
                              : 'Call connection interrupted'}
                    </h2>
                    {!closed && (
                        <p className="mt-1 text-sm break-words text-muted-foreground">
                            {title}
                        </p>
                    )}
                </div>
            </div>
            <p role="status" className="text-sm">
                {closed
                    ? 'Call access could not be confirmed. Your microphone is off. Check again after signing in or restoring access.'
                    : permissionPending
                      ? 'Allow microphone access to reconnect, or stop this attempt.'
                      : working
                        ? 'Checking this same invitation…'
                        : stopping
                          ? 'Stopping is not confirmed yet. This device will not reconnect. Check again or retry stopping.'
                          : checked
                            ? 'This invitation is still available. Choose Try connecting to join audio. Your microphone is off.'
                            : restored
                              ? 'This page reopened after a call. Check its status before reconnecting. Your microphone is off.'
                              : 'Your answer may have reached the server. Check the call before trying again. Your microphone is off.'}
            </p>
            {error && (
                <p
                    role="alert"
                    className="text-sm break-words text-destructive"
                >
                    {error}
                </p>
            )}
            <div className="flex flex-wrap gap-2 [&>button]:min-h-[44px] [&>button]:whitespace-normal">
                {closed ? (
                    <Button
                        variant="outline"
                        data-recovery-primary
                        disabled={working}
                        onClick={() => act(onDismiss ?? onCheck)}
                    >
                        {onDismiss ? 'Dismiss' : 'Check call'}
                    </Button>
                ) : (
                    <>
                        <Button
                            variant="outline"
                            disabled={working}
                            data-recovery-primary={
                                !checked && !stopping ? '' : undefined
                            }
                            onClick={() => act(onCheck)}
                        >
                            <RefreshCw className="size-4" />
                            Check call
                        </Button>
                        {!stopping && checked && (
                            <Button
                                variant="outline"
                                disabled={working}
                                data-recovery-primary
                                onClick={() => act(onJoin)}
                            >
                                Try connecting
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            disabled={working && !permissionPending}
                            data-recovery-primary={stopping ? '' : undefined}
                            onClick={() => act(onStop)}
                        >
                            {stopping
                                ? endingEveryone
                                    ? 'Retry ending call'
                                    : 'Retry stopping'
                                : 'Stop my attempt'}
                        </Button>
                    </>
                )}
            </div>
        </section>
    );
}
