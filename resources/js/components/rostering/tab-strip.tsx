import type { ComponentType, KeyboardEvent, ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type RosterTabTone =
    | 'primary'
    | 'warning'
    | 'success'
    | 'info'
    | 'violet'
    | 'critical';

export type RosterTabItem = {
    id: string;
    label: string;
    icon: ComponentType<{ className?: string }>;
    tone: RosterTabTone;
    badge?: ReactNode;
};

const TONE_ACTIVE: Record<RosterTabTone, string> = {
    primary:
        'bg-primary/10 text-primary [&_.chip]:bg-primary [&_.chip]:text-primary-foreground [&_.underline-bar]:bg-primary',
    warning:
        'bg-status-warning-bg text-status-warning [&_.chip]:bg-status-warning [&_.chip]:text-white [&_.underline-bar]:bg-status-warning',
    success:
        'bg-status-success-bg text-status-success [&_.chip]:bg-status-success [&_.chip]:text-white [&_.underline-bar]:bg-status-success',
    info:
        'bg-status-info-bg text-status-info [&_.chip]:bg-status-info [&_.chip]:text-white [&_.underline-bar]:bg-status-info',
    violet:
        'bg-primary/10 text-primary [&_.chip]:bg-primary [&_.chip]:text-primary-foreground [&_.underline-bar]:bg-primary',
    critical:
        'bg-status-critical-bg text-status-critical [&_.chip]:bg-status-critical [&_.chip]:text-white [&_.underline-bar]:bg-status-critical',
};

export function TabStrip({
    value,
    onChange,
    items,
    className,
}: {
    value: string;
    onChange: (next: string) => void;
    items: RosterTabItem[];
    className?: string;
}) {
    const handleKeyDown = (
        event: KeyboardEvent<HTMLButtonElement>,
        tabId: string,
    ) => {
        const currentIndex = items.findIndex((item) => item.id === tabId);
        if (currentIndex < 0) {
            return;
        }

        let nextIndex: number | null = null;
        if (event.key === 'ArrowRight') {
            nextIndex = (currentIndex + 1) % items.length;
        } else if (event.key === 'ArrowLeft') {
            nextIndex = (currentIndex - 1 + items.length) % items.length;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = items.length - 1;
        }

        if (nextIndex == null) {
            return;
        }

        event.preventDefault();
        const next = items[nextIndex];
        onChange(next.id);
        const tabs =
            event.currentTarget.parentElement?.querySelectorAll<HTMLButtonElement>(
                '[role="tab"]',
            );
        tabs?.[nextIndex]?.focus();
    };

    return (
        <div
            role="tablist"
            aria-label="Roster views"
            className={cn(
                'flex flex-wrap items-center gap-1 rounded-[14px] border border-border bg-card p-1.5 shadow-sm',
                className,
            )}
        >
            {items.map((t) => {
                const active = value === t.id;
                const Icon = t.icon;
                return (
                    <button
                        key={t.id}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(t.id)}
                        onKeyDown={(event) => handleKeyDown(event, t.id)}
                        className={cn(
                            'relative inline-flex items-center gap-2 rounded-[9px] px-3 py-2 text-[13px] font-semibold transition-colors',
                            active
                                ? TONE_ACTIVE[t.tone]
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        )}
                    >
                        <span
                            className={cn(
                                'chip inline-flex h-[22px] w-[22px] items-center justify-center rounded-md text-foreground',
                                !active && 'bg-muted text-muted-foreground',
                            )}
                        >
                            <Icon className="h-3.5 w-3.5" />
                        </span>
                        <span>{t.label}</span>
                        {t.badge != null ? (
                            <span className="ml-0.5 inline-flex items-center rounded-full bg-background/60 px-1.5 py-0.5 text-[10px] font-bold tabular-nums">
                                {t.badge}
                            </span>
                        ) : null}
                        {active ? (
                            <span
                                className="underline-bar absolute inset-x-3.5 -bottom-px h-0.5 rounded"
                                aria-hidden="true"
                            />
                        ) : null}
                    </button>
                );
            })}
        </div>
    );
}

export default TabStrip;
