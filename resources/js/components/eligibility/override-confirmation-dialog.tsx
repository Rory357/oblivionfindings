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
    confirmLabel?: string;
    processingLabel?: string;
}

export function OverrideConfirmationDialog(
    props: OverrideConfirmationDialogProps,
) {
    // Each review starts with its own reason. A parent-confirmed close must not
    // carry an earlier acknowledgement into a different assignment.
    return props.open ? <OverrideConfirmationBody {...props} /> : null;
}

function OverrideConfirmationBody({
    open,
    onOpenChange,
    warnings,
    staffName,
    onConfirm,
    processing = false,
    confirmLabel = 'Override & Assign',
    processingLabel = 'Assigning...',
}: OverrideConfirmationDialogProps) {
    const [reason, setReason] = useState('');
    const [touched, setTouched] = useState(false);

    const canSubmit = reason.trim().length > 0 && !processing;

    function handleConfirm() {
        setTouched(true);
        if (!canSubmit) return;
        onConfirm(reason.trim());
    }

    function handleOpenChange(next: boolean) {
        if (processing) return;
        if (!next) {
            setReason('');
            setTouched(false);
        }
        onOpenChange(next);
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent
                className="frontline-dialog flex max-h-[88dvh] min-w-0 flex-col overflow-hidden p-0 sm:max-w-md"
                showCloseButton={!processing}
                onEscapeKeyDown={(event) =>
                    processing && event.preventDefault()
                }
                onPointerDownOutside={(event) =>
                    processing && event.preventDefault()
                }
            >
                <DialogHeader className="shrink-0 border-b p-5 pr-12 text-left">
                    <DialogTitle className="flex items-center gap-2">
                        <ShieldCheck className="size-5 shrink-0 text-status-warning" />
                        Override Eligibility Warnings
                    </DialogTitle>
                    <DialogDescription>
                        {staffName
                            ? `The following warnings will be overridden for ${staffName}. This action is audited.`
                            : 'The following warnings will be overridden for this assignment. This action is audited.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 space-y-3 overflow-y-auto p-5 [overflow-wrap:anywhere]">
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
                            disabled={processing}
                            aria-required="true"
                            aria-invalid={touched && reason.trim().length === 0}
                            aria-describedby={
                                touched && reason.trim().length === 0
                                    ? 'override-reason-error'
                                    : undefined
                            }
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
                            <p
                                id="override-reason-error"
                                role="alert"
                                className="text-xs text-destructive"
                            >
                                A reason is required when overriding eligibility
                                warnings.
                            </p>
                        )}
                    </div>
                </div>

                <DialogFooter className="shrink-0 flex-wrap border-t bg-muted/30 p-4 [&_button]:h-auto [&_button]:max-w-full [&_button]:whitespace-normal">
                    <Button
                        variant="outline"
                        onClick={() => handleOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button onClick={handleConfirm} disabled={!canSubmit}>
                        {processing ? processingLabel : confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
