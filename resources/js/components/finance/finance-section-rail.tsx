import { Link, router, usePage } from '@inertiajs/react';

import { PageHeaderRail } from '@/components/page';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import {
    type FinanceSection,
    financeSectionForUrl,
    financeTierTwoForUrl,
    visibleSectionTabs,
} from '@/lib/finance-sections';

/** Per-hub, per-tab row counts shared by HandleInertiaRequests. */
export type FinanceHubCounts = Record<string, Record<string, number>>;

/**
 * The connected-tab rail for a finance hub (lib/finance-sections.ts). Pass it
 * to <PageHeader rail>. The hub and active tab are resolved from the current
 * URL, so a finance page only needs `rail={<FinanceSectionRail />}` — counts
 * come from the shared `financeHubCounts` prop unless you pass your own.
 *
 * A viewer who can reach only one of the hub's pages still gets the rail —
 * one tab plus the Find chip (DESIGN.md "Rail without the Find chip").
 */
export function FinanceSectionRail({
    counts,
}: {
    /** Optional per-tab counters, keyed by tab key. Defaults to the hub's slice of `financeHubCounts`. */
    counts?: Partial<Record<string, number>>;
}) {
    const page = usePage<{
        auth?: { can?: Record<string, unknown> };
        financeHubCounts?: FinanceHubCounts | null;
    }>();
    const match = financeSectionForUrl(page.url);
    if (!match) return null;

    const shared = page.props.financeHubCounts?.[match.section.key];

    return (
        <SectionRail
            section={match.section}
            activeKey={match.tab.key}
            can={page.props.auth?.can}
            counts={counts ?? shared}
        />
    );
}

/** "Payables pages" — names what the rail's tabs are. */
export function sectionRailLabel(section: Pick<FinanceSection, 'label'>) {
    return `${section.label} pages`;
}

/**
 * The tier-2 strip for the active rail tab (Aged AR, EFTPOS, report groups).
 * Renders nothing when the tab has no siblings, so a page can pass it to
 * `<PageLayout tabs>` unconditionally.
 */
export function FinanceTierTwoNav() {
    const page = usePage();
    const match = financeSectionForUrl(page.url);
    const siblings = match?.tab.tier2;
    if (!match || !siblings?.length) return null;

    const active = financeTierTwoForUrl(match.tab, page.url);

    // Each sibling is its own route, so the strip renders real links — a
    // middle-click or "open in new tab" works, and Inertia handles the plain
    // click. `TierTwoTabs` only falls back to a <button> when a tab has no
    // href, so a tab with an href MUST return an element from renderLink.
    return (
        <TierTwoTabs
            tabs={siblings.map((sibling) => ({
                key: sibling.key,
                label: sibling.label,
                icon: sibling.icon,
                href: sibling.href,
            }))}
            activeTab={active?.key ?? siblings[0].key}
            onTab={(key) => {
                const target = siblings.find((sibling) => sibling.key === key);
                if (target && key !== active?.key) router.visit(target.href);
            }}
            testIdPrefix="finance"
            ariaLabel={`${match.tab.label} views`}
            renderLink={(tab, className, inner, accessibility) => (
                <Link
                    key={tab.key}
                    href={tab.href!}
                    className={className}
                    {...accessibility}
                >
                    {inner}
                </Link>
            )}
        />
    );
}

function SectionRail({
    section,
    activeKey,
    can,
    counts,
}: {
    section: FinanceSection;
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
                // An empty register carries no badge, so the rail reads clean
                // and the page's own empty state does the talking.
                // PageHeaderRail renders any non-null count, including 0.
                count: counts?.[tab.key] || undefined,
            }))}
        />
    );
}

export default FinanceSectionRail;
