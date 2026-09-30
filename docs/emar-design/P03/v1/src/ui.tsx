/* Shared P03 compositions, copied from P08a v1 / P07a v1 / P01 v2 (src/ui.tsx) where
 * they exist there: every one is built from the app's real primitives (Alert,
 * StatusBadge, EntityChip, PersonDisc) with semantic tokens only; safety
 * surfaces use the fixed status pairs. */
import { EntityChip, PersonDisc } from '@/components/lists/entity-cells';
import { TierTwoTabs, type GroupedProfileNavTab } from '@/components/page/grouped-profile-nav';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import {
    AlertOctagon,
    AlertTriangle,
    CheckCircle2,
    Clock3,
    Flag,
    Hand,
    Info,
    MessageSquare,
    UserCheck,
    CircleDashed,
    LockKeyhole,
    Save,
    Settings2,
    ShieldCheck,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { Fragment, type ReactNode } from 'react';
import { PEOPLE, SUPPORT, type Support } from './data';
import { STATE_LABEL, type PlanState } from './model';

/* ───────────── "Not configured" (P00 pattern, from P01) ───────────── */
export function NotConfigured({ label = 'Not configured' }: { label?: string }) {
    return (
        <StatusBadge variant="neutral" className="rounded-[8px] align-middle" title="Organisation value not decided yet">
            <Settings2 className="size-3" />
            {label}
        </StatusBadge>
    );
}

/** Renders {NC} as the Not configured chip and **x** as bold. */
export function Rich({ text }: { text: string }) {
    const parts = text.split(/(\{NC\}|\*\*[^*]+\*\*)/g);
    return (
        <>
            {parts.map((part, i) =>
                part === '{NC}' ? (
                    <NotConfigured key={i} />
                ) : part.startsWith('**') ? (
                    <strong key={i} className="font-semibold">
                        {part.slice(2, -2)}
                    </strong>
                ) : (
                    <Fragment key={i}>{part}</Fragment>
                ),
            )}
        </>
    );
}

/* ───────────── notices (Alert primitive, fixed status pairs — from P01) ───────────── */
export type Tone = 'info' | 'warning' | 'critical' | 'success' | 'neutral';
export const TONE: Record<Tone, { cls: string; icon: LucideIcon }> = {
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

/* ───────────── person mark (initials; photos are P01/P02) ───────────── */
export function PersonMark({ pid, size = 30 }: { pid: string; size?: number }) {
    return <PersonDisc name={PEOPLE[pid].legal} size={size} />;
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
export interface Tile {
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

/** P02’s approved rule for cross-person lists: controlled rows are left out for roles without
 *  controlled-medicine view, and the caption counts them (a redacted row would still show who). */
export function ConcealedCount({ n, noun = 'medicine', children }: { n?: number; noun?: string; children?: ReactNode }) {
    if (!n && !children) return null;
    return (
        <span className="inline-flex items-center gap-1">
            <LockKeyhole className="size-3" aria-hidden="true" /> {children ?? `${n} controlled ${noun}${n === 1 ? '' : 's'} not shown — needs controlled-medicine access`}
        </span>
    );
}

/* ───────────── the support chip (P01 v2 SupportChip, with the P03 label for the fourth word) ───────────── */
const SUPPORT_ICON: Record<Support, LucideIcon> = { selfmanaged: UserCheck, prompt: MessageSquare, assist: Hand, administer: Hand };
export function SupportChip({ support }: { support: Support | null }) {
    if (!support)
        return (
            <span title="No support set yet — staff give it">
                <EntityChip icon={CircleDashed}>Not set</EntityChip>
            </span>
        );
    const s = SUPPORT[support];
    return (
        <span title={s.desc}>
            <EntityChip icon={SUPPORT_ICON[support]}>{s.label}</EntityChip>
        </span>
    );
}

/* ───────────── plan state badge ───────────── */
export function PlanBadge({ state }: { state: PlanState }) {
    const b = STATE_LABEL[state];
    const Icon = state === 'current' ? CheckCircle2 : state === 'soon' ? Clock3 : AlertTriangle;
    return (
        <StatusBadge variant={b.variant} className="rounded-[8px] font-semibold">
            <Icon className="size-3" aria-hidden="true" />
            {b.label}
        </StatusBadge>
    );
}

/* ───────────── controlled-medicine concealment (P02 v1 / P11 v4 wording) ───────────── */
export const CONCEALED = {
    name: 'Controlled medicine',
    subline: 'Details need controlled-medicine access',
    ask: 'The house lead or Rangi Parata can tell you more.',
};

/* ───────────── P02 v1 compositions, copied unchanged (tier-2 strip, section card, fact strip) ───────────── */
export function SubTabs({ tabs, active, onTab, hrefOf, label }: { tabs: GroupedProfileNavTab[]; active: string; onTab: (k: string) => void; hrefOf: (k: string) => string; label: string }) {
    return (
        <TierTwoTabs
            tabs={tabs}
            activeTab={active}
            onTab={onTab}
            testIdPrefix="p03-record"
            ariaLabel={label}
            panelId="p03-record-panel"
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

/* ───────────── a titled card section (Fleet record body cards) ───────────── */
export function SectionCard({ eyebrow, title, right, children, className, icon: Icon }: { eyebrow?: string; title: ReactNode; right?: ReactNode; children: ReactNode; className?: string; icon?: LucideIcon }) {
    return (
        <Card className={cn('gap-3 p-5', className)}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    {Icon ? (
                        <span className="grid size-9 shrink-0 place-items-center rounded-[10px] bg-primary/10 text-primary">
                            <Icon className="size-4" aria-hidden="true" />
                        </span>
                    ) : null}
                    <div className="min-w-0">
                        {eyebrow ? <p className="text-[10px] font-semibold tracking-[0.08em] text-muted-foreground uppercase">{eyebrow}</p> : null}
                        <h2 className="text-section-title">{title}</h2>
                    </div>
                </div>
                {right ? <div className="flex flex-wrap items-center gap-2">{right}</div> : null}
            </div>
            {children}
        </Card>
    );
}

/** Small key/value facts row (Fleet "Open work / Next check / Latest mileage" footer). */
export function FactStrip({ items }: { items: { label: string; value: ReactNode; onClick?: () => void; aria?: string }[] }) {
    return (
        <div className="grid divide-y rounded-lg border sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            {items.map((it) => {
                const body = (
                    <>
                        <span className="block text-[11px] text-muted-foreground">{it.label}</span>
                        <span className="block text-[13px] font-semibold">{it.value}</span>
                    </>
                );
                return it.onClick ? (
                    <Button key={it.label} variant="ghost" className="h-auto flex-col items-start justify-start rounded-none px-3 py-2.5 text-left" onClick={it.onClick} aria-label={it.aria}>
                        {body}
                    </Button>
                ) : (
                    <div key={it.label} className="px-3 py-2.5">
                        {body}
                    </div>
                );
            })}
        </div>
    );
}
/** P02 v1’s redacted identity for a controlled medicine inside the person’s own record. */
export function ConcealedIdentity({ compact = false }: { compact?: boolean }) {
    return (
        <span className="flex min-w-0 items-start gap-2.5">
            <span className="mt-0.5 grid size-[30px] shrink-0 place-items-center rounded-[8px] bg-muted text-muted-foreground">
                <LockKeyhole className="size-4" aria-hidden="true" />
            </span>
            <span className="min-w-0">
                <span className="block text-[13px] font-semibold">{CONCEALED.name}</span>
                <span className="block text-[11.5px] text-muted-foreground">{compact ? CONCEALED.subline : `${CONCEALED.subline}. ${CONCEALED.ask}`}</span>
            </span>
        </span>
    );
}
