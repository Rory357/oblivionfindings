/* eslint-disable no-restricted-syntax -- positioned history menu uses shared Buttons inside a custom portal layout. */
import type { ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { X } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/** The same record actions are reachable by touch, keyboard and right-click. */
export function HistoryRecordMenu({
    ctx,
    onClose,
}: {
    ctx: ShiftCtxState;
    onClose: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const returnFocus = useRef<HTMLElement | null>(null);
    const [position, setPosition] = useState({ top: ctx.y, left: ctx.x });
    useLayoutEffect(() => {
        const menu = ref.current;
        if (!menu) return;
        if (!menu.contains(document.activeElement))
            returnFocus.current = document.activeElement as HTMLElement | null;
        setPosition({
            top: Math.max(
                8,
                Math.min(ctx.y, window.innerHeight - menu.offsetHeight - 8),
            ),
            left: Math.max(
                8,
                Math.min(ctx.x, window.innerWidth - menu.offsetWidth - 8),
            ),
        });
        menu.querySelector<HTMLButtonElement>('[role="menuitem"]')?.focus();
    }, [ctx]);
    const close = useRef(onClose);
    useEffect(() => {
        close.current = onClose;
    }, [onClose]);
    useEffect(() => {
        const outside = (event: PointerEvent) => {
            if (!ref.current?.contains(event.target as Node)) close.current();
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') close.current();
        };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('keydown', escape);
            if (returnFocus.current?.isConnected) returnFocus.current.focus();
        };
    }, []);
    if (typeof document === 'undefined') return null;
    return createPortal(
        <div
            ref={ref}
            role="menu"
            aria-label="Record actions"
            className="bg-popover text-popover-foreground fixed z-[60] w-72 max-w-[calc(100vw-16px)] overflow-y-auto overscroll-contain rounded-xl border p-2 shadow-lg"
            style={{ ...position, maxHeight: 'calc(100vh - 16px)' }}
            onKeyDown={(event) => {
                if (event.key === 'Tab') {
                    event.preventDefault();
                    onClose();
                    return;
                }
                const items = [
                    ...(ref.current?.querySelectorAll<HTMLButtonElement>(
                        '[role="menuitem"]',
                    ) ?? []),
                ];
                const index = items.indexOf(
                    document.activeElement as HTMLButtonElement,
                );
                let next: number | undefined;
                if (event.key === 'ArrowDown')
                    next = (index + 1) % items.length;
                if (event.key === 'ArrowUp')
                    next = (index - 1 + items.length) % items.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = items.length - 1;
                if (next !== undefined) {
                    event.preventDefault();
                    items[next]?.focus();
                }
            }}
        >
            <div className="flex items-start justify-between gap-2 border-b pb-2">
                <div className="min-w-0 text-sm">
                    <p className="font-semibold">{ctx.tag}</p>
                    <p className="text-muted-foreground break-words">
                        {ctx.meta}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    className="frontline-tap frontline-focus shrink-0"
                    aria-label="Close record actions"
                    onClick={onClose}
                >
                    <X className="size-4" />
                </Button>
            </div>
            {ctx.items.map((item, index) =>
                item.sep ? (
                    <hr key={index} className="my-2" />
                ) : (
                    <Button
                        key={item.label}
                        role="menuitem"
                        variant="ghost"
                        className="frontline-tap frontline-focus h-auto w-full justify-start gap-2 whitespace-normal py-2 text-left"
                        onClick={() => {
                            onClose();
                            item.onClick?.();
                        }}
                    >
                        <span className="shrink-0">{item.icon}</span>
                        <span className="min-w-0">
                            <span className="block">{item.label}</span>
                            {item.sub && (
                                <span className="text-muted-foreground block text-xs">
                                    {item.sub}
                                </span>
                            )}
                        </span>
                    </Button>
                ),
            )}
        </div>,
        document.body,
    );
}
