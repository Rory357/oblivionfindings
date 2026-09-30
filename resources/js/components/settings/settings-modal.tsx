import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import type { ComponentProps, ReactNode } from 'react';

/**
 * The settings-page dialog layout (promoted from Fleet Settings, eMAR P11
 * Q1): a fixed title and description, a scrolling body, and a footer that
 * defaults to Close. Settings dialogs use this, WizardShell or ConfirmDialog.
 */
export function SettingsModal({
    title,
    description,
    children,
    footer,
    onClose,
    onCloseAutoFocus,
}: {
    title: string;
    description: string;
    children: ReactNode;
    footer?: ReactNode;
    onClose: () => void;
    onCloseAutoFocus?: ComponentProps<typeof DialogContent>['onCloseAutoFocus'];
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="flex max-h-[88vh] flex-col overflow-hidden p-0 sm:max-w-[480px]"
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <div className="shrink-0 border-b p-5 pr-12">
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription className="mt-2">
                        {description}
                    </DialogDescription>
                </div>
                <div className="min-h-0 space-y-4 overflow-y-auto p-5">
                    {children}
                </div>
                <DialogFooter className="shrink-0 border-t bg-muted/30 p-4">
                    {footer ?? (
                        <Button variant="outline" onClick={onClose}>
                            Close
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
