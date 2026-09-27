/* eslint-disable no-restricted-syntax -- Custom connected tabs, location selectors and directory rows follow the approved workspace composition; standard actions use Button. */
import { EntityCard } from '@/components/lists/entity-card';
import { EntityTable } from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { useIsMobile } from '@/hooks/use-mobile';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ClipboardCheck,
    Download,
    FileUp,
    LayoutGrid,
    List,
    LockKeyhole,
    MapPin,
    Package,
    Plus,
    QrCode,
    ShieldCheck,
    TriangleAlert,
    Wifi,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    AssetWizardDialog,
    type AssetWizardPrefill,
} from './components/asset-wizard-dialog';
import { api, type Option } from './register/api';
import { ErrorNotice, Paging } from './register/controls';
import { Imports } from './register/imports';
import { Labels } from './register/labels';
import {
    LocationNavigator,
    type LocationOption,
} from './register/location-navigator';
import { Stocktakes } from './register/stocktakes';
import './register/workspace.css';

type Asset = {
    id: number;
    name: string;
    asset_tag: string | null;
    category: string | null;
    status: string;
    site: Option | null;
    room: Option | null;
    location_note?: string | null;
    ownership: string;
    serial_number: string | null;
    tracker_count: number | null;
    last_inspected_at?: string | null;
    inspection_due_at?: string | null;
    maintenance_due_at?: string | null;
};
type Filters = {
    category?: string;
    status?: string;
    site_id?: string | number;
    site_room_id?: string | number;
    search?: string;
    view?: string;
    ownership?: string;
    workflow_status?: string;
};
type Props = {
    hero: {
        total: number;
        active: number;
        maintenance: number;
        inspections_due: number;
    };
    assets: {
        data: Asset[];
        meta: { current_page: number; last_page: number; total: number };
    };
    filters: Filters;
    sites: LocationOption[];
    rooms: LocationOption[];
    staff: Option[];
    register_permissions: { create: boolean; count: boolean };
    clients?: {
        id: number;
        first_name: string;
        last_name: string;
        site_id?: number | null;
    }[];
    prefill?: AssetWizardPrefill | null;
    workflow_metrics?: {
        label: string;
        value: number;
        caption: string;
        status: string;
    }[];
};

export function AssetTechnologySummary({ count }: { count: number | null }) {
    return (
        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
            {count === null ? (
                <LockKeyhole className="size-3" />
            ) : (
                <Wifi className="size-3" />
            )}
            {count === null
                ? 'Device details restricted'
                : `${count} linked ${count === 1 ? 'device' : 'devices'}`}
        </span>
    );
}
const tabs = [
    { key: 'inventory', label: 'Inventory', icon: Package },
    { key: 'attention', label: 'Needs attention', icon: TriangleAlert },
    { key: 'stocktake', label: 'Stocktake', icon: ClipboardCheck },
    { key: 'imports', label: 'Imports', icon: FileUp },
    { key: 'labels', label: 'QR labels', icon: QrCode },
    { key: 'archived', label: 'Archived', icon: Archive },
];
const info: Record<string, [string, string]> = {
    inventory: [
        'Assets',
        'Find equipment, follow its assigned location and keep every source connected.',
    ],
    attention: [
        'Assets needing attention',
        'Review service and inspection needs before putting equipment into use.',
    ],
    stocktake: [
        'Stocktake',
        'Choose a room, scan its assets, then review what needs follow-up.',
    ],
    imports: [
        'Import inventory',
        'Bring existing inventory into the register with a checked, recoverable import.',
    ],
    labels: [
        'Asset QR labels',
        'Create durable labels that open the same asset throughout its life.',
    ],
    archived: [
        'Archived assets',
        'Retired records remain available for history and reference.',
    ],
};

export default function AssetsIndex({
    workflow_metrics = [],
    hero,
    assets,
    filters = {},
    sites = [],
    rooms = [],
    staff = [],
    register_permissions: permissions = { create: false, count: false },
    clients,
    prefill,
}: Props) {
    const view = tabs.some((t) => t.key === filters.view)
        ? filters.view!
        : 'inventory';
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    const mobile = useIsMobile();
    const [directoryOpen, setDirectoryOpen] = useState(false);
    const [newCount, setNewCount] = useState(0);
    const [search, setSearch] = useState(filters.search || '');
    const [cards, setCards] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [error, setError] = useState('');
    const [wizardOpen, setWizardOpen] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(location.search).get('new') === '1',
    );
    useEffect(() => setSearch(filters.search || ''), [filters.search]);
    useEffect(() => {
        if (search === (filters.search || '')) return;
        const timer = window.setTimeout(
            () =>
                router.get(
                    '/fleet-assets/assets',
                    { ...filters, search, page: 1 },
                    { preserveState: true, preserveScroll: true },
                ),
            350,
        );
        return () => window.clearTimeout(timer);
    }, [search, filters]);
    const inventory = ['inventory', 'attention', 'archived'].includes(view);
    function apply(next: Record<string, string | number>) {
        router.get(
            '/fleet-assets/assets',
            { ...filters, page: 1, ...next },
            { preserveState: true, preserveScroll: true },
        );
    }
    function navigate(next: string) {
        apply({
            view: next,
            workflow_status: '',
            ...(next === 'archived' ? { status: '' } : {}),
        });
    }
    function toggle(id: number, checked: boolean) {
        setSelected((previous) => {
            const next = new Set(previous);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    }
    const actions = (asset: Asset) => [
        {
            label: 'Open asset profile',
            icon: Package,
            onClick: () => router.visit(`/fleet-assets/assets/${asset.id}`),
        },
        {
            label: 'Create QR label',
            icon: QrCode,
            onClick: () => {
                setSelected(new Set([asset.id]));
                navigate('labels');
            },
        },
    ];
    const rows = assets?.data || [];
    const outsidePage = [...selected].filter(
        (id) => !rows.some((r) => r.id === id),
    ).length;
    const query = new URLSearchParams(
        Object.entries(filters)
            .filter(([, v]) => v !== null && v !== undefined && v !== '')
            .map(([k, v]) => [k, String(v)]),
    );
    const metrics = [
        {
            label: 'Inventory',
            value: hero.total,
            caption: 'permitted assets, including retired',
            filter: { view: 'inventory', status: '' },
        },
        {
            label: 'Active',
            value: hero.active,
            caption: 'active records',
            filter: { view: 'inventory', status: 'active' },
        },
        {
            label: 'Out of service',
            value: hero.maintenance,
            caption: 'review before use',
            filter: { view: 'attention', status: 'out_of_service' },
        },
        {
            label: 'Inspections due',
            value: hero.inspections_due,
            caption: 'within 30 days',
            filter: { view: 'attention', status: '' },
        },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Assets', href: '/fleet-assets/assets' },
            ]}
        >
            <Head title={info[view][0]} />
            <div className="assets-register-workspace">
                <PageHeader
                    icon={
                        view === 'stocktake'
                            ? ClipboardCheck
                            : view === 'imports'
                              ? FileUp
                              : view === 'labels'
                                ? QrCode
                                : Package
                    }
                    title={info[view][0]}
                    titleChip={
                        <PageHeaderStatusChip variant="neutral">
                            {inventory ? 'Site inventory' : 'Assets'}
                        </PageHeaderStatusChip>
                    }
                    subline={info[view][1]}
                    actions={
                        <>
                            {inventory && (
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    placeholder="Search asset, tag, serial…"
                                />
                            )}
                            {permissions.create && inventory && (
                                <PageHeaderPrimaryButton
                                    onClick={() => setWizardOpen(true)}
                                >
                                    <Plus className="size-4" />
                                    Register asset
                                </PageHeaderPrimaryButton>
                            )}
                            {view === 'stocktake' && permissions.count && (
                                <PageHeaderPrimaryButton
                                    onClick={() => setNewCount((n) => n + 1)}
                                >
                                    <Plus className="size-4" />
                                    New stocktake
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    meters={
                        <>
                            {(workflow_metrics.length
                                ? workflow_metrics.map((metric) => ({
                                      ...metric,
                                      filter: {
                                          view,
                                          workflow_status: metric.status,
                                      },
                                  }))
                                : metrics
                            ).map((metric) => (
                                <PageHeaderMeterBlock
                                    key={metric.label}
                                    label={metric.label}
                                    onClick={() =>
                                        apply({
                                            ...metric.filter,
                                            category: '',
                                            site_id: '',
                                            site_room_id: '',
                                            search: '',
                                            ownership: '',
                                        })
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {metric.value}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {metric.caption}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </>
                    }
                    filters={
                        <>
                            <span className="asset-scope-hint">
                                <ShieldCheck className="size-3" />
                                {sites.length} permitted sites ·{' '}
                                {inventory
                                    ? sites.find(
                                          (s) =>
                                              String(s.id) ===
                                              String(filters.site_id),
                                      )?.name || 'All locations'
                                    : view === 'stocktake'
                                      ? 'Count history is separate from inventory filters'
                                      : 'Permitted records only'}
                            </span>
                            {inventory ? (
                                <>
                                    <PageHeaderGlassButton
                                        className="asset-header-location"
                                        onClick={() => setDirectoryOpen(true)}
                                    >
                                        <MapPin className="size-3" />
                                        {filters.site_id
                                            ? 'Change site'
                                            : 'Choose site'}
                                    </PageHeaderGlassButton>
                                    <PageHeaderFilterSelect
                                        label="All categories"
                                        allValue=""
                                        value={filters.category || ''}
                                        onChange={(category) =>
                                            apply({ category })
                                        }
                                        options={[
                                            {
                                                value: 'equipment',
                                                label: 'Equipment',
                                            },
                                            {
                                                value: 'vehicle',
                                                label: 'Vehicle',
                                            },
                                            {
                                                value: 'property',
                                                label: 'Property',
                                            },
                                            { value: 'other', label: 'Other' },
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="All ownership"
                                        allValue=""
                                        value={filters.ownership || ''}
                                        onChange={(ownership) =>
                                            apply({ ownership })
                                        }
                                        options={[
                                            {
                                                value: 'organisation',
                                                label: 'Organisation',
                                            },
                                            {
                                                value: 'client',
                                                label: 'Client-owned',
                                            },
                                            {
                                                value: 'unknown',
                                                label: 'Not recorded',
                                            },
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="All statuses"
                                        allValue=""
                                        value={filters.status || ''}
                                        onChange={(status) => apply({ status })}
                                        options={
                                            view === 'archived'
                                                ? [
                                                      {
                                                          value: 'retired',
                                                          label: 'Retired',
                                                      },
                                                  ]
                                                : [
                                                      {
                                                          value: 'active',
                                                          label: 'Active',
                                                      },
                                                      {
                                                          value: 'out_of_service',
                                                          label: 'Out of service',
                                                      },
                                                  ]
                                        }
                                    />
                                    <PageHeaderViewToggle
                                        ariaLabel="Inventory view"
                                        value={cards ? 'cards' : 'list'}
                                        onChange={(value) =>
                                            setCards(value === 'cards')
                                        }
                                        options={[
                                            {
                                                value: 'list',
                                                label: 'List',
                                                icon: List,
                                            },
                                            {
                                                value: 'cards',
                                                label: 'Cards',
                                                icon: LayoutGrid,
                                            },
                                        ]}
                                    />
                                    {(filters.site_id ||
                                        filters.search ||
                                        filters.category ||
                                        filters.ownership ||
                                        filters.status) && (
                                        <div className="asset-active-filters">
                                            <span>Filtered by</span>
                                            {filters.site_id && (
                                                <button
                                                    onClick={() =>
                                                        apply({
                                                            site_id: '',
                                                            site_room_id: '',
                                                        })
                                                    }
                                                >
                                                    {
                                                        sites.find(
                                                            (s) =>
                                                                String(s.id) ===
                                                                String(
                                                                    filters.site_id,
                                                                ),
                                                        )?.name
                                                    }
                                                    {filters.site_room_id
                                                        ? ` · ${rooms.find((r) => String(r.id) === String(filters.site_room_id))?.name || 'Room'}`
                                                        : ''}
                                                    <X className="size-3" />
                                                </button>
                                            )}
                                            <button
                                                onClick={() =>
                                                    apply({
                                                        site_id: '',
                                                        site_room_id: '',
                                                        category: '',
                                                        ownership: '',
                                                        status: '',
                                                        search: '',
                                                    })
                                                }
                                            >
                                                Reset all{' '}
                                                <X className="size-3" />
                                            </button>
                                        </div>
                                    )}
                                </>
                            ) : (
                                <PageHeaderGlassButton
                                    onClick={() => navigate('inventory')}
                                >
                                    Return to inventory
                                </PageHeaderGlassButton>
                            )}
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={tabs.filter(
                                (t) =>
                                    t.key !== 'imports' || permissions.create,
                            )}
                            value={view}
                            onSelect={navigate}
                        />
                    }
                />
                <ErrorNotice message={error} />
                {view === 'stocktake' ? (
                    <Stocktakes
                        newCountRequest={newCount}
                        onNewHandled={() => setNewCount(0)}
                        initialStatus={filters.workflow_status || ''}
                        sites={sites}
                        staff={staff}
                        canCount={permissions.count}
                        initialSite={String(filters.site_id || '')}
                        initialRoom={String(filters.site_room_id || '')}
                        selected={[...selected]}
                    />
                ) : view === 'imports' ? (
                    permissions.create ? (
                        <Imports
                            sites={sites}
                            status={filters.workflow_status || ''}
                        />
                    ) : (
                        <p>You do not have permission to import assets.</p>
                    )
                ) : view === 'labels' ? (
                    <Labels
                        status={filters.workflow_status || ''}
                        selected={[...selected]}
                        onInventory={() => navigate('inventory')}
                        onClear={() => setSelected(new Set())}
                    />
                ) : (
                    <>
                        <div className="asset-inventory-layout">
                            <LocationNavigator
                                sites={sites}
                                rooms={rooms}
                                site={String(filters.site_id || '')}
                                room={String(filters.site_room_id || '')}
                                onSite={(site_id) =>
                                    apply({ site_id, site_room_id: '' })
                                }
                                onRoom={(site_room_id) =>
                                    apply({ site_room_id })
                                }
                                open={directoryOpen}
                                onOpenChange={setDirectoryOpen}
                                userId={auth.user.id}
                                archived={view === 'archived'}
                            />
                            <section className="asset-inventory-results">
                                <div className="asset-results-heading">
                                    <div>
                                        <h2 className="text-section-title">
                                            {view === 'archived'
                                                ? 'Archived inventory'
                                                : view === 'attention'
                                                  ? 'Assets needing attention'
                                                  : sites.find(
                                                        (s) =>
                                                            String(s.id) ===
                                                            String(
                                                                filters.site_id,
                                                            ),
                                                    )?.name ||
                                                    'Inventory across your sites'}
                                            {filters.site_room_id
                                                ? ` · ${rooms.find((r) => String(r.id) === String(filters.site_room_id))?.name || 'Room'}`
                                                : ''}
                                        </h2>
                                        <p>
                                            {assets.meta.total} matching records
                                            · assigned inventory
                                        </p>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            window.location.href = `/fleet-assets/assets?${query}&export=csv`;
                                        }}
                                    >
                                        <Download className="size-3" />
                                        Export CSV
                                    </Button>
                                </div>

                                <div className="asset-selection-toolbar">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setSelected(
                                                    (previous) =>
                                                        new Set([
                                                            ...previous,
                                                            ...rows.map(
                                                                (r) => r.id,
                                                            ),
                                                        ]),
                                                )
                                            }
                                        >
                                            Select this page
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                setError('');
                                                void api<{ ids: number[] }>(
                                                    `/fleet-assets/assets?${query}&selection=1`,
                                                )
                                                    .then((data) =>
                                                        setSelected(
                                                            new Set(data.ids),
                                                        ),
                                                    )
                                                    .catch((e) =>
                                                        setError(e.message),
                                                    );
                                            }}
                                        >
                                            Select all matching
                                        </Button>
                                        <span className="text-xs text-muted-foreground">
                                            {assets.meta.total} results
                                        </span>
                                    </div>
                                    {selected.size > 0 && (
                                        <div className="flex flex-wrap items-center gap-2 rounded-lg bg-primary/5 p-2">
                                            <strong className="text-sm">
                                                {selected.size} selected
                                            </strong>
                                            {outsidePage > 0 && (
                                                <span className="text-xs text-muted-foreground">
                                                    ({outsidePage} outside this
                                                    page)
                                                </span>
                                            )}
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    navigate('labels')
                                                }
                                            >
                                                <QrCode />
                                                QR labels
                                            </Button>
                                            {permissions.count && (
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        navigate('stocktake')
                                                    }
                                                >
                                                    <ClipboardCheck />
                                                    Stocktake
                                                </Button>
                                            )}
                                            <Button
                                                variant="ghost"
                                                onClick={() =>
                                                    setSelected(new Set())
                                                }
                                            >
                                                Clear
                                            </Button>
                                        </div>
                                    )}
                                </div>
                                {!rows.length ? (
                                    <Card
                                        unstyled
                                        className="rounded-xl border border-dashed bg-card p-10 text-center"
                                    >
                                        <Package className="mx-auto mb-3 size-10 text-primary" />
                                        <h2 className="font-semibold">
                                            No assets match this view
                                        </h2>
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            Try another location or clear the
                                            filters.
                                        </p>
                                    </Card>
                                ) : cards || mobile ? (
                                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                        {rows.map((asset) => (
                                            <EntityCard
                                                key={asset.id}
                                                meridian={
                                                    asset.status ===
                                                    'out_of_service'
                                                        ? 'warning'
                                                        : 'success'
                                                }
                                                icon={Package}
                                                name={asset.name}
                                                subline={`${asset.asset_tag || 'No tag'} · ${asset.category || 'Uncategorised'}`}
                                                href={`/fleet-assets/assets/${asset.id}`}
                                                actions={actions(asset)}
                                                selection={{
                                                    checked: selected.has(
                                                        asset.id,
                                                    ),
                                                    label: `Select ${asset.name}`,
                                                    onToggle: (checked) =>
                                                        toggle(
                                                            asset.id,
                                                            checked,
                                                        ),
                                                }}
                                                chips={
                                                    <StatusBadge
                                                        status={asset.status}
                                                    />
                                                }
                                                footer={{
                                                    primary:
                                                        asset.site?.name ||
                                                        'No assigned site',
                                                    secondary:
                                                        asset.room?.name ||
                                                        (asset.location_note
                                                            ? `Location note: ${asset.location_note}`
                                                            : 'No assigned room'),
                                                }}
                                                alerts={
                                                    <AssetTechnologySummary
                                                        count={
                                                            asset.tracker_count
                                                        }
                                                    />
                                                }
                                            />
                                        ))}
                                    </div>
                                ) : (
                                    <EntityTable
                                        identityLabel="Asset / identity"
                                        identityWidth="1.35fr"
                                        minWidth={850}
                                        rows={rows}
                                        rowKey={(asset) => asset.id}
                                        identity={(asset) => ({
                                            icon: Package,
                                            name: asset.name,
                                            subline: `${asset.asset_tag || 'No tag'} · ${asset.serial_number || 'No serial number'}`,
                                        })}
                                        columns={[
                                            {
                                                key: 'location',
                                                label: 'Assigned location',
                                                width: '1.05fr',
                                                cell: (asset) => (
                                                    <div>
                                                        <p>
                                                            {asset.site?.name ||
                                                                'No assigned site'}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {asset.room?.name ||
                                                                (asset.location_note
                                                                    ? `Location note: ${asset.location_note}`
                                                                    : 'No assigned room')}
                                                        </p>
                                                    </div>
                                                ),
                                            },
                                            {
                                                key: 'status',
                                                label: 'Status & work',
                                                width: '1fr',
                                                cell: (asset) => (
                                                    <div className="space-y-1">
                                                        <StatusBadge
                                                            status={
                                                                asset.status
                                                            }
                                                        />
                                                        <p className="text-xs text-muted-foreground">
                                                            {asset.inspection_due_at
                                                                ? `Inspection due ${formatDateOnly(asset.inspection_due_at)}`
                                                                : asset.maintenance_due_at
                                                                  ? `Maintenance due ${formatDateOnly(asset.maintenance_due_at)}`
                                                                  : 'No scheduled work'}
                                                        </p>
                                                    </div>
                                                ),
                                            },
                                            {
                                                key: 'ownership',
                                                label: 'Responsibility',
                                                width: '1fr',
                                                cell: (asset) => (
                                                    <div>
                                                        <p>
                                                            {asset.ownership ===
                                                            'site'
                                                                ? 'Organisation'
                                                                : asset.ownership ===
                                                                    'client'
                                                                  ? 'Client-owned'
                                                                  : 'Not recorded'}
                                                        </p>
                                                        <p className="mt-1 text-xs text-muted-foreground capitalize">
                                                            {asset.category ||
                                                                'Category not recorded'}
                                                        </p>
                                                    </div>
                                                ),
                                            },
                                            {
                                                key: 'verification',
                                                label: 'Last inspection',
                                                width: '1fr',
                                                cell: (asset) => (
                                                    <div>
                                                        <p>
                                                            {asset.last_inspected_at
                                                                ? formatDateOnly(
                                                                      asset.last_inspected_at,
                                                                  )
                                                                : 'Not recorded'}
                                                        </p>
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {asset.last_inspected_at
                                                                ? 'Dated inspection'
                                                                : 'No inspection evidence'}
                                                        </p>
                                                    </div>
                                                ),
                                            },
                                        ]}
                                        hrefFor={(asset) =>
                                            `/fleet-assets/assets/${asset.id}`
                                        }
                                        actionsFor={actions}
                                        selection={{
                                            keys: selected,
                                            labelFor: (asset) =>
                                                `Select ${asset.name}`,
                                            onToggle: (asset, checked) =>
                                                toggle(asset.id, checked),
                                        }}
                                    />
                                )}
                                <Paging
                                    page={assets.meta.current_page}
                                    last={assets.meta.last_page}
                                    total={assets.meta.total}
                                    onChange={(page) => apply({ page })}
                                />
                                <p className="asset-evidence-note">
                                    <ShieldCheck className="size-4" />A room
                                    assignment or QR read does not confirm
                                    current custody or safe use. Open the asset
                                    profile for its full record.
                                </p>
                            </section>
                        </div>
                    </>
                )}
                <AssetWizardDialog
                    open={wizardOpen}
                    onClose={() => {
                        setWizardOpen(false);
                        const url = new URL(location.href);
                        url.searchParams.delete('new');
                        url.searchParams.delete('created');
                        history.replaceState(null, '', url);
                    }}
                    sites={sites}
                    clients={clients}
                    prefill={prefill}
                />
            </div>
        </AppLayout>
    );
}
