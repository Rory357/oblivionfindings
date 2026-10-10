import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import { useEffect, useState } from 'react';
import type { QueueItem } from './types';
export type ConflictConfirmKind = 'ack' | 'dismiss' | 'clear';
export interface ConflictConfirmResult {
    reason?: string;
}
export interface ConflictConfirmDialogProps {
    open: boolean;
    kind: ConflictConfirmKind;
    item: QueueItem | null;
    pending?: boolean;
    error?: string | null;
    onOpenChange: (open: boolean) => void;
    onConfirm: (result: ConflictConfirmResult) => void;
}
const labels = {
    ack: 'Acknowledge coverage review',
    dismiss: 'Dismiss coverage review',
    clear: 'Clear acknowledgement',
};
export function ConflictConfirmDialog({
    open,
    kind,
    item,
    pending = false,
    error,
    onOpenChange,
    onConfirm,
}: ConflictConfirmDialogProps) {
    const [reason, setReason] = useState('');
    const [discard, setDiscard] = useState(false);
    useEffect(() => {
        if (open) {
            setReason('');
            setDiscard(false);
        }
    }, [open, kind, item?.id]);
    if (!item) return null;
    function changeOpen(next: boolean) {
        if (pending) return;
        if (!next && reason.trim()) setDiscard(true);
        else onOpenChange(next);
    }
    return (
        <>
            <Dialog open={open} onOpenChange={changeOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{labels[kind]}</DialogTitle>
                        <DialogDescription>
                            {item.who} · {item.summary}
                        </DialogDescription>
                    </DialogHeader>
                    <p className="text-sm text-muted-foreground">
                        {kind === 'clear'
                            ? 'Remove the recorded acknowledgement or dismissal for this exact window.'
                            : 'Record a review decision for this exact window.'}{' '}
                        Staffing and publication checks stay unchanged. The
                        finding remains visible while the source still reports
                        it.
                    </p>
                    {kind !== 'clear' ? (
                        <div className="space-y-2">
                            <Label htmlFor="coverage-review-reason">
                                Reason{' '}
                                {kind === 'dismiss'
                                    ? '(required)'
                                    : '(optional)'}
                            </Label>
                            <Textarea
                                id="coverage-review-reason"
                                value={reason}
                                disabled={pending}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                rows={3}
                                maxLength={1000}
                            />
                        </div>
                    ) : null}
                    {error ? (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {error}
                        </p>
                    ) : null}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            className="frontline-tap"
                            disabled={pending}
                            onClick={() => changeOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            className="frontline-tap"
                            disabled={
                                pending ||
                                (kind === 'dismiss' && !reason.trim())
                            }
                            onClick={() =>
                                onConfirm({
                                    reason: reason.trim() || undefined,
                                })
                            }
                        >
                            {pending ? 'Saving…' : labels[kind]}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <AlertDialog open={discard} onOpenChange={setDiscard}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Discard this reason?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Your review has not been saved.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep editing</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                setReason('');
                                onOpenChange(false);
                            }}
                        >
                            Discard reason
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
