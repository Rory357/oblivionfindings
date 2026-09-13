import { router, usePage } from '@inertiajs/react';

import { PageHeaderRail } from '@/components/page';
import {
    type GovernanceSection,
    governanceSectionForUrl,
    visibleSectionTabs,
} from '@/lib/governance-sections';

/**
 * The connected-tab rail for a Governance hub (lib/governance-sections.ts).
 * Pass it to <PageHeader rail>. The hub and active tab are resolved from
 * the current URL, so a register page only needs `rail={<GovernanceSectionRail />}`.
 * Renders nothing when the viewer can reach fewer than two of the hub's tabs.
 */
export function GovernanceSectionRail({
    counts,
}: {
    /** Optional per-tab counters, keyed by tab key. */
    counts?: Partial<Record<string, number>>;
}) {
    const page = usePage<{ auth?: { can?: Record<string, unknown> } }>();
    const match = governanceSectionForUrl(page.url);
    if (!match) return null;

    return (
        <SectionRail
            section={match.section}
            activeKey={match.tab.key}
            can={page.props.auth?.can}
            counts={counts}
        />
    );
}

function SectionRail({
    section,
    activeKey,
    can,
    counts,
}: {
    section: GovernanceSection;
    activeKey: string;
    can: Record<string, unknown> | undefined;
    counts?: Partial<Record<string, number>>;
}) {
    const tabs = visibleSectionTabs(section, can);
    if (tabs.length < 2) return null;

    return (
        <PageHeaderRail
            ariaLabel={`${section.label} registers`}
            value={activeKey}
            onSelect={(key) => {
                const tab = tabs.find((candidate) => candidate.key === key);
                if (tab && key !== activeKey) router.visit(tab.href);
            }}
            items={tabs.map((tab) => ({
                key: tab.key,
                label: tab.label,
                icon: tab.icon,
                count: counts?.[tab.key],
            }))}
        />
    );
}
