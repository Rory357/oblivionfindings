import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { MessageCircle, PhoneOff, X } from 'lucide-react';
import { lazy, Suspense, useRef, type ReactNode } from 'react';
import {
    callOutcomeLabels,
    waitingCallPolicy,
    type CallOutcomeData,
} from './call-outcome';
import { ChatCallbacks } from './chat-callbacks';
import type { ChatThreadControls } from './chat-thread';

const CallThread = lazy(() =>
    import('./chat-thread').then((module) => ({ default: module.ChatThread })),
);
export function CallResultCard({
    data,
    loading,
    denied,
    error,
    actorId,
    action,
    onAction,
    onRetry,
    onDismiss,
    onRecord,
    callAgain,
}: {
    data: CallOutcomeData | null;
    loading: boolean;
    denied: boolean;
    error: string;
    actorId: number;
    action: 'message' | 'callback' | null;
    onAction: (value: 'message' | 'callback' | null) => void;
    onRetry: () => void;
    onDismiss: () => void;
    onRecord: () => void;
    callAgain: ReactNode;
}) {
    const messageTrigger = useRef<HTMLButtonElement>(null);
    const thread = useRef<ChatThreadControls>(null);
    const outcome = data?.call.outcome;
    const elsewhere =
        outcome === 'answered_elsewhere' ||
        outcome === 'answered_on_another_device';
    return (
        <>
            <section
                aria-label="Call result"
                className="space-y-3 rounded-2xl border bg-card p-4 text-card-foreground"
            >
                <div className="flex items-start gap-3">
                    <PhoneOff className="mt-1 size-5 shrink-0 text-muted-foreground" />
                    <div className="min-w-0 flex-1">
                        <h2 className="text-sm font-semibold">
                            {loading
                                ? 'Checking call result…'
                                : denied
                                  ? 'Call access changed'
                                  : (callOutcomeLabels[outcome ?? ''] ??
                                    'Call result unavailable')}
                        </h2>
                        {data && (
                            <p className="mt-1 text-sm break-words text-muted-foreground">
                                {data.call.title}
                            </p>
                        )}
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-[44px] shrink-0"
                        aria-label="Dismiss call result"
                        onClick={onDismiss}
                    >
                        <X className="size-4" />
                    </Button>
                </div>
                {elsewhere && (
                    <p role="status" className="text-sm">
                        This invitation has closed here. No audio is connected
                        on this device.
                    </p>
                )}
                {data?.followup.callbacks && (
                    <p className="text-xs text-muted-foreground">
                        Calling or reading does not resolve the family request.
                    </p>
                )}
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                <div className="flex flex-wrap gap-2">
                    {!loading && !denied && !data && (
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            onClick={onRetry}
                        >
                            Check call result
                        </Button>
                    )}
                    {data && (
                        <>
                            <Button
                                ref={messageTrigger}
                                variant="outline"
                                className="min-h-[44px]"
                                onClick={() => onAction('message')}
                            >
                                <MessageCircle className="size-4" />
                                {data.followup.conversation.can_send
                                    ? 'Send a message'
                                    : 'View conversation'}
                            </Button>
                            {!elsewhere &&
                                outcome !== 'ongoing' &&
                                data.followup.conversation.can_call &&
                                callAgain}
                            {data.followup.callbacks && (
                                <ChatCallbacks
                                    actorId={actorId}
                                    conversationId={data.call.conversation_id}
                                    family={data.followup.family}
                                    controlledOpen={action === 'callback'}
                                    onOpenChange={(open) =>
                                        onAction(open ? 'callback' : null)
                                    }
                                />
                            )}
                            {!data.followup.family &&
                                data.call.can_record_note &&
                                !elsewhere &&
                                outcome !== 'ongoing' && (
                                    <Button
                                        variant="outline"
                                        className="min-h-[44px] whitespace-normal"
                                        onClick={onRecord}
                                    >
                                        Record communication
                                    </Button>
                                )}
                        </>
                    )}
                </div>
            </section>
            <Dialog
                open={action === 'message' && !!data}
                onOpenChange={(open) => {
                    if (!open) onAction(null);
                }}
            >
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 720px)',
                        width: 'min(92vw, 720px)',
                    }}
                    showCloseButton={false}
                    className="flex max-h-[calc(100dvh-2rem)] flex-col overflow-hidden rounded-2xl bg-card p-4 text-card-foreground"
                    onEscapeKeyDown={(event) => {
                        if (thread.current?.closeDetails())
                            event.preventDefault();
                    }}
                    onCloseAutoFocus={(event) => {
                        if (messageTrigger.current?.isConnected) {
                            event.preventDefault();
                            messageTrigger.current.focus();
                        }
                    }}
                >
                    <Button
                        variant="ghost"
                        size="icon"
                        className="absolute top-2 right-2 size-[44px]"
                        aria-label="Close call follow-up"
                        onClick={() => onAction(null)}
                    >
                        <X className="size-4" />
                    </Button>
                    <DialogHeader className="pr-10 text-left">
                        <DialogTitle>
                            {data?.call.title ?? 'Conversation'}
                        </DialogTitle>
                        <DialogDescription>
                            Your work stays open. Review the conversation
                            audience before sending.
                        </DialogDescription>
                    </DialogHeader>
                    {action === 'message' && data && (
                        <div className="flex h-[min(34rem,65dvh)] min-h-48 min-w-0 overflow-hidden rounded-xl border">
                            <Suspense
                                fallback={
                                    <p className="p-4 text-sm">
                                        Opening conversation…
                                    </p>
                                }
                            >
                                <CallThread
                                    actorId={actorId}
                                    conversationId={data.call.conversation_id}
                                    family={data.followup.family}
                                    policy={waitingCallPolicy}
                                    controlsRef={thread}
                                    inCall
                                />
                            </Suspense>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
