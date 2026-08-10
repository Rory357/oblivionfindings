/**
 * Native-feeling right-click menu for the directory table rows. Positioned at
 * the cursor and clamped to the viewport; closes on outside-click / Esc /
 * scroll. Driven entirely by parent state.
 */
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export type ContextMenuItem =
    | { sep: true }
    | {
          sep?: false;
          icon: LucideIcon;
          label: string;
          onClick: () => void;
          danger?: boolean;
          disabled?: boolean;
      };

export type ContextMenuState = {
    x: number;
    y: number;
    header: { icon: LucideIcon; title: string; sub?: string };
    items: ContextMenuItem[];
};

export function RowContextMenu({
    menu,
    onClose,
}: {
    menu: ContextMenuState | null;
    onClose: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({
        x: menu?.x ?? 0,
        y: menu?.y ?? 0,
        ready: false,
    });

    useEffect(() => {
        if (!menu) return;
        const onDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node))
                onClose();
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        const onScroll = () => onClose();
        document.addEventListener('mousedown', onDown);
        document.addEventListener('contextmenu', onDown);
        window.addEventListener('keydown', onKey);
        window.addEventListener('scroll', onScroll, true);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('contextmenu', onDown);
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('scroll', onScroll, true);
        };
    }, [menu, onClose]);

    useLayoutEffect(() => {
        if (!menu) {
            setPos((p) => ({ ...p, ready: false }));
            return;
        }
        const el = ref.current;
        if (!el) return;
        const rect = el.getBoundingClientRect();
        const pad = 10;
        let x = menu.x;
        let y = menu.y;
        if (x + rect.width + pad > window.innerWidth)
            x = window.innerWidth - rect.width - pad;
        if (y + rect.height + pad > window.innerHeight)
            y = window.innerHeight - rect.height - pad;
        setPos({ x: Math.max(pad, x), y: Math.max(pad, y), ready: true });
    }, [menu]);

    if (!menu) return null;
    const HeaderIcon = menu.header.icon;

    return (
        <div
            ref={ref}
            role="menu"
            className="fixed z-50 min-w-[224px] overflow-hidden rounded-xl border border-border bg-popover p-1 text-popover-foreground shadow-xl"
            style={{
                left: pos.x,
                top: pos.y,
                visibility: pos.ready ? 'visible' : 'hidden',
            }}
        >
            <div className="mb-1 flex items-center gap-2 border-b border-border px-2.5 py-2">
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <HeaderIcon className="h-3.5 w-3.5" />
                </span>
                <div className="min-w-0">
                    <div className="truncate text-sm font-semibold">
                        {menu.header.title}
                    </div>
                    {menu.header.sub ? (
                        <div className="truncate text-xs text-muted-foreground">
                            {menu.header.sub}
                        </div>
                    ) : null}
                </div>
            </div>
            {menu.items.map((item, i) =>
                item.sep ? (
                    <div key={`sep-${i}`} className="my-1 h-px bg-border" />
                ) : (
                    // eslint-disable-next-line no-restricted-syntax -- context-menu item, not a standard Button
                    <button
                        key={item.label}
                        type="button"
                        role="menuitem"
                        disabled={item.disabled}
                        onClick={() => {
                            onClose();
                            item.onClick();
                        }}
                        className={cn(
                            'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-sm transition-colors',
                            'hover:bg-muted focus:bg-muted focus:outline-none disabled:pointer-events-none disabled:opacity-40',
                            item.danger
                                ? 'text-status-critical hover:bg-status-critical-bg'
                                : 'text-foreground',
                        )}
                    >
                        <item.icon className="h-4 w-4 shrink-0" />
                        <span className="truncate">{item.label}</span>
                    </button>
                ),
            )}
        </div>
    );
}
