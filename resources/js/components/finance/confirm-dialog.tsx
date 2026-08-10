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
import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

/**
 * Finance confirmation dialog — the single design-system replacement for every
 * native `confirm()` gate in the finance module. Wraps the shadcn AlertDialog
 * primitive so destructive deletes and state-transition actions (approve,
 * process, mark filed, post, send, convert…) share one consistent surface.
 *
 * The confirm button is disabled while `processing` so a router call can't be
 * fired twice. `variant="destructive"` paints the confirm button red for
 * delete/cancel/disconnect/remove; `default` is used for state transitions.
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    cancelLabel = 'Cancel',
    variant = 'default',
    onConfirm,
    processing = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    cancelLabel?: string;
    variant?: 'default' | 'destructive';
    onConfirm: () => void;
    processing?: boolean;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div>{description}</div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={processing}>
                        {cancelLabel}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={(event) => {
                            // Keep the dialog under our control: run the action, but
                            // don't let Radix auto-close before the router call fires.
                            event.preventDefault();
                            onConfirm();
                        }}
                        disabled={processing}
                        className={cn(
                            variant === 'destructive' &&
                                'bg-destructive text-white hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40',
                        )}
                    >
                        {confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

export default ConfirmDialog;
