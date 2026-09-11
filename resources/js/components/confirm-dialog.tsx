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
import type { ComponentProps } from 'react';

export function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    title,
    description,
    confirmText = 'Confirm',
    variant = 'destructive',
    onCloseAutoFocus,
}: {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    title: string;
    description: string;
    confirmText?: string;
    variant?: 'destructive' | 'default';
    onCloseAutoFocus?: ComponentProps<
        typeof AlertDialogContent
    >['onCloseAutoFocus'];
}) {
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
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel onClick={onClose}>
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={() => {
                            onConfirm();
                            onClose();
                        }}
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
