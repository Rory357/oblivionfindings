/* eslint-disable no-restricted-syntax -- Client-profile pattern language from the
 * redesign handoff (.design-drops/client-profile-redesign): MiniStat strips,
 * filter chips and the tab scaffold are bespoke styled-native surfaces; every
 * colour is a semantic design token. */
import { cn } from '@/lib/utils';
import type { ComponentType, ReactNode } from 'react';

export type ProfileTone =
    | 'success'
    | 'warning'
    | 'critical'
    | 'info'
    | 'neutral';

export const TONE_CLASS: Record<ProfileTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'bg-muted text-muted-foreground',
};

export const SEVERITY_TONE: Record<string, ProfileTone> = {
    critical: 'critical',
    high: 'warning',
    medium: 'info',
    low: 'success',
};

export type IconType = ComponentType<{ className?: string }>;

/** Stat tile used at the top of tab surfaces (2–4 per row). */
export function MiniStat({
    icon: Icon,
    label,
    value,
    tone = 'neutral',
    sub,
    onClick,
}: {
    icon: IconType;
    label: string;
    value: ReactNode;
    tone?: ProfileTone;
    sub?: ReactNode;
    onClick?: () => void;
}) {
    const Tag = onClick ? 'button' : 'div';
    return (
        <Tag
            type={onClick ? 'button' : undefined}
            onClick={onClick}
            className={cn(
                'flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 text-left',
                onClick && 'transition-colors hover:bg-accent/40',
            )}
        >
            <span
                className={cn(
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                    TONE_CLASS[tone],
                )}
            >
                <Icon className="h-[18px] w-[18px]" />
            </span>
            <div className="min-w-0 leading-tight">
                <div className="text-xl font-bold">{value}</div>
                <div className="truncate text-xs text-muted-foreground">
                    {label}
                </div>
            </div>
            {sub ? (
                <div className="ml-auto shrink-0 text-right text-[11px] text-muted-foreground">
                    {sub}
                </div>
            ) : null}
        </Tag>
    );
}

/** Pill filter row with counts; active pill uses the primary tone. */
export function FilterChips<T extends string>({
    value,
    onChange,
    options,
    className,
}: {
    value: T;
    onChange: (key: T) => void;
    options: Array<{ key: T; label: string; count?: number; icon?: IconType }>;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'scrollbar-none flex items-center gap-1.5 overflow-x-auto',
                className,
            )}
        >
            {options.map((o) => {
                const active = value === o.key;
                const Icon = o.icon;
                return (
                    <button
                        key={o.key}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(o.key)}
                        className={cn(
                            'inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition-colors',
                            active
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {Icon ? <Icon className="h-3.5 w-3.5" /> : null}
                        {o.label}
                        {o.count != null && o.count > 0 ? (
                            <span
                                className={cn(
                                    'rounded-full px-1.5 text-[10px] font-bold',
                                    active
                                        ? 'bg-primary-foreground/20'
                                        : 'bg-card',
                                )}
                            >
                                {o.count}
                            </span>
                        ) : null}
                    </button>
                );
            })}
        </div>
    );
}

/** Tab header scaffold — icon tile + title/sub on the left, primary CTA right. */
export function TabScaffold({
    icon: Icon,
    title,
    sub,
    action,
    children,
    dataTest,
}: {
    icon: IconType;
    title: string;
    sub?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    dataTest?: string;
}) {
    return (
        <div className="space-y-4" data-test={dataTest}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-primary">
                        <Icon className="h-[19px] w-[19px]" />
                    </span>
                    <div>
                        <h2 className="text-lg leading-tight font-semibold">
                            {title}
                        </h2>
                        {sub ? (
                            <p className="text-sm text-muted-foreground">
                                {sub}
                            </p>
                        ) : null}
                    </div>
                </div>
                {action}
            </div>
            {children}
        </div>
    );
}

/** Section label — small uppercase tracking label above grouped lists. */
export function SectionLabel({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <p
            className={cn(
                'text-[11px] font-semibold tracking-wider text-muted-foreground uppercase',
                className,
            )}
        >
            {children}
        </p>
    );
}
