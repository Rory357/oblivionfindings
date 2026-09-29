import { FleetPageMenu } from '@/components/fleet-assets/fleet-page-menu';
import { EntityChip } from '@/components/lists/entity-cells';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    History,
    Map,
    Settings,
    Shield,
    SlidersHorizontal,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Maps } from './_maps';
import { Notifications, type NotificationSummary } from './_notifications';
import { ChangeHistory, Setup, Tracking } from './_owners';
import type { MapSnapshot, NotificationSnapshot, Policy } from './_types';
import { Modal } from './_ui';

const tabs = [
    { key: 'maps', label: 'Maps', icon: Map },
    { key: 'tracking', label: 'Tracking & data', icon: Activity },
    { key: 'notifications', label: 'Notifications', icon: Bell },
    { key: 'setup', label: 'Setup', icon: SlidersHorizontal },
    { key: 'history', label: 'Change history', icon: History },
];
export default function SettingsWorkspace({
    notifications,
    maps,
    policies,
    permissions,
}: {
    notifications: NotificationSnapshot;
    maps: MapSnapshot;
    policies: Policy[];
    permissions: {
        manageMaps: boolean;
        roleDefaults: boolean;
        devices: boolean;
    };
}) {
    const { auth } = usePage<{ auth: { user: { id: number; name: string } } }>()
        .props;
    const initialTab = () =>
        tabs.some((tab) => tab.key === window.location.hash.slice(1))
            ? window.location.hash.slice(1)
            : window.location.pathname.endsWith('/notifications')
              ? 'notifications'
              : 'maps';
    const [view, setView] = useState(initialTab),
        [query, setQuery] = useState(''),
        [filter, setFilter] = useState('all'),
        [scope, setScope] = useState('personal'),
        [dirty, setDirty] = useState(false),
        [leaving, setLeaving] = useState<string | null>(null);
    const [mapDirty, setMapDirty] = useState(false);
    const [notificationSection, setNotificationSection] =
        useState('preferences');
    const [notificationSummary, setNotificationSummary] =
        useState<NotificationSummary>({
            inapp: notifications.events.filter((event) => event.effective.inapp)
                .length,
            email: notifications.events.filter((event) => event.effective.email)
                .length,
            overrides: Object.keys(notifications.overrides).length,
            changes: 0,
            configured: Object.values(notifications.channels).filter(
                (channel) => channel.available,
            ).length,
        });
    const allowLeave = useRef(false),
        onDirty = useCallback((value: boolean) => setDirty(value), []);
    const select = (key: string) => {
        setView(key);
        setQuery('');
        window.history.replaceState({}, '', '#' + key);
    };
    useEffect(() => {
        const changed = () => {
            setView(initialTab());
            setQuery('');
        };
        window.addEventListener('hashchange', changed);
        return () => window.removeEventListener('hashchange', changed);
    }, []);
    useEffect(() => {
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if ((dirty || mapDirty) && !allowLeave.current) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', beforeUnload);
        const remove = router.on('before', (event) => {
            if (
                (dirty || mapDirty) &&
                !allowLeave.current &&
                event.detail.visit.method === 'get' &&
                !event.detail.visit.only.length
            ) {
                event.preventDefault();
                setLeaving(event.detail.visit.url.toString());
            }
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            remove();
        };
    }, [dirty, mapDirty]);
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Settings', href: '/fleet-assets/settings' },
            ]}
        >
            <Head title="Fleet & Assets settings" />
            <div className="space-y-5">
                <PageHeader
                    className="overflow-clip!"
                    icon={Settings}
                    title="Settings"
                    titleChip={<EntityChip>Organisation</EntityChip>}
                    subline="Maps, tracking, notifications and operational setup"
                    actions={
                        <>
                            <FleetPageMenu />
                            {
                                <>
                                    <PageHeaderSearch
                                        value={query}
                                        onChange={setQuery}
                                        placeholder={`Search ${tabs.find((tab) => tab.key === view)?.label.toLowerCase()}`}
                                    />
                                    <PageHeaderGlassButton
                                        icon={History}
                                        onClick={() => select('history')}
                                    >
                                        Changes
                                    </PageHeaderGlassButton>
                                </>
                            }
                        </>
                    }
                    meters={
                        view === 'notifications' ? (
                            <>
                                <PageHeaderMeterBlock
                                    label="In-app copies"
                                    onClick={() => {
                                        setNotificationSection('preferences');
                                        setFilter('all');
                                    }}
                                >
                                    <PageHeaderMeterBig>
                                        {notificationSummary.inapp}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Of {notifications.events.length} event
                                        types ·{' '}
                                        {scope !== 'personal'
                                            ? 'inherited defaults'
                                            : notificationSummary.changes
                                              ? 'draft choices'
                                              : 'saved choices'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Email copies"
                                    onClick={() =>
                                        setNotificationSection('delivery')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {notificationSummary.email}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Optional choices · receipt unconfirmed
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="My overrides"
                                    onClick={() => {
                                        setScope('personal');
                                        setFilter('overrides');
                                        setNotificationSection('preferences');
                                    }}
                                >
                                    <PageHeaderMeterBig>
                                        {notificationSummary.overrides}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Event types with personal choices
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Channels"
                                    onClick={() =>
                                        setNotificationSection('delivery')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {notificationSummary.configured} of{' '}
                                        {
                                            Object.keys(notifications.channels)
                                                .length
                                        }
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Configured · inspect delivery
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        ) : (
                            <>
                                <PageHeaderMeterBlock
                                    label="Map capabilities"
                                    onClick={() => select('maps')}
                                >
                                    <PageHeaderMeterBig>
                                        {
                                            maps.capabilities.filter(
                                                (capability) =>
                                                    capability.enabled,
                                            ).length
                                        }
                                        <span className="text-[11px] text-primary-foreground/70">
                                            {' '}
                                            / 4 Google
                                        </span>
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        OSM remains the default
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Notification types"
                                    onClick={() => select('notifications')}
                                >
                                    <PageHeaderMeterBig>
                                        {notifications.events.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Optional copies
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Data policies"
                                    onClick={() => select('tracking')}
                                >
                                    <PageHeaderMeterBig>
                                        {policies.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Source-owned interpretation
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Setup"
                                    onClick={() => select('setup')}
                                >
                                    <PageHeaderMeterBig>
                                        Manual first
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Tracking is optional
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        )
                    }
                    filters={
                        view === 'notifications' ? (
                            <>
                                <span className="mr-2 inline-flex items-center gap-1.5 text-[11px] text-primary-foreground/80">
                                    <Shield className="size-3" />
                                    Approved sites · Pacific/Auckland
                                </span>
                                <PageHeaderFilterSelect
                                    allValue="personal"
                                    label="Personal"
                                    value={scope}
                                    onChange={setScope}
                                    options={[
                                        {
                                            value: 'personal',
                                            label: 'Personal',
                                        },
                                        {
                                            value: 'roles',
                                            label: 'Role defaults',
                                        },
                                    ]}
                                />
                                <PageHeaderFilterSelect
                                    label="All notifications"
                                    value={filter}
                                    onChange={setFilter}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All notifications',
                                        },
                                        {
                                            value: 'overrides',
                                            label: 'My overrides',
                                        },
                                        {
                                            value: 'changed',
                                            label: 'Unsaved changes',
                                        },
                                        {
                                            value: 'inherited',
                                            label: 'Inherited events',
                                        },
                                    ]}
                                />
                            </>
                        ) : undefined
                    }
                    rail={
                        <PageHeaderRail
                            items={tabs}
                            value={view}
                            onSelect={select}
                            ariaLabel="Settings views"
                        />
                    }
                />
                <div hidden={view !== 'maps'}>
                    <Maps
                        userId={auth.user.id}
                        onDirty={setMapDirty}
                        initial={maps}
                        canManage={permissions.manageMaps}
                        query={view === 'maps' ? query : ''}
                    />
                </div>
                <div hidden={view !== 'notifications'}>
                    <Notifications
                        initial={notifications}
                        userId={auth.user.id}
                        userName={auth.user.name}
                        section={notificationSection}
                        onSection={setNotificationSection}
                        onSummary={setNotificationSummary}
                        query={view === 'notifications' ? query : ''}
                        filter={filter}
                        scope={scope}
                        onDirty={onDirty}
                        canManageRoles={permissions.roleDefaults}
                    />
                </div>
                {view === 'tracking' && (
                    <Tracking
                        policies={policies}
                        query={query}
                        canViewDevices={permissions.devices}
                    />
                )}
                {view === 'setup' && <Setup query={query} />}
                {view === 'history' && <ChangeHistory query={query} />}
                {leaving && (
                    <Modal
                        title="Leave with an unsaved draft?"
                        description="Your saved settings will stay unchanged. This browser keeps the draft where storage is available."
                        onClose={() => setLeaving(null)}
                        footer={
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => setLeaving(null)}
                                >
                                    Keep editing
                                </Button>
                                <Button
                                    onClick={() => {
                                        allowLeave.current = true;
                                        router.visit(leaving);
                                    }}
                                >
                                    Leave page
                                </Button>
                            </>
                        }
                    >
                        Review and save your changes before leaving if you want
                        them applied.
                    </Modal>
                )}
            </div>
        </AppLayout>
    );
}
