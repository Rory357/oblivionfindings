import {
    HeroCluster,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
    type Tone,
} from '@/components/command-centre/hero-kit';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import { type ReactNode, useId } from 'react';

export type ControlRoomHeroMetric = {
    label: string;
    value: string;
    caption: string;
    tone: Tone;
    href?: string;
    delta?: string;
    deltaTone?: Tone;
};

export type ControlRoomHeroMetricGroup = {
    title: string;
    icon: LucideIcon;
    metrics: readonly ControlRoomHeroMetric[];
};

export function ControlRoomWorkspaceHero({
    variant = 'full',
    icon,
    title,
    description,
    status = 'Control Room workspace',
    freshness,
    actions,
    workflow,
    footer,
    metricGroups = [],
}: {
    variant?: 'full' | 'compact';
    icon: LucideIcon;
    title: string;
    description: string;
    status?: string;
    freshness?: string;
    actions?: ReactNode;
    workflow?: ReactNode;
    footer?: ReactNode;
    metricGroups?: readonly ControlRoomHeroMetricGroup[];
}) {
    const titleId = useId();
    const hasMetrics = metricGroups.length > 0;

    return (
        <section aria-labelledby={titleId}>
            <HeroShell footer={footer}>
                {workflow}
                <div
                    data-testid="control-room-hero"
                    className={cn(
                        'min-h-[10rem] gap-5',
                        hasMetrics
                            ? 'grid lg:grid-cols-[minmax(16rem,0.8fr)_minmax(26rem,1.2fr)] lg:items-center'
                            : 'flex flex-col justify-center',
                    )}
                >
                    <div className="flex min-w-0 items-start gap-4">
                        <HeroMedallion icon={icon} />
                        <div className="min-w-0 flex-1 space-y-3">
                            <div className="flex flex-wrap items-center gap-3">
                                <HeroStatusPill>{status}</HeroStatusPill>
                                {freshness ? (
                                    <span className="text-xs font-medium text-primary-foreground/70">
                                        {freshness}
                                    </span>
                                ) : null}
                            </div>
                            <div>
                                <h1
                                    id={titleId}
                                    className={cn(
                                        'font-bold tracking-tight',
                                        variant === 'full'
                                            ? 'text-3xl'
                                            : 'text-2xl',
                                    )}
                                >
                                    {title}
                                </h1>
                                <p className="mt-1 max-w-3xl text-sm text-primary-foreground/75">
                                    {description}
                                </p>
                            </div>
                            {actions ? (
                                <div className="flex flex-wrap items-center gap-2 pt-1">
                                    {actions}
                                </div>
                            ) : null}
                        </div>
                    </div>

                    {hasMetrics ? (
                        <div
                            className={cn(
                                'grid gap-3',
                                metricGroups.length > 1
                                    ? 'xl:grid-cols-2'
                                    : 'grid-cols-1',
                            )}
                        >
                            {metricGroups.map((group) => (
                                <HeroCluster
                                    key={group.title}
                                    title={group.title}
                                    icon={group.icon}
                                    columns={Math.min(
                                        4,
                                        Math.max(2, group.metrics.length),
                                    )}
                                >
                                    {group.metrics.map((metric) => (
                                        <HeroClusterTile
                                            key={metric.label}
                                            {...metric}
                                        />
                                    ))}
                                </HeroCluster>
                            ))}
                        </div>
                    ) : null}
                </div>
            </HeroShell>
        </section>
    );
}
