import type { CSSProperties, ReactNode } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { cn } from '@/lib/utils';

export type ShiftCtxItem =
    | { sep: true }
    | {
          sep?: false;
          icon: ReactNode;
          label: string;
          sub?: string;
          kbd?: string;
          tone?: 'primary' | 'critical';
          onClick?: () => void;
      };

export type ShiftCtxState = {
    x: number;
    y: number;
    tag: string;
    tagBg?: string;
    tagColor?: string;
    meta: string;
    items: ShiftCtxItem[];
};

export function ShiftContextMenu({
    ctx,
    onClose,
}: {
    ctx: ShiftCtxState;
    onClose: () => void;
}) {
    const ref = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const onDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node))
                onClose();
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [onClose]);

    const [pos, setPos] = useState({ top: ctx.y, left: ctx.x });
    useLayoutEffect(() => {
        if (!ref.current) return;
        const r = ref.current.getBoundingClientRect();
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        let top = ctx.y;
        let left = ctx.x;
        if (left + r.width + 8 > vw) left = vw - r.width - 8;
        if (top + r.height + 8 > vh) top = vh - r.height - 8;
        // Never let the menu run off the top/left edge — when it is taller than
        // the viewport the maxHeight cap + internal scroll (below) keep every
        // item reachable instead of clipping the lowest ones off-screen.
        top = Math.max(8, top);
        left = Math.max(8, left);
        setPos({ top, left });
    }, [ctx]);

    const tagStyle: CSSProperties | undefined =
        ctx.tagBg || ctx.tagColor
            ? { background: ctx.tagBg, color: ctx.tagColor }
            : undefined;

    return createPortal(
        <div
            ref={ref}
            className="pointer-events-auto fixed z-[60] w-[280px] overflow-y-auto overscroll-contain rounded-[12px] border border-border bg-popover p-1.5 text-popover-foreground shadow-lg animate-in fade-in-0 zoom-in-95 duration-100"
            style={{ top: pos.top, left: pos.left, maxHeight: 'calc(100vh - 16px)' }}
            role="menu"
        >
            <div className="mb-1 flex items-center gap-2 px-2 py-1.5 border-b border-border/60">
                <span
                    className="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider"
                    style={tagStyle}
                >
                    {ctx.tag}
                </span>
                <span className="truncate text-[11px] text-muted-foreground">
                    {ctx.meta}
                </span>
            </div>
            <ul className="space-y-px">
                {ctx.items.map((it, i) =>
                    it.sep ? (
                        <li
                            key={i}
                            role="separator"
                            className="my-1 h-px bg-border/60"
                        />
                    ) : (
                        <li
                            key={i}
                            role="menuitem"
                            onClick={() => {
                                if (it.onClick) it.onClick();
                                onClose();
                            }}
                            className={cn(
                                'grid grid-cols-[24px_1fr_auto] items-center gap-2.5 rounded-md px-2 py-1.5 text-[12.5px] cursor-pointer transition-colors hover:bg-accent',
                                it.tone === 'primary' && 'text-primary',
                                it.tone === 'critical' &&
                                    'text-status-critical',
                            )}
                        >
                            <span
                                className={cn(
                                    'inline-flex h-[22px] w-[22px] items-center justify-center rounded-md',
                                    it.tone === 'primary'
                                        ? 'bg-primary/15 text-primary'
                                        : it.tone === 'critical'
                                          ? 'bg-status-critical-bg text-status-critical'
                                          : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {it.icon}
                            </span>
                            <span className="min-w-0">
                                <span className="block leading-tight">
                                    {it.label}
                                </span>
                                {it.sub ? (
                                    <span className="mt-0.5 block text-[10.5px] text-muted-foreground">
                                        {it.sub}
                                    </span>
                                ) : null}
                            </span>
                            {it.kbd ? (
                                <span className="rounded border border-border bg-background px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">
                                    {it.kbd}
                                </span>
                            ) : null}
                        </li>
                    ),
                )}
            </ul>
        </div>,
        document.body,
    );
}

export default ShiftContextMenu;
