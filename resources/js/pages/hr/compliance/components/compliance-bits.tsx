/* eslint-disable no-restricted-syntax -- The compliance hub uses a few bespoke
 * on-surface controls (conic compliance ring, viewport-flipped portal context
 * menu) that the shadcn kit doesn't provide. All colours are semantic tokens. */
import { cn } from '@/lib/utils';
import { StatusBadge } from '@/components/ui/status-badge';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import type { LucideIcon } from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Status → badge mapping (shared across every tab)                   */
/* ------------------------------------------------------------------ */

type Variant = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

export const VETTING_BADGE: Record<string, { label: string; variant: Variant }> = {
    cleared: { label: 'Clear', variant: 'success' },
    clear: { label: 'Clear', variant: 'success' },
    pending: { label: 'Pending', variant: 'warning' },
    flagged: { label: 'Flagged', variant: 'critical' },
    expired: { label: 'Expired', variant: 'critical' },
    not_started: { label: 'Not started', variant: 'neutral' },
    none: { label: '—', variant: 'neutral' },
};

export const DRIVER_BADGE: Record<string, { label: string; variant: Variant }> = {
    eligible: { label: 'Eligible', variant: 'success' },
    pending_review: { label: 'Pending', variant: 'warning' },
    suspended: { label: 'Suspended', variant: 'critical' },
    expired: { label: 'Expired', variant: 'critical' },
    none: { label: '—', variant: 'neutral' },
};

export const CHECK_TYPE_BADGE: Record<string, { label: string; variant: Variant }> = {
    training_course: { label: 'Training', variant: 'info' },
    credential: { label: 'Credential', variant: 'info' },
    background_check: { label: 'Background', variant: 'warning' },
    policy_attestation: { label: 'Attestation', variant: 'neutral' },
    manual: { label: 'Manual', variant: 'neutral' },
};

export const RENEWAL_TYPE_BADGE: Record<string, { label: string; variant: Variant }> = {
    compliance: { label: 'Compliance', variant: 'info' },
    vetting: { label: 'Vetting', variant: 'warning' },
    driver: { label: 'Driver', variant: 'info' },
    training: { label: 'Training', variant: 'success' },
};

export function VettingChip({ status }: { status?: string | null }) {
    const b = VETTING_BADGE[status ?? 'none'] ?? VETTING_BADGE.none;
    return <StatusBadge variant={b.variant}>{b.label}</StatusBadge>;
}

export function DriverChip({ status }: { status?: string | null }) {
    const b = DRIVER_BADGE[status ?? 'none'] ?? DRIVER_BADGE.none;
    return <StatusBadge variant={b.variant}>{b.label}</StatusBadge>;
}

/** Overall compliance status pill from a per-staff status breakdown. */
export function complianceStatusBadge(row: {
    compliance_percent: number;
    expired_count: number;
    not_started_count: number;
}): { label: string; variant: Variant } {
    if (row.compliance_percent === 100) return { label: 'Compliant', variant: 'success' };
    if (row.expired_count > 0) return { label: 'Has expired', variant: 'critical' };
    if (row.not_started_count > 0) return { label: 'Incomplete', variant: 'neutral' };
    return { label: 'Expiring', variant: 'warning' };
}

export function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((p) => p[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

/* ------------------------------------------------------------------ */
/*  Conic compliance ring (% complete)                                 */
/* ------------------------------------------------------------------ */

export function ComplianceRing({ pct, size = 38 }: { pct: number; size?: number }) {
    const color =
        pct === 100
            ? 'var(--status-success)'
            : pct >= 70
              ? 'var(--status-warning)'
              : 'var(--status-critical)';
    const inner = size - 8;
    return (
        <span
            className="grid shrink-0 place-items-center rounded-full"
            style={{
                height: size,
                width: size,
                background: `conic-gradient(${color} ${pct * 3.6}deg, var(--muted) 0)`,
            }}
        >
            <span
                className="grid place-items-center rounded-full bg-card text-[11px] font-bold tabular-nums"
                style={{ height: inner, width: inner, color }}
            >
                {pct}
            </span>
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Avatar bubble                                                       */
/* ------------------------------------------------------------------ */

export function AvatarBubble({ name, size = 34 }: { name: string; size?: number }) {
    return (
        <span
            className="grid shrink-0 place-items-center rounded-full bg-accent font-bold text-primary"
            style={{ height: size, width: size, fontSize: size <= 30 ? 11 : 12 }}
        >
            {initials(name)}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Portal context menu (right-click + ⋮ menus)                        */
/* ------------------------------------------------------------------ */

export type CtxItem =
    | { sep: true }
    | {
          sep?: false;
          icon: LucideIcon;
          label: string;
          kbd?: string;
          tone?: 'critical' | 'success';
          onClick: () => void;
      };

export type CtxState = { x: number; y: number; items: CtxItem[] } | null;

export function useContextMenu(): {
    ctx: CtxState;
    open: (e: React.MouseEvent, items: CtxItem[]) => void;
    close: () => void;
} {
    const [ctx, setCtx] = useState<CtxState>(null);
    const open = useCallback((e: React.MouseEvent, items: CtxItem[]) => {
        e.preventDefault();
        const rows = items.length;
        const x = Math.min(e.clientX, window.innerWidth - 232);
        const y = Math.min(e.clientY, window.innerHeight - (rows * 38 + 16));
        setCtx({ x: Math.max(8, x), y: Math.max(8, y), items });
    }, []);
    const close = useCallback(() => setCtx(null), []);
    return { ctx, open, close };
}

export function ComplianceContextMenu({ ctx, onClose }: { ctx: CtxState; onClose: () => void }) {
    useEffect(() => {
        if (!ctx) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [ctx, onClose]);

    if (!ctx) return null;

    return createPortal(
        <>
            <div
                className="fixed inset-0 z-[80]"
                onClick={onClose}
                onContextMenu={(e) => {
                    e.preventDefault();
                    onClose();
                }}
            />
            <div
                role="menu"
                className="fixed z-[81] min-w-[212px] rounded-xl border border-border bg-popover p-1.5 shadow-[0_18px_40px_-12px_rgba(20,10,40,0.4)] animate-in fade-in-0 zoom-in-95 duration-100"
                style={{ left: ctx.x, top: ctx.y }}
            >
                {ctx.items.map((item, i) =>
                    'sep' in item && item.sep ? (
                        <div key={`sep-${i}`} className="my-1 h-px bg-border" />
                    ) : (
                        <button
                            key={item.label + i}
                            type="button"
                            role="menuitem"
                            onClick={() => {
                                onClose();
                                item.onClick();
                            }}
                            className={cn(
                                'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13px] font-medium transition-colors hover:bg-muted',
                                item.tone === 'critical' ? 'text-status-critical' : 'text-foreground',
                            )}
                        >
                            <item.icon
                                className={cn(
                                    'h-4 w-4 shrink-0',
                                    item.tone === 'critical'
                                        ? 'text-status-critical'
                                        : item.tone === 'success'
                                          ? 'text-status-success'
                                          : 'text-muted-foreground',
                                )}
                            />
                            <span className="flex-1">{item.label}</span>
                            {item.kbd ? (
                                <span className="rounded border border-border px-1 text-[10px] text-muted-foreground">
                                    {item.kbd}
                                </span>
                            ) : null}
                        </button>
                    ),
                )}
            </div>
        </>,
        document.body,
    );
}

/** Small helper to fire an Inertia download with the right filename. */
export function downloadExport(url: string, params: Record<string, string>) {
    const qs = new URLSearchParams(params).toString();
    window.location.href = `${url}?${qs}`;
}

export type { ReactNode };
