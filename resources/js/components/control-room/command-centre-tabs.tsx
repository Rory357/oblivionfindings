import {
    WorkspaceStrip,
    type WorkspaceRoute,
} from '@/components/command-centre/workspace-strip';
import type { ReactNode } from 'react';

/**
 * Compatibility wrapper for the Desk, Active alerts, Escalations, and Safety
 * handovers pages migrated before the six-destination workspace strip landed.
 */
export type CommandCentreTab = WorkspaceRoute;

export function CommandCentreTabs({
    current,
    badges,
    className,
}: {
    current: CommandCentreTab;
    badges?: Partial<Record<CommandCentreTab, ReactNode>>;
    className?: string;
}) {
    return (
        <WorkspaceStrip
            current={current}
            badges={badges}
            className={className}
        />
    );
}
