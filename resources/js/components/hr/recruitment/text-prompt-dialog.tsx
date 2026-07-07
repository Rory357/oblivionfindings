/* eslint-disable no-restricted-syntax -- Single-input prompt dialog: native
 * footer buttons mirror BulkRejectDialog's composition; semantic tokens. */
import { useEffect, useState } from 'react';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

/**
 * A kit replacement for `window.prompt()` — one labelled text input with
 * Cancel/Submit. The caller owns what happens with the value.
 */
export function TextPromptDialog({
    open,
    onClose,
    onSubmit,
    title,
    description,
    label,
    placeholder,
    submitLabel = 'Save',
    required = true,
}: {
    open: boolean;
    onClose: () => void;
    /** Receives the trimmed value ('' possible when `required` is false). */
    onSubmit: (value: string) => void;
    title: string;
    description?: string;
    label: string;
    placeholder?: string;
    submitLabel?: string;
    required?: boolean;
}) {
    const [value, setValue] = useState('');

    useEffect(() => {
        if (open) setValue('');
    }, [open]);

    const trimmed = value.trim();
    const canSubmit = required ? trimmed !== '' : true;

    const submit = () => {
        if (!canSubmit) return;
        onSubmit(trimmed);
        onClose();
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description ? (
                        <DialogDescription>{description}</DialogDescription>
                    ) : null}
                </DialogHeader>

                <div>
                    <Label className="mb-1.5 block text-sm font-semibold">
                        {label}
                        {required ? null : (
                            <span className="ml-1 font-normal text-muted-foreground">
                                (optional)
                            </span>
                        )}
                    </Label>
                    <input
                        type="text"
                        value={value}
                        autoFocus
                        onChange={(e) => setValue(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') submit();
                        }}
                        maxLength={255}
                        placeholder={placeholder}
                        className="h-9 w-full rounded-md border border-border bg-card px-2.5 text-[13px] outline-none focus:border-primary"
                    />
                </div>

                <DialogFooter>
                    <button
                        type="button"
                        onClick={onClose}
                        className="h-9 rounded-md border border-border bg-card px-4 text-[13px] font-semibold hover:bg-muted"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={!canSubmit}
                        className="h-9 rounded-md bg-primary px-4 text-[13px] font-bold text-primary-foreground disabled:opacity-50"
                    >
                        {submitLabel}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default TextPromptDialog;
