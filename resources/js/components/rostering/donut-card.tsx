import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

import { Donut, DonutLegend, type DonutSegment } from './donut';

export type DonutCardTone = 'primary' | 'warning' | 'success';

export type DonutCardProps = {
    tone: DonutCardTone;
    title: string;
    subtitle: string;
    segments: DonutSegment[];
    centerValue: string | number;
    centerLabel: string;
    accentKeys?: string[];
    active: boolean;
    cta: string;
    onClick: () => void;
    ariaControls?: string;
};

const TONE_STYLES: Record<
    DonutCardTone,
    {
        bar: string;
        activeBorder: string;
        activeRing: string;
    }
> = {
    primary: {
        bar: 'bg-primary/55 group-hover:bg-primary group-data-[active=true]:bg-primary',
        activeBorder: 'data-[active=true]:border-primary/60',
        activeRing: 'data-[active=true]:ring-primary/15',
    },
    warning: {
        bar: 'bg-status-warning/55 group-hover:bg-status-warning group-data-[active=true]:bg-status-warning',
        activeBorder: 'data-[active=true]:border-status-warning/60',
        activeRing: 'data-[active=true]:ring-status-warning/15',
    },
    success: {
        bar: 'bg-status-success/55 group-hover:bg-status-success group-data-[active=true]:bg-status-success',
        activeBorder: 'data-[active=true]:border-status-success/60',
        activeRing: 'data-[active=true]:ring-status-success/15',
    },
};

export function DonutCard({
    tone,
    title,
    subtitle,
    segments,
    centerValue,
    centerLabel,
    accentKeys,
    active,
    cta,
    onClick,
    ariaControls,
}: DonutCardProps): ReactNode {
    const t = TONE_STYLES[tone];
    return (
        <button
            type="button"
            role="tab"
            aria-selected={active}
            aria-controls={ariaControls}
            data-active={active}
            onClick={onClick}
            className={cn(
                'group relative overflow-hidden rounded-[14px] border border-border bg-card p-4 pl-5 text-left transition',
                'hover:-translate-y-px hover:shadow-sm',
                'data-[active=true]:ring-4',
                t.activeBorder,
                t.activeRing,
                'cursor-pointer',
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'absolute inset-y-0 left-0 w-1 rounded-l-[14px] transition-colors',
                    t.bar,
                )}
            />
            <div className="grid grid-cols-[132px_1fr] items-center gap-4">
                <Donut
                    segments={segments}
                    centerValue={centerValue}
                    centerLabel={centerLabel}
                />
                <div className="min-w-0">
                    <div className="text-sm font-bold tracking-tight text-muted-foreground">
                        {title}
                    </div>
                    <div className="mb-2 text-xs text-muted-foreground/70">
                        {subtitle}
                    </div>
                    <DonutLegend segments={segments} accentKeys={accentKeys} />
                    <div className="mt-3 flex items-center gap-1 text-xs font-semibold text-muted-foreground transition-colors group-hover:text-foreground">
                        <span>{cta}</span>
                        <span
                            aria-hidden="true"
                            className="transition-transform group-hover:translate-x-1"
                        >
                            →
                        </span>
                    </div>
                </div>
            </div>
        </button>
    );
}

export default DonutCard;
