/* eslint-disable no-restricted-syntax -- A right-click / ⋯ context menu is a
 * floating cursor-positioned surface (raw <button>/<div> + portal), not a
 * shadcn <Button>/<Card> case. Mirrors the checklists FloatingMenu mechanics. */
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import {
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { createPortal } from 'react-dom';

export type LeaveCtxItem =
    | { kind: 'divider' }
    | {
          kind: 'item';
          label: string;
          icon: LucideIcon;
          onSelect: () => void;
          tone?: 'default' | 'critical' | 'success';
          kbd?: string;
      };

const MENU_W = 216;

/* Floating menu shell — fixed at the cursor, nudged inside the viewport;
 * closes on outside-click, Esc, scroll or resize; arrow keys rove items. */
function FloatingMenu({
    x,
    y,
    onClose,
    children,
}: {
    x: number;
    y: number;
    onClose: () => void;
    children: ReactNode;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState<{ left: number; top: number }>({
        left: x,
        top: y,
    });

    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) return;
        const r = el.getBoundingClientRect();
        let left = x;
        let top = y;
        if (left + r.width > window.innerWidth - 8)
            left = Math.max(8, window.innerWidth - r.width - 8);
        if (top + r.height > window.innerHeight - 8)
            top = Math.max(8, window.innerHeight - r.height - 8);
        setPos({ left, top });
    }, [x, y]);

    useEffect(() => {
        const onDoc = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node))
                onClose();
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
                return;
            }
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            const items = Array.from(
                ref.current?.querySelectorAll<HTMLElement>(
                    '[role="menuitem"]:not([disabled])',
                ) ?? [],
            );
            if (items.length === 0) return;
            e.preventDefault();
            const idx = items.indexOf(document.activeElement as HTMLElement);
            const next =
                e.key === 'ArrowDown'
                    ? (idx + 1) % items.length
                    : (idx - 1 + items.length) % items.length;
            items[next]?.focus();
        };
        const onScroll = () => onClose();
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        window.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', onScroll, true);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll, true);
        };
    }, [onClose]);

    return (
        <div
            ref={ref}
            role="menu"
            className="fixed z-[80] overflow-hidden rounded-xl border border-border bg-popover p-1.5 text-popover-foreground shadow-2xl"
            style={{ left: pos.left, top: pos.top, width: MENU_W }}
            onContextMenu={(e) => e.preventDefault()}
        >
            {children}
        </div>
    );
}

function LeaveContextMenu({
    x,
    y,
    items,
    onClose,
}: {
    x: number;
    y: number;
    items: LeaveCtxItem[];
    onClose: () => void;
}) {
    let firstItem = true;
    return (
        <FloatingMenu x={x} y={y} onClose={onClose}>
            {items.map((item, i) => {
                if (item.kind === 'divider')
                    return (
                        <div key={`div-${i}`} className="my-1 h-px bg-border" />
                    );
                const autoFocus = firstItem;
                firstItem = false;
                const Icon = item.icon;
                return (
                    <button
                        key={item.label}
                        type="button"
                        role="menuitem"
                        autoFocus={autoFocus}
                        onClick={() => {
                            onClose();
                            item.onSelect();
                        }}
                        className={cn(
                            'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[12.5px] font-semibold transition-colors outline-none focus:bg-accent',
                            item.tone === 'critical'
                                ? 'text-status-critical hover:bg-status-critical-bg focus:bg-status-critical-bg'
                                : item.tone === 'success'
                                  ? 'text-status-success hover:bg-status-success-bg focus:bg-status-success-bg'
                                  : 'hover:bg-accent',
                        )}
                    >
                        <Icon className="h-4 w-4 shrink-0" />
                        <span className="flex-1 truncate">{item.label}</span>
                        {item.kbd ? (
                            <span className="rounded border border-border px-1.5 py-px text-[10px] font-bold text-muted-foreground">
                                {item.kbd}
                            </span>
                        ) : null}
                    </button>
                );
            })}
        </FloatingMenu>
    );
}

/**
 * Right-click / ⋯ context menu for leave rows & cards. `open(items)` returns an
 * event handler usable for both `onContextMenu` and a button `onClick`, opening
 * the menu at the cursor. Render `element` once in the same component.
 */
export function useLeaveContextMenu() {
    const [state, setState] = useState<{
        x: number;
        y: number;
        items: LeaveCtxItem[];
    } | null>(null);

    const open = (items: LeaveCtxItem[]) => (e: React.MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();
        setState({ x: e.clientX, y: e.clientY, items });
    };

    const element =
        state && typeof document !== 'undefined'
            ? createPortal(
                  <LeaveContextMenu
                      x={state.x}
                      y={state.y}
                      items={state.items}
                      onClose={() => setState(null)}
                  />,
                  document.body,
              )
            : null;

    return { open, element };
}

export default useLeaveContextMenu;
