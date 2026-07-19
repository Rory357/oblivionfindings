import { Link, usePage } from '@inertiajs/react';
import { Menu, Shield } from 'lucide-react';
import type { ReactNode } from 'react';

import {
    buildSecurityDevicesNavigationGroups,
    isSecurityDevicesDestinationActive,
    type SecurityDevicesNavigationGroup,
    type SecurityDevicesPermissions,
} from './security-devices-navigation';

interface SecurityDevicesPageProps {
    [key: string]: unknown;
    auth?: {
        can?: {
            securityDevices?: SecurityDevicesPermissions;
        };
    };
}

interface Props {
    children: ReactNode;
}

function groupId(label: string): string {
    return `security-devices-nav-${label.replace(/\s+/g, '-').toLowerCase()}`;
}

function SecurityDevicesSideNavigation({
    groups,
    currentUrl,
}: {
    groups: SecurityDevicesNavigationGroup[];
    currentUrl: string;
}) {
    return (
        <nav aria-label="Security & Devices" className="space-y-5">
            {groups.map((group) => (
                <section
                    key={group.label}
                    aria-labelledby={groupId(group.label)}
                >
                    <h2
                        id={groupId(group.label)}
                        className="px-3 text-[10px] font-bold tracking-[0.13em] text-muted-foreground uppercase"
                    >
                        {group.label}
                    </h2>
                    <ul className="mt-1.5 space-y-1">
                        {group.items.map((item) => {
                            const Icon = item.icon ?? Shield;
                            const active = isSecurityDevicesDestinationActive(
                                currentUrl,
                                item.href,
                            );

                            return (
                                <li key={`${group.label}-${item.title}`}>
                                    <Link
                                        href={item.href}
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        className={`frontline-focus flex min-h-11 items-center gap-3 rounded-xl px-3 text-[13px] font-medium transition-colors ${
                                            active
                                                ? 'bg-primary text-primary-foreground shadow-sm'
                                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                        }`}
                                    >
                                        <Icon
                                            className="h-4 w-4 flex-none"
                                            aria-hidden="true"
                                        />
                                        <span>{item.title}</span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            ))}
        </nav>
    );
}

export function SecurityDevicesModuleShell({ children }: Props) {
    const page = usePage<SecurityDevicesPageProps>();
    const groups = buildSecurityDevicesNavigationGroups(
        page.props.auth?.can?.securityDevices,
    );

    if (groups.length === 0) {
        return <>{children}</>;
    }

    return (
        <div className="mx-auto grid w-full max-w-[1800px] gap-5 px-4 py-4 lg:grid-cols-[17rem_minmax(0,1fr)] lg:px-6">
            <aside className="hidden self-start rounded-2xl border border-border bg-card p-3 shadow-sm lg:sticky lg:top-4 lg:block">
                <div className="mb-4 border-b border-border px-3 pb-3">
                    <p className="flex items-center gap-2 text-sm font-bold">
                        <Shield
                            className="h-4 w-4 text-primary"
                            aria-hidden="true"
                        />
                        Security & Devices
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Estate, monitoring and management
                    </p>
                </div>
                <SecurityDevicesSideNavigation
                    groups={groups}
                    currentUrl={page.url}
                />
            </aside>

            <details className="group rounded-2xl border border-border bg-card p-3 shadow-sm lg:hidden">
                <summary className="frontline-focus flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-xl px-2 font-semibold">
                    <Menu className="h-5 w-5 text-primary" aria-hidden="true" />
                    Security & Devices navigation
                </summary>
                <div className="mt-3 border-t border-border pt-3">
                    <SecurityDevicesSideNavigation
                        groups={groups}
                        currentUrl={page.url}
                    />
                </div>
            </details>

            <div className="min-w-0">{children}</div>
        </div>
    );
}
