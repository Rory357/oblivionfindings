import * as SheetPrimitive from '@radix-ui/react-dialog';
import { XIcon } from 'lucide-react';
import * as React from 'react';

import {
    clearOverlayFocusReturn,
    OverlayFocusReturnProvider,
    rememberOverlayActiveElement,
    rememberOverlayTrigger,
    restoreOverlayFocus,
    useOverlayFocusReturn,
} from '@/components/ui/overlay-focus-return';
import { cn } from '@/lib/utils';

function Sheet({
    children,
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Root>) {
    return (
        <OverlayFocusReturnProvider>
            <SheetPrimitive.Root data-slot="sheet" {...props}>
                {children}
            </SheetPrimitive.Root>
        </OverlayFocusReturnProvider>
    );
}

function SheetTrigger({
    onClick,
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Trigger>) {
    const returnFocusRef = useOverlayFocusReturn();

    return (
        <SheetPrimitive.Trigger
            data-slot="sheet-trigger"
            {...props}
            onClick={(event) => {
                onClick?.(event);
                if (!event.defaultPrevented) {
                    rememberOverlayTrigger(returnFocusRef, event.currentTarget);
                }
            }}
        />
    );
}

function SheetClose({
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Close>) {
    return <SheetPrimitive.Close data-slot="sheet-close" {...props} />;
}

function SheetPortal({
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Portal>) {
    return <SheetPrimitive.Portal data-slot="sheet-portal" {...props} />;
}

function SheetOverlay({
    className,
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Overlay>) {
    return (
        <SheetPrimitive.Overlay
            data-slot="sheet-overlay"
            className={cn(
                'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/80',
                className,
            )}
            {...props}
        />
    );
}

function SheetContent({
    className,
    children,
    side = 'right',
    overlayClassName,
    closeButtonClassName,
    closeLabel = 'Close',
    onOpenAutoFocus,
    onCloseAutoFocus,
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Content> & {
    side?: 'top' | 'right' | 'bottom' | 'left';
    overlayClassName?: string;
    closeButtonClassName?: string;
    closeLabel?: string;
}) {
    const returnFocusRef = useOverlayFocusReturn();

    return (
        <SheetPortal>
            <SheetOverlay className={overlayClassName} />
            <SheetPrimitive.Content
                data-slot="sheet-content"
                className={cn(
                    'bg-background data-[state=open]:animate-in data-[state=closed]:animate-out fixed z-50 flex flex-col gap-4 shadow-lg transition ease-in-out data-[state=closed]:duration-300 data-[state=open]:duration-500',
                    side === 'right' &&
                        'data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right inset-y-0 right-0 h-full w-3/4 border-l sm:max-w-sm',
                    side === 'left' &&
                        'data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left inset-y-0 left-0 h-full w-3/4 border-r sm:max-w-sm',
                    side === 'top' &&
                        'data-[state=closed]:slide-out-to-top data-[state=open]:slide-in-from-top inset-x-0 top-0 h-auto border-b',
                    side === 'bottom' &&
                        'data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom inset-x-0 bottom-0 h-auto border-t',
                    className,
                )}
                {...props}
                onOpenAutoFocus={(event) => {
                    rememberOverlayActiveElement(returnFocusRef);
                    onOpenAutoFocus?.(event);
                }}
                onCloseAutoFocus={(event) => {
                    onCloseAutoFocus?.(event);
                    if (event.defaultPrevented) {
                        clearOverlayFocusReturn(returnFocusRef);
                        return;
                    }

                    if (returnFocusRef) {
                        event.preventDefault();
                        restoreOverlayFocus(returnFocusRef);
                    }
                }}
            >
                {children}
                <SheetPrimitive.Close
                    data-slot="sheet-close"
                    className={cn(
                        'ring-offset-background focus:ring-ring data-[state=open]:bg-secondary rounded-xs focus:outline-hidden absolute right-4 top-4 opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 disabled:pointer-events-none',
                        closeButtonClassName,
                    )}
                >
                    <XIcon className="size-4" />
                    <span className="sr-only">{closeLabel}</span>
                </SheetPrimitive.Close>
            </SheetPrimitive.Content>
        </SheetPortal>
    );
}

function SheetHeader({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="sheet-header"
            className={cn('flex flex-col gap-1.5 p-4', className)}
            {...props}
        />
    );
}

function SheetFooter({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="sheet-footer"
            className={cn('mt-auto flex flex-col gap-2 p-4', className)}
            {...props}
        />
    );
}

function SheetTitle({
    className,
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Title>) {
    return (
        <SheetPrimitive.Title
            data-slot="sheet-title"
            className={cn('text-foreground font-semibold', className)}
            {...props}
        />
    );
}

function SheetDescription({
    className,
    ...props
}: React.ComponentProps<typeof SheetPrimitive.Description>) {
    return (
        <SheetPrimitive.Description
            data-slot="sheet-description"
            className={cn('text-muted-foreground text-sm', className)}
            {...props}
        />
    );
}

export {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
};
