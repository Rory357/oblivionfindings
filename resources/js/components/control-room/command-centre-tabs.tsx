import { TabStrip, type RosterTabItem } from '@/components/rostering/tab-strip';
import { router } from '@inertiajs/react';
import { AlertOctagon, ArrowUpCircle, Bell, LayoutDashboard } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * The Control Room command centre is ONE workspace across four routes —
 * Overview, Alerts, Escalations and Incidents. This strip renders under each
 * page's hero so the four surfaces read (and navigate) as tabs of a single
 * command centre. Tab ids are the routes themselves.
 */
export type CommandCentreTab =
    | '/control-room'
    | '/control-room/alerts'
    | '/control-room/escalations'
    | '/control-room/incidents';

const TABS: RosterTabItem[] = [
    { id: '/control-room', label: 'Overview', icon: LayoutDashboard, tone: 'primary' },
    { id: '/control-room/alerts', label: 'Alerts', icon: Bell, tone: 'critical' },
    { id: '/control-room/escalations', label: 'Escalations', icon: ArrowUpCircle, tone: 'warning' },
    { id: '/control-room/incidents', label: 'Incidents', icon: AlertOctagon, tone: 'info' },
];

export function CommandCentreTabs({
    current,
    badges,
    className,
}: {
    current: CommandCentreTab;
    /** Optional per-tab count chips, keyed by route id. */
    badges?: Partial<Record<CommandCentreTab, ReactNode>>;
    className?: string;
}) {
    return (
        <TabStrip
            value={current}
            onChange={(id) => {
                if (id !== current) router.visit(id);
            }}
            items={TABS.map((t) => ({ ...t, badge: badges?.[t.id as CommandCentreTab] }))}
            ariaLabel="Command centre views"
            className={className}
        />
    );
}
