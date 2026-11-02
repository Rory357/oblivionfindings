import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import type { SecurityDevicesPermissions } from '@/components/security-devices/security-devices-navigation';
import { Link, router, usePage } from '@inertiajs/react';
import { Cpu, Download, Plug, Settings } from 'lucide-react';

/**
 * The tabs above the Setup pages that aren't part of the new Setup yet: the Obli downloads and each
 * connected service's own page. S&D rebuild C6: the old Discovery, Integrations and Settings & audit
 * pages are gone (they redirect into Setup), so these tabs point at their new homes. The old
 * "Alerts & upkeep" tabs went with the pages they joined up.
 */
export type SecurityDevicesSetupTabKey =
    | 'setup'
    | 'integrations'
    | 'supported-devices'
    | 'agent-downloads';

type SectionPageProps = {
    auth?: {
        can?: {
            securityDevices?: SecurityDevicesPermissions;
        };
    };
};

export function SecurityDevicesSectionTabs(props: {
    section: 'setup';
    activeTab: SecurityDevicesSetupTabKey;
}) {
    const page = usePage<SectionPageProps>();
    const sd = page?.props?.auth?.can?.securityDevices ?? {};
    const seesServices = Boolean(sd.integrationsView || sd.integrationsManage);

    const tabs: GroupedProfileNavTab[] = [
        ...(sd.setupOpen
            ? [
                  {
                      key: 'setup',
                      label: 'Setup',
                      href: '/security-devices/setup',
                      icon: Settings,
                  },
              ]
            : []),
        ...(seesServices
            ? [
                  {
                      key: 'integrations',
                      label: 'Connected services',
                      href: '/security-devices/setup/services?tab=connected',
                      icon: Plug,
                  },
                  {
                      key: 'supported-devices',
                      label: 'Supported devices',
                      href: '/security-devices/setup/services?tab=supported',
                      icon: Cpu,
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
    ];

    if (tabs.length <= 1) {
        return null;
    }

    return (
        <TierTwoTabs
            tabs={tabs}
            activeTab={props.activeTab}
            ariaLabel="Setup sections"
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
