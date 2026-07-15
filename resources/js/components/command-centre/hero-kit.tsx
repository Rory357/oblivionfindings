import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type LucideIcon, Sparkles } from 'lucide-react';
import { type ReactNode } from 'react';

export type Tone = 'success' | 'warning' | 'critical' | 'neutral';

export const DOT_CLASS: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-primary-foreground/50',
};

const DELTA_TEXT: Record<Tone, string> = {
    success: 'text-status-success',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    neutral: 'text-primary-foreground/70',
};

export function fmt(value: number | null | undefined, suffix = ''): string {
    return value === null || value === undefined ? '—' : `${value}${suffix}`;
}

export function HeroShell({
    children,
    footer,
}: {
    children: ReactNode;
    footer?: ReactNode;
}) {
    return (
        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 text-primary-foreground shadow-[0_24px_60px_-28px_color-mix(in_oklch,var(--primary)_55%,transparent)]">
            <div
                className="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl"
                aria-hidden
            >
                <div className="absolute -top-16 -right-16 h-64 w-64 rounded-full bg-primary-foreground/5" />
                <div className="absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-primary-foreground/5" />
                <div className="absolute top-1/4 right-1/3 h-24 w-24 rounded-full bg-primary-foreground/5" />
            </div>
            <div className="relative flex flex-col gap-5 p-6 md:p-7">
                {children}
            </div>
            {footer != null ? (
                <div className="relative flex flex-col gap-3 border-t border-primary-foreground/15 px-6 py-3 md:px-7">
                    {footer}
                </div>
            ) : null}
        </div>
    );
}

export function HeroStatusPill({ children }: { children: ReactNode }) {
    return (
        <div className="inline-flex items-center gap-1.5 self-start rounded-full bg-primary-foreground/15 px-2.5 py-1 text-[11px] font-semibold tracking-[0.07em] text-primary-foreground/85 uppercase">
            <span className="relative flex h-2 w-2" aria-hidden>
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-70 motion-reduce:animate-none" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
            </span>
            {children}
        </div>
    );
}

export function HeroMedallion({ icon: Icon }: { icon: LucideIcon }) {
    return (
        <div
            className="hidden h-[72px] w-[72px] shrink-0 items-center justify-center rounded-full border-4 border-primary-foreground/20 bg-primary-foreground/10 shadow-xl sm:flex md:h-20 md:w-20"
            aria-hidden
        >
            <Icon className="h-9 w-9 text-primary-foreground md:h-10 md:w-10" />
        </div>
    );
}

export function HeroClusterTile({
    href,
    label,
    value,
    caption,
    tone,
    delta,
    deltaTone = 'neutral',
}: {
    href?: string;
    label: string;
    value: string;
    caption: string;
    tone: Tone;
    delta?: string;
    deltaTone?: Tone;
}) {
    const base =
        'flex flex-col gap-0.5 rounded-xl border border-primary-foreground/15 bg-primary-foreground/10 px-3 py-2.5 text-left';
    const inner = (
        <>
            <span className="flex items-center gap-1.5">
                <span
                    className={cn(
                        'h-1.5 w-1.5 shrink-0 rounded-full',
                        DOT_CLASS[tone],
                    )}
                    aria-hidden
                />
                <span className="text-[10.5px] font-semibold tracking-wide text-primary-foreground/70 uppercase">
                    {label}
                </span>
            </span>
            <span className="text-[25px] leading-tight font-bold text-primary-foreground tabular-nums">
                {value}
            </span>
            {delta ? (
                <span
                    className={cn(
                        'text-[10.5px] font-semibold',
                        DELTA_TEXT[deltaTone],
                    )}
                >
                    {delta}
                </span>
            ) : null}
            <span className="text-[10.5px] text-primary-foreground/60">
                {caption}
            </span>
        </>
    );

    return href ? (
        <Link
            href={href}
            className={cn(
                base,
                'transition-colors hover:bg-primary-foreground/20',
            )}
        >
            {inner}
        </Link>
    ) : (
        <div className={base}>{inner}</div>
    );
}

const CLUSTER_COLUMNS: Record<number, string> = {
    2: 'grid grid-cols-2 gap-2',
    3: 'grid grid-cols-2 gap-2 sm:grid-cols-3',
    4: 'grid grid-cols-2 gap-2 sm:grid-cols-4',
};

export function HeroCluster({
    title,
    icon: Icon,
    children,
    columns = 4,
}: {
    title: string;
    icon: LucideIcon;
    children: ReactNode;
    columns?: number;
}) {
    return (
        <div className="rounded-2xl border border-primary-foreground/15 bg-primary-foreground/5 p-3">
            <div className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                <Icon className="h-3.5 w-3.5" aria-hidden />
                {title}
            </div>
            <div className={CLUSTER_COLUMNS[columns] ?? CLUSTER_COLUMNS[4]}>
                {children}
            </div>
        </div>
    );
}

const PILL_BASE =
    'rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/40';
const PILL_ACTIVE = 'bg-primary-foreground/25 text-primary-foreground';
const PILL_INACTIVE =
    'bg-primary-foreground/10 text-primary-foreground/80 hover:bg-primary-foreground/20';
const SEG_BASE =
    'rounded-md px-2.5 py-1 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/40';
const SEG_ACTIVE = 'bg-primary-foreground text-primary';
const SEG_INACTIVE = 'text-primary-foreground/80 hover:text-primary-foreground';

export type HeroSegItem = {
    key: string;
    label: string;
    popover?: ReactNode;
};

export function HeroSegmented({
    label,
    items,
    value,
    onChange,
    ariaLabel,
    variant = 'segmented',
}: {
    label?: string;
    items: readonly HeroSegItem[];
    value: string;
    onChange: (key: string) => void;
    ariaLabel: string;
    variant?: 'pill' | 'segmented';
}) {
    if (variant === 'pill') {
        return (
            <div
                role="group"
                aria-label={ariaLabel}
                className="flex flex-wrap items-center gap-1.5"
            >
                {label ? (
                    <span className="mr-1 text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                        {label}
                    </span>
                ) : null}
                {items.map((item) => {
                    const active = value === item.key;
                    const className = cn(
                        PILL_BASE,
                        active ? PILL_ACTIVE : PILL_INACTIVE,
                    );
                    if (item.popover) {
                        return (
                            <Popover key={item.key}>
                                <PopoverTrigger asChild>
                                    {/* eslint-disable-next-line no-restricted-syntax -- compact segmented control on the dark hero. */}
                                    <button
                                        type="button"
                                        aria-pressed={active}
                                        className={className}
                                    >
                                        {item.label}
                                    </button>
                                </PopoverTrigger>
                                <PopoverContent
                                    align="start"
                                    className="w-auto space-y-2 p-3"
                                >
                                    {item.popover}
                                </PopoverContent>
                            </Popover>
                        );
                    }
                    return (
                        // eslint-disable-next-line no-restricted-syntax -- compact segmented control on the dark hero.
                        <button
                            key={item.key}
                            type="button"
                            aria-pressed={active}
                            onClick={() => onChange(item.key)}
                            className={className}
                        >
                            {item.label}
                        </button>
                    );
                })}
            </div>
        );
    }

    return (
        <>
            {label ? (
                <span className="ml-1 text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                    {label}
                </span>
            ) : null}
            <div
                role="group"
                aria-label={ariaLabel}
                className="inline-flex items-center gap-0.5 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 p-0.5"
            >
                {items.map((item) => {
                    const active = value === item.key;
                    return (
                        // eslint-disable-next-line no-restricted-syntax -- compact segmented control on the dark hero.
                        <button
                            key={item.key}
                            type="button"
                            aria-pressed={active}
                            onClick={() => onChange(item.key)}
                            className={cn(
                                SEG_BASE,
                                active ? SEG_ACTIVE : SEG_INACTIVE,
                            )}
                        >
                            {item.label}
                        </button>
                    );
                })}
            </div>
        </>
    );
}

export function HeroSummaryMetric({
    tone,
    children,
}: {
    tone: Tone;
    children: ReactNode;
}) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span
                className={cn('h-1.5 w-1.5 rounded-full', DOT_CLASS[tone])}
                aria-hidden
            />
            {children}
        </span>
    );
}

export function HeroSummaryStrip({
    label,
    children,
    collapsed = false,
    onToggle,
    toggleLabel = 'summary',
}: {
    label?: string;
    children: ReactNode;
    collapsed?: boolean;
    onToggle?: () => void;
    toggleLabel?: string;
}) {
    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-primary-foreground/15 pt-2.5 text-xs text-primary-foreground/80">
            {label ? (
                <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                    {label}
                </span>
            ) : null}
            {collapsed ? null : children}
            {onToggle ? (
                // eslint-disable-next-line no-restricted-syntax -- compact on-dark disclosure control in the hero summary.
                <button
                    type="button"
                    onClick={onToggle}
                    aria-pressed={!collapsed}
                    className="ml-auto inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                >
                    <Sparkles className="h-3 w-3" aria-hidden />{' '}
                    {collapsed ? 'Show' : 'Hide'} {toggleLabel}
                </button>
            ) : null}
        </div>
    );
}
