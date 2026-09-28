import { PageHeaderGlassButton } from '@/components/page/page-header';
import {
    fleetNavigationPath,
    type FleetNavigationPermissions,
} from '@/lib/fleet-navigation';
import { fleetScopeHref } from '@/lib/fleet-queue-context';
import { router, usePage } from '@inertiajs/react';
import { Bell, ShieldCheck } from 'lucide-react';

/** Direct queue entries belong in the hero's action slot. */
export function FleetQueueActions({
    siteId,
}: {
    siteId?: string | number | null;
}) {
    const page = usePage<{ auth?: { can?: FleetNavigationPermissions } }>();
    const can = page.props.auth?.can;
    const current = fleetNavigationPath(page.url);
    const scope = `/fleet-assets?${new URLSearchParams({ site: String(siteId ?? '') })}`;
    return (
        <>
            {can?.fleet?.viewAny && current !== '/fleet-assets/compliance' && (
                <PageHeaderGlassButton
                    icon={ShieldCheck}
                    className="max-sm:min-h-11"
                    onClick={() =>
                        router.visit(
                            fleetScopeHref('/fleet-assets/compliance', scope),
                        )
                    }
                >
                    Compliance & renewals
                </PageHeaderGlassButton>
            )}
            {(can?.assets?.viewAny || can?.assets?.alertsView) &&
                current !== '/fleet-assets/alerts' && (
                    <PageHeaderGlassButton
                        icon={Bell}
                        className="max-sm:min-h-11"
                        onClick={() =>
                            router.visit(
                                fleetScopeHref('/fleet-assets/alerts', scope),
                            )
                        }
                    >
                        Fleet alerts
                    </PageHeaderGlassButton>
                )}
        </>
    );
}
