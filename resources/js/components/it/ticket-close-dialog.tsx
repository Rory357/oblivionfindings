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
import type { ItDraftFields } from '@/hooks/it-ticket-draft-contract';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Loader2, XCircle } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { ItBulkResult } from './it-bulk-result';
import type { TicketVersions } from './ticket-version-conflict';
import { useTicketFormCommand } from './use-ticket-form-command';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    scope: 'single' | 'bulk';
    ticketIds: number[];
    expectedVersions: TicketVersions;
    ticketReference?: string | null;
    onCompleted?: () => void;
    onBulkResult?: (result: ItBulkResult) => void;
}

/** The mounted editor owns one selection; later list updates cannot replace it. */
export function TicketCloseDialog(props: Props) {
    const actorId = usePage<SharedData>().props.auth.user?.id;
    if (!props.open) return null;
    return <TicketCloseForm {...props} actorId={actorId} />;
}

function TicketCloseForm({
    onOpenChange,
    scope,
    ticketIds,
    expectedVersions,
    ticketReference,
    onCompleted,
    onBulkResult,
    actorId,
}: Props & { actorId: number | undefined }) {
    const [origin] = useState(() => ({
        ids: [...ticketIds],
        scope,
        reference: ticketReference,
    }));
    const [reason, setReason] = useState('');
    const form = useRef<HTMLDivElement>(null);
    const closeButton = useRef<HTMLButtonElement>(null);
    const returnFocus = useRef<HTMLElement | null>(null);
    const fields: ItDraftFields = useMemo(
        () => ({ reason: reason.trim() }),
        [reason],
    );
    const bulk = origin.scope === 'bulk';
    const command = useTicketFormCommand({
        actorId,
        ticketIds,
        expectedVersions,
        fields,
        bulk,
        operation: 'close',
        dirty: reason !== '',
        onResume: () => false,
        onClear: () => setReason(''),
        onClose: () => onOpenChange(false),
        onBulkResult,
        onCloseAutoFocus: (event) => {
            if (!form.current?.isConnected) return;
            event.preventDefault();
            const target = returnFocus.current;
            if (
                target?.isConnected &&
                form.current.contains(target) &&
                !target.matches('[disabled], [aria-disabled="true"]')
            ) {
                target.focus({ preventScroll: true });
            } else {
                closeButton.current?.focus({ preventScroll: true });
            }
        },
        onCommitted: () => {
            setReason('');
            onCompleted?.();
            router.reload({ preserveScroll: true });
        },
    });
    const done = command.state === 'done';
    const title = command.concealed
        ? 'Closing details unavailable'
        : bulk
          ? `Close ${origin.ids.length} selected ticket${origin.ids.length === 1 ? '' : 's'}`
          : `Close ${origin.reference ?? 'this ticket'}`;

    return (
        <>
            <Dialog open onOpenChange={(next) => !next && command.close()}>
                <DialogContent
                    ref={form}
                    onFocusCapture={(event) => {
                        if (
                            event.target instanceof HTMLElement &&
                            event.currentTarget.contains(event.target)
                        ) {
                            returnFocus.current = event.target;
                        }
                    }}
                    className="max-h-[90vh] min-w-0 overflow-y-auto"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <XCircle
                                className="size-4 text-destructive"
                                aria-hidden="true"
                            />
                            {title}
                        </DialogTitle>
                        <DialogDescription>
                            {bulk
                                ? 'Close work only when it is complete, withdrawn or no longer actionable. The reason appears on every ticket timeline. Required tasks and approvals must still be complete.'
                                : 'Record why this ticket is ready to leave the working queue. Required tasks and approvals must be complete. Closed work can return through the governed reopen action.'}
                        </DialogDescription>
                    </DialogHeader>
                    {command.recovery}
                    {done && (
                        <p role="status">
                            {bulk
                                ? 'The selected tickets are closed and the reason is recorded.'
                                : 'The ticket is closed and your reason is recorded.'}
                        </p>
                    )}
                    {!command.concealed && !done && (
                        <div className="space-y-2">
                            <label
                                htmlFor="ticket-close-reason"
                                className="text-sm font-medium"
                            >
                                Reason for closing
                            </label>
                            <Textarea
                                id="ticket-close-reason"
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                disabled={command.locked}
                                required
                                rows={4}
                                maxLength={1000}
                                aria-invalid={
                                    command.errors.reason ? true : undefined
                                }
                                placeholder={
                                    bulk
                                        ? 'Explain why every selected ticket is ready to close'
                                        : 'For example, requester confirmed the service is restored'
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Keep this editor open to retain the reason until
                                closure is confirmed. Leaving requires
                                confirmation before discarding it.
                            </p>
                        </div>
                    )}
                    <DialogFooter className="flex-wrap sm:flex-wrap">
                        <Button
                            ref={closeButton}
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={command.busy}
                            onClick={command.close}
                        >
                            {done
                                ? 'Done'
                                : command.state === 'editing'
                                  ? 'Keep open'
                                  : 'Close editor'}
                        </Button>
                        {command.busy && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={command.cancelWait}
                            >
                                Stop waiting
                            </Button>
                        )}
                        {!command.concealed && !done && (
                            <Button
                                type="button"
                                variant="destructive"
                                className="min-h-11"
                                disabled={
                                    !command.ready ||
                                    reason.trim() === '' ||
                                    reason.trim().length > 1000 ||
                                    (!bulk && origin.ids.length !== 1)
                                }
                                onClick={() => void command.submit()}
                            >
                                {command.busy ? (
                                    <Loader2
                                        className="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <XCircle
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                )}
                                {command.busy
                                    ? 'Closing…'
                                    : bulk
                                      ? 'Close selected tickets'
                                      : 'Close ticket'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {command.confirmation}
        </>
    );
}
