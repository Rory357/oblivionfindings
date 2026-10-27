import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import type { SecurityDevicesPermissions } from '@/components/security-devices/security-devices-navigation';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    BatteryWarning,
    Bell,
    Download,
    Plug,
    Radar,
    Server,
    Settings,
    Wrench,
} from 'lucide-react';

export type SecurityDevicesAlertsTabKey =
    | 'monitoring'
    | 'alerts-events'
    | 'maintenance'
    | 'maintenance-health';

export type SecurityDevicesSetupTabKey =
    | 'discovery'
    | 'integrations'
    | 'agent-downloads'
    | 'settings'
    | 'server-readiness';

type SectionPageProps = {
    auth?: {
        can?: {
            securityDevices?: SecurityDevicesPermissions;
            settings?: {
                manageAccess?: boolean;
            };
        };
    };
};

export function SecurityDevicesSectionTabs(
    props:
        | {
              section: 'alerts-upkeep';
              activeTab: SecurityDevicesAlertsTabKey;
          }
        | {
              section: 'setup';
              activeTab: SecurityDevicesSetupTabKey;
          },
) {
    const page = usePage<SectionPageProps>();
    const sd = page?.props?.auth?.can?.securityDevices ?? {};
    const canManageSettings = Boolean(
        page?.props?.auth?.can?.settings?.manageAccess,
    );

    const tabs: GroupedProfileNavTab[] =
        props.section === 'alerts-upkeep'
            ? [
                  ...(sd.eventsView
                      ? [
                            {
                                key: 'monitoring',
                                label: 'Monitors',
                                href: '/security-devices/monitoring',
                                icon: Activity,
                            },
                            {
                                key: 'alerts-events',
                                label: 'Events',
                                href: '/security-devices/alerts-events',
                                icon: Bell,
                            },
                        ]
                      : []),
                  ...(sd.maintenanceView || sd.maintenanceManage
                      ? [
                            {
                                key: 'maintenance',
                                label: 'Maintenance',
                                href: '/security-devices/maintenance',
                                icon: Wrench,
                            },
                            {
                                key: 'maintenance-health',
                                label: 'Upkeep & battery',
                                href: '/security-devices/maintenance-health',
                                icon: BatteryWarning,
                            },
                        ]
                      : []),
              ]
            : [
                  ...(sd.integrationsView
                      ? [
                            {
                                key: 'discovery',
                                label: 'Discovery & hubs',
                                href: '/security-devices/discovery',
                                icon: Radar,
                            },
                            {
                                key: 'integrations',
                                label: 'Integrations',
                                href: '/security-devices/integrations',
                                icon: Plug,
                            },
                        ]
                      : []),
                  ...(sd.commandsAdmin || sd.integrationsManage
                      ? [
                            {
                                key: 'agent-downloads',
                                label: 'Downloads',
                                href: '/security-devices/agent-downloads',
                                icon: Download,
                            },
                        ]
                      : []),
                  ...(sd.groupsManage ||
                  sd.reportsView ||
                  sd.commandsAdmin ||
                  sd.monitoringManage
                      ? [
                            {
                                key: 'settings',
                                label: 'Settings & audit',
                                href: '/security-devices/settings',
                                icon: Settings,
                            },
                        ]
                      : []),
                  ...(canManageSettings
                      ? [
                            {
                                key: 'server-readiness',
                                label: 'Server readiness',
                                href: '/settings/server-readiness',
                                icon: Server,
                            },
                        ]
                      : []),
              ];

    if (tabs.length <= 1) {
        return null;
    }

    const ariaLabel =
        props.section === 'alerts-upkeep'
            ? 'Alerts & upkeep sections'
            : 'Setup sections';

    return (
        <TierTwoTabs
            tabs={tabs}
            activeTab={props.activeTab}
            ariaLabel={ariaLabel}
            testIdPrefix={`sd-${props.section}`}
            onTab={(key) => {
                const target = tabs.find((item) => item.key === key);
                if (target?.href) {
                    router.visit(target.href);
                }
            }}
            renderLink={(tab, className, inner, accessibilityProps) => (
                <Link
                    href={tab.href ?? '#'}
                    className={className}
                    {...accessibilityProps}
                >
                    {inner}
                </Link>
            )}
        />
    );
}
