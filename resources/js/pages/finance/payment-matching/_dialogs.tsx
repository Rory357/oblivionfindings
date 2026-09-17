import { useForm } from '@inertiajs/react';
import { ThumbsDown } from 'lucide-react';
import { useEffect, type FormEvent } from 'react';

import InputError from '@/components/input-error';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

/**
 * Reject a suggested payment match. The endpoint has always accepted a reason;
 * the old inline button never sent one, so the audit trail recorded nothing
 * about why a suggestion was turned down.
 */
export function RejectMatchDialog({
    matchId,
    label,
    open,
    onClose,
}: {
    matchId: number | null;
    label: string;
    open: boolean;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({ reason: '' });

    useEffect(() => {
        if (!open) return;
        clearErrors();
        reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, matchId]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!matchId) return;
        post(`/finance/payment-matching/${matchId}/reject`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) onClose();
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 520px)',
                    width: 'min(92vw, 520px)',
                }}
            >
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <ThumbsDown className="h-4 w-4 text-primary" />
                            Reject this match?
                        </DialogTitle>
                        <DialogDescription>
                            {label} stays unmatched and the bank transaction
                            returns to the unreconciled pool. Matching can
                            suggest it again later.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-3 space-y-1.5">
                        <Label htmlFor="reject-reason">
                            Reason (optional, kept on the audit trail)
                        </Label>
                        <Textarea
                            id="reject-reason"
                            rows={3}
                            maxLength={500}
                            value={data.reason}
                            onChange={(event) =>
                                setData('reason', event.target.value)
                            }
                            placeholder="e.g. Same amount, but this payment belongs to another vendor."
                        />
                        <InputError message={errors.reason} />
                    </div>

                    <DialogFooter className="mt-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                        >
                            {processing && <Spinner className="mr-2" />}
                            Reject match
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
