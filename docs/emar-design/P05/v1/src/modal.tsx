/* The Fleet Settings `Modal` anatomy (pages/fleet-assets/settings/_ui.tsx) —
 * P01 v2 / P07a v1's `Modal`, copied unchanged: header band with title and
 * description, scrolling body, muted footer band; width tokens 480 / 720 / 900.
 */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { type ComponentProps, type ReactNode } from 'react';
import { DesignNote } from './ui';

export function Modal({
    title,
    description,
    children,
    footer,
    onClose,
    width = 480,
    onCloseAutoFocus,
}: {
    title: string;
    description: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
    onClose: () => void;
    width?: 480 | 720 | 900;
    onCloseAutoFocus?: ComponentProps<typeof DialogContent>['onCloseAutoFocus'];
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="flex max-h-[88vh] flex-col overflow-hidden p-0"
                style={{ width: `min(92vw, ${width}px)`, maxWidth: `min(92vw, ${width}px)` }}
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <div className="shrink-0 border-b p-5 pr-12">
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription className="mt-2">{description}</DialogDescription>
                </div>
                <div className="min-h-0 space-y-4 overflow-y-auto p-5">{children}</div>
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

/** A record you can’t see and one that doesn’t exist look the same (P00 v5). */
export function NotFoundDialog({ onClose }: { onClose: () => void }) {
    return (
        <Modal title="We can’t show this record" description="It may not exist, or it may not be available to you. Check the link, or go back." onClose={onClose}>
            <DesignNote>A person at a house outside your access, a controlled medicine opened without controlled-medicine access, and a record that doesn’t exist all show this (404 — no existence leak, P00 v5).</DesignNote>
        </Modal>
    );
}

