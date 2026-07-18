import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { MoreHorizontal, type LucideIcon } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type KeyboardEvent,
    type MouseEvent as ReactMouseEvent,
    type ReactNode,
} from 'react';
import { createPortal } from 'react-dom';

export type ControlRoomRowAction = {
    key: string;
    label: string;
    icon?: LucideIcon;
    onSelect: () => void;
    disabled?: boolean;
    tone?: 'default' | 'critical';
};

type RenderProps = {
    rowProps: {
        onContextMenu: (event: ReactMouseEvent<HTMLElement>) => void;
        tabIndex: number;
    };
    overflowButton: ReactNode;
};

const VIEWPORT_GUTTER = 8;
const MENU_WIDTH = 224;

export function ControlRoomRowActions({
    label,
    items,
    children,
}: {
    label: string;
    items: readonly ControlRoomRowAction[];
    children: (props: RenderProps) => ReactNode;
}) {
    const [position, setPosition] = useState<{
        x: number;
        y: number;
    } | null>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const overflowRef = useRef<HTMLButtonElement>(null);
    const returnFocusRef = useRef<HTMLElement | null>(null);

    const close = useCallback((restoreFocus = true) => {
        setPosition(null);
        if (restoreFocus) {
            window.requestAnimationFrame(() => {
                returnFocusRef.current?.focus();
            });
        }
    }, []);

    const rememberReturnFocus = useCallback((fallback: HTMLElement) => {
        returnFocusRef.current =
            document.activeElement instanceof HTMLElement &&
            document.activeElement !== document.body
                ? document.activeElement
                : fallback;
    }, []);

    const openAt = useCallback(
        (x: number, y: number, fallback: HTMLElement) => {
            rememberReturnFocus(fallback);
            setPosition({ x, y });
        },
        [rememberReturnFocus],
    );

    const onContextMenu = useCallback(
        (event: ReactMouseEvent<HTMLElement>) => {
            if (!items.length) return;
            event.preventDefault();
            openAt(event.clientX, event.clientY, event.currentTarget);
        },
        [items.length, openAt],
    );

    const openFromOverflow = useCallback(
        (event: ReactMouseEvent<HTMLButtonElement>) => {
            const trigger = overflowRef.current ?? event.currentTarget;
            if (!items.length) return;
            const rect = trigger.getBoundingClientRect();
            openAt(rect.right - MENU_WIDTH, rect.bottom + 4, trigger);
        },
        [items.length, openAt],
    );

    useLayoutEffect(() => {
        if (!position || !menuRef.current) return;

        const menu = menuRef.current;
        const rect = menu.getBoundingClientRect();
        const nextX = Math.max(
            VIEWPORT_GUTTER,
            Math.min(
                position.x,
                window.innerWidth - rect.width - VIEWPORT_GUTTER,
            ),
        );
        const nextY = Math.max(
            VIEWPORT_GUTTER,
            Math.min(
                position.y,
                window.innerHeight - rect.height - VIEWPORT_GUTTER,
            ),
        );

        if (nextX !== position.x || nextY !== position.y) {
            setPosition({ x: nextX, y: nextY });
            return;
        }

        menu.querySelector<HTMLButtonElement>(
            '[role="menuitem"]:not(:disabled)',
        )?.focus();
    }, [position]);

    useEffect(() => {
        if (!position) return;

        const onPointerDown = (event: PointerEvent) => {
            if (!menuRef.current?.contains(event.target as Node)) {
                close(false);
            }
        };
        const onViewportChange = () => close(false);

        document.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('resize', onViewportChange);
        window.addEventListener('wheel', onViewportChange, { passive: true });
        window.addEventListener('touchmove', onViewportChange, {
            passive: true,
        });

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            window.removeEventListener('resize', onViewportChange);
            window.removeEventListener('wheel', onViewportChange);
            window.removeEventListener('touchmove', onViewportChange);
        };
    }, [close, position]);

    const onMenuKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }

        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        const menuItems = Array.from(
            event.currentTarget.querySelectorAll<HTMLButtonElement>(
                '[role="menuitem"]:not(:disabled)',
            ),
        );
        if (!menuItems.length) return;

        const current = menuItems.indexOf(
            document.activeElement as HTMLButtonElement,
        );
        const next =
            event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? menuItems.length - 1
                  : event.key === 'ArrowUp'
                    ? (current - 1 + menuItems.length) % menuItems.length
                    : (current + 1) % menuItems.length;
        menuItems[next]?.focus();
    };

    const overflowButton = items.length ? (
        <Button
            ref={overflowRef}
            type="button"
            variant="ghost"
            size="icon"
            aria-label={label}
            aria-haspopup="menu"
            aria-expanded={position !== null}
            className="min-h-11 min-w-11 shrink-0 md:min-h-9 md:min-w-9"
            onClick={openFromOverflow}
        >
            <MoreHorizontal className="h-4 w-4" aria-hidden />
        </Button>
    ) : null;

    const menu = position ? (
        <div
            ref={menuRef}
            role="menu"
            aria-label={label}
            className="fixed z-[100] w-56 rounded-lg border border-border bg-popover p-1.5 text-popover-foreground shadow-xl"
            style={{ left: position.x, top: position.y }}
            onKeyDown={onMenuKeyDown}
        >
            {items.map((item) => {
                const Icon = item.icon;
                return (
                    // eslint-disable-next-line no-restricted-syntax -- WAI-ARIA menu items require native button keyboard semantics.
                    <button
                        key={item.key}
                        type="button"
                        role="menuitem"
                        disabled={item.disabled}
                        className={cn(
                            'flex min-h-10 w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium outline-none hover:bg-accent hover:text-accent-foreground focus-visible:bg-accent focus-visible:text-accent-foreground disabled:pointer-events-none disabled:opacity-50',
                            item.tone === 'critical' &&
                                'text-status-critical hover:text-status-critical focus-visible:text-status-critical',
                        )}
                        onClick={() => {
                            close(false);
                            item.onSelect();
                        }}
                    >
                        {Icon ? (
                            <Icon className="h-4 w-4 shrink-0" aria-hidden />
                        ) : null}
                        {item.label}
                    </button>
                );
            })}
        </div>
    ) : null;

    return (
        <>
            {/* eslint-disable-next-line react-hooks/refs -- The render prop receives callbacks and a trigger element; no ref value is read while rendering. */}
            {children({
                rowProps: { onContextMenu, tabIndex: -1 },
                overflowButton,
            })}
            {menu && typeof document !== 'undefined'
                ? createPortal(menu, document.body)
                : null}
        </>
    );
}
