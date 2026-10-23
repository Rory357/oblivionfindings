import {
    PageHeader,
    PageHeaderRail,
    type PageHeaderProps,
} from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import {
    controlRoomDestinations,
    controlRoomHubForUrl,
    type ControlRoomNavCapabilities,
} from '@/lib/control-room-navigation';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

type PageProps = {
    [key: string]: unknown;
    auth?: { can?: ControlRoomNavCapabilities };
};

/**
 * The view tabs of the current Control Room destination, on the bottom edge
 * of the header (lib/control-room-navigation.ts — the eMAR hub-rail pattern).
 * Each tab opens its view's own URL, so deep links and Back keep working.
 */
export function ControlRoomHubRail({
    counts,
    alerts,
}: {
    counts?: Partial<Record<string, number>>;
    /** Counters in the fixed critical pair, shown only above 0. */
    alerts?: Partial<Record<string, number>>;
}) {
    const page = usePage<PageProps>();
    const hub = controlRoomHubForUrl(page.url, page.props.auth?.can);
    if (!hub || hub.destination.views.length < 2) return null;

    return (
        <PageHeaderRail
            ariaLabel={`${hub.destination.title} views`}
            value={hub.activeView}
            onSelect={(key) => {
                if (key !== hub.activeView) router.visit(key);
            }}
            items={hub.destination.views.map((view) => {
                const alert = alerts?.[view.key];
                return alert
                    ? {
                          key: view.key,
                          label: view.label,
                          icon: view.icon,
                          count: alert,
                          alert: true,
                      }
                    : {
                          key: view.key,
                          label: view.label,
                          icon: view.icon,
                          count: counts?.[view.key],
                      };
            })}
        />
    );
}

export type ControlRoomPageProps = {
    /** Browser tab title; defaults to the active view's label. */
    headTitle?: string;
    /** A record page's own crumb after the destination, e.g. the alert reference. */
    recordCrumb?: BreadcrumbItem;
    /** Replace the whole trail (a record opened from another module keeps its way back). */
    breadcrumbs?: BreadcrumbItem[];
    /** The shared PageHeader; title and icon default to the destination's. */
    header: Omit<PageHeaderProps, 'title' | 'icon' | 'rail'> & {
        title?: string;
        icon?: PageHeaderProps['icon'];
    };
    /** Replace the destination rail (record pages pass their own tabs). */
    rail?: ReactNode;
    railCounts?: Partial<Record<string, number>>;
    railAlerts?: Partial<Record<string, number>>;
    className?: string;
    children: ReactNode;
};

/**
 * Every Control Room page opens with the standard Event Horizon header
 * (DESIGN.md "Page headers"; Stephan chose it for Control Room 23 Oct 2026):
 * the destination as the title, one line of what this view is for, up to
 * four linked numbers, filters inside, and the view tabs on the bottom edge.
 * The shell owns the 20px gutter; sections below stack at gap-5.
 */
export function ControlRoomPage({
    headTitle,
    recordCrumb,
    breadcrumbs: breadcrumbOverride,
    header,
    rail,
    railCounts,
    railAlerts,
    className,
    children,
}: ControlRoomPageProps) {
    const page = usePage<PageProps>();
    const can = page.props.auth?.can;
    const hub = controlRoomHubForUrl(page.url, can);
    const destinations = controlRoomDestinations(can);
    const activeView = hub?.destination.views.find(
        (view) => view.key === hub.activeView,
    );

    const trail: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        {
            title: 'Control Room',
            href: destinations[0]?.href ?? '/control-room',
        },
    ];
    if (hub) {
        trail.push({
            title: hub.destination.title,
            href: hub.destination.href,
        });
    }
    if (recordCrumb) trail.push(recordCrumb);
    const breadcrumbs = breadcrumbOverride ?? trail;

    const { title, icon, ...rest } = header;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={`${headTitle ?? activeView?.label ?? hub?.destination.title ?? 'Control Room'} - Control Room`}
            />
            <div className={cn('flex min-w-0 flex-col gap-5', className)}>
                <PageHeader
                    {...rest}
                    title={title ?? hub?.destination.title ?? 'Control Room'}
                    icon={icon ?? hub?.destination.icon}
                    rail={
                        rail !== undefined ? (
                            rail
                        ) : hub && hub.destination.views.length > 1 ? (
                            <ControlRoomHubRail
                                counts={railCounts}
                                alerts={railAlerts}
                            />
                        ) : undefined
                    }
                />
                {children}
            </div>
        </AppLayout>
    );
}
