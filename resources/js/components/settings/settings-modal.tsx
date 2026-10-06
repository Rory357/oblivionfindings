import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
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
    width = 480,
    frontline = false,
}: {
    title: string;
    description: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
    onClose: () => void;
    onCloseAutoFocus?: ComponentProps<typeof DialogContent>['onCloseAutoFocus'];
    /** The popup guide's width tokens: 480 (default), 720 or 900 px. */
    width?: 480 | 720 | 900;
    frontline?: boolean;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className={cn(
                    'flex max-h-[88vh] flex-col overflow-hidden p-0',
                    frontline && 'frontline-dialog',
                )}
                style={{
                    width: `min(92vw, ${width}px)`,
                    maxWidth: `min(92vw, ${width}px)`,
                }}
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
                <DialogFooter
                    className={cn(
                        'shrink-0 border-t bg-muted/30 p-4',
                        frontline &&
                            'flex-wrap [&_button]:h-auto [&_button]:max-w-full [&_button]:whitespace-normal',
                    )}
                >
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
