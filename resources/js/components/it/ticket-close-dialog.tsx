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
import { router } from '@inertiajs/react';
import { XCircle } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { toast } from 'sonner';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    scope: 'single' | 'bulk';
    ticketIds: number[];
    ticketReference?: string | null;
    onCompleted?: () => void;
}

/** A reasoned, named close journey shared by the ticket and bulk workspaces. */
export function TicketCloseDialog({
    open,
    onOpenChange,
    scope,
    ticketIds,
    ticketReference,
    onCompleted,
}: Props) {
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const bulk = scope === 'bulk';

    const close = () => {
        if (processing) return;
        setReason('');
        setErrors({});
        onOpenChange(false);
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const recordedReason = reason.trim();
        if (recordedReason === '' || ticketIds.length === 0) return;

        const url = bulk
            ? '/it/tickets/bulk'
            : `/it/tickets/${ticketIds[0]}/close`;
        const payload = bulk
            ? { ids: ticketIds, action: 'close', reason: recordedReason }
            : { reason: recordedReason };

        router.post(url, payload, {
            preserveScroll: true,
            preserveState: bulk,
            onStart: () => setProcessing(true),
            onError: (nextErrors) => setErrors(nextErrors),
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { error?: string; success?: string }
                    | undefined;
                if (flash?.error) {
                    toast.error(flash.error);
                    return;
                }

                toast.success(
                    flash?.success ??
                        (bulk ? 'Selected tickets closed.' : 'Ticket closed.'),
                );
                setReason('');
                setErrors({});
                onOpenChange(false);
                onCompleted?.();
            },
            onFinish: () => setProcessing(false),
        });
    };

    const title = bulk
        ? `Close ${ticketIds.length} selected ticket${ticketIds.length === 1 ? '' : 's'}`
        : `Close ${ticketReference ?? 'this ticket'}`;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <XCircle
                                className="h-5 w-5 text-destructive"
                                aria-hidden="true"
                            />
                            {title}
                        </DialogTitle>
                        <DialogDescription>
                            {bulk
                                ? 'This removes active work from the queue. Use it only when the selected tickets are withdrawn, duplicated elsewhere, or no longer actionable. The reason appears on every ticket timeline.'
                                : 'This finalises the resolved working record. Record why it is ready to close; the ticket can only return through the governed reopen journey.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-5 space-y-2">
                        <label
                            htmlFor={`ticket-close-reason-${scope}`}
                            className="text-sm font-medium"
                        >
                            Reason for closing
                        </label>
                        <Textarea
                            id={`ticket-close-reason-${scope}`}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder={
                                bulk
                                    ? 'Explain why every selected ticket can leave the working queue'
                                    : 'For example, requester confirmed the service is restored'
                            }
                            required
                            rows={4}
                            maxLength={1000}
                            aria-invalid={errors.reason ? true : undefined}
                            aria-describedby={
                                errors.reason
                                    ? `ticket-close-reason-${scope}-error`
                                    : undefined
                            }
                        />
                        {errors.reason ? (
                            <p
                                id={`ticket-close-reason-${scope}-error`}
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {errors.reason}
                            </p>
                        ) : null}
                    </div>

                    <DialogFooter className="mt-6 gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={processing}
                            onClick={close}
                        >
                            Keep open
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            className="min-h-11"
                            disabled={
                                processing ||
                                reason.trim() === '' ||
                                ticketIds.length === 0
                            }
                        >
                            <XCircle className="h-4 w-4" aria-hidden="true" />
                            {processing
                                ? 'Closing…'
                                : bulk
                                  ? 'Close selected tickets'
                                  : 'Close ticket'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
