/* Shared P09 compositions, copied from P08b v1.1 / P05 v1.1 / P01 v2 (src/ui.tsx):
 * every one is built from the app's real primitives (Alert, TierTwoTabs) with
 * semantic tokens only; safety surfaces use the fixed status pairs. */
import { TierTwoTabs, type GroupedProfileNavTab } from '@/components/page/grouped-profile-nav';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { cn } from '@/lib/utils';
import { AlertOctagon, AlertTriangle, CheckCircle2, Info, XCircle, type LucideIcon } from 'lucide-react';
import { Fragment, type ReactNode } from 'react';

/* ───────────── notices (Alert primitive, fixed status pairs — from P01) ───────────── */
export type Tone = 'info' | 'warning' | 'critical' | 'success' | 'neutral';
const TONE: Record<Tone, { cls: string; icon: LucideIcon }> = {
    info: { cls: 'border-status-info/30 bg-status-info-bg text-status-info', icon: Info },
    warning: { cls: 'border-status-warning/30 bg-status-warning-bg text-status-warning', icon: AlertTriangle },
    critical: { cls: 'border-status-critical/30 bg-status-critical-bg text-status-critical', icon: AlertOctagon },
    success: { cls: 'border-status-success/30 bg-status-success-bg text-status-success', icon: CheckCircle2 },
    neutral: { cls: 'border-border bg-muted/60 text-foreground', icon: Info },
};
export function Notice({
    tone,
    title,
    children,
    actions,
    icon,
    live,
    className,
}: {
    tone: Tone;
    title: ReactNode;
    children?: ReactNode;
    actions?: ReactNode;
    icon?: LucideIcon;
    live?: 'alert' | 'status' | 'note';
    className?: string;
}) {
    const t = TONE[tone];
    const Icon = icon ?? t.icon;
    return (
        <Alert role={live ?? (tone === 'critical' ? 'alert' : 'note')} className={cn(t.cls, className)}>
            <Icon />
            <AlertTitle className="line-clamp-none font-semibold">{title}</AlertTitle>
            {children || actions ? (
                <AlertDescription className="text-foreground/85">
                    {children}
                    {actions ? <div className="mt-2 flex flex-wrap gap-2">{actions}</div> : null}
                </AlertDescription>
            ) : null}
        </Alert>
    );
}

/* ───────────── key/value rows (from P01) ───────────── */
export function KV({ rows }: { rows: [ReactNode, ReactNode][] }) {
    return (
        <dl className="grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[170px_1fr]">
            {rows.map(([k, v], i) => (
                <Fragment key={i}>
                    <dt className="text-muted-foreground">{k}</dt>
                    <dd className="min-w-0">{v}</dd>
                </Fragment>
            ))}
        </dl>
    );
}

/* ───────────── tile picker (POPUP_STYLE_GUIDE "Type picker", from P01) ───────────── */
interface Tile {
    key: string;
    label: string;
    description: string;
    icon: LucideIcon;
    disabled?: string | null;
}
export function TilePicker({
    tiles,
    value,
    onChange,
    labelledBy,
    invalid,
}: {
    tiles: Tile[];
    value: string | null;
    onChange: (k: string) => void;
    labelledBy: string;
    invalid?: boolean;
}) {
    return (
        <div role="group" aria-labelledby={labelledBy} className="grid grid-cols-2 gap-2">
            {tiles.map((t) => {
                const Icon = t.icon;
                const active = value === t.key;
                return (
                    // eslint-disable-next-line no-restricted-syntax -- the tile picker markup is the POPUP_STYLE_GUIDE "Type picker" pattern verbatim
                    <button
                        key={t.key}
                        type="button"
                        data-tile={t.key}
                        aria-pressed={active}
                        aria-disabled={t.disabled ? true : undefined}
                        aria-invalid={invalid || undefined}
                        onClick={() => !t.disabled && onChange(t.key)}
                        className={cn(
                            'group frontline-tap flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all',
                            'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            t.disabled ? 'cursor-not-allowed border-border opacity-80' : 'hover:border-primary/50 hover:bg-card',
                            active ? 'border-primary bg-primary/10 ring-1 ring-primary/40' : invalid ? 'border-status-critical/60' : 'border-border',
                        )}
                    >
                        <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                            <Icon className={cn('h-4 w-4', active ? 'text-primary' : 'text-muted-foreground')} />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">{t.label}</span>
                            <span className="block text-xs text-muted-foreground">{t.description}</span>
                            {t.disabled ? (
                                <span className="mt-1 flex items-start gap-1 text-xs font-semibold text-status-critical">
                                    <XCircle className="mt-0.5 size-3 shrink-0" aria-hidden="true" /> {t.disabled}
                                </span>
                            ) : null}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

/* ───────────── design-note / mockup annotations (never product UI — from P01) ───────────── */
export function DesignNote({ title = 'Design note', children }: { title?: string; children: ReactNode }) {
    return (
        <aside className="rounded-xl border border-dashed border-border bg-card/60 p-3 text-sm text-muted-foreground" aria-label={title}>
            <p className="mb-1 text-[10px] font-bold tracking-[0.08em] uppercase">{title}</p>
            <div className="space-y-1">{children}</div>
        </aside>
    );
}

/** A plain line under a status badge (tone + icon, never colour alone). */
export function StateLine({ tone, icon: Icon, children }: { tone?: 'warning' | 'critical' | 'success'; icon?: LucideIcon; children: ReactNode }) {
    return (
        <span className={cn('flex items-start gap-1 text-[12px] leading-snug', tone === 'critical' ? 'font-semibold text-status-critical' : tone === 'warning' ? 'font-semibold text-status-warning' : tone === 'success' ? 'text-status-success' : 'text-muted-foreground')}>
            {Icon ? <Icon className="mt-0.5 size-3 shrink-0" aria-hidden="true" /> : null}
            <span>{children}</span>
        </span>
    );
}

/* ───────────── P02 v1’s tier-2 strip, copied unchanged ───────────── */
export function SubTabs({ tabs, active, onTab, hrefOf, label }: { tabs: GroupedProfileNavTab[]; active: string; onTab: (k: string) => void; hrefOf: (k: string) => string; label: string }) {
    return (
        <TierTwoTabs
            tabs={tabs}
            activeTab={active}
            onTab={onTab}
            testIdPrefix="p09-reports"
            ariaLabel={label}
            panelId="p09-reports-panel"
            renderLink={(entry, className, inner, a11y) => (
                <a
                    key={entry.key}
                    href={hrefOf(entry.key)}
                    className={className}
                    {...a11y}
                    onClick={(event) => {
                        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
                        event.preventDefault();
                        onTab(entry.key);
                    }}
                >
                    {inner}
                </a>
            )}
        />
    );
}

/** A long identity subline that wraps inside EntityTable's truncating subline slot (P11 v3 review: let it wrap). */
export const Wrap = ({ children }: { children: ReactNode }) => <span className="block whitespace-normal">{children}</span>;
