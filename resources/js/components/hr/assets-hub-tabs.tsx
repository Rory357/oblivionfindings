import { Boxes, LayoutDashboard, UserCheck, Wrench } from 'lucide-react';

import { HrTabs, type HrTabItem } from '@/components/hr/hr-tabs';

export type AssetHubTab =
    | 'overview'
    | 'inventory'
    | 'assignments'
    | 'maintenance';

/**
 * The Asset Management hub tab strip — Overview · Inventory · Assignments ·
 * Maintenance & Docs. Tabs switch client-side (single hub payload) and sync to
 * `?tab=` via the page's useHrTab hook, mirroring the Leave hub chrome.
 */
export function AssetsHubTabs({
    active,
    onChange,
    counts,
    onItemContextMenu,
}: {
    active: AssetHubTab;
    onChange: (tab: AssetHubTab) => void;
    counts?: { inventory?: number; overdue?: number; maintenance?: number };
    onItemContextMenu?: (id: string, event: React.MouseEvent) => void;
}) {
    const items: HrTabItem[] = [
        { id: 'overview', label: 'Overview', icon: LayoutDashboard, tone: 'primary' },
        {
            id: 'inventory',
            label: 'Inventory',
            icon: Boxes,
            tone: 'info',
            badge: counts?.inventory ? counts.inventory : undefined,
        },
        {
            id: 'assignments',
            label: 'Assignments',
            icon: UserCheck,
            tone: 'violet',
            badge: counts?.overdue ? counts.overdue : undefined,
        },
        {
            id: 'maintenance',
            label: 'Maintenance & Docs',
            icon: Wrench,
            tone: 'warning',
            badge: counts?.maintenance ? counts.maintenance : undefined,
        },
    ];

    return (
        <HrTabs
            value={active}
            onChange={(id) => onChange(id as AssetHubTab)}
            items={items}
            ariaLabel="Asset views"
            className="mb-6"
            onItemContextMenu={onItemContextMenu}
            trailing={
                <span className="ml-auto pr-1.5 text-[11px] font-medium text-muted-foreground">
                    Right-click rows for actions
                </span>
            }
        />
    );
}
