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
import { CircleAlert, XCircle } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';

export interface ProvisioningCancelTarget {
    id: number;
    item: string;
    from_onboarding: boolean;
}

interface Props {
    request: ProvisioningCancelTarget | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function ProvisioningCancelDialog({
    request,
    open,
    onOpenChange,
}: Props) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            setReason('');
            setError(null);
        }
    }, [open, request?.id]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!request || processing) return;

        const cleanReason = reason.trim();
        if (cleanReason === '') {
            setError('Add a reason before cancelling this request.');

            return;
        }

        setError(null);
        setProcessing(true);
        router.post(
            `/it/provisioning/${request.id}/cancel`,
            { reason: cleanReason },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setError(
                        typeof errors.reason === 'string'
                            ? errors.reason
                            : 'Check the cancellation reason and try again.',
                    );
                },
                onSuccess: (page) => {
                    const flash = page.props.flash as
                        | { error?: string; success?: string }
                        | undefined;
                    if (flash?.error) {
                        toast.error(flash.error);

                        return;
                    }
                    if (flash?.success) toast.success(flash.success);
                    onOpenChange(false);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (!processing) onOpenChange(nextOpen);
            }}
        >
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <div className="flex items-start gap-3">
                            <span className="grid h-11 w-11 flex-none place-items-center rounded-xl bg-status-critical-bg text-status-critical">
                                <CircleAlert
                                    className="h-5 w-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <div>
                                <DialogTitle>
                                    Cancel “{request?.item}”?
                                </DialogTitle>
                                <DialogDescription className="mt-1">
                                    The provisioning work will close. Completed
                                    actions are not automatically undone.
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    {request?.from_onboarding ? (
                        <p className="mt-4 rounded-xl border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning">
                            The linked onboarding task remains open and its
                            owner is notified so they can choose the correct
                            next action.
                        </p>
                    ) : null}

                    <div className="mt-5 space-y-1.5">
                        <label
                            htmlFor="provisioning-cancel-reason"
                            className="text-sm font-medium"
                        >
                            Reason for cancelling
                        </label>
                        <Textarea
                            id="provisioning-cancel-reason"
                            aria-required="true"
                            rows={4}
                            maxLength={500}
                            value={reason}
                            aria-invalid={Boolean(error)}
                            aria-describedby={
                                error
                                    ? 'provisioning-cancel-reason-error'
                                    : 'provisioning-cancel-reason-help'
                            }
                            onChange={(event) => {
                                setReason(event.target.value);
                                if (error) setError(null);
                            }}
                        />
                        <p
                            id="provisioning-cancel-reason-help"
                            className="text-xs text-muted-foreground"
                        >
                            This reason is retained on the request timeline and
                            audit history.
                        </p>
                        {error ? (
                            <p
                                id="provisioning-cancel-reason-error"
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {error}
                            </p>
                        ) : null}
                    </div>

                    <DialogFooter className="mt-6">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={processing}
                            onClick={() => onOpenChange(false)}
                        >
                            Keep request
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            className="min-h-11"
                            disabled={processing}
                        >
                            <XCircle className="h-4 w-4" aria-hidden="true" />
                            Cancel request
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
