import {
    GroupPillRail,
    TabSearchPalette,
    TierTwoTabs,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import {
    Activity,
    Bell,
    BookOpen,
    CalendarDays,
    FileText,
    Gauge,
    GitBranch,
    History,
    MapPin,
    Monitor,
    Network,
    Radar,
    Satellite,
    Settings,
    Terminal,
    Ticket,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useMemo } from 'react';
import type {
    DeviceProfileGroupKey,
    DeviceProfileSection,
    DeviceProfileSectionKey,
} from './device-profile';

const groupDefinitions: Array<{
    key: DeviceProfileGroupKey;
    label: string;
    icon: LucideIcon;
}> = [
    { key: 'status', label: 'Status', icon: Activity },
    { key: 'technical', label: 'Technology', icon: Network },
    { key: 'operations', label: 'Operations', icon: Wrench },
    { key: 'records', label: 'History & files', icon: BookOpen },
];

const sectionIcons: Record<DeviceProfileSectionKey, LucideIcon> = {
    health: Activity,
    monitors: Radar,
    topology: GitBranch,
    'interfaces-sensors': Gauge,
    configuration: Settings,
    endpoint: Monitor,
    console: Satellite,
    management: Terminal,
    assignments: MapPin,
    tickets: Ticket,
    events: Bell,
    maintenance: CalendarDays,
    documents: FileText,
    audit: History,
};

const passiveEmptyTabKeys = new Set<DeviceProfileSectionKey>([
    'interfaces-sensors',
    'events',
    'tickets',
    'audit',
]);

function navigationGroups(
    sections: DeviceProfileSection[],
    activeSection: DeviceProfileSectionKey,
): GroupedProfileNavGroup[] {
    return groupDefinitions
        .map((group) => ({
            key: group.key,
            label: group.label,
            icon: group.icon,
            tabs: sections
                .filter(
                    (section) =>
                        section.group === group.key &&
                        !(
                            passiveEmptyTabKeys.has(section.key) &&
                            section.count === 0 &&
                            section.key !== activeSection
                        ),
                )
                .map((section) => ({
                    key: section.key,
                    label: section.label,
                    icon: sectionIcons[section.key],
                    count: section.count,
                })),
        }))
        .filter((group) => group.tabs.length > 0);
}

function activeGroupKey(
    groups: GroupedProfileNavGroup[],
    activeSection: DeviceProfileSectionKey,
): string {
    return (
        groups.find((group) =>
            group.tabs.some((tab) => tab.key === activeSection),
        )?.key ??
        groups[0]?.key ??
        'status'
    );
}

export function DeviceProfileGroupNavigation({
    sections,
    activeSection,
    onSectionChange,
    onSearch,
}: {
    sections: DeviceProfileSection[];
    activeSection: DeviceProfileSectionKey;
    onSectionChange: (section: DeviceProfileSectionKey) => void;
    onSearch: () => void;
}) {
    const groups = useMemo(
        () => navigationGroups(sections, activeSection),
        [sections, activeSection],
    );

    return (
        <GroupPillRail
            groups={groups}
            openGroup={activeGroupKey(groups, activeSection)}
            activeTab={activeSection}
            onOpenGroup={(_group, targetSection) =>
                onSectionChange(targetSection as DeviceProfileSectionKey)
            }
            onSearch={onSearch}
            testIdPrefix="device-profile"
            ariaLabel="Device profile groups"
        />
    );
}

export function DeviceProfileNavigation({
    sections,
    activeSection,
    onSectionChange,
    searchOpen,
    onSearchClose,
}: {
    sections: DeviceProfileSection[];
    activeSection: DeviceProfileSectionKey;
    onSectionChange: (section: DeviceProfileSectionKey) => void;
    searchOpen: boolean;
    onSearchClose: () => void;
}) {
    const groups = useMemo(
        () => navigationGroups(sections, activeSection),
        [sections, activeSection],
    );
    const activeGroup = groups.find(
        (group) => group.key === activeGroupKey(groups, activeSection),
    );

    return (
        <>
            <TierTwoTabs
                tabs={activeGroup?.tabs ?? []}
                activeTab={activeSection}
                onTab={(section) =>
                    onSectionChange(section as DeviceProfileSectionKey)
                }
                renderLink={() => null}
                testIdPrefix="device-profile"
                ariaLabel="Device profile sections"
                panelId="device-profile-panel"
            />
            <TabSearchPalette
                open={searchOpen}
                onClose={onSearchClose}
                groups={groups}
                onTab={(section) => {
                    onSectionChange(section as DeviceProfileSectionKey);
                    onSearchClose();
                }}
                testIdPrefix="device-profile"
                searchLabel="Find device information"
            />
        </>
    );
}
