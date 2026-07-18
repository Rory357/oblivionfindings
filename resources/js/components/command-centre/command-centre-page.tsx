import {
    WorkspaceStrip,
    type WorkspaceRoute,
} from '@/components/command-centre/workspace-strip';
import {
    ControlRoomWorkspaceHero,
    type ControlRoomHeroMetricGroup,
} from '@/components/control-room/control-room-workspace-hero';
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
    metricGroups,
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
    metricGroups?: readonly ControlRoomHeroMetricGroup[];
    badges?: Partial<Record<WorkspaceRoute, ReactNode>>;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('space-y-5', className)}>
            <ControlRoomWorkspaceHero
                variant={variant}
                icon={icon}
                title={title}
                description={description}
                status={status}
                freshness={freshness}
                actions={actions}
                workflow={workflow}
                footer={footer}
                metricGroups={metricGroups}
            />

            <WorkspaceStrip current={current} badges={badges} />

            {children}
        </div>
    );
}
