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
import { RotateCcw } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { toast } from 'sonner';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    ticketId: number | null;
    ticketReference?: string | null;
    audience: 'agent' | 'requester';
    onCompleted?: () => void;
}

/** One reasoned recovery journey for both technicians and requesters. */
export function TicketReopenDialog({
    open,
    onOpenChange,
    ticketId,
    ticketReference,
    audience,
    onCompleted,
}: Props) {
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const requester = audience === 'requester';

    const close = () => {
        if (processing) return;
        setReason('');
        setErrors({});
        onOpenChange(false);
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const recordedReason = reason.trim();
        if (processing || ticketId === null || recordedReason.length < 5)
            return;

        router.post(
            `/it/tickets/${ticketId}/reopen`,
            { reason: recordedReason },
            {
                preserveScroll: true,
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

                    toast.success(flash?.success ?? 'Ticket reopened.');
                    setReason('');
                    setErrors({});
                    onOpenChange(false);
                    onCompleted?.();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <RotateCcw
                                className="h-5 w-5 text-primary"
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

                    <div className="mt-5 space-y-2">
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
                            placeholder={
                                requester
                                    ? 'For example, the issue returned after I signed in again'
                                    : 'For example, monitoring shows the fault returned after validation'
                            }
                            required
                            minLength={5}
                            maxLength={2000}
                            rows={4}
                            aria-invalid={errors.reason ? true : undefined}
                            aria-describedby={
                                errors.reason
                                    ? 'ticket-reopen-reason-error'
                                    : undefined
                            }
                        />
                        {errors.reason ? (
                            <p
                                id="ticket-reopen-reason-error"
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
                            Keep settled
                        </Button>
                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={
                                processing ||
                                ticketId === null ||
                                reason.trim().length < 5
                            }
                        >
                            <RotateCcw className="h-4 w-4" aria-hidden="true" />
                            {processing ? 'Reopening…' : 'Reopen ticket'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
