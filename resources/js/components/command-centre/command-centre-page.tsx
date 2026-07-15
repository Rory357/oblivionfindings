import {
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/components/command-centre/hero-kit';
import {
    WorkspaceStrip,
    type WorkspaceRoute,
} from '@/components/command-centre/workspace-strip';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export function CommandCentrePage({
    variant = 'full',
    current,
    icon,
    title,
    description,
    status = 'Control Room workspace',
    freshness,
    actions,
    workflow,
    footer,
    badges,
    children,
    className,
}: {
    variant?: 'full' | 'compact';
    current: string;
    icon: LucideIcon;
    title: string;
    description: string;
    status?: string;
    freshness?: string;
    actions?: ReactNode;
    workflow?: ReactNode;
    footer?: ReactNode;
    badges?: Partial<Record<WorkspaceRoute, ReactNode>>;
    children: ReactNode;
    className?: string;
}) {
    const Icon = icon;

    return (
        <div className={cn('space-y-5', className)}>
            <section aria-labelledby="command-centre-page-title">
                <HeroShell footer={footer}>
                    {workflow}
                    <div
                        className={cn(
                            'flex items-center justify-between gap-8',
                            variant === 'full' ? 'min-h-36' : 'min-h-24',
                        )}
                    >
                        <div className="flex min-w-0 items-start gap-4">
                            <HeroMedallion icon={Icon} />
                            <div className="min-w-0 space-y-3">
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
                                        id="command-centre-page-title"
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
                            </div>
                        </div>
                        {actions ? (
                            <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                {actions}
                            </div>
                        ) : null}
                    </div>
                </HeroShell>
            </section>

            <WorkspaceStrip current={current} badges={badges} />

            {children}
        </div>
    );
}
