/**
 * A small reusable "capture a reason, then confirm" pop-up — used for the
 * destructive / with-reason pipeline actions (decline a referral, reject a
 * request, discharge a stay). The caller's onConfirm fires the request and
 * closes the dialog on success.
 */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useEffect, useState } from 'react';

export function ReasonDialog({
    open,
    onClose,
    title,
    description,
    label,
    placeholder,
    confirmLabel,
    destructive = true,
    onConfirm,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    label: string;
    placeholder?: string;
    confirmLabel: string;
    destructive?: boolean;
    /** Fire the request; call `done` when it settles (to re-enable the button on failure). */
    onConfirm: (reason: string, done: () => void) => void;
}) {
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (open) {
            setReason('');
            setSubmitting(false);
        }
    }, [open]);

    const submit = () => {
        if (!reason.trim()) return;
        setSubmitting(true);
        onConfirm(reason.trim(), () => setSubmitting(false));
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogTitle className="text-left text-lg">{title}</DialogTitle>
                {description ? <DialogDescription className="text-left">{description}</DialogDescription> : null}
                <div className="grid gap-1.5">
                    <Label htmlFor="respite-reason">{label}</Label>
                    <Textarea
                        id="respite-reason"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder={placeholder}
                        rows={3}
                        autoFocus
                    />
                </div>
                <div className="flex justify-end gap-2">
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        onClick={submit}
                        disabled={submitting || !reason.trim()}
                    >
                        {confirmLabel}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
