/**
 * A small reusable "capture a reason, then confirm" pop-up — used for
 * destructive / with-reason actions (reject a timesheet, decline a referral,
 * end an attendance session). The reason is required; the caller's onConfirm
 * fires the request and closes the dialog on success.
 */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Loader2 } from 'lucide-react';
import { useEffect, useId, useState } from 'react';

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
    const fieldId = useId();
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
                {description ? (
                    <DialogDescription className="text-left">
                        {description}
                    </DialogDescription>
                ) : null}
                <div className="grid gap-1.5">
                    <Label htmlFor={fieldId}>{label}</Label>
                    <Textarea
                        id={fieldId}
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
                        {submitting ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : null}
                        {confirmLabel}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
