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
 *
 * A viewer who can reach only one of the hub's pages still gets the rail —
 * one tab plus the Find chip (DESIGN.md "Rail without the Find chip").
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

/** "Board finance pages" — names what the rail's tabs are. */
export function sectionRailLabel(section: Pick<GovernanceSection, 'label'>) {
    return `${section.label} pages`;
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
    const visibleKeys = new Set(
        visibleSectionTabs(section, can).map((tab) => tab.key),
    );
    // The page being viewed is always a tab (in the hub's own order), even if
    // the shared permission map is momentarily stale — the server authorised
    // the page itself.
    const items = section.tabs.filter(
        (tab) => visibleKeys.has(tab.key) || tab.key === activeKey,
    );
    if (items.length === 0) return null;

    return (
        <PageHeaderRail
            ariaLabel={sectionRailLabel(section)}
            value={activeKey}
            onSelect={(key) => {
                const tab = items.find((candidate) => candidate.key === key);
                if (tab && key !== activeKey) router.visit(tab.href);
            }}
            items={items.map((tab) => ({
                key: tab.key,
                label: tab.label,
                icon: tab.icon,
                count: counts?.[tab.key],
            }))}
        />
    );
}
