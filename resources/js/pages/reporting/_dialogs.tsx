import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { ReactNode } from 'react';
export function ReportDialog({
    open,
    onClose,
    title,
    description,
    children,
    footer,
    error,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description: string;
    children: ReactNode;
    footer?: ReactNode;
    error?: string;
}) {
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) onClose();
            }}
        >
            <DialogContent
                className="flex max-h-[90dvh] flex-col gap-0 overflow-hidden p-0"
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                {open && (
                    <>
                        <DialogHeader className="shrink-0 border-b p-5">
                            <DialogTitle>{title}</DialogTitle>
                            <DialogDescription>{description}</DialogDescription>
                        </DialogHeader>
                        <div className="min-h-0 space-y-4 overflow-y-auto p-5">
                            {error && (
                                <p
                                    role="alert"
                                    className="rounded-lg bg-status-critical-bg p-3 text-status-critical-foreground"
                                >
                                    {error}
                                </p>
                            )}
                            {children}
                        </div>
                        {footer && (
                            <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t bg-muted p-4">
                                {footer}
                            </div>
                        )}
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
