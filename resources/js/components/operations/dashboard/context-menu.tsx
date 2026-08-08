import {
    AlertTriangle,
    BarChart3,
    Building2,
    Calendar,
    CalendarPlus,
    Clock,
    Download,
    FileSpreadsheet,
    FileText,
    GraduationCap,
    type LucideIcon,
    MessageSquare,
    PieChart,
    Pin,
    Send,
    Settings2,
    ShieldAlert,
    ShieldCheck,
    SlidersHorizontal,
    Target,
    Timer,
    User,
    UserCheck,
    UserPlus,
    UserX,
    Users,
} from 'lucide-react';
import {
    type MouseEvent as ReactMouseEvent,
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';

import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import { cn } from '@/lib/utils';

const ICON_MAP: Record<string, LucideIcon> = {
    users: Users,
    'user-plus': UserPlus,
    'user-check': UserCheck,
    'user-x': UserX,
    user: User,
    'pie-chart': PieChart,
    'bar-chart-3': BarChart3,
    calendar: Calendar,
    'calendar-plus': CalendarPlus,
    download: Download,
    pin: Pin,
    'settings-2': Settings2,
    clock: Clock,
    'building-2': Building2,
    target: Target,
    'alert-triangle': AlertTriangle,
    timer: Timer,
    send: Send,
    'message-square': MessageSquare,
    'sliders-horizontal': SlidersHorizontal,
    'file-text': FileText,
    'file-spreadsheet': FileSpreadsheet,
    'shield-check': ShieldCheck,
    'shield-alert': ShieldAlert,
    'graduation-cap': GraduationCap,
};

export type CtxMenuItem =
    | { divider: true }
    | {
          divider?: false;
          icon: string;
          text: string;
          shortcut?: string;
          danger?: boolean;
          muted?: boolean;
          onSelect?: () => void;
          href?: string;
      };

export type CtxMenuDef = {
    label: string;
    items: CtxMenuItem[];
};

type ContextMenuProps = {
    open: boolean;
    x: number;
    y: number;
    menu: CtxMenuDef | null;
    onClose: () => void;
};

export function ContextMenu({ open, x, y, menu, onClose }: ContextMenuProps) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState<{ left: number; top: number } | null>(null);

    useLayoutEffect(() => {
        if (!open || !ref.current) return;
        const el = ref.current;
        const rect = el.getBoundingClientRect();
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        let left = x;
        let top = y;
        if (left + rect.width + 8 > vw) left = vw - rect.width - 8;
        if (top + rect.height + 8 > vh) top = vh - rect.height - 8;
        setPos({ left: Math.max(8, left), top: Math.max(8, top) });
    }, [open, x, y, menu]);

    useEffect(() => {
        if (!open) return;
        const onClick = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node))
                onClose();
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        const onScroll = () => onClose();
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        window.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', onScroll);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        };
    }, [open, onClose]);

    if (!open || !menu || typeof window === 'undefined') return null;

    return createPortal(
        <GuardrailCard
            unstyled
            ref={ref}
            role="menu"
            className="fixed z-[60] min-w-[240px] rounded-lg border bg-card py-1 shadow-xl select-none"
            style={{
                left: pos?.left ?? -9999,
                top: pos?.top ?? -9999,
                opacity: pos ? 1 : 0,
                borderColor: 'var(--border)',
                boxShadow:
                    '0 12px 32px -8px rgba(76, 29, 149, 0.18), 0 4px 12px -4px rgba(0,0,0,0.08)',
            }}
        >
            <div className="px-3 pt-1.5 pb-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                {menu.label}
            </div>
            {menu.items.map((item, idx) => {
                if ('divider' in item && item.divider) {
                    return (
                        <div
                            key={idx}
                            className="mx-1.5 my-1 h-px"
                            style={{ background: 'var(--border)' }}
                        />
                    );
                }
                const it = item as Exclude<CtxMenuItem, { divider: true }>;
                const Icon = ICON_MAP[it.icon] ?? Settings2;
                return (
                    <GuardrailButton
                        unstyled
                        key={idx}
                        type="button"
                        role="menuitem"
                        className={cn(
                            'group flex w-full items-center gap-2.5 border-0 bg-transparent px-3 py-1.5 text-left text-[12px]',
                            it.danger
                                ? 'text-[color:var(--status-critical)]'
                                : 'text-foreground',
                            'hover:bg-accent hover:text-accent-foreground',
                        )}
                        style={it.muted ? { opacity: 0.55 } : undefined}
                        onClick={() => {
                            it.onSelect?.();
                            if (it.href) window.location.assign(it.href);
                            onClose();
                        }}
                    >
                        <Icon
                            className={cn(
                                'h-3.5 w-3.5 shrink-0',
                                it.danger
                                    ? 'text-[color:var(--status-critical)]'
                                    : 'text-muted-foreground group-hover:text-primary',
                            )}
                        />
                        <span className="flex-1 truncate">{it.text}</span>
                        {it.shortcut ? (
                            <span className="ml-auto text-[10px] text-muted-foreground tabular-nums">
                                {it.shortcut}
                            </span>
                        ) : null}
                    </GuardrailButton>
                );
            })}
        </GuardrailCard>,
        document.body,
    );
}

export function useContextMenu() {
    const [state, setState] = useState<{
        open: boolean;
        x: number;
        y: number;
        menu: CtxMenuDef | null;
    }>({ open: false, x: 0, y: 0, menu: null });

    const onContextMenu = useCallback((menu: CtxMenuDef) => {
        return (e: ReactMouseEvent) => {
            e.preventDefault();
            e.stopPropagation();
            setState({ open: true, x: e.clientX, y: e.clientY, menu });
        };
    }, []);

    const close = useCallback(
        () => setState((s) => ({ ...s, open: false })),
        [],
    );

    return { state, onContextMenu, close };
}
