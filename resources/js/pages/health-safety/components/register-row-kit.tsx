/* Register row kit — the neutral, presentational ROW primitives shared by the
 * Health & Safety governance registers (Events, Corrective actions).
 *
 * Relocated out of the retiring `governance-register-kit` (prompt A4) so both
 * registers can adopt the gold-standard `hs-hero-kit` hero chrome (matching
 * `/incidents`, `/safeguarding`, `/fleet-assets/incidents`) while still sharing
 * one identical set of table rows — so they read as one product and can't drift
 * apart again. Hero / tab / footer chrome now comes from `hs-hero-kit` +
 * `@/components/rostering` (TabStrip, EntityFilter, ShiftContextMenu); this file
 * holds ONLY the row-level helpers. Semantic tokens only. NZ-only, web-only. */
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/** Semantic status tone used by severity / priority chips and dots. Matches the
 *  `Tone` union exported by `hs-hero-kit` so the two compose without casts. */
export type Tone = 'success' | 'warning' | 'critical' | 'neutral';

export const TONE_BG: Record<Tone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};

export const TONE_DOT: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

export function titleCase(s: string): string {
    return s.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const ENTITY_TONE = [
    'bg-primary text-primary-foreground',
    'bg-status-info text-primary-foreground',
    'bg-status-success text-primary-foreground',
    'bg-status-critical text-primary-foreground',
];

export function initials(label: string | null | undefined): string {
    if (!label) return 'HS';
    const parts = label.split(/\s+/).filter(Boolean);
    const text =
        parts.length > 1 ? `${parts[0][0]}${parts[1][0]}` : label.slice(0, 2);
    return text.toUpperCase();
}

/** Deterministic avatar tone keyed off a stable id so a row keeps its colour. */
export function entityTone(id: number): string {
    return ENTITY_TONE[id % ENTITY_TONE.length];
}

/** A compact, tinted flag chip used in the register tables' Flags / Governance
 *  columns (Overdue, Verify, No owner, parent-event stage, …). */
export function FlagBadge({
    icon: Icon,
    children,
    tone,
    title,
}: {
    icon: LucideIcon;
    children: ReactNode;
    tone: 'critical' | 'warning' | 'success' | 'info' | 'neutral';
    title: string;
}) {
    const cls =
        {
            critical: 'bg-status-critical-bg text-status-critical',
            warning: 'bg-status-warning-bg text-status-warning',
            success: 'bg-status-success-bg text-status-success',
            info: 'bg-status-info-bg text-status-info',
            neutral: 'bg-muted text-muted-foreground',
        }[tone] ?? 'bg-muted text-muted-foreground';

    return (
        <span
            title={title}
            className={`inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-bold whitespace-nowrap ${cls}`}
        >
            <Icon className="h-3 w-3" />
            {children}
        </span>
    );
}

/** Card-header strip shared by the register tables: an accent-tiled title plus an
 *  optional hint to the right (e.g. "Right-click or ⋮ for the full lifecycle"). */
export function RegisterTableHeader({
    icon: Icon,
    title,
    subtitle,
    hint,
    hintIcon: HintIcon,
}: {
    icon: LucideIcon;
    title: string;
    subtitle?: string;
    hint?: string;
    hintIcon?: LucideIcon;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3 md:px-5">
            <div className="flex items-center gap-2.5">
                <span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary">
                    <Icon className="h-4 w-4" />
                </span>
                <div className="flex flex-wrap items-baseline gap-1.5">
                    <h2 className="text-sm font-bold text-foreground">
                        {title}
                    </h2>
                    {subtitle ? (
                        <span className="text-xs font-semibold text-muted-foreground">
                            · {subtitle}
                        </span>
                    ) : null}
                </div>
            </div>
            {hint ? (
                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    {HintIcon ? <HintIcon className="h-3.5 w-3.5" /> : null}
                    {hint}
                </span>
            ) : null}
        </div>
    );
}
