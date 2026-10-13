import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import { MaintenanceCompletionReview } from '@/components/security-devices/maintenance-completion-review';
import {
    MaintenanceHealthDeviceRecords,
    MaintenanceHealthWorkRecords,
} from '@/components/security-devices/maintenance-health-records';
import { maintenanceLabel } from '@/components/security-devices/maintenance-records';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { LayoutGrid, List, RefreshCw, Search, Wrench } from 'lucide-react';
import { useState } from 'react';
import { type Paginated } from './devices/shared';
import type { MaintenanceRow } from './maintenance';
export type MaintenanceRecord = {
    id: number;
    device_id: number;
    device_name: string | null;
    device_uid: string | null;
    type: string;
    status: string;
    description: string;
    scheduled_for: string | null;
    completed_at: string | null;
    performed_by: string | null;
    vendor_reference: string | null;
    cost: string | null;
    notes: string | null;
    is_overdue: boolean;
};
export type AttentionDevice = {
    id: number;
    name: string;
    device_uid: string;
    domain: string;
    category: string;
    status: string;
    health_status: string;
    battery_level: number | null;
    last_seen_at: string | null;
};
export type LowBatteryDevice = {
    id: number;
    name: string;
    device_uid: string;
    battery_level: number | null;
    battery_updated_at: string | null;
};
export type MaintenanceHealthProps = {
    actor_id: number;
    pageMeta?: { title: string; description: string; href: string };
    stats: {
        overdue: number;
        upcoming: number;
        offline: number;
        degraded: number;
        lowBattery: number;
        critical: number;
    };
    records: Paginated<MaintenanceRecord>;
    attentionDevices: AttentionDevice[];
    lowBatteryDevices: LowBatteryDevice[];
    filters: Record<string, string>;
    can: { manage: boolean };
};
type Tab = 'work' | 'attention' | 'battery';
export default function MaintenanceHealth(props: MaintenanceHealthProps) {
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: Record<string, unknown> };
    }>().props;
    const [view, setView] = useState<'cards' | 'table'>('cards');
    const [tab, setTab] = useState<Tab>('work');
    const visible = Boolean(
        auth?.user?.id &&
        props.actor_id === auth.user.id &&
        auth.can?.['securityDevices.maintenance.view'] === true,
    );
    return (
        <MaintenanceHealthBody
            key={JSON.stringify([auth, props])}
            data={props}
            actor={auth?.user?.id ?? 0}
            visible={visible}
            manage={
                visible &&
                props.can.manage &&
                auth?.can?.['securityDevices.maintenance.manage'] === true
            }
            profiles={auth?.can?.['securityDevices.devices.view'] === true}
            tab={tab}
            setTab={setTab}
            view={view}
            setView={setView}
        />
    );
}
function MaintenanceHealthBody({
    data,
    actor,
    visible: receivedVisible,
    manage,
    profiles,
    tab,
    setTab,
    view,
    setView,
}: {
    data: MaintenanceHealthProps;
    actor: number;
    visible: boolean;
    manage: boolean;
    profiles: boolean;
    tab: Tab;
    setTab: (tab: Tab) => void;
    view: 'cards' | 'table';
    setView: (view: 'cards' | 'table') => void;
}) {
    const [denied, setDenied] = useState(false),
        [search, setSearch] = useState(
            receivedVisible ? (data.filters.search ?? '') : '',
        ),
        [review, setReview] = useState<MaintenanceRecord | null>(null);
    const visible = receivedVisible && !denied;
    const pageUrl =
        data.pageMeta?.href === '/security-devices/maintenance'
            ? '/security-devices/maintenance'
            : '/security-devices/maintenance-health';
    const get = (changes: Record<string, string>) =>
        visible &&
        router.get(
            pageUrl,
            { ...data.filters, ...changes, page: '1' },
            { preserveState: true, preserveScroll: true },
        );
    const refresh = () =>
        router.reload({
            only: [
                'actor_id',
                'stats',
                'records',
                'attentionDevices',
                'lowBatteryDevices',
                'filters',
                'can',
                'auth',
            ],
            onSuccess: (page) => {
                const props = page.props as {
                    actor_id?: number;
                    auth?: {
                        user?: { id?: number };
                        can?: Record<string, unknown>;
                    };
                };
                if (
                    page.component === 'security-devices/maintenance-health' &&
                    props.actor_id === actor &&
                    props.auth?.user?.id === actor &&
                    props.auth.can?.['securityDevices.maintenance.view'] ===
                        true
                )
                    setDenied(false);
            },
        });
    const filtered = Object.values(data.filters).some(
        (value) => value && value !== 'all',
    );
    const meters: Array<{
        label: string;
        value: number;
        caption: string;
        tab: Tab;
    }> = [
        {
            label: 'Overdue',
            value: data.stats.overdue,
            caption: 'Scheduled work past its date',
            tab: 'work',
        },
        {
            label: 'Upcoming',
            value: data.stats.upcoming,
            caption: 'Work due within 14 days',
            tab: 'work',
        },
        {
            label: 'Offline',
            value: data.stats.offline,
            caption: 'Devices recorded offline',
            tab: 'attention',
        },
        {
            label: 'Degraded',
            value: data.stats.degraded,
            caption: 'Devices recorded degraded',
            tab: 'attention',
        },
        {
            label: 'Low battery',
            value: data.stats.lowBattery,
            caption: 'Devices with battery below 20%',
            tab: 'battery',
        },
        {
            label: 'Critical health',
            value: data.stats.critical,
            caption: 'Devices recorded critical',
            tab: 'attention',
        },
    ];
    const options = (values: string[], label: string) => [
        { value: 'all', label },
        ...values.map((value) => ({ value, label: maintenanceLabel(value) })),
    ];
    const reviewRow: MaintenanceRow | null = review
        ? {
              id: review.id,
              type: review.type,
              status: review.status,
              schedule_state: review.is_overdue ? 'overdue' : review.status,
              description: review.description,
              scheduled_for: review.scheduled_for,
              completed_at: review.completed_at,
              performed_by: review.performed_by,
              vendor_reference: review.vendor_reference,
              device: {
                  id: review.device_id,
                  name: review.device_name || `Device #${review.device_id}`,
                  href: `/security-devices/devices/${review.device_id}`,
                  domain: null,
                  category: null,
              },
              site: null,
          }
        : null;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Maintenance & health', href: pageUrl },
            ]}
        >
            <Head title="Maintenance & health - Security & Devices" />
            <div className="space-y-5">
                <PageHeader
                    icon={Wrench}
                    title="Maintenance & health"
                    subline="Device work, recorded health and battery readings across your permitted Sites."
                    mobileSummary={
                        visible
                            ? `${data.stats.overdue} overdue work record${data.stats.overdue === 1 ? '' : 's'} · ${data.stats.critical} critical device${data.stats.critical === 1 ? '' : 's'}`
                            : 'Maintenance records restricted'
                    }
                    actions={
                        <>
                            {visible && tab === 'work' ? (
                                <>
                                    <PageHeaderSearch
                                        ariaLabel="Search maintenance work"
                                        placeholder="Search work, device or reference…"
                                        value={search}
                                        onChange={setSearch}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter')
                                                get({ search });
                                        }}
                                    />
                                    <PageHeaderGlassButton
                                        onClick={() => get({ search })}
                                    >
                                        <Search className="size-4" />
                                        Search
                                    </PageHeaderGlassButton>
                                </>
                            ) : null}
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
                            onClick={() => visible && setTab(meter.tab)}
                            ariaLabel={
                                meter.tab === 'work'
                                    ? 'Open maintenance work'
                                    : meter.tab === 'attention'
                                      ? 'Open health attention'
                                      : 'Open low battery devices'
                            }
                        >
                            <PageHeaderMeterBig>
                                {visible ? meter.value : 'Restricted'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {visible
                                    ? meter.caption
                                    : 'Maintenance permission required'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                    filters={
                        <>
                            {visible && tab === 'work' ? (
                                <>
                                    <PageHeaderFilterSelect
                                        label="Status"
                                        value={data.filters.status || 'all'}
                                        options={options(
                                            [
                                                'scheduled',
                                                'in_progress',
                                                'completed',
                                                'cancelled',
                                            ],
                                            'All statuses',
                                        )}
                                        onChange={(value) =>
                                            get({ status: value })
                                        }
                                    />
                                    <PageHeaderFilterSelect
                                        label="Work type"
                                        value={data.filters.type || 'all'}
                                        options={options(
                                            [
                                                'scheduled_service',
                                                'repair',
                                                'firmware_update',
                                                'inspection',
                                                'replacement',
                                                'calibration',
                                                'connectivity_check',
                                                'battery_replacement',
                                            ],
                                            'All work types',
                                        )}
                                        onChange={(value) =>
                                            get({ type: value })
                                        }
                                    />
                                    {filtered ? (
                                        <PageHeaderFilterButton
                                            onClick={() => {
                                                setSearch('');
                                                if (visible)
                                                    router.get(
                                                        pageUrl,
                                                        {},
                                                        { preserveState: true },
                                                    );
                                            }}
                                        >
                                            Clear work filters
                                        </PageHeaderFilterButton>
                                    ) : null}
                                </>
                            ) : null}
                            <PageHeaderViewToggle
                                value={view}
                                onChange={setView}
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
                            items={[
                                { key: 'work', label: 'Maintenance work' },
                                { key: 'attention', label: 'Health attention' },
                                { key: 'battery', label: 'Low battery' },
                            ]}
                            value={tab}
                            onSelect={(key) => visible && setTab(key)}
                            ariaLabel="Maintenance and health views"
                        />
                    }
                />
                {!visible ? (
                    <p
                        role="status"
                        className="rounded-xl border bg-card p-5 text-sm"
                    >
                        Maintenance records are restricted. Refresh after your
                        access has been restored.
                    </p>
                ) : tab === 'work' ? (
                    <>
                        <MaintenanceHealthWorkRecords
                            rows={data.records.data}
                            total={
                                data.records.meta.total ??
                                data.records.data.length
                            }
                            view={view}
                            profiles={profiles}
                            canManage={manage}
                            onComplete={setReview}
                            filtered={filtered}
                        />
                        {(data.records.meta.last_page ?? 1) > 1 ? (
                            <nav
                                aria-label="Maintenance pages"
                                className="flex flex-wrap justify-center gap-2"
                            >
                                {data.records.links.map((link, index) => (
                                    <Button
                                        key={index}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        disabled={!link.url}
                                        aria-current={
                                            link.active ? 'page' : undefined
                                        }
                                        onClick={() =>
                                            link.url &&
                                            router.get(
                                                link.url,
                                                {},
                                                {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                    >
                                        {index === 0
                                            ? 'Previous'
                                            : index ===
                                                data.records.links.length - 1
                                              ? 'Next'
                                              : link.label.replace(
                                                    /<[^>]*>/g,
                                                    '',
                                                )}
                                    </Button>
                                ))}
                            </nav>
                        ) : null}
                    </>
                ) : (
                    <MaintenanceHealthDeviceRecords
                        rows={
                            tab === 'attention'
                                ? data.attentionDevices
                                : data.lowBatteryDevices
                        }
                        mode={tab}
                        view={view}
                        profiles={profiles}
                    />
                )}
            </div>
            {visible && manage && reviewRow ? (
                <MaintenanceCompletionReview
                    row={reviewRow}
                    actor={actor}
                    expectedComponent="security-devices/maintenance-health"
                    onClose={() => setReview(null)}
                    onAuthorityDenied={() => {
                        setDenied(true);
                        setReview(null);
                        setSearch('');
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
