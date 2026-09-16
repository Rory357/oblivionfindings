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
import type { ComponentProps, ReactNode } from 'react';

/**
 * The app's one confirmation dialog (DESIGN.md "Confirmations"). Every
 * destructive delete and every state transition that the user can't simply
 * undo — approve, post, settle, reverse, disconnect — goes through it, with a
 * description that states the effect.
 *
 * `processing` is for callers that keep the dialog open while their request is
 * in flight: both buttons disable, and the dialog does NOT close itself on
 * confirm — the caller closes it when the request settles. Callers that leave
 * `processing` undefined keep the original fire-and-close behaviour.
 */
export function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    title,
    description,
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    variant = 'destructive',
    processing,
    onCloseAutoFocus,
}: {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    title: string;
    description: ReactNode;
    confirmText?: string;
    cancelText?: string;
    variant?: 'destructive' | 'default';
    /** Request in flight: disable both buttons and leave closing to the caller. */
    processing?: boolean;
    onCloseAutoFocus?: ComponentProps<
        typeof AlertDialogContent
    >['onCloseAutoFocus'];
}) {
    const callerOwnsClose = processing !== undefined;

    return (
        <AlertDialog
            open={open}
            onOpenChange={(isOpen) => {
                if (!isOpen) onClose();
            }}
        >
            <AlertDialogContent onCloseAutoFocus={onCloseAutoFocus}>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div>{description}</div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel
                        onClick={onClose}
                        disabled={processing}
                    >
                        {cancelText}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={(event) => {
                            if (callerOwnsClose) {
                                // Don't let Radix close before the request fires.
                                event.preventDefault();
                                onConfirm();
                                return;
                            }
                            onConfirm();
                            onClose();
                        }}
                        disabled={processing}
                        className={
                            variant === 'destructive'
                                ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90'
                                : ''
                        }
                    >
                        {confirmText}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

export default ConfirmDialog;
