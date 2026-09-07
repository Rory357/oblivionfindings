/* eslint-disable no-restricted-syntax -- The context menu is a
 * cursor-anchored styled-native surface (semantic tokens only), extracted
 * verbatim from the sites/clients indexes per LIST_STYLE_GUIDE.md §4.2. */
/**
 * Shared list actions (design_styles/LIST_STYLE_GUIDE.md §1): every entity
 * card and table row carries the kebab menu AND the right-click context
 * menu, both fed by the SAME `MenuItem[]`. Edge-clamped positioning;
 * Esc / scroll / click-away closes. Dropping either entry point or any
 * menu item during a migration is a regression.
 */
import { MoreVertical } from 'lucide-react';
import {
    type ComponentType,
    type MouseEvent as ReactMouseEvent,
    useEffect,
    useRef,
    useState,
} from 'react';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

type IconType = ComponentType<{ className?: string }>;

export type MenuItem = {
    separator?: boolean;
    label?: string;
    icon?: IconType;
    onClick?: () => void;
    danger?: boolean;
};

/** Remove falsy entries and collapse / trim separators. */
export function compactMenu(
    items: (MenuItem | false | null | undefined)[],
): MenuItem[] {
    const cleaned = items.filter(Boolean) as MenuItem[];
    const out: MenuItem[] = [];
    for (const it of cleaned) {
        if (it.separator) {
            if (out.length === 0 || out[out.length - 1].separator) continue;
        }
        out.push(it);
    }
    while (out.length && out[out.length - 1].separator) out.pop();
    return out;
}

/** The kebab — the always-visible actions entry point on cards and rows. */
export function EntityKebab({
    actions,
    className,
}: {
    actions: MenuItem[];
    className?: string;
}) {
    return (
        <div onClick={(e) => e.stopPropagation()}>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label="More actions"
                        className={cn(
                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring',
                            className,
                        )}
                    >
                        <MoreVertical className="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    {actions.map((it, i) =>
                        it.separator ? (
                            <DropdownMenuSeparator key={`s${i}`} />
                        ) : (
                            <DropdownMenuItem
                                key={i}
                                onClick={it.onClick}
                                className={cn(
                                    it.danger &&
                                        'text-status-critical focus:text-status-critical',
                                )}
                            >
                                {it.icon ? (
                                    <it.icon className="size-4" />
                                ) : null}
                                {it.label}
                            </DropdownMenuItem>
                        ),
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

/**
 * The right-click context menu — cursor-anchored, clamped to the viewport,
 * closed by Esc / scroll / click-away. `header` names the record (icon
 * tile + title) so the user always knows what they're acting on.
 */
export function EntityContextMenu({
    x,
    y,
    icon: Icon,
    title,
    items,
    onClose,
}: {
    x: number;
    y: number;
    icon?: IconType;
    title: string;
    items: MenuItem[];
    onClose: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({ x, y });

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const r = el.getBoundingClientRect();
        let nx = x;
        let ny = y;
        if (nx + r.width > window.innerWidth - 8)
            nx = window.innerWidth - r.width - 8;
        if (ny + r.height > window.innerHeight - 8)
            ny = window.innerHeight - r.height - 8;
        setPos({ x: Math.max(8, nx), y: Math.max(8, ny) });
    }, [x, y]);

    useEffect(() => {
        const close = () => onClose();
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('mousedown', close);
        window.addEventListener('scroll', close, true);
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('mousedown', close);
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('keydown', onKey);
        };
    }, [onClose]);

    return (
        <div
            ref={ref}
            style={{ left: pos.x, top: pos.y }}
            onMouseDown={(e) => e.stopPropagation()}
            onContextMenu={(e) => e.preventDefault()}
            className="fixed z-[200] min-w-[200px] rounded-xl border border-border bg-popover p-1.5 shadow-xl"
        >
            <div className="mb-1 flex items-center gap-2 border-b border-border px-2 pt-1 pb-2">
                {Icon ? (
                    <span className="flex h-[26px] w-[26px] items-center justify-center rounded-md bg-primary/15 text-primary">
                        <Icon className="size-3.5" />
                    </span>
                ) : null}
                <span className="truncate text-[12.5px] font-semibold">
                    {title}
                </span>
            </div>
            {items.map((it, i) =>
                it.separator ? (
                    <div key={`s${i}`} className="my-1 h-px bg-border" />
                ) : (
                    <button
                        key={i}
                        type="button"
                        onClick={() => {
                            onClose();
                            it.onClick?.();
                        }}
                        className={cn(
                            'flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-[13px] hover:bg-muted',
                            it.danger
                                ? 'text-status-critical'
                                : 'text-foreground',
                        )}
                    >
                        {it.icon ? (
                            <it.icon
                                className={cn(
                                    'size-4',
                                    !it.danger && 'opacity-80',
                                )}
                            />
                        ) : null}
                        {it.label}
                    </button>
                ),
            )}
        </div>
    );
}

/** Convenience state hook for wiring `onContextMenu` on cards/rows. */
export function useEntityContextMenu<T>() {
    const [ctx, setCtx] = useState<{ x: number; y: number; record: T } | null>(
        null,
    );
    const open = (e: ReactMouseEvent, record: T) => {
        e.preventDefault();
        setCtx({ x: e.clientX, y: e.clientY, record });
    };
    const close = () => setCtx(null);
    return { ctx, open, close } as const;
}
