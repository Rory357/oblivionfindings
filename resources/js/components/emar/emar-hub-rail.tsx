import { router, usePage } from '@inertiajs/react';

import { PageHeaderRail } from '@/components/page';
import {
    emarHubForUrl,
    emarScopedHref,
    visibleEmarViews,
    type EmarNavigationPermissions,
} from '@/lib/emar-navigation';

/**
 * The connected-tab rail for a Medication hub (lib/emar-navigation.ts) — the
 * Governance SectionRail pattern. The hub and active view come from the URL,
 * so a hub page mounts it in one line: `rail={<EmarHubRail />}` on its
 * PageHeader. Query-selected views share the same page without sharing an
 * active key; tabs open each view's canonical URL.
 *
 * Hubs with their own in-page rail (Meds today, Reports, Settings) render nothing.
 * Navigation only — every page is still authorised on the server.
 */
export function EmarHubRail({
    counts,
    alerts,
}: {
    /** Optional per-view counters, keyed by view key. */
    counts?: Partial<Record<string, number>>;
    /** Per-view counters in the critical pair (shown only when above 0). */
    alerts?: Partial<Record<string, number>>;
}) {
    const page = usePage<{ auth?: { can?: EmarNavigationPermissions } }>();
    const match = emarHubForUrl(page.url);
    if (!match || match.hub.ownRail) return null;

    const visibleKeys = new Set(
        visibleEmarViews(match.hub, page.props.auth?.can).map((v) => v.key),
    );
    // The page being viewed is always a tab, in the hub's own order, even if
    // the shared permission map is momentarily stale — the server authorised
    // the page itself.
    const items = match.hub.views.filter(
        (item) => visibleKeys.has(item.key) || item.key === match.view.key,
    );

    return (
        <PageHeaderRail
            ariaLabel={`${match.hub.label} pages`}
            value={match.view.key}
            onSelect={(key) => {
                const target = items.find((item) => item.key === key);
                if (target && key !== match.view.key)
                    router.visit(emarScopedHref(target.href, page.url));
            }}
            items={items.map((item) => {
                const alert = alerts?.[item.key];
                return alert
                    ? {
                          key: item.key,
                          label: item.label,
                          icon: item.icon,
                          count: alert,
                          alert: true,
                      }
                    : {
                          key: item.key,
                          label: item.label,
                          icon: item.icon,
                          count: counts?.[item.key],
                      };
            })}
        />
    );
}
