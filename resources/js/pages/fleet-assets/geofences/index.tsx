import type { Coordinate } from '@/components/client-location/types';
import { EntityCard } from '@/components/lists/entity-card';
import {
    EntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import PageShell from '@/components/page-shell';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    Archive,
    Building2,
    ClipboardList,
    Copy,
    Eye,
    History,
    LayoutGrid,
    List,
    Map,
    Pencil,
    Plus,
    Settings2,
    Shapes,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { base, query, request, useDebounced, useRemote } from './workspace/api';
import { BoundaryWizard } from './workspace/boundary-wizard';
import {
    mapBoundary,
    type AddressCapabilities,
    type Boundary,
    type BoundaryRecord,
    type EventRecord,
    type Impact,
    type Page,
    type ResourceRecord,
    type RuleRecord,
    type SiteOption,
} from './workspace/data';
import { BoundaryHistory, Pagination } from './workspace/history';
import {
    LifecycleDialog,
    type LifecycleAction,
} from './workspace/lifecycle-dialog';
import { BoundaryMap } from './workspace/map';
import { OperationalMap } from './workspace/operational-map';
import { PolicySummary } from './workspace/policy-summary';
import './workspace/production.css';
import { MapProviderContext, type MapProvider } from './workspace/provider';
import { RemotePicker } from './workspace/remote-picker';
import { RuleWizard } from './workspace/rule-wizard';
import {
    Button,
    Empty,
    Facts,
    Modal,
    Notice,
    SectionDialog,
} from './workspace/ui';
import './workspace/workspace.css';

const tabs = [
    { key: 'map', label: 'Map', icon: Map },
    { key: 'boundaries', label: 'Boundaries', icon: Shapes },
    { key: 'rules', label: 'Rules & uses', icon: ClipboardList },
    { key: 'events', label: 'Events & follow-up', icon: Activity },
    { key: 'history', label: 'History', icon: History },
];
export default function MapsBoundaries({
    canManage,
    addressSearch,
    mapProvider,
}: {
    canManage: boolean;
    addressSearch: AddressCapabilities;
    mapProvider: MapProvider;
}) {
    const [nav, setNav] = useState(
            () => new URLSearchParams(window.location.search),
        ),
        [refresh, setRefresh] = useState(0),
        [search, setSearch] = useState(nav.get('q') ?? ''),
        [site, setSite] = useState<SiteOption | null>(null),
        [wizard, setWizard] = useState<{
            existing?: BoundaryRecord;
            copy?: boolean;
            point?: Coordinate;
        } | null>(nav.get('new') === '1' ? {} : null),
        [ruleWizard, setRuleWizard] = useState<{
            existing?: RuleRecord;
            initialBoundary?: BoundaryRecord;
            initialResource?: ResourceRecord;
        } | null>(null),
        [detail, setDetail] = useState<BoundaryRecord | null>(null),
        [retire, setRetire] = useState<BoundaryRecord | null>(null),
        [retireReason, setRetireReason] = useState(''),
        [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [toast, setToast] = useState(''),
        [ruleDetail, setRuleDetail] = useState<RuleRecord | null>(null),
        [event, setEvent] = useState<EventRecord | null>(null),
        [menu, setMenu] = useState<{
            x: number;
            y: number;
            title: string;
            items: MenuItem[];
        } | null>(null),
        [people, setPeople] = useState<BoundaryRecord | null>(null);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [lifecycle, setLifecycle] = useState<LifecycleAction | null>(null);
    const tab = tabs.some((t) => t.key === nav.get('tab'))
            ? nav.get('tab')!
            : 'map',
        layout = nav.get('layout') === 'cards' ? 'cards' : 'list',
        page = Math.max(1, Number(nav.get('page')) || 1),
        size = Number(nav.get('size')) || 12,
        q = useDebounced(search),
        siteId = Number(nav.get('site_id')) || null,
        status = nav.get('status') ?? 'available',
        selectedId = Number(nav.get('selected')) || null;
    const currentNav = useRef(nav);
    const go = (values: Record<string, string | number | null>) => {
        const next = new URLSearchParams(currentNav.current);
        Object.entries(values).forEach(([k, v]) =>
            v === null || v === '' ? next.delete(k) : next.set(k, String(v)),
        );
        currentNav.current = next;
        setNav(next);
        // Keep Inertia's history state and initial-navigation queue intact.
        router.push({
            url: base + '?' + next,
            preserveState: true,
            preserveScroll: true,
        });
    };
    useEffect(() => {
        const onPop = () => {
            const p = new URLSearchParams(window.location.search);
            currentNav.current = p;
            setNav(p);
            setSearch(p.get('q') ?? '');
        };
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);
    useEffect(() => {
        if (q !== (currentNav.current.get('q') ?? '')) go({ q, page: 1 });
    }, [q]);
    useEffect(() => {
        if (!toast) return;
        const t = setTimeout(() => setToast(''), 5000);
        return () => clearTimeout(t);
    }, [toast]);
    const library = useRemote<Page<BoundaryRecord>>(
        tab === 'boundaries'
            ? query('/catalogue', {
                  q: nav.get('q'),
                  site_id: siteId,
                  status,
                  use: nav.get('use'),
                  sort: nav.get('sort'),
                  page,
                  size,
              })
            : null,
        refresh,
    );
    const selection = useRemote<{ boundary: BoundaryRecord; impact: Impact }>(
        selectedId ? base + '/' + selectedId : null,
        refresh,
    );
    const edit = useRemote<{ boundary: BoundaryRecord }>(
        nav.get('edit') ? base + '/' + nav.get('edit') : null,
    );
    useEffect(() => {
        if (edit.data && canManage) {
            setWizard({ existing: edit.data.boundary });
            go({ edit: null });
        }
    }, [edit.data, canManage]);
    const handoffToken = nav.get('handoff') ?? undefined;
    const handoff = useRemote<{ site: SiteOption; ready: boolean }>(
        handoffToken ? base + '/handoffs/' + handoffToken : null,
    );
    useEffect(() => {
        if (handoff.data) {
            setSite(handoff.data.site);
            go({ site_id: handoff.data.site.id });
        }
    }, [handoff.data]);
    const returnBoundary = async (b: BoundaryRecord) => {
        if (!handoffToken) return;
        try {
            await request(
                base + '/handoffs/' + handoffToken + '/return',
                'POST',
                { boundary_id: b.id, revision: b.revision },
            );
            setToast(
                'Boundary returned. Continue in the original Client Location draft.',
            );
        } catch (e) {
            setError((e as Error).message);
        }
    };
    const prefilledSite = useRemote<{ data: SiteOption[] }>(
        siteId && !site ? query('/sites', { id: siteId }) : null,
    );
    useEffect(() => {
        if (prefilledSite.data?.data[0]) setSite(prefilledSite.data.data[0]);
    }, [prefilledSite.data]);
    const requestedRule = useRemote<Page<RuleRecord>>(
        nav.get('rule') ? query('/rules', { id: nav.get('rule') }) : null,
    );
    useEffect(() => {
        if (requestedRule.data) {
            if (requestedRule.data.data[0])
                setRuleDetail(requestedRule.data.data[0]);
            else setError('This purpose rule is unavailable.');
            go({ rule: null });
        }
    }, [requestedRule.data]);
    const chosen = selection.data?.boundary ?? null;
    const mapped = (library.data?.data ?? [])
        .map(mapBoundary)
        .filter((b): b is Boundary => !!b);
    const rules = useRemote<Page<RuleRecord>>(
        tab === 'rules'
            ? query('/rules', {
                  q: nav.get('q'),
                  site_id: siteId,
                  review: nav.get('review'),
                  boundary_id: nav.get('boundary_id'),
                  page,
              })
            : null,
        refresh,
    );
    const events = useRemote<Page<EventRecord>>(
        tab === 'events'
            ? query('/events', {
                  q: nav.get('q'),
                  site_id: siteId,
                  follow_up: nav.get('follow_up'),
                  boundary_id: nav.get('boundary_id'),
                  page,
              })
            : null,
        refresh,
    );
    const summary = useRemote<{
        boundaries: number;
        rules: number;
        review: number;
        follow_up: number;
    }>(query('/summary', { site_id: siteId }), refresh);
    const impact = useRemote<{ impact: Impact }>(
        retire ? base + '/' + retire.id : null,
        refresh,
    );
    const inspect = (b: BoundaryRecord) => {
        go({ selected: b.id });
        setDetail(b);
    };
    const boundaryActions = (b: BoundaryRecord): MenuItem[] => [
        ...(handoffToken && b.personal_eligible
            ? [
                  {
                      label: 'Return to Client Location draft',
                      icon: ShieldCheck,
                      onClick: () => returnBoundary(b),
                  },
              ]
            : []),
        { label: 'View boundary', icon: Eye, onClick: () => inspect(b) },
        {
            label: 'View history',
            icon: History,
            onClick: () => go({ tab: 'history', selected: b.id, page: 1 }),
        },
        {
            label: 'Person / client connection',
            icon: ShieldCheck,
            onClick: () => setPeople(b),
        },
        ...(canManage
            ? [
                  ...(!b.retired_at
                      ? [
                            {
                                label: 'Edit shared boundary',
                                icon: Pencil,
                                onClick: () => setWizard({ existing: b }),
                            },
                            {
                                label: 'Add inactive rule',
                                icon: Plus,
                                onClick: () =>
                                    setRuleWizard({ initialBoundary: b }),
                            },
                        ]
                      : []),
                  {
                      label: 'Make a custom copy',
                      icon: Copy,
                      onClick: () => setWizard({ existing: b, copy: true }),
                  },
                  ...(!b.retired_at
                      ? [
                            {
                                label: 'Review legacy monitoring & links',
                                icon: Settings2,
                                onClick: () =>
                                    setLifecycle({
                                        kind: 'legacy',
                                        boundary: b,
                                    }),
                            },
                            {
                                label: 'Retire boundary',
                                icon: Archive,
                                onClick: () => {
                                    setRetire(b);
                                    setRetireReason('');
                                    setError('');
                                },
                            },
                        ]
                      : []),
              ]
            : []),
    ];
    const context = (e: React.MouseEvent, title: string, items: MenuItem[]) => {
        e.preventDefault();
        setMenu({ x: e.clientX, y: e.clientY, title, items });
    };
    const save = (b: BoundaryRecord) => {
        setRefresh((n) => n + 1);
        go({ selected: b.id, new: null, edit: null });
        setToast('Boundary saved. Monitoring was not started.');
    };
    const openRule = async (r: RuleRecord) => {
        try {
            const b = await request<{ boundary: BoundaryRecord }>(
                base + '/' + r.boundary_id,
            );
            setRuleDetail(null);
            setRuleWizard({ existing: r, initialBoundary: b.boundary });
        } catch (e) {
            setError((e as Error).message);
        }
    };
    const retireBoundary = async () => {
        if (!retire) return;
        setBusy(true);
        setError('');
        try {
            await request(base + '/' + retire.id, 'DELETE', {
                expected_revision: retire.revision,
                reason: retireReason,
            });
            setRetire(null);
            setRefresh((n) => n + 1);
            setToast('Boundary retired. Its evidence is retained.');
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Maps & boundaries', href: base },
            ]}
        >
            <Head title="Maps & boundaries" />
            <MapProviderContext.Provider
                value={mapProvider ?? { url: '', attribution: '' }}
            >
                <PageShell>
                    <div className="pkg07 flow-stack">
                        <PageHeader
                            icon={Shapes}
                            title="Maps & boundaries"
                            subline="Plan shared areas. Review rules. Follow up with the right team."
                            actions={
                                <>
                                    <PageHeaderSearch
                                        value={search}
                                        onChange={(value) => {
                                            setSearch(value);
                                            if (
                                                tab === 'map' ||
                                                tab === 'history'
                                            )
                                                go({
                                                    tab: 'boundaries',
                                                    page: 1,
                                                });
                                        }}
                                        placeholder="Search boundaries or rules…"
                                    />
                                    {canManage && (
                                        <PageHeaderPrimaryButton
                                            icon={Plus}
                                            onClick={() => setWizard({})}
                                        >
                                            Create boundary
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            meters={
                                <>
                                    {[
                                        [
                                            'boundaries',
                                            'Shared boundaries',
                                            'Across your permitted sites',
                                            'boundaries',
                                        ],
                                        [
                                            'rules',
                                            'Linked rules',
                                            'Each keeps its own purpose',
                                            'rules',
                                        ],
                                        [
                                            'review',
                                            'Needs review',
                                            'Source or permitted use changed',
                                            'rules',
                                        ],
                                        [
                                            'follow_up',
                                            'Open follow-up',
                                            'Continue in Control Room',
                                            'events',
                                        ],
                                    ].map(([key, label, caption, target]) => (
                                        <PageHeaderMeterBlock
                                            key={key}
                                            label={label}
                                            tone={
                                                key === 'review' &&
                                                summary.data?.review
                                                    ? 'warning'
                                                    : 'brand'
                                            }
                                            onClick={() => {
                                                setSearch('');
                                                go({
                                                    tab: target,
                                                    q: null,
                                                    page: 1,
                                                    status: 'available',
                                                    boundary_id: null,
                                                    review:
                                                        key === 'review'
                                                            ? 1
                                                            : null,
                                                    follow_up:
                                                        key === 'follow_up'
                                                            ? 1
                                                            : null,
                                                });
                                            }}
                                        >
                                            <PageHeaderMeterBig>
                                                {summary.data?.[
                                                    key as keyof NonNullable<
                                                        typeof summary.data
                                                    >
                                                ] ?? '—'}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                {summary.error
                                                    ? 'Counts unavailable'
                                                    : caption}
                                            </PageHeaderMeterCaption>
                                        </PageHeaderMeterBlock>
                                    ))}
                                </>
                            }
                            filters={
                                <div className="bnd-header-filters">
                                    {tab === 'boundaries' && (
                                        <>
                                            <PageHeaderFilterSelect
                                                label="Availability"
                                                value={status}
                                                allValue="available"
                                                options={[
                                                    {
                                                        value: 'available',
                                                        label: 'Available areas',
                                                    },
                                                    {
                                                        value: 'retired',
                                                        label: 'Retired areas',
                                                    },
                                                    {
                                                        value: 'all',
                                                        label: 'All permitted areas',
                                                    },
                                                ]}
                                                onChange={(value) =>
                                                    go({
                                                        status: value,
                                                        page: 1,
                                                    })
                                                }
                                            />
                                            <PageHeaderFilterSelect
                                                label="Sort by name"
                                                value={
                                                    nav.get('sort') ?? 'name'
                                                }
                                                allValue="name"
                                                options={[
                                                    {
                                                        value: 'name',
                                                        label: 'Name',
                                                    },
                                                    {
                                                        value: 'updated',
                                                        label: 'Recently changed',
                                                    },
                                                ]}
                                                onChange={(value) =>
                                                    go({ sort: value, page: 1 })
                                                }
                                            />
                                            <PageHeaderFilterSelect
                                                label="All uses"
                                                value={nav.get('use') || 'all'}
                                                options={[
                                                    {
                                                        value: 'all',
                                                        label: 'All uses',
                                                    },
                                                    {
                                                        value: 'Vehicles',
                                                        label: 'Vehicles',
                                                    },
                                                    {
                                                        value: 'Assets',
                                                        label: 'Assets',
                                                    },
                                                ]}
                                                onChange={(value) =>
                                                    go({
                                                        use:
                                                            value === 'all'
                                                                ? null
                                                                : value,
                                                        page: 1,
                                                    })
                                                }
                                            />
                                            <PageHeaderFilterSelect
                                                label="12 per page"
                                                value={String(size)}
                                                allValue="12"
                                                options={[12, 24, 48, 96].map(
                                                    (n) => ({
                                                        value: String(n),
                                                        label: n + ' per page',
                                                    }),
                                                )}
                                                onChange={(value) =>
                                                    go({ size: value, page: 1 })
                                                }
                                            />
                                            <PageHeaderViewToggle
                                                value={layout}
                                                onChange={(value) =>
                                                    go({ layout: value })
                                                }
                                                ariaLabel="Boundary display"
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
                                        </>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setSettingsOpen(true)}
                                    >
                                        <Settings2 />
                                        Map service status
                                    </Button>
                                    <div className="bnd-header-site">
                                        {' '}
                                        <div className="min-w-60">
                                            <RemotePicker<SiteOption>
                                                label="Site filter"
                                                value={
                                                    site?.name ??
                                                    (siteId
                                                        ? 'Site ' + siteId
                                                        : 'All permitted sites')
                                                }
                                                url={(q) =>
                                                    query('/sites', { q })
                                                }
                                                describe={(s) => ({
                                                    id: s.id,
                                                    name: s.name,
                                                })}
                                                onSelect={(s) => {
                                                    setSite(s);
                                                    go({
                                                        site_id: s.id,
                                                        page: 1,
                                                    });
                                                }}
                                            />
                                        </div>
                                        {siteId && (
                                            <Button
                                                variant="ghost"
                                                onClick={() => {
                                                    setSite(null);
                                                    go({
                                                        site_id: null,
                                                        page: 1,
                                                    });
                                                }}
                                            >
                                                Clear site
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            }
                            rail={
                                <PageHeaderRail
                                    value={tab}
                                    onSelect={(value) => {
                                        setSearch('');
                                        go({
                                            tab: value,
                                            page: 1,
                                            q: null,
                                            review: null,
                                            follow_up: null,
                                            boundary_id: null,
                                        });
                                    }}
                                    items={tabs}
                                />
                            }
                        />
                        {(nav.get('review') ||
                            nav.get('follow_up') ||
                            nav.get('boundary_id')) && (
                            <Notice
                                title={
                                    nav.get('review')
                                        ? 'Showing rules that need source review'
                                        : nav.get('follow_up')
                                          ? 'Showing events with open follow-up'
                                          : `Showing ${tab === 'rules' ? 'rules' : 'events'} for boundary BG-${nav.get('boundary_id')}`
                                }
                            >
                                <Button
                                    variant="link"
                                    onClick={() =>
                                        go({
                                            review: null,
                                            follow_up: null,
                                            boundary_id: null,
                                            page: 1,
                                        })
                                    }
                                >
                                    Show all
                                </Button>
                            </Notice>
                        )}
                        {error && !retire && (
                            <Notice tone="critical" title={error} />
                        )}{' '}
                        {selection.error && (
                            <Notice
                                tone="warning"
                                title="Selected boundary is unavailable"
                            >
                                {selection.error}
                            </Notice>
                        )}
                        {handoffToken && (
                            <Notice
                                title={
                                    handoff.error
                                        ? 'Return request unavailable'
                                        : 'Client Location return request'
                                }
                                tone={handoff.error ? 'warning' : 'info'}
                            >
                                {handoff.error ??
                                    'Choose an eligible area and use Return to Client Location draft in its actions. A new area created here is eligible for person-specific review at this site; monitoring remains inactive. Keep the original person draft open.'}
                            </Notice>
                        )}{' '}
                        {tab === 'map' && (
                            <OperationalMap
                                initialBoundary={chosen}
                                initialResource={
                                    Number(nav.get('resource')) || null
                                }
                                site={siteId}
                                canManage={canManage}
                                onStart={() => setWizard({})}
                                onCreate={(point) => setWizard({ point })}
                                onInspect={inspect}
                                boundaryActions={boundaryActions}
                                onRule={(initialResource) =>
                                    setRuleWizard({ initialResource })
                                }
                                refresh={refresh}
                            />
                        )}
                        {tab === 'boundaries' && (
                            <>
                                <div className="flex flex-wrap items-center gap-3">
                                    <h2 className="text-section-title mr-auto">
                                        Boundary library
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Choose an area to see its rules, history
                                        and follow-up.
                                    </p>
                                </div>
                                {library.loading ? (
                                    <p role="status">Loading boundaries…</p>
                                ) : library.error ? (
                                    <Notice
                                        tone="critical"
                                        title={library.error}
                                    >
                                        <Button
                                            variant="outline"
                                            onClick={library.reload}
                                        >
                                            Retry
                                        </Button>
                                    </Notice>
                                ) : library.data?.data.length ? (
                                    <>
                                        {layout === 'list' ? (
                                            <EntityTable
                                                rows={library.data.data}
                                                rowKey={(b) => b.id}
                                                identity={(b) => ({
                                                    name: b.name,
                                                    subline:
                                                        b.address ??
                                                        `BG-${b.id}`,
                                                    icon: Shapes,
                                                })}
                                                columns={[
                                                    {
                                                        key: 'site',
                                                        label: 'Owning site',
                                                        width: '1.2fr',
                                                        cell: (b) =>
                                                            b.site ??
                                                            'Owning resource',
                                                    },
                                                    {
                                                        key: 'shape',
                                                        label: 'Geometry',
                                                        width: '1fr',
                                                        cell: (b) =>
                                                            b.geometry
                                                                ? `${b.geometry.type === 'circle' ? b.geometry.radius_m + ' m radius' : b.geometry.coordinates.length + ' corners'} · v${b.geometry_version}`
                                                                : 'Needs geometry review',
                                                    },
                                                    {
                                                        key: 'uses',
                                                        label: 'Permitted uses',
                                                        width: '1fr',
                                                        cell: (b) =>
                                                            b.uses.join(', '),
                                                    },
                                                    {
                                                        key: 'status',
                                                        label: 'Availability',
                                                        width: '130px',
                                                        cell: (b) => (
                                                            <StatusBadge
                                                                variant={
                                                                    b.retired_at
                                                                        ? 'neutral'
                                                                        : b.geometry
                                                                          ? 'success'
                                                                          : 'warning'
                                                                }
                                                            >
                                                                {b.retired_at
                                                                    ? 'Retired'
                                                                    : b.geometry
                                                                      ? 'Available'
                                                                      : 'Needs review'}
                                                            </StatusBadge>
                                                        ),
                                                    },
                                                ]}
                                                actionsFor={boundaryActions}
                                                onOpen={inspect}
                                                onRowContextMenu={(e, b) =>
                                                    context(
                                                        e,
                                                        b.name,
                                                        boundaryActions(b),
                                                    )
                                                }
                                            />
                                        ) : (
                                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                                                {library.data.data.map((b) => (
                                                    <EntityCard
                                                        key={b.id}
                                                        meridian={
                                                            b.geometry
                                                                ? 'success'
                                                                : 'warning'
                                                        }
                                                        icon={Shapes}
                                                        name={b.name}
                                                        subline={
                                                            b.address ??
                                                            'Address not recorded'
                                                        }
                                                        actions={boundaryActions(
                                                            b,
                                                        )}
                                                        onOpen={() =>
                                                            inspect(b)
                                                        }
                                                        onContextMenu={(e) =>
                                                            context(
                                                                e,
                                                                b.name,
                                                                boundaryActions(
                                                                    b,
                                                                ),
                                                            )
                                                        }
                                                        chips={
                                                            <>
                                                                <StatusBadge variant="neutral">
                                                                    {b.retired_at
                                                                        ? 'Retired'
                                                                        : 'Available'}
                                                                </StatusBadge>
                                                                <span className="text-xs">
                                                                    {b.uses.join(
                                                                        ' · ',
                                                                    )}
                                                                </span>
                                                            </>
                                                        }
                                                        footer={{
                                                            primary:
                                                                b.site ??
                                                                'Owning resource',
                                                            secondary: `BG-${b.id} · geometry v${b.geometry_version}`,
                                                            personIcon:
                                                                Building2,
                                                        }}
                                                    />
                                                ))}
                                            </div>
                                        )}
                                        <Pagination
                                            page={page}
                                            last={library.data.last_page}
                                            total={library.data.total}
                                            onChange={(page) => go({ page })}
                                        />
                                    </>
                                ) : (
                                    <Empty
                                        title="No boundaries match"
                                        action={
                                            canManage ? (
                                                <Button
                                                    onClick={() =>
                                                        setWizard({})
                                                    }
                                                >
                                                    <Plus />
                                                    Create boundary
                                                </Button>
                                            ) : undefined
                                        }
                                    >
                                        Clear your filters or create a shared
                                        area at a permitted site.
                                    </Empty>
                                )}
                            </>
                        )}
                        {tab === 'rules' && (
                            <>
                                <div className="flex items-center justify-between">
                                    <h2 className="text-section-title">
                                        Purpose-specific rules
                                    </h2>
                                    {canManage && (
                                        <Button
                                            onClick={() => setRuleWizard({})}
                                        >
                                            <Plus />
                                            Add rule
                                        </Button>
                                    )}
                                </div>
                                <Notice title="Inactive proposals retain their reviewed geometry">
                                    Vehicle and asset assignments share the
                                    existing register. Timing, detection and
                                    response proposals do not activate
                                    monitoring.
                                </Notice>
                                {rules.loading ? (
                                    <p role="status">Loading rules…</p>
                                ) : rules.error ? (
                                    <Notice
                                        tone="critical"
                                        title={rules.error}
                                    />
                                ) : rules.data?.data.length ? (
                                    <>
                                        <EntityTable
                                            rows={rules.data.data}
                                            rowKey={(r) => r.id}
                                            identity={(r) => ({
                                                name: r.label,
                                                subline: r.resource,
                                                icon: ClipboardList,
                                            })}
                                            columns={[
                                                {
                                                    key: 'boundary',
                                                    label: 'Boundary',
                                                    width: '120px',
                                                    cell: (r) =>
                                                        'BG-' + r.boundary_id,
                                                },
                                                {
                                                    key: 'revision',
                                                    label: 'Rule version',
                                                    width: '120px',
                                                    cell: (r) => r.revision,
                                                },
                                                {
                                                    key: 'state',
                                                    label: 'State',
                                                    width: '180px',
                                                    cell: (r) => (
                                                        <StatusBadge
                                                            variant={
                                                                r.source_changed
                                                                    ? 'warning'
                                                                    : 'neutral'
                                                            }
                                                        >
                                                            {r.source_changed
                                                                ? 'Source needs review'
                                                                : 'Inactive proposal'}
                                                        </StatusBadge>
                                                    ),
                                                },
                                            ]}
                                            actionsFor={(r) => [
                                                {
                                                    label: 'View purpose rule',
                                                    icon: Eye,
                                                    onClick: () =>
                                                        setRuleDetail(r),
                                                },
                                                ...(canManage
                                                    ? [
                                                          {
                                                              label: 'Edit inactive rule',
                                                              icon: Pencil,
                                                              onClick: () =>
                                                                  openRule(r),
                                                          },
                                                          {
                                                              label: 'Remove inactive rule',
                                                              icon: Archive,
                                                              onClick: () =>
                                                                  setLifecycle({
                                                                      kind: 'remove-rule',
                                                                      rule: r,
                                                                  }),
                                                          },
                                                      ]
                                                    : []),
                                            ]}
                                            onOpen={setRuleDetail}
                                        />
                                        <Pagination
                                            page={page}
                                            last={rules.data.last_page}
                                            total={rules.data.total}
                                            onChange={(page) => go({ page })}
                                        />
                                    </>
                                ) : (
                                    <Empty title="No rules match">
                                        Create a purpose-specific proposal from
                                        a shared boundary or an existing
                                        profile.
                                    </Empty>
                                )}
                            </>
                        )}
                        {tab === 'events' && (
                            <>
                                <h2 className="text-section-title">
                                    Events & follow-up
                                </h2>
                                <Notice title="Original evidence and existing response ownership">
                                    The original observation time is retained.
                                    Continue acknowledgement, notes, evidence
                                    and resolution in Control Room.
                                </Notice>
                                {events.loading ? (
                                    <p role="status">Loading events…</p>
                                ) : events.error ? (
                                    <Notice
                                        tone="critical"
                                        title={events.error}
                                    />
                                ) : events.data?.data.length ? (
                                    <>
                                        <EntityTable
                                            rows={events.data.data}
                                            rowKey={(e) => e.id}
                                            identity={(e) => ({
                                                name: e.resource,
                                                subline: e.kind,
                                                icon: Activity,
                                            })}
                                            columns={[
                                                {
                                                    key: 'time',
                                                    label: 'Observed',
                                                    width: '1.4fr',
                                                    cell: (e) =>
                                                        formatDateTime(
                                                            e.observed_at,
                                                        ),
                                                },
                                                {
                                                    key: 'geometry',
                                                    label: 'Geometry at event',
                                                    width: '1fr',
                                                    cell: (e) =>
                                                        e.boundary_version
                                                            ? 'Version ' +
                                                              e.boundary_version
                                                            : 'Not retained',
                                                },
                                                {
                                                    key: 'delivery',
                                                    label: 'Delivery',
                                                    width: '1fr',
                                                    cell: (e) => e.delivery,
                                                },
                                            ]}
                                            actionsFor={(e) => [
                                                {
                                                    label: 'View event & follow-up',
                                                    icon: Eye,
                                                    onClick: () => setEvent(e),
                                                },
                                            ]}
                                            onOpen={setEvent}
                                        />
                                        <Pagination
                                            page={page}
                                            last={events.data.last_page}
                                            total={events.data.total}
                                            onChange={(page) => go({ page })}
                                        />
                                    </>
                                ) : (
                                    <Empty title="No permitted boundary events">
                                        No events here does not establish
                                        anyone’s safety or a device’s health.
                                    </Empty>
                                )}
                            </>
                        )}
                        {tab === 'history' && (
                            <BoundaryHistory
                                onEdit={(b) => setWizard({ existing: b })}
                                onPeople={setPeople}
                                onRule={setRuleDetail}
                                onRules={(b) => {
                                    setSearch('');
                                    go({
                                        tab: 'rules',
                                        boundary_id: b.id,
                                        page: 1,
                                        q: null,
                                        review: null,
                                        follow_up: null,
                                    });
                                }}
                                onEvents={(b) => {
                                    setSearch('');
                                    go({
                                        tab: 'events',
                                        boundary_id: b.id,
                                        page: 1,
                                        q: null,
                                        review: null,
                                        follow_up: null,
                                    });
                                }}
                                selected={chosen}
                                onSelect={(b) => go({ selected: b.id })}
                                canManage={canManage}
                                onCopy={(b) =>
                                    setWizard({ existing: b, copy: true })
                                }
                                refresh={refresh}
                            />
                        )}
                        <footer className="text-xs text-muted-foreground">
                            One shared boundary · independent rules ·
                            accountable response
                        </footer>
                    </div>
                    {wizard && canManage && (
                        <BoundaryWizard
                            {...wizard}
                            initialSite={site ?? undefined}
                            boundaries={mapped}
                            capabilities={addressSearch}
                            handoffToken={handoffToken}
                            onClose={() => {
                                setWizard(null);
                                go({ new: null, edit: null });
                            }}
                            onSaved={save}
                            onView={(b) => {
                                setWizard(null);
                                inspect(b);
                            }}
                        />
                    )}
                    {ruleWizard && canManage && (
                        <RuleWizard
                            {...ruleWizard}
                            onClose={() => setRuleWizard(null)}
                            onSaved={() => {
                                setRefresh((n) => n + 1);
                                setToast('Inactive rule saved.');
                            }}
                        />
                    )}
                    {detail && (
                        <SectionDialog
                            title={detail.name}
                            description={`BG-${detail.id} · geometry v${detail.geometry_version} · revision ${detail.revision}`}
                            onClose={() => setDetail(null)}
                            footer={
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setDetail(null);
                                            go({
                                                tab: 'history',
                                                selected: detail.id,
                                                page: 1,
                                            });
                                        }}
                                    >
                                        History
                                    </Button>
                                    {canManage && !detail.retired_at && (
                                        <Button
                                            onClick={() => {
                                                setDetail(null);
                                                setWizard({ existing: detail });
                                            }}
                                        >
                                            Edit boundary
                                        </Button>
                                    )}
                                </>
                            }
                            sections={[
                                {
                                    key: 'area',
                                    label: 'Shared area',
                                    blurb: 'Geometry, site and uses',
                                    icon: Shapes,
                                    content: (
                                        <div className="bnd-form flow-stack">
                                            <BoundaryMap
                                                shape={detail.geometry}
                                                className="editor-map"
                                            />
                                            <Facts
                                                rows={[
                                                    [
                                                        'Owning site',
                                                        detail.site ??
                                                            'Owning resource',
                                                    ],
                                                    [
                                                        'Address',
                                                        detail.address ??
                                                            'Not recorded',
                                                    ],
                                                    [
                                                        'Permitted uses',
                                                        detail.uses.join(', '),
                                                    ],
                                                    [
                                                        'Availability',
                                                        detail.retired_at
                                                            ? 'Retired'
                                                            : 'Available',
                                                    ],
                                                    [
                                                        'Legacy monitoring',
                                                        detail.legacy_monitoring
                                                            ? 'Existing profile monitoring remains active'
                                                            : 'No legacy monitoring link',
                                                    ],
                                                ]}
                                            />
                                        </div>
                                    ),
                                },
                                {
                                    key: 'integration',
                                    label: 'Linked workflows',
                                    blurb: 'Purpose and canonical owners',
                                    icon: ShieldCheck,
                                    content: (
                                        <div className="bnd-form flow-stack">
                                            <Notice title="Shared area, independent authority">
                                                Sites and location profiles,
                                                vehicle and asset assignments,
                                                Client Location and consent,
                                                EVV, Devices, Transport and
                                                Control Room keep their existing
                                                ownership. Editing this area
                                                does not establish anyone’s
                                                presence or consent.
                                            </Notice>
                                            <Button
                                                variant="outline"
                                                onClick={() => {
                                                    setDetail(null);
                                                    go({
                                                        tab: 'rules',
                                                        q: null,
                                                        page: 1,
                                                    });
                                                }}
                                            >
                                                View permitted purpose rules
                                            </Button>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setPeople(detail)
                                                }
                                            >
                                                Person / client connection
                                            </Button>
                                            {detail.site_id && (
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                >
                                                    <a
                                                        href={
                                                            '/sites/' +
                                                            detail.site_id
                                                        }
                                                    >
                                                        Open owning site
                                                    </a>
                                                </Button>
                                            )}
                                        </div>
                                    ),
                                },
                            ]}
                        />
                    )}
                    {people && (
                        <Modal
                            title="Connect through Client Location"
                            description={
                                people.name +
                                ' · shared geometry and separate personal authority'
                            }
                            width={720}
                            onClose={() => setPeople(null)}
                        >
                            <div className="bnd-dialog flow-stack">
                                <Notice
                                    title={
                                        people.personal_eligible
                                            ? 'Eligible source for Client Location review'
                                            : 'Personal use is not currently eligible'
                                    }
                                >
                                    Start from the authorised person’s Location
                                    profile to preserve their draft, consent and
                                    assignment. Selecting geometry alone never
                                    activates monitoring.
                                </Notice>
                                <p>
                                    Fleet links do not carry person IDs,
                                    locations or consent evidence. The person
                                    workspace rechecks the boundary version and
                                    current authority on return.
                                </p>
                            </div>
                        </Modal>
                    )}
                    {retire && (
                        <Modal
                            title={'Retire ' + retire.name + '?'}
                            description="Remove the area from new selections while keeping its evidence."
                            onClose={() => !busy && setRetire(null)}
                            footer={
                                <>
                                    <Button
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() => setRetire(null)}
                                    >
                                        Keep boundary
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        disabled={
                                            busy ||
                                            impact.loading ||
                                            !!impact.error ||
                                            !impact.data ||
                                            impact.data.impact
                                                .retirement_blocked ||
                                            retireReason.trim().length < 3
                                        }
                                        onClick={retireBoundary}
                                    >
                                        Retire boundary
                                    </Button>
                                </>
                            }
                        >
                            <div className="bnd-dialog flow-stack">
                                {error && (
                                    <Notice tone="critical" title={error} />
                                )}{' '}
                                {impact.loading ? (
                                    <p>Checking dependencies…</p>
                                ) : impact.error ? (
                                    <Notice
                                        tone="critical"
                                        title={impact.error}
                                    />
                                ) : impact.data?.impact.retirement_blocked ? (
                                    <Notice
                                        tone="warning"
                                        title="Retirement is blocked by linked dependencies"
                                    >
                                        Their authorised owners must remove or
                                        replace the links. Private records are
                                        not disclosed.
                                    </Notice>
                                ) : (
                                    <>
                                        <Notice title="No blocking dependencies found">
                                            The server rechecks before saving.
                                            Retirement does not generate a
                                            crossing.
                                        </Notice>
                                        <label className="field">
                                            Reason
                                            <Input
                                                value={retireReason}
                                                onChange={(e) =>
                                                    setRetireReason(
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                    </>
                                )}
                            </div>
                        </Modal>
                    )}
                    {ruleDetail && (
                        <Modal
                            title={ruleDetail.label}
                            description={
                                'Rule version ' +
                                ruleDetail.revision +
                                ' · inactive assignment'
                            }
                            width={720}
                            onClose={() => setRuleDetail(null)}
                            footer={
                                <>
                                    {canManage && (
                                        <Button
                                            onClick={() => openRule(ruleDetail)}
                                        >
                                            Edit proposal
                                        </Button>
                                    )}
                                    <Button
                                        variant="outline"
                                        onClick={() => setRuleDetail(null)}
                                    >
                                        Close
                                    </Button>
                                </>
                            }
                        >
                            <div className="bnd-dialog flow-stack">
                                <Facts
                                    rows={[
                                        ['Resource', ruleDetail.resource],
                                        ['Purpose', ruleDetail.purpose],
                                        [
                                            'Boundary',
                                            'BG-' + ruleDetail.boundary_id,
                                        ],
                                        [
                                            'Source',
                                            ruleDetail.source_changed
                                                ? 'Changed · review required'
                                                : 'Current reviewed shape',
                                        ],
                                        [
                                            'Timing',
                                            ruleDetail.policy?.timing ??
                                                (ruleDetail.schedule
                                                    ? 'Scheduled'
                                                    : 'Unconfigured'),
                                        ],
                                        [
                                            'Response',
                                            ruleDetail.response_proposal ??
                                                'Not proposed',
                                        ],
                                    ]}
                                />
                                <PolicySummary
                                    schedule={ruleDetail.schedule}
                                    policy={ruleDetail.policy}
                                />
                                <BoundaryMap
                                    shape={ruleDetail.geometry}
                                    className="location-map"
                                />
                                <Notice title="Monitoring inactive">
                                    The proposal is saved independently of
                                    existing profile monitoring.
                                </Notice>
                            </div>
                        </Modal>
                    )}
                    {event && (
                        <SectionDialog
                            title={event.resource + ' · ' + event.kind}
                            description={'Recorded boundary event ' + event.id}
                            onClose={() => setEvent(null)}
                            sections={[
                                {
                                    key: 'record',
                                    label: 'Original evidence',
                                    blurb: 'Observation and geometry',
                                    icon: Activity,
                                    content: (
                                        <div className="bnd-form flow-stack">
                                            <Facts
                                                rows={[
                                                    [
                                                        'Observed',
                                                        formatDateTime(
                                                            event.observed_at,
                                                        ),
                                                    ],
                                                    [
                                                        'Received',
                                                        formatDateTime(
                                                            event.received_at,
                                                        ),
                                                    ],
                                                    [
                                                        'Delivery',
                                                        event.delivery,
                                                    ],
                                                    [
                                                        'Boundary version',
                                                        event.boundary_version ??
                                                            'Not retained',
                                                    ],
                                                ]}
                                            />
                                            {event.geometry ? (
                                                <BoundaryMap
                                                    shape={event.geometry}
                                                    className="location-map"
                                                />
                                            ) : (
                                                <Notice title="Original shape not retained">
                                                    The current boundary is not
                                                    substituted for historical
                                                    evidence.
                                                </Notice>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'response',
                                    label: 'Follow-up',
                                    blurb: 'Existing Control Room record',
                                    icon: ClipboardList,
                                    content: (
                                        <div className="bnd-form flow-stack">
                                            <Notice title="One response record">
                                                Acknowledgement, tasks,
                                                attachments, escalation and
                                                closure remain with the existing
                                                Control Room workflow.
                                            </Notice>
                                            {event.follow_up_href ? (
                                                <Button asChild>
                                                    <a
                                                        href={
                                                            event.follow_up_href
                                                        }
                                                    >
                                                        Open Control Room
                                                        follow-up
                                                    </a>
                                                </Button>
                                            ) : (
                                                <p>
                                                    Your current permissions do
                                                    not allow this response
                                                    workspace.
                                                </p>
                                            )}
                                        </div>
                                    ),
                                },
                            ]}
                        />
                    )}
                    {settingsOpen && (
                        <Modal
                            title="Map service status"
                            description="Availability of the configured map and address services"
                            onClose={() => setSettingsOpen(false)}
                            footer={
                                <Button
                                    variant="outline"
                                    onClick={() => setSettingsOpen(false)}
                                >
                                    Close
                                </Button>
                            }
                        >
                            <div className="bnd-dialog flow-stack">
                                <Facts
                                    rows={[
                                        [
                                            'Map imagery',
                                            mapProvider?.url
                                                ? 'Configured'
                                                : 'Unavailable',
                                        ],
                                        [
                                            'Address search',
                                            addressSearch.enabled
                                                ? 'Enabled'
                                                : 'Not enabled',
                                        ],
                                        [
                                            'Address suggestions',
                                            addressSearch.enabled &&
                                            addressSearch.autocomplete
                                                ? 'Enabled while typing'
                                                : 'Use explicit search or manual coordinates',
                                        ],
                                    ]}
                                />
                                <Notice title="Shared geometry remains available">
                                    Choose an approved site, enter coordinates
                                    or edit the boundary when an external
                                    service is unavailable. Provider changes are
                                    managed in application configuration.
                                </Notice>
                            </div>
                        </Modal>
                    )}
                    {lifecycle && (
                        <LifecycleDialog
                            action={lifecycle}
                            onClose={() => setLifecycle(null)}
                            onSaved={() => {
                                setRefresh((n) => n + 1);
                                setToast('Change saved with its reason.');
                            }}
                        />
                    )}
                    {menu && (
                        <EntityContextMenu
                            {...menu}
                            onClose={() => setMenu(null)}
                        />
                    )}{' '}
                    {toast && (
                        // eslint-disable-next-line no-restricted-syntax -- Positioned live-status toast, not a content card.
                        <div
                            role="status"
                            className="fixed bottom-5 left-1/2 z-50 -translate-x-1/2 rounded-lg border bg-card px-5 py-3 shadow-lg"
                        >
                            {toast}
                        </div>
                    )}
                </PageShell>
            </MapProviderContext.Provider>
        </AppLayout>
    );
}
