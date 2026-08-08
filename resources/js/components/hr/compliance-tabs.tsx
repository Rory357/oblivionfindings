import { router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Car,
    ChevronRight,
    LayoutGrid,
    Pin,
    ShieldCheck,
    Star,
    UserCheck,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import {
    ComplianceContextMenu,
    useContextMenu,
} from '@/pages/hr/compliance/components/compliance-bits';
import { HrTabs, type HrTabItem } from './hr-tabs';

export type ComplianceTab =
    | 'overview'
    | 'matrix'
    | 'calendar'
    | 'vetting'
    | 'drivers';

const TAB_URLS: Record<ComplianceTab, string> = {
    overview: '/hr/compliance',
    matrix: '/hr/compliance/matrix',
    calendar: '/hr/compliance/calendar',
    vetting: '/hr/compliance/vetting',
    drivers: '/hr/compliance/drivers',
};

const DEFAULT_KEY = 'hr.compliance.defaultTab';
const PIN_KEY = 'hr.compliance.pinnedTabs';

type HrCan = {
    compliance?: { view?: boolean; manage?: boolean };
    vetting?: { view?: boolean };
    driver?: { view?: boolean };
};

/**
 * Section-level tab strip shared across the Compliance hub (overview / matrix /
 * renewals / vetting / drivers routes). Underline-active chips with count badges,
 * a right-click menu (Open · Set as default view · Pin) persisting the default
 * tab + pins to localStorage, and a star decoration on the default tab.
 */
export function ComplianceTabs({
    active,
    counts,
}: {
    active: ComplianceTab;
    counts?: Partial<Record<ComplianceTab, number>>;
}) {
    const hr = (usePage().props as { auth?: { can?: { hr?: HrCan } } }).auth
        ?.can?.hr;
    const { ctx, open, close } = useContextMenu();

    const [defaultTab, setDefaultTab] = useState<string>('overview');
    const [pinned, setPinned] = useState<Record<string, boolean>>({});

    useEffect(() => {
        try {
            setDefaultTab(localStorage.getItem(DEFAULT_KEY) || 'overview');
            setPinned(JSON.parse(localStorage.getItem(PIN_KEY) || '{}'));
        } catch {
            /* ignore */
        }
    }, []);

    const setAsDefault = (id: string) => {
        setDefaultTab(id);
        try {
            localStorage.setItem(DEFAULT_KEY, id);
        } catch {
            /* ignore */
        }
        toast.success('Set as default view');
    };

    const togglePin = (id: string) => {
        setPinned((prev) => {
            const next = { ...prev, [id]: !prev[id] };
            try {
                localStorage.setItem(PIN_KEY, JSON.stringify(next));
            } catch {
                /* ignore */
            }
            toast.success(next[id] ? 'View pinned' : 'View unpinned');
            return next;
        });
    };

    const navigate = (id: string) => {
        if (id !== active) router.visit(TAB_URLS[id as ComplianceTab]);
    };

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: {
                id: 'overview',
                label: 'Overview',
                icon: ShieldCheck,
                tone: 'primary',
                badge: counts?.overview,
            },
            show: !!hr?.compliance?.view,
        },
        {
            item: {
                id: 'matrix',
                label: 'Matrix',
                icon: LayoutGrid,
                tone: 'info',
                badge: counts?.matrix,
            },
            show: !!hr?.compliance?.manage,
        },
        {
            item: {
                id: 'calendar',
                label: 'Renewals',
                icon: CalendarDays,
                tone: 'violet',
                badge: counts?.calendar,
            },
            show: !!hr?.compliance?.view,
        },
        {
            item: {
                id: 'vetting',
                label: 'Vetting',
                icon: UserCheck,
                tone: 'warning',
                badge: counts?.vetting,
            },
            show: !!hr?.vetting?.view,
        },
        {
            item: {
                id: 'drivers',
                label: 'Drivers',
                icon: Car,
                tone: 'critical',
                badge: counts?.drivers,
            },
            show: !!hr?.driver?.view,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

    const decorations: Record<string, React.ReactNode> = {};
    for (const { item } of all) {
        if (defaultTab === item.id) {
            decorations[item.id] = (
                <Star className="h-3 w-3 fill-[color:var(--hr-amber)] text-[color:var(--hr-amber)]" />
            );
        } else if (pinned[item.id]) {
            decorations[item.id] = (
                <Pin className="h-3 w-3 text-muted-foreground" />
            );
        }
    }

    return (
        <>
            <HrTabs
                value={active}
                onChange={navigate}
                items={items}
                decorations={decorations}
                onItemContextMenu={(id, event) =>
                    open(event, [
                        {
                            icon: ChevronRight,
                            label: 'Open',
                            onClick: () => navigate(id),
                        },
                        {
                            icon: Star,
                            label: 'Set as default view',
                            onClick: () => setAsDefault(id),
                        },
                        {
                            icon: Pin,
                            label: pinned[id] ? 'Unpin' : 'Pin',
                            onClick: () => togglePin(id),
                        },
                    ])
                }
                ariaLabel="Compliance views"
                className="mb-6"
            />
            <ComplianceContextMenu ctx={ctx} onClose={close} />
        </>
    );
}

export default ComplianceTabs;
