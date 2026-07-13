import { useEffect, useRef, useState } from 'react';
import type { LucideIcon } from 'lucide-react';
import { Button as GuardrailButton } from '@/components/ui/button';

export type ContextMenuItem =
    | { type: 'header'; label: string }
    | { type: 'separator' }
    | {
          type?: 'action';
          label: string;
          icon?: LucideIcon;
          onClick?: () => void;
          disabled?: boolean;
          destructive?: boolean;
          shortcut?: string;
      };

type Props = {
    x: number;
    y: number;
    items: ContextMenuItem[];
    onClose: () => void;
};

export function ShiftContextMenu({ x, y, items, onClose }: Props) {
    const menuRef = useRef<HTMLDivElement | null>(null);
    const [pos, setPos] = useState({ left: x, top: y });

    useEffect(() => {
        const el = menuRef.current;
        if (!el) return;
        const r = el.getBoundingClientRect();
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        let left = x;
        let top = y;
        if (left + r.width > vw - 8) left = Math.max(8, vw - r.width - 8);
        if (top + r.height > vh - 8) top = Math.max(8, vh - r.height - 8);
        setPos({ left, top });
    }, [x, y]);

    useEffect(() => {
        const onDown = (e: MouseEvent) => {
            if (!menuRef.current?.contains(e.target as Node)) onClose();
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('mousedown', onDown);
        window.addEventListener('keydown', onKey);
        window.addEventListener('contextmenu', onDown, true);
        return () => {
            window.removeEventListener('mousedown', onDown);
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('contextmenu', onDown, true);
        };
    }, [onClose]);

    return (
        <div
            ref={menuRef}
            role="menu"
            className="fixed z-50 min-w-[228px] rounded-xl border border-border bg-popover py-1.5 shadow-lg"
            style={{
                left: pos.left,
                top: pos.top,
                boxShadow:
                    '0 12px 32px -8px oklch(0.20 0.05 277 / 0.25), 0 4px 12px -4px oklch(0.20 0.05 277 / 0.15)',
            }}
        >
            {items.map((it, i) => {
                if (it.type === 'separator') {
                    return (
                        <div
                            key={`sep-${i}`}
                            className="my-1 h-px bg-border"
                        />
                    );
                }
                if (it.type === 'header') {
                    return (
                        <div
                            key={`hdr-${i}`}
                            className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground"
                        >
                            {it.label}
                        </div>
                    );
                }
                const action = it;
                const Icon = action.icon;
                return (
                    <GuardrailButton unstyled
                        key={`${action.label}-${i}`}
                        type="button"
                        role="menuitem"
                        disabled={action.disabled}
                        onClick={() => {
                            action.onClick?.();
                            onClose();
                        }}
                        className={[
                            'flex w-full items-center gap-2.5 px-3 py-1.5 text-left text-sm transition-colors',
                            action.destructive
                                ? 'text-status-critical hover:bg-status-critical-bg'
                                : 'text-foreground hover:bg-muted',
                            action.disabled ? 'cursor-not-allowed opacity-50' : '',
                        ].join(' ')}
                    >
                        {Icon ? (
                            <Icon className="h-4 w-4 shrink-0" />
                        ) : (
                            <span className="w-4 shrink-0" />
                        )}
                        <span className="min-w-0 flex-1">{action.label}</span>
                        {action.shortcut ? (
                            <span className="text-[11px] text-muted-foreground tabular-nums">
                                {action.shortcut}
                            </span>
                        ) : null}
                    </GuardrailButton>
                );
            })}
        </div>
    );
}
