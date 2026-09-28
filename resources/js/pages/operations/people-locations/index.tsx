import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    EntityContextMenu,
    EntityKebab,
    ListCaption,
    PersonDisc,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import Analytics from '@/components/people-locations/analytics';
import { HistoryPanel } from '@/components/people-locations/history-panel';
import {
    cohortLabel,
    filterPeople,
    labels,
    time,
    type Person,
    type View,
    type Workspace,
} from '@/components/people-locations/model';
import type { MapCamera } from '@/components/people-locations/people-map';
import PeopleMap from '@/components/people-locations/people-map';
import { PeopleView } from '@/components/people-locations/people-view';
import { PreferencesPanel } from '@/components/people-locations/preferences';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { ReportDialog } from '@/components/people-locations/report-dialog';
import {
    ResponseSummary,
    Responses,
} from '@/components/people-locations/responses';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    FileClock,
    LayoutGrid,
    List,
    MapPin,
    RefreshCw,
    Settings,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

const tabs = [
    { key: 'map', label: 'Map', icon: MapPin },
    { key: 'people', label: 'People', icon: Users },
    { key: 'analytics', label: 'Analytics', icon: BarChart3 },
    { key: 'alerts', label: 'Alerts', icon: Bell },
    { key: 'history', label: 'History & reports', icon: FileClock },
    { key: 'settings', label: 'Settings', icon: Settings },
] as const;
export default function PeopleLocations(props: { view: View }) {
    const { url } = usePage();
    const camera = useRef<MapCamera | null>(null);
    return <WorkspacePage key={url} {...props} url={url} camera={camera} />;
}
function WorkspacePage({
    view,
    url,
    camera,
}: {
    view: View;
    url: string;
    camera: React.MutableRefObject<MapCamera | null>;
}) {
    const params = new URLSearchParams(url.split('?')[1]);
    const [snapshot, setSnapshot] = useState<Workspace | null>(null),
        [query, setQuery] = useState(params.get('q') ?? '');
    const [error, setError] = useState(''),
        [loading, setLoading] = useState(true);
    const [context, setContext] = useState<{
            x: number;
            y: number;
            person: Person;
        } | null>(null),
        [exporting, setExporting] = useState(false);
    const [exportJourney, setExportJourney] = useState('');
    const reportOpened = useRef(false);
    const pending = useRef<AbortController | null>(null);
    const refresh = useCallback(async () => {
        if (pending.current || document.hidden) return;
        const abort = new AbortController();
        pending.current = abort;
        setLoading(true);
        const timeout = window.setTimeout(() => abort.abort(), 25_000);
        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: abort.signal,
                cache: 'no-store',
            });
            if (
                [401, 403, 419].includes(response.status) ||
                response.redirected
            ) {
                setSnapshot(null);
                throw new Error(
                    'Location access has ended. Reopen this workspace after signing in.',
                );
            }
            if (!response.ok)
                throw new Error(
                    'Access could not be revalidated. Location evidence is hidden until a successful refresh.',
                );
            const next = (await response.json()) as Workspace;
            if (abort.signal.aborted || pending.current !== abort) return;
            setSnapshot(next);
            setContext((current) => {
                const person = next.people.find(
                    (p) => p.id === current?.person.id,
                );
                return current && person ? { ...current, person } : null;
            });
            setError('');
            if (
                !next.canExport ||
                !next.people.some((p) => p.id === next.filters.selected)
            )
                setExporting(false);
        } catch (e) {
            if (pending.current === abort) {
                setSnapshot(null);
                setContext(null);
                setExporting(false);
                setError(
                    abort.signal.aborted
                        ? 'Access check timed out. Location evidence is hidden; retry when the service is available.'
                        : e instanceof Error
                          ? e.message
                          : 'Access could not be checked.',
                );
            }
        } finally {
            window.clearTimeout(timeout);
            if (pending.current === abort) {
                pending.current = null;
                setLoading(false);
            }
        }
    }, [url]);
    useEffect(() => {
        void refresh();
        const timer = window.setInterval(() => void refresh(), 30_000);
        const focus = () => {
            if (!document.hidden) void refresh();
        };
        const visibility = () => {
            if (document.hidden) {
                pending.current?.abort();
                pending.current = null;
                setSnapshot(null);
                setContext(null);
                setExporting(false);
            } else void refresh();
        };
        window.addEventListener('focus', focus);
        document.addEventListener('visibilitychange', visibility);
        return () => {
            clearInterval(timer);
            pending.current?.abort();
            pending.current = null;
            window.removeEventListener('focus', focus);
            document.removeEventListener('visibilitychange', visibility);
        };
    }, [refresh]);
    const go = (next: View, changes: Record<string, string> = {}) => {
        const updated = new URLSearchParams(url.split('?')[1]);
        updated.set('q', query);
        Object.entries(changes).forEach(([k, v]) =>
            v ? updated.set(k, v) : updated.delete(k),
        );
        router.get(
            `/operations/people-locations/${next}?${updated}`,
            {},
            { preserveScroll: next === view, preserveState: true },
        );
    };
    const people = snapshot?.people ?? [],
        cohort = params.get('cohort') ?? 'all',
        sort = params.get('sort') ?? 'name';
    const rows = filterPeople(
            people,
            query,
            cohort,
            sort,
            snapshot?.checkedAt ?? Date.now(),
        ),
        selected = people.find((p) => p.id === snapshot?.filters.selected);
    const history = snapshot?.history,
        alerts =
            snapshot?.alerts.filter((a) =>
                rows.some((p) => p.id === a.personId),
            ) ?? [];
    const alertStatus = params.get('alertStatus') ?? '';
    const visibleAlerts = alerts.filter(
        (a) => !alertStatus || a.status === alertStatus,
    );
    const outsideFilter = selected && !rows.some((p) => p.id === selected.id);
    const peopleView =
            params.get('peopleView') === 'list'
                ? 'list'
                : params.get('peopleView') === 'cards'
                  ? 'cards'
                  : (snapshot?.preferences?.value.peopleView ?? 'cards'),
        dataView = params.get('chartView') === 'data';
    const select = (person: Person, next: View = view) => {
        if (next === 'map' && person.position)
            camera.current = {
                center: [person.position.lat, person.position.lng],
                zoom: camera.current?.zoom ?? 16,
                focusSelection: true,
            };
        go(next, {
            selected: person.id,
            source: person.sources.length === 1 ? person.sources[0].id : '',
            journey: '',
            report: '',
        });
    };
    const requestedReport = params.get('report');
    useEffect(() => {
        if (
            requestedReport === 'day' &&
            snapshot?.canExport &&
            snapshot.history &&
            !snapshot.history.needsSource &&
            !reportOpened.current
        ) {
            reportOpened.current = true;
            setExportJourney('');
            setExporting(true);
        }
    }, [requestedReport, snapshot]);
    const actions = (p: Person): MenuItem[] => [
        { label: 'Show on map', icon: MapPin, onClick: () => select(p, 'map') },
        {
            label:
                p.kind === 'client'
                    ? 'Open client Location'
                    : 'Open safety session',
            icon: Users,
            onClick: () => router.visit(p.profileUrl),
        },
        ...(p.kind === 'client' && snapshot?.canViewHistory
            ? [
                  {
                      label: 'Day & outing reports',
                      icon: FileClock,
                      onClick: () => select(p, 'history'),
                  },
              ]
            : []),
        ...(p.transportUrl
            ? [
                  {
                      label: 'Open Transport & Handover',
                      onClick: () => router.visit(p.transportUrl!),
                  },
              ]
            : []),
        ...(snapshot?.alerts ?? [])
            .filter((a) => a.personId === p.id)
            .map((a) => ({
                label: `Control Room · ${a.reference}`,
                icon: Bell,
                onClick: () => router.visit(a.href),
            })),
    ];
    const openContext = (e: React.MouseEvent, p: Person) => {
        e.preventDefault();
        const rect = e.currentTarget.getBoundingClientRect();
        setContext({
            x: e.clientX || rect.left,
            y: e.clientY || rect.bottom,
            person: p,
        });
    };
    const sourceId = String(
        snapshot?.filters.source ||
            (selected?.sources.length === 1 ? selected.sources[0].id : ''),
    );
    const drill = (next: string) =>
        go('people', {
            cohort: [
                ...new Set(
                    [cohort === 'all' ? '' : cohort, next].filter(Boolean),
                ),
            ].join('|'),
        });
    const historyControls = snapshot ? (
        <div className="pl-history-controls">
            <div className="min-w-60">
                <Label>Person</Label>
                <RecordPicker
                    label="Person"
                    value={selected?.id ?? ''}
                    options={people.map((p) => ({
                        value: p.id,
                        label: p.name,
                        description: `${p.reference} · ${p.site.name}`,
                    }))}
                    onChange={(id) => {
                        const person = people.find((p) => p.id === id);
                        if (person) select(person);
                    }}
                />
            </div>
            {selected && (
                <div className="min-w-60">
                    <Label>Source</Label>
                    <RecordPicker
                        label="Source"
                        value={sourceId}
                        options={selected.sources.map((source) => ({
                            value: source.id,
                            label: source.label,
                            description: `${source.reference} · ${source.purpose}`,
                        }))}
                        onChange={(source) =>
                            go(view, { source, journey: '', report: '' })
                        }
                    />
                </div>
            )}
            <div>
                <Label htmlFor="report-date">Recorded day</Label>
                <DatePicker
                    id="report-date"
                    label="Recorded day"
                    value={snapshot.filters.date}
                    onChange={(date) => {
                        const today = new Intl.DateTimeFormat('en-CA', {
                            timeZone: 'Pacific/Auckland',
                        }).format(new Date());
                        if (date > today) {
                            setError(
                                'Choose today or an earlier recorded day.',
                            );
                            return;
                        }
                        go(view, { date, journey: '', report: '' });
                    }}
                />
            </div>
        </div>
    ) : null;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations/activity' },
                {
                    title: 'People Locations',
                    href: '/operations/people-locations',
                },
                {
                    title: tabs.find((tab) => tab.key === view)?.label ?? 'Map',
                    href: `/operations/people-locations/${view}`,
                },
            ]}
        >
            <Head title="People Locations" />
            <div className="flex min-w-0 flex-col gap-5">
                <PageHeader
                    icon={Users}
                    title="People Locations"
                    subline={
                        snapshot
                            ? 'Client and staff observations · Pacific/Auckland'
                            : 'Checking current access before showing personal evidence'
                    }
                    actions={
                        <>
                            <PageHeaderSearch
                                value={query}
                                onChange={setQuery}
                                placeholder="Search people, site or source…"
                            />
                            <Button
                                variant="secondary"
                                onClick={() => void refresh()}
                                disabled={loading}
                            >
                                <RefreshCw
                                    className={`size-4 ${loading ? 'animate-spin' : ''}`}
                                />
                                Refresh
                            </Button>
                        </>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="People in view"
                                onClick={() => go('people')}
                            >
                                <PageHeaderMeterBig>
                                    {rows.length}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {
                                        rows.filter((p) => p.kind === 'client')
                                            .length
                                    }{' '}
                                    clients ·{' '}
                                    {
                                        rows.filter((p) => p.kind === 'staff')
                                            .length
                                    }{' '}
                                    staff
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Recent positions"
                                onClick={() => drill('position:recent')}
                            >
                                <PageHeaderMeterBig>
                                    {
                                        rows.filter(
                                            (p) => p.positionState === 'recent',
                                        ).length
                                    }
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Reported in the last 15 minutes
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Stale positions"
                                onClick={() => drill('position:stale')}
                            >
                                <PageHeaderMeterBig>
                                    {
                                        rows.filter(
                                            (p) => p.positionState === 'stale',
                                        ).length
                                    }
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Last known position only
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Low battery"
                                onClick={() => drill('battery:low')}
                            >
                                <PageHeaderMeterBig>
                                    {
                                        rows.filter(
                                            (p) =>
                                                p.battery != null &&
                                                p.battery <= 20,
                                        ).length
                                    }
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Latest reported sample ≤20%
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    filters={
                        <fieldset
                            className="pl-workspace-filters"
                            disabled={!snapshot}
                            aria-label="People location filters"
                        >
                            <span className="text-[11px] leading-snug text-primary-foreground/80">
                                Permitted records only
                            </span>
                            <div className="pl-workspace-filter-controls">
                                <PageHeaderViewToggle
                                    ariaLabel="Population"
                                    value={
                                        snapshot?.filters.population ?? 'both'
                                    }
                                    options={[
                                        { value: 'clients', label: 'Clients' },
                                        ...(snapshot?.staffAvailable
                                            ? [
                                                  {
                                                      value: 'staff',
                                                      label: 'Staff',
                                                  },
                                              ]
                                            : []),
                                        { value: 'both', label: 'Both' },
                                    ]}
                                    onChange={(population) =>
                                        go(view, {
                                            population,
                                            selected: '',
                                            source: '',
                                        })
                                    }
                                />
                                <RecordPicker
                                    variant="header"
                                    label="Site"
                                    value={snapshot?.filters.site ?? 'all'}
                                    active={Boolean(
                                        snapshot &&
                                        snapshot.filters.site !== 'all',
                                    )}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All permitted sites',
                                        },
                                        ...(snapshot?.sites ?? []).map(
                                            (site) => ({
                                                value: String(site.id),
                                                label: site.name,
                                            }),
                                        ),
                                    ]}
                                    onChange={(site) =>
                                        go(view, {
                                            site,
                                            selected: '',
                                            source: '',
                                        })
                                    }
                                />
                                <RecordPicker
                                    variant="header"
                                    label="Evidence"
                                    value={cohort}
                                    active={cohort !== 'all'}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All evidence states',
                                        },
                                        {
                                            value: 'position:recent',
                                            label: 'Recent position',
                                        },
                                        {
                                            value: 'position:stale',
                                            label: 'Stale position',
                                        },
                                        {
                                            value: 'position:unknown',
                                            label: 'Position unavailable',
                                        },
                                        ...(![
                                            'all',
                                            'position:recent',
                                            'position:stale',
                                            'position:unknown',
                                        ].includes(cohort)
                                            ? [
                                                  {
                                                      value: cohort,
                                                      label: cohortLabel(
                                                          cohort,
                                                          snapshot?.sites,
                                                      ),
                                                  },
                                              ]
                                            : []),
                                    ]}
                                    onChange={(cohort) => go(view, { cohort })}
                                />
                                <div className="pl-ins-filter-label">
                                    <span>Sort</span>
                                    <RecordPicker
                                        variant="header"
                                        label="Sort"
                                        value={sort}
                                        active={sort !== 'name'}
                                        options={[
                                            {
                                                value: 'name',
                                                label: 'Name A–Z',
                                            },
                                            {
                                                value: 'position',
                                                label: 'Latest position',
                                            },
                                            {
                                                value: 'battery',
                                                label: 'Lowest battery',
                                            },
                                            {
                                                value: 'attention',
                                                label: 'Source attention',
                                            },
                                        ]}
                                        onChange={(sort) => go(view, { sort })}
                                    />
                                </div>
                                {view === 'people' && (
                                    <PageHeaderViewToggle
                                        ariaLabel="People display"
                                        value={peopleView}
                                        onChange={(peopleView) =>
                                            go(view, { peopleView })
                                        }
                                        options={[
                                            {
                                                value: 'cards',
                                                label: 'Cards',
                                                icon: LayoutGrid,
                                            },
                                            {
                                                value: 'list',
                                                label: 'List',
                                                icon: List,
                                            },
                                        ]}
                                    />
                                )}
                            </div>
                        </fieldset>
                    }
                    rail={
                        <PageHeaderRail
                            items={tabs.map((tab) => ({
                                ...tab,
                                label:
                                    tab.key === 'alerts' &&
                                    snapshot?.canReadAlerts
                                        ? `Alerts · ${alerts.length}`
                                        : tab.label,
                            }))}
                            value={view}
                            onSelect={(v) => go(v)}
                        />
                    }
                />
                {snapshot && (
                    <div className="pl-refresh-summary" role="status">
                        <RefreshCw className="size-4 shrink-0" />
                        <div className="grow">
                            <strong className="text-subtle">
                                Recorded data snapshot
                            </strong>
                            <p className="text-caption">
                                Checked {time(snapshot.checkedAt)} · Each
                                observation keeps its recorded time.
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={loading}
                            onClick={() => void refresh()}
                        >
                            Check for updates
                        </Button>
                    </div>
                )}
                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                {!snapshot ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {loading
                                    ? 'Checking current access…'
                                    : 'Location access unavailable'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Button
                                onClick={() =>
                                    router.visit('/operations/people-locations')
                                }
                            >
                                {loading ? 'Checking…' : 'Reopen workspace'}
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <ListCaption
                            title={
                                view === 'people'
                                    ? 'People & source health'
                                    : view === 'analytics'
                                      ? 'Location intelligence'
                                      : tabs.find((t) => t.key === view)?.label
                            }
                            caption={
                                view === 'alerts'
                                    ? `${visibleAlerts.length} of ${alerts.length} responses shown`
                                    : view === 'analytics'
                                      ? 'Current source snapshot'
                                      : `${rows.length} of ${people.length} shown`
                            }
                            right={
                                view === 'people' ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => go('map')}
                                    >
                                        <MapPin className="size-4" />
                                        Show on map
                                    </Button>
                                ) : view === 'analytics' ? (
                                    <Button
                                        variant="outline"
                                        aria-pressed={dataView}
                                        onClick={() =>
                                            go(view, {
                                                chartView: dataView
                                                    ? 'charts'
                                                    : 'data',
                                            })
                                        }
                                    >
                                        {dataView
                                            ? 'Show charts'
                                            : 'Show chart data'}
                                    </Button>
                                ) : (
                                    <span className="text-caption">
                                        Checked {time(snapshot.checkedAt)}
                                    </span>
                                )
                            }
                        />
                        {(cohort !== 'all' || query || alertStatus) && (
                            <div className="pl-ins-active-filter">
                                <span>
                                    Filtered selection{' '}
                                    {alertStatus &&
                                        ` · Response status: ${alertStatus.replaceAll('_', ' ')}`}
                                    {query ? ` · “${query}”` : ''}
                                    {cohort !== 'all'
                                        ? ` · ${cohortLabel(cohort, snapshot?.sites ?? [])}`
                                        : ''}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setQuery('');
                                        go(view, {
                                            cohort: '',
                                            q: '',
                                            alertStatus: '',
                                        });
                                    }}
                                >
                                    Clear filters
                                </Button>
                            </div>
                        )}
                        {outsideFilter &&
                            ['map', 'analytics', 'history'].includes(view) && (
                                <Alert>
                                    <AlertDescription>
                                        Selected record:{' '}
                                        <strong>{selected.name}</strong> ·{' '}
                                        {selected.reference}. This person is
                                        outside your search or chart filters.
                                        The selected source and reports still
                                        belong to {selected.name}.
                                        <Button
                                            variant="link"
                                            onClick={() =>
                                                go(view, {
                                                    selected: '',
                                                    source: '',
                                                    journey: '',
                                                    report: '',
                                                })
                                            }
                                        >
                                            Clear selection
                                        </Button>
                                    </AlertDescription>
                                </Alert>
                            )}
                        {view === 'alerts' && (
                            <div className="max-w-80">
                                <PageHeaderFilterSelect
                                    label="Response status"
                                    value={alertStatus || 'all'}
                                    allValue="all"
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All active responses',
                                        },
                                        ...[
                                            ...new Set(
                                                (snapshot.alerts ?? []).map(
                                                    (a) => a.status,
                                                ),
                                            ),
                                        ].map((status) => ({
                                            value: status,
                                            label: status.replaceAll('_', ' '),
                                        })),
                                    ]}
                                    onChange={(status) =>
                                        go(view, {
                                            alertStatus:
                                                status === 'all' ? '' : status,
                                        })
                                    }
                                />
                            </div>
                        )}
                        {view === 'history' && historyControls}
                        {view === 'map' && (
                            <div className="grid min-w-0 grid-cols-[minmax(0,1fr)_340px] gap-5">
                                <PeopleMap
                                    people={rows}
                                    camera={camera}
                                    boundaries={snapshot.boundaries ?? []}
                                    showBoundaries={
                                        snapshot.preferences?.value
                                            .boundaries ?? true
                                    }
                                    selected={selected?.id ?? ''}
                                    onSelect={(p) => select(p)}
                                    onContext={(x, y, person) =>
                                        setContext({ x, y, person })
                                    }
                                />
                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            {selected?.name ??
                                                'Select a person'}
                                        </CardTitle>
                                        <CardDescription>
                                            {selected
                                                ? `${selected.kind} · ${selected.site.name}`
                                                : 'Hover or focus a marker for evidence. Select it for details and actions.'}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        {selected ? (
                                            <>
                                                <div className="flex items-center justify-between">
                                                    <StatusBadge
                                                        variant={
                                                            selected.positionState ===
                                                            'stale'
                                                                ? 'warning'
                                                                : 'neutral'
                                                        }
                                                    >
                                                        {
                                                            labels[
                                                                selected
                                                                    .positionState
                                                            ]
                                                        }
                                                    </StatusBadge>
                                                    <EntityKebab
                                                        actions={actions(
                                                            selected,
                                                        )}
                                                    />
                                                </div>
                                                {selected.sources.length >
                                                    1 && (
                                                    <>
                                                        <Label>
                                                            Location source
                                                        </Label>
                                                        <RecordPicker
                                                            label="Source"
                                                            value={sourceId}
                                                            options={selected.sources.map(
                                                                (source) => ({
                                                                    value: source.id,
                                                                    label: source.label,
                                                                    description:
                                                                        source.reference,
                                                                }),
                                                            )}
                                                            onChange={(
                                                                source,
                                                            ) =>
                                                                go(view, {
                                                                    source,
                                                                })
                                                            }
                                                        />
                                                    </>
                                                )}
                                                <p className="text-sm text-muted-foreground">
                                                    {selected.sources
                                                        .map(
                                                            (s) =>
                                                                `${s.label} · ${s.reference}`,
                                                        )
                                                        .join(' / ')}
                                                </p>
                                                <dl className="space-y-3 text-sm">
                                                    {[
                                                        [
                                                            'Position',
                                                            time(
                                                                selected
                                                                    .position
                                                                    ?.timestamp,
                                                            ),
                                                        ],
                                                        [
                                                            'Accuracy',
                                                            selected.position
                                                                ?.accuracy ==
                                                            null
                                                                ? 'Unknown'
                                                                : `${selected.position.accuracy} m`,
                                                        ],
                                                        [
                                                            'Battery',
                                                            selected.battery ==
                                                            null
                                                                ? 'Unknown'
                                                                : `${selected.battery}% · ${time(selected.batteryAt)}`,
                                                        ],
                                                        [
                                                            'Power',
                                                            `${labels[selected.power]} · ${time(selected.powerAt)}`,
                                                        ],
                                                        [
                                                            'Movement',
                                                            `${labels[selected.motion]} · ${time(selected.motionAt)}`,
                                                        ],
                                                        [
                                                            'Source contact',
                                                            time(
                                                                selected.contactAt,
                                                            ),
                                                        ],
                                                    ].map(([key, val]) => (
                                                        <div key={key}>
                                                            <dt className="text-xs text-muted-foreground">
                                                                {key}
                                                            </dt>
                                                            <dd>{val}</dd>
                                                        </div>
                                                    ))}
                                                </dl>
                                                <p className="text-xs text-muted-foreground">
                                                    {selected.authority}.
                                                    Contact, position and device
                                                    measurements have
                                                    independent times.
                                                </p>
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    className="w-full"
                                                >
                                                    <Link
                                                        href={
                                                            selected.profileUrl
                                                        }
                                                    >
                                                        {selected.kind ===
                                                        'client'
                                                            ? 'Open client Location & zones'
                                                            : 'Open safety session'}
                                                    </Link>
                                                </Button>
                                                <ResponseSummary
                                                    alerts={(
                                                        snapshot.alerts ?? []
                                                    ).filter(
                                                        (a) =>
                                                            a.personId ===
                                                            selected.id,
                                                    )}
                                                    checkedAt={
                                                        snapshot.checkedAt
                                                    }
                                                />
                                                {selected.kind === 'client' &&
                                                    snapshot.canViewHistory && (
                                                        <Button
                                                            className="w-full"
                                                            onClick={() =>
                                                                select(
                                                                    selected,
                                                                    'history',
                                                                )
                                                            }
                                                        >
                                                            Day & outing reports
                                                        </Button>
                                                    )}
                                            </>
                                        ) : (
                                            <Button
                                                variant="outline"
                                                onClick={() => go('people')}
                                            >
                                                Browse permitted people
                                            </Button>
                                        )}
                                        <div className="border-t pt-3">
                                            <h3 className="mb-2 text-sm font-semibold">
                                                People in this view ·{' '}
                                                {rows.length}
                                            </h3>
                                            <div
                                                className="max-h-64 space-y-1 overflow-auto"
                                                role="region"
                                                aria-label="People beside map"
                                                tabIndex={0}
                                            >
                                                {rows.map((p) => (
                                                    <Button
                                                        variant="ghost"
                                                        key={p.id}
                                                        type="button"
                                                        className="h-auto w-full justify-start text-left"
                                                        aria-pressed={
                                                            p.id ===
                                                            selected?.id
                                                        }
                                                        onClick={() =>
                                                            select(p, 'map')
                                                        }
                                                        onContextMenu={(e) =>
                                                            openContext(e, p)
                                                        }
                                                    >
                                                        <PersonDisc
                                                            name={p.name}
                                                        />
                                                        <span>
                                                            <span className="block font-medium">
                                                                {p.name}
                                                            </span>
                                                            <span className="text-xs text-muted-foreground">
                                                                {p.site.name} ·{' '}
                                                                {
                                                                    labels[
                                                                        p
                                                                            .positionState
                                                                    ]
                                                                }
                                                            </span>
                                                        </span>
                                                    </Button>
                                                ))}
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        )}
                        {view === 'people' && (
                            <PeopleView
                                rows={rows}
                                alerts={alerts}
                                canReadAlerts={snapshot.canReadAlerts}
                                view={peopleView}
                                actions={actions}
                                onOpen={(p) => select(p, 'map')}
                                onContext={openContext}
                            />
                        )}
                        {['map', 'people'].includes(view) && !rows.length && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        No permitted people match this view
                                    </CardTitle>
                                    <CardDescription>
                                        Check the search and filters. People
                                        whose consent or session authority has
                                        ended are excluded.
                                    </CardDescription>
                                </CardHeader>
                            </Card>
                        )}
                        {view === 'analytics' && (
                            <Analytics
                                people={rows}
                                alerts={alerts}
                                canReadAlerts={snapshot.canReadAlerts}
                                checkedAt={snapshot.checkedAt}
                                history={history ?? null}
                                historyControls={historyControls}
                                onHistory={() => go('history')}
                                onReport={() =>
                                    go('history', { report: 'day' })
                                }
                                data={dataView}
                                drill={drill}
                                openAlerts={(alertStatus) =>
                                    go('alerts', { alertStatus })
                                }
                            />
                        )}
                        {view === 'alerts' && (
                            <Responses
                                alerts={visibleAlerts}
                                people={people}
                                canRead={snapshot.canReadAlerts}
                                checkedAt={snapshot.checkedAt}
                            />
                        )}
                        {view === 'history' && (
                            <HistoryPanel
                                workspace={snapshot}
                                selected={selected}
                                onJourney={(journey) =>
                                    go(view, { journey, report: '' })
                                }
                                onReport={(journey) => {
                                    setExportJourney(journey ?? '');
                                    setExporting(true);
                                }}
                            />
                        )}
                        {view === 'settings' && (
                            <PreferencesPanel
                                workspace={snapshot}
                                selected={selected}
                                onSaved={(preferences) =>
                                    setSnapshot((current) =>
                                        current
                                            ? { ...current, preferences }
                                            : null,
                                    )
                                }
                            />
                        )}
                    </>
                )}
                <p className="text-xs text-muted-foreground">
                    All times Pacific/Auckland · Last reported evidence · No
                    safety or wellbeing inference
                </p>
            </div>
            {context &&
                snapshot?.people.some((p) => p.id === context.person.id) && (
                    <EntityContextMenu
                        x={context.x}
                        y={context.y}
                        title={context.person.name}
                        items={actions(context.person)}
                        onClose={() => setContext(null)}
                    />
                )}
            {exporting &&
                selected &&
                snapshot?.canExport &&
                history &&
                !history.needsSource && (
                    <ReportDialog
                        workspace={snapshot}
                        initialJourney={exportJourney}
                        person={selected}
                        source={sourceId}
                        onClose={() => setExporting(false)}
                    />
                )}
        </AppLayout>
    );
}
