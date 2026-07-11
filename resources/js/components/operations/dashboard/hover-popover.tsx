import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    ClipboardCheck,
    GitBranch,
    Route,
    ShieldAlert,
    type LucideIcon,
} from 'lucide-react';
import {
    type ReactNode,
    type RefObject,
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';

import { cn } from '@/lib/utils';

import type { HoverPopoverContent } from './types';
import { Card as GuardrailCard } from '@/components/ui/card';

const ICON_MAP: Record<string, LucideIcon> = {
    'alert-triangle': AlertTriangle,
    'clipboard-check': ClipboardCheck,
    'git-branch': GitBranch,
    'shield-alert': ShieldAlert,
    'building-2': Building2,
    route: Route,
};

const TONE: Record<string, { bg: string; fg: string }> = {
    critical: { bg: 'var(--status-critical-bg)', fg: 'var(--status-critical)' },
    warning: { bg: 'var(--status-warning-bg)', fg: 'var(--status-warning)' },
    success: { bg: 'var(--status-success-bg)', fg: 'var(--status-success)' },
    info: { bg: 'var(--accent)', fg: 'var(--primary)' },
};

type HoverPopoverProps = {
    open: boolean;
    anchorRef: RefObject<HTMLElement | null>;
    content: HoverPopoverContent;
    onMouseEnter?: () => void;
    onMouseLeave?: () => void;
    placement?: 'below' | 'right';
};

export function HoverPopover({
    open,
    anchorRef,
    content,
    onMouseEnter,
    onMouseLeave,
    placement = 'below',
}: HoverPopoverProps) {
    const popRef = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState<{ left: number; top: number } | null>(null);

    const reposition = useCallback(() => {
        const anchor = anchorRef.current;
        const pop = popRef.current;
        if (!anchor || !pop) return;
        const r = anchor.getBoundingClientRect();
        const popR = pop.getBoundingClientRect();
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        let left: number;
        let top: number;
        if (placement === 'right') {
            left = r.right + 8;
            top = r.top - 4;
            if (left + popR.width + 8 > vw) left = r.left - popR.width - 8;
            if (top + popR.height + 8 > vh) top = vh - popR.height - 8;
        } else {
            left = r.left;
            top = r.bottom + 8;
            if (left + popR.width + 8 > vw) left = vw - popR.width - 8;
            if (top + popR.height + 8 > vh) top = r.top - popR.height - 8;
        }
        setPos({ left: Math.max(8, left), top: Math.max(8, top) });
    }, [anchorRef, placement]);

    useLayoutEffect(() => {
        if (open) reposition();
    }, [open, content, reposition]);

    useEffect(() => {
        if (!open) return;
        const handle = () => reposition();
        window.addEventListener('scroll', handle, true);
        window.addEventListener('resize', handle);
        return () => {
            window.removeEventListener('scroll', handle, true);
            window.removeEventListener('resize', handle);
        };
    }, [open, reposition]);

    if (!open || typeof window === 'undefined') return null;

    const Icon = ICON_MAP[content.icon] ?? AlertTriangle;
    const headerTone = TONE[content.tone] ?? TONE.info;

    return createPortal(
        <GuardrailCard unstyled
            ref={popRef}
            role="dialog"
            onMouseEnter={onMouseEnter}
            onMouseLeave={onMouseLeave}
            className="pointer-events-auto fixed z-50 w-[340px] overflow-hidden rounded-xl border bg-card shadow-xl transition-opacity duration-150"
            style={{
                left: pos?.left ?? -9999,
                top: pos?.top ?? -9999,
                opacity: pos ? 1 : 0,
                borderColor: 'var(--border)',
                boxShadow:
                    '0 12px 32px -8px rgba(76, 29, 149, 0.18), 0 4px 12px -4px rgba(0,0,0,0.08)',
            }}
        >
            <div className="flex items-center gap-2 border-b px-3 py-2.5" style={{ borderColor: 'var(--border)' }}>
                <div
                    className="flex h-7 w-7 items-center justify-center rounded-md"
                    style={{ background: headerTone.bg, color: headerTone.fg }}
                >
                    <Icon className="h-3.5 w-3.5" />
                </div>
                <div className="text-[12px] font-semibold">{content.title}</div>
                <div className="ml-auto text-[10.5px] text-muted-foreground">{content.sub}</div>
            </div>
            <div className="max-h-[280px] overflow-y-auto py-1.5">
                {content.rows.length === 0 ? (
                    <div className="px-3 py-4 text-center text-[11px] text-muted-foreground">No items</div>
                ) : (
                    content.rows.map((row, idx) => (
                        <div
                            key={idx}
                            className={cn(
                                'grid items-center gap-2 px-3 py-1.5 hover:bg-muted/60',
                                idx > 0 && 'border-t',
                            )}
                            style={{
                                gridTemplateColumns: '32px 1fr auto',
                                borderColor: 'var(--border)',
                            }}
                        >
                            <div className="text-right text-[10.5px] tabular-nums text-muted-foreground">{row.time}</div>
                            <div className="min-w-0">
                                <div className="text-[11.5px] font-semibold leading-tight">{row.site}</div>
                                <div className="truncate text-[10.5px] text-muted-foreground">{row.detail}</div>
                            </div>
                            {row.tag ? (
                                <span
                                    className="inline-flex items-center rounded px-1.5 py-0.5 text-[9.5px] font-semibold uppercase tracking-wide"
                                    style={{
                                        background: TONE[row.tag.cls].bg,
                                        color: TONE[row.tag.cls].fg,
                                    }}
                                >
                                    {row.tag.text}
                                </span>
                            ) : (
                                <span />
                            )}
                        </div>
                    ))
                )}
            </div>
            <div
                className="flex items-center justify-between border-t bg-muted px-3 py-2 text-[11px]"
                style={{ borderColor: 'var(--border)' }}
            >
                <span className="text-muted-foreground">Click card to open</span>
                <Link href={content.href} className="inline-flex items-center gap-1 font-semibold text-primary">
                    {content.cta}
                    <ArrowRight className="h-3 w-3" />
                </Link>
            </div>
        </GuardrailCard>,
        document.body,
    );
}

export function useHoverPopover() {
    const [open, setOpen] = useState(false);
    const hoverTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const hideTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const onEnter = useCallback(() => {
        if (hideTimer.current) clearTimeout(hideTimer.current);
        if (hoverTimer.current) clearTimeout(hoverTimer.current);
        hoverTimer.current = setTimeout(() => setOpen(true), 180);
    }, []);

    const onLeave = useCallback(() => {
        if (hoverTimer.current) clearTimeout(hoverTimer.current);
        hideTimer.current = setTimeout(() => setOpen(false), 160);
    }, []);

    const popEnter = useCallback(() => {
        if (hideTimer.current) clearTimeout(hideTimer.current);
    }, []);

    const popLeave = useCallback(() => {
        hideTimer.current = setTimeout(() => setOpen(false), 160);
    }, []);

    useEffect(() => {
        if (!open) return;
        const onScroll = () => setOpen(false);
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        window.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', onScroll);
        document.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return { open, onEnter, onLeave, popEnter, popLeave };
}

export function PulseDot({ className }: { className?: string }): ReactNode {
    return (
        <span aria-hidden="true" className={cn('relative inline-flex h-2 w-2', className)}>
                            <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-status-success/70" />
                            <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success ring-2 ring-status-success/30" />
        </span>
    );
}
