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
import { AlertTriangle, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

export interface OverrideableWarning {
    rule: string;
    message: string;
    overrideable: boolean;
}

interface OverrideConfirmationDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    warnings: OverrideableWarning[];
    staffName?: string;
    onConfirm: (reason: string) => void;
    processing?: boolean;
}

export function OverrideConfirmationDialog({
    open,
    onOpenChange,
    warnings,
    staffName,
    onConfirm,
    processing = false,
}: OverrideConfirmationDialogProps) {
    const [reason, setReason] = useState('');
    const [touched, setTouched] = useState(false);

    const canSubmit = reason.trim().length > 0 && !processing;

    function handleConfirm() {
        setTouched(true);
        if (reason.trim().length === 0) return;
        onConfirm(reason.trim());
    }

    function handleOpenChange(next: boolean) {
        if (!next) {
            setReason('');
            setTouched(false);
        }
        onOpenChange(next);
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <ShieldCheck className="size-5 text-status-warning" />
                        Override Eligibility Warnings
                    </DialogTitle>
                    <DialogDescription>
                        {staffName
                            ? `The following warnings will be overridden for ${staffName}. This action is audited.`
                            : 'The following warnings will be overridden for this assignment. This action is audited.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3">
                    {/* Warning list */}
                    <div className="rounded-md border border-status-warning/30 bg-status-warning-bg p-3 dark:border-status-warning/30">
                        <ul className="space-y-1.5">
                            {warnings.map((w, i) => (
                                <li
                                    key={i}
                                    className="flex items-start gap-2 text-sm text-status-warning dark:text-status-warning"
                                >
                                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-status-warning dark:text-status-warning" />
                                    <span>{w.message}</span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* Reason input */}
                    <div className="space-y-1.5">
                        <Label
                            htmlFor="override-reason"
                            className="text-sm font-medium"
                        >
                            Reason for override{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Textarea
                            id="override-reason"
                            placeholder="Explain why this override is appropriate..."
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            onBlur={() => setTouched(true)}
                            rows={3}
                            className={
                                touched && reason.trim().length === 0
                                    ? 'border-destructive'
                                    : ''
                            }
                        />
                        {touched && reason.trim().length === 0 && (
                            <p className="text-xs text-destructive">
                                A reason is required when overriding eligibility
                                warnings.
                            </p>
                        )}
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => handleOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={handleConfirm}
                        disabled={!canSubmit}
                        className="bg-status-warning text-white hover:bg-status-warning dark:hover:bg-status-warning"
                    >
                        {processing ? 'Assigning...' : 'Override & Assign'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
