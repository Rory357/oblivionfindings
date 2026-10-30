import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { MessageSquare } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ChatError } from './api';
import { ChatFamilyInbox, refreshFamilyInbox } from './chat-family-inbox';
import { watchChatUpdates } from './chat-update-watch';

/** Advisory only: never adds a clock-out blocker, write or attendance dependency. */
export function ChatShiftHandover({
    actorId,
    enabled,
    active,
    context = 'clock-out',
}: {
    actorId?: number;
    enabled: boolean;
    active: boolean;
    context?: 'clock-out' | 'off-duty';
}) {
    if (!enabled || !active || !actorId) return null;
    return (
        <ShiftHandoverSession
            key={`${actorId}:${context}`}
            actorId={actorId}
            context={context}
        />
    );
}

function ShiftHandoverSession({
    actorId,
    context,
}: {
    actorId: number;
    context: 'clock-out' | 'off-duty';
}) {
    const offDuty = context === 'off-duty';
    const returnLabel = offDuty ? 'Return to Chat' : 'Return to clock-out';
    const [count, setCount] = useState<number | null>(null);
    const [shown, setShown] = useState(false);
    const [error, setError] = useState(false);
    const [open, setOpen] = useState(false);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        const stop = watchChatUpdates(
            async () => {
                try {
                    const page = await refreshFamilyInbox(
                        '/chat/api/family-inbox?filter=mine',
                        null,
                        controller.signal,
                        actorId,
                    );
                    if (controller.signal.aborted) return false;
                    // These are organisation defaults; individual client sites can override them.
                    // Keep authorised owned work visible. The shared thread enforces its own policy.
                    const mine = page.counts.mine;
                    if (!Number.isSafeInteger(mine) || mine < 0)
                        throw new Error('Invalid family work count');
                    setCount(mine);
                    if (mine > 0) setShown(true);
                    setError(false);
                    return true;
                } catch (failure) {
                    if (controller.signal.aborted) return false;
                    if (
                        failure instanceof ChatError &&
                        [401, 403, 404, 419].includes(failure.status)
                    ) {
                        setCount(null);
                        setShown(false);
                        setError(false);
                        setOpen(false);
                    } else setError(true);
                    return false;
                }
            },
            { interval: () => 10000 },
        );
        return () => {
            stop();
            controller.abort();
        };
    }, [actorId, retry]);
    function close() {
        setOpen(false);
        setRetry((value) => value + 1);
    }
    if (!shown && !error && !open) return null;
    return (
        <section
            aria-label="Family handover"
            className="mb-4 space-y-2 rounded-lg border bg-muted/30 p-3 text-sm"
        >
            <h3 className="flex items-center gap-2 font-semibold">
                <MessageSquare className="size-4" />
                Family handover
            </h3>
            <p role="status">
                {error
                    ? 'Family work could not be checked. Open the inbox to review current ownership.'
                    : count === 0
                      ? 'No unresolved family conversations are assigned to you.'
                      : `${count} family conversation${count === 1 ? ' is' : 's are'} still assigned to you.`}
            </p>
            <p className="text-muted-foreground">
                {offDuty
                    ? 'You are already off duty. Review if needed; you can leave without waiting.'
                    : 'Hand over or release work to the team if needed. You can finish and clock out without waiting for a reply. No off-duty response is expected.'}
            </p>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-[44px]"
                    onClick={() => setOpen(true)}
                >
                    Review family handover
                </Button>
                {error && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="min-h-[44px]"
                        onClick={() => setRetry((value) => value + 1)}
                    >
                        Check again
                    </Button>
                )}
            </div>
            <Sheet
                open={open}
                onOpenChange={(next) => {
                    if (next) setOpen(true);
                    else close();
                }}
            >
                <SheetContent
                    style={{
                        maxWidth: 'min(92vw, 720px)',
                        width: 'min(92vw, 720px)',
                    }}
                    className="flex flex-col gap-3 p-3"
                    closeLabel={returnLabel}
                    closeButtonClassName="size-[44px]"
                >
                    <SheetHeader className="pr-12">
                        <SheetTitle>Hand over family work</SheetTitle>
                        <SheetDescription>
                            {offDuty
                                ? 'Your off-duty preference is already saved. Work stays assigned until it is handed over or released. No off-duty response is expected.'
                                : 'Your clock-out details stay open. Choose a conversation, then hand it over or release it to the team.'}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="flex min-h-0 flex-1 overflow-hidden">
                        <ChatFamilyInbox
                            actorId={actorId}
                            initialFilter="mine"
                            exitLabel={
                                offDuty ? 'Back to Chat' : 'Back to clock-out'
                            }
                            onExit={close}
                        />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-[44px] shrink-0"
                        onClick={close}
                    >
                        {returnLabel}
                    </Button>
                </SheetContent>
            </Sheet>
        </section>
    );
}
