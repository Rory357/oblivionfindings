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
    buttonClassName,
    onCloseAutoFocus,
    frontline = false,
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
    /** Optional sizing for both confirmation controls, such as frontline-hit. */
    buttonClassName?: string;
    /** Medication confirmations use pixel-based bounds and 44px touch targets. */
    frontline?: boolean;
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
            <AlertDialogContent
                onCloseAutoFocus={onCloseAutoFocus}
                className={
                    frontline
                        ? 'frontline-dialog max-h-[88vh] overflow-y-auto'
                        : undefined
                }
                style={
                    frontline
                        ? {
                              width: 'min(92vw, 480px)',
                              maxWidth: 'min(92vw, 480px)',
                          }
                        : undefined
                }
            >
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
                        className={cn(
                            frontline && 'whitespace-normal',
                            buttonClassName,
                        )}
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
                        variant={variant}
                        className={cn(
                            frontline && 'whitespace-normal',
                            buttonClassName,
                        )}
                    >
                        {confirmText}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

export default ConfirmDialog;
