import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import {
    integrationForViewer,
    IntegrationRecords,
    integrationViews,
    type IntegrationPermissions,
    type IntegrationView,
} from '@/components/security-devices/integration-records';
import type { IntegrationsProps } from '@/components/security-devices/integration-types';
import { SecurityDevicesSectionTabs } from '@/components/security-devices/security-devices-section-tabs';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, router, usePage } from '@inertiajs/react';
import { LayoutGrid, List, Plug, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type CurrentPermissions = {
    securityDevices?: {
        integrationsView?: boolean;
        integrationsManage?: boolean;
        devicesView?: boolean;
    };
};

export type {
    Provider,
    ProviderException,
} from '@/components/security-devices/integration-types';
export default function IntegrationsHub(data: IntegrationsProps) {
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: CurrentPermissions };
    }>().props;
    // Metadata-only partial changes cannot transfer ownership of retained DTOs.
    // A complete read with a fresh page mount establishes the next source actor.
    const [sourceActor] = useState(data.actor_id);
    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const visible = Boolean(
        auth?.user?.id &&
        data.actor_id === auth.user.id &&
        data.actor_id === sourceActor &&
        auth.can?.securityDevices?.integrationsView === true,
    );
    const permission: IntegrationPermissions = {
        manage:
            visible &&
            data.can.manage &&
            auth?.can?.securityDevices?.integrationsManage === true,
        devices: visible && auth?.can?.securityDevices?.devicesView === true,
    };
    return (
        <IntegrationsBody
            key={JSON.stringify([auth, data])}
            data={data}
            actor={auth?.user?.id ?? 0}
            visible={visible}
            permission={permission}
            layout={layout}
            setLayout={setLayout}
        />
    );
}
function IntegrationsBody({
    data,
    actor,
    visible: receivedVisible,
    permission,
    layout,
    setLayout,
}: {
    data: IntegrationsProps;
    actor: number;
    visible: boolean;
    permission: IntegrationPermissions;
    layout: 'cards' | 'table';
    setLayout: (v: 'cards' | 'table') => void;
}) {
    const [scope, setScope] = useState<IntegrationView>('providers'),
        [search, setSearch] = useState(''),
        [denied, setDenied] = useState(false),
        [notice, setNotice] = useState('');
    const live = useRef(true),
        refreshing = useRef(false);
    const visible = receivedVisible && !denied;
    useEffect(() => {
        live.current = true;
        return () => {
            live.current = false;
        };
    }, []);
    useEffect(
        () =>
            router.on('invalid', (event) => {
                if (!live.current || !refreshing.current) return;
                const response = event.detail.response;
                if (
                    response.config.method?.toLowerCase() !== 'get' ||
                    typeof response.config.url !== 'string'
                )
                    return;
                let target: URL;
                try {
                    target = new URL(
                        response.config.url,
                        window.location.origin,
                    );
                } catch {
                    return;
                }
                if (
                    target.origin !== window.location.origin ||
                    target.pathname !== '/security-devices/integrations'
                )
                    return;
                event.preventDefault();
                refreshing.current = false;
                if ([401, 403, 404].includes(response.status)) {
                    setDenied(true);
                    setSearch('');
                } else
                    setNotice(
                        'The catalogue could not be refreshed. Previous provider readings are still shown with their read time. Try Refresh again.',
                    );
            }),
        [],
    );
    const refresh = () => {
        if (refreshing.current) return;
        refreshing.current = true;
        setNotice('');
        if (!visible) {
            router.visit('/security-devices/integrations', {
                method: 'get',
                preserveState: false,
                preserveScroll: true,
                onFinish: () => {
                    if (live.current) refreshing.current = false;
                },
            });
            return;
        }
        router.reload({
            only: [
                'actor_id',
                'as_of',
                'providers',
                'stats',
                'can',
                'boundaries',
                'auth',
            ],
            onSuccess: (page) => {
                if (!live.current) return;
                const props = page.props as {
                    actor_id?: number;
                    auth?: {
                        user?: { id?: number };
                        can?: CurrentPermissions;
                    };
                };
                if (
                    page.component === 'security-devices/integrations' &&
                    props.actor_id === actor &&
                    props.auth?.user?.id === actor &&
                    props.auth.can?.securityDevices?.integrationsView === true
                )
                    setDenied(false);
                else {
                    setDenied(true);
                    setSearch('');
                }
            },
            onFinish: () => {
                if (live.current) refreshing.current = false;
            },
        });
    };
    const select = (view: IntegrationView) => {
        if (visible) {
            setScope(view);
            setSearch('');
        }
    };
    const providers = visible
        ? data.providers.map((p) => integrationForViewer(p, permission))
        : [];
    const rows = providers.filter((p) =>
        JSON.stringify(p)
            .toLocaleLowerCase()
            .includes(search.trim().toLocaleLowerCase()),
    );
    const meters: Array<{
        label: string;
        value: number;
        caption: string;
        scope: IntegrationView;
    }> = [
        {
            label: 'Providers',
            value: data.stats.providers_total,
            caption: 'Declared catalogue entries',
            scope: 'providers',
        },
        {
            label: 'Connected',
            value: data.stats.providers_connected,
            caption: 'Connection state; health is separate',
            scope: 'connection',
        },
        {
            label: 'Connection errors',
            value: data.stats.providers_errored,
            caption: 'Recorded connection errors',
            scope: 'connection',
        },
        {
            label: 'Imported devices',
            value: data.stats.imported_devices,
            caption: 'Current permitted device records',
            scope: 'sites',
        },
        {
            label: 'Events in 24 hours',
            value: data.stats.events_24h,
            caption: 'Permitted events in the past window',
            scope: 'health',
        },
        {
            label: 'Required action',
            value: data.stats.exceptions,
            caption: 'Recorded reconciliation exceptions',
            scope: 'actions',
        },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Integrations',
                    href: '/security-devices/integrations',
                },
            ]}
        >
            <Head title="Integrations - Security & Devices" />
            <div className="space-y-5">
                <PageHeader
                    icon={Plug}
                    title="Integrations"
                    subline="Connection, Site mapping, collected health and supported capabilities"
                    actions={
                        <>
                            <PageHeaderSearch
                                value={visible ? search : ''}
                                onChange={(value) =>
                                    visible && setSearch(value)
                                }
                                ariaLabel="Search provider records"
                                placeholder="Search permitted provider records…"
                            />
                            <PageHeaderGlassButton onClick={refresh}>
                                <RefreshCw className="size-4" />
                                Refresh
                            </PageHeaderGlassButton>
                        </>
                    }
                    meters={meters.map((meter) => (
                        <PageHeaderMeterBlock
                            key={meter.label}
                            label={meter.label}
                            onClick={() => select(meter.scope)}
                            ariaLabel={
                                'Open ' +
                                integrationViews.find(
                                    (v) => v.key === meter.scope,
                                )?.label +
                                ' view'
                            }
                        >
                            <PageHeaderMeterBig>
                                {visible ? meter.value : 'Restricted'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {meter.caption}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                    mobileSummary={
                        visible
                            ? `${data.stats.providers_total} providers · ${data.stats.exceptions} exceptions`
                            : 'Provider records restricted'
                    }
                    filters={
                        <>
                            <PageHeaderFilterButton
                                onClick={() => {
                                    if (visible) {
                                        setSearch('');
                                        setScope('providers');
                                    }
                                }}
                            >
                                All providers
                            </PageHeaderFilterButton>
                            <PageHeaderViewToggle
                                value={layout}
                                onChange={setLayout}
                                options={[
                                    {
                                        value: 'cards',
                                        label: 'Cards',
                                        icon: LayoutGrid,
                                    },
                                    {
                                        value: 'table',
                                        label: 'Table',
                                        icon: List,
                                    },
                                ]}
                            />
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={integrationViews}
                            value={scope}
                            onSelect={select}
                            ariaLabel="Provider views"
                        />
                    }
                />
                {visible ? (
                    <SecurityDevicesSectionTabs
                        section="setup"
                        activeTab="integrations"
                    />
                ) : null}
                {!visible ? (
                    <p
                        role="status"
                        className="rounded-xl border bg-card p-5 text-sm"
                    >
                        Provider records are restricted. Ask an administrator to
                        restore access, then Refresh.
                    </p>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Read started{' '}
                            {formatDateTimeLong(
                                data.as_of,
                                'at a time not recorded',
                            )}
                            . Stored readings can change between refreshes.
                        </p>
                        {notice ? (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                {notice}
                            </p>
                        ) : null}
                        <IntegrationRecords
                            rows={rows}
                            data={{ ...data, providers }}
                            permission={permission}
                            scope={scope}
                            layout={layout}
                            searching={Boolean(search.trim())}
                        />
                        <p className="text-sm text-muted-foreground">
                            Sync is marked old after{' '}
                            {data.boundaries.sync_stale_after_hours} hours.
                            Credential review cadence is{' '}
                            {data.boundaries.credential_rotation_cadence_days}{' '}
                            days. Correlated operational alerts remain owned by{' '}
                            {data.boundaries.alert_owner}.
                        </p>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

export { CredentialRotationStatus } from '@/components/security-devices/integration-records';
