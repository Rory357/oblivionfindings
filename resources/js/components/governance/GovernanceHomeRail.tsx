import { router, usePage } from '@inertiajs/react';
import { CalendarDays, FileSearch, Landmark, ListChecks } from 'lucide-react';

import { PageHeaderRail, type PageHeaderRailItem } from '@/components/page';

export type GovernanceHomeView = 'overview' | 'my-work' | 'calendar' | 'records';

const HOME_VIEWS: Array<
    PageHeaderRailItem<GovernanceHomeView> & { href: string }
> = [
    { key: 'overview', label: 'Home', icon: Landmark, href: '/governance/dashboard' },
    { key: 'my-work', label: 'My work', icon: ListChecks, href: '/governance/my-work' },
    { key: 'calendar', label: 'Calendar', icon: CalendarDays, href: '/governance/calendar' },
    { key: 'records', label: 'Records', icon: FileSearch, href: '/governance/records' },
];

/**
 * The Governance home rail — the member's four primary destinations (the
 * same four the member sidebar shows) as connected tabs on Home, My work and
 * Calendar headers. Records only appears for viewers with `governance.view`;
 * the server still authorises every destination.
 */
export function GovernanceHomeRail({
    value,
    myWorkCount,
}: {
    value: GovernanceHomeView;
    /** Full pending My work total (never the number of cards displayed). */
    myWorkCount?: number | null;
}) {
    const page = usePage<{
        auth?: { can?: { governance?: { view?: boolean } } };
    }>();
    const canViewRecords = Boolean(page.props.auth?.can?.governance?.view);

    const views = HOME_VIEWS.filter(
        (view) => view.key !== 'records' || canViewRecords,
    );

    return (
        <PageHeaderRail<GovernanceHomeView>
            ariaLabel="Governance home"
            value={value}
            onSelect={(key) => {
                const view = views.find((candidate) => candidate.key === key);
                if (view && key !== value) router.visit(view.href);
            }}
            items={views.map(({ key, label, icon }) => ({
                key,
                label,
                icon,
                count:
                    key === 'my-work' && myWorkCount != null
                        ? myWorkCount
                        : undefined,
            }))}
        />
    );
}

export default GovernanceHomeRail;
