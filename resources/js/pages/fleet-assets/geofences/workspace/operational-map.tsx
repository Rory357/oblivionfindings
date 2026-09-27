import type { Coordinate } from '@/components/client-location/types';
import { EntityKebab, type MenuItem } from '@/components/lists/entity-menu';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import {
    ArrowRight,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    Eye,
    Layers,
    LocateFixed,
    MapPin,
    Package,
    Plus,
    RefreshCw,
    Shapes,
    ShieldCheck,
    Truck,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { base, query, request, useDebounced } from './api';
import {
    mapBoundary,
    type Boundary,
    type BoundaryRecord,
    type Page,
    type ResourceRecord,
} from './data';
import { mapResource, type MapResource } from './map-data';
import { resourceStatus } from './map-model';
import { OperationalCanvas } from './operational-canvas';
import { RemotePicker } from './remote-picker';
import { Button, Facts, Notice, SectionDialog } from './ui';

export function OperationalMap({
    site,
    canManage,
    onCreate,
    onStart,
    onInspect,
    boundaryActions,
    onRule,
    refresh,
    initialResource,
    initialBoundary,
}: {
    site: number | null;
    canManage: boolean;
    onCreate: (p: Coordinate) => void;
    onStart: () => void;
    onInspect: (b: BoundaryRecord) => void;
    boundaryActions: (b: BoundaryRecord) => MenuItem[];
    onRule: (r: ResourceRecord) => void;
    refresh: number;
    initialResource?: number | null;
    initialBoundary?: BoundaryRecord | null;
}) {
    const [areas, setAreas] = useState<BoundaryRecord[]>([]),
        [records, setRecords] = useState<ResourceRecord[]>([]),
        [areaTotal, setAreaTotal] = useState(0),
        [recordTotal, setRecordTotal] = useState(0),
        [areaPage, setAreaPage] = useState(1),
        [recordPage, setRecordPage] = useState(1),
        [q, setQ] = useState(''),
        [kind, setKind] = useState(''),
        [status, setStatus] = useState(''),
        [retired, setRetired] = useState(false),
        [scope, setScope] = useState('all'),
        [showAreas, setShowAreas] = useState(true),
        [vehicles, setVehicles] = useState(true),
        [assets, setAssets] = useState(true),
        [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [revision, setRevision] = useState(0),
        [selected, setSelected] = useState(''),
        [selectedBoundary, setSelectedBoundary] = useState(''),
        [page, setPage] = useState(1),
        [fit, setFit] = useState(0),
        [focus, setFocus] = useState(0),
        [detail, setDetail] = useState<ResourceRecord | null>(null),
        [asOf, setAsOf] = useState<string | null>(null),
        [clustering, setClustering] = useState(true),
        [accuracy, setAccuracy] = useState(true);
    const generation = useRef(0);
    const lastScope = useRef('');
    const initialPin = useRef<number | null>(null);
    const initialBoundaryPin = useRef<number | null>(null);
    const retained = useRef({
        areaPage: 1,
        recordPage: 1,
        selected: '',
        page: 1,
    });
    retained.current = { areaPage, recordPage, selected, page };
    const search = useDebounced(q);
    useEffect(() => {
        generation.current++;
        const scopeKey = JSON.stringify([site, retired, search, kind]);
        const changedScope = scopeKey !== lastScope.current;
        const keep = changedScope
            ? { areaPage: 1, recordPage: 1, selected: '', page: 1 }
            : retained.current;
        lastScope.current = scopeKey;
        setAreaPage(keep.areaPage);
        setRecordPage(keep.recordPage);
        // Keep the last permitted snapshot visible while refreshing the same
        // scope. A new scope clears immediately; a failed refresh clears below.
        if (changedScope) {
            setAreas([]);
            setRecords([]);
            setAreaTotal(0);
            setRecordTotal(0);
            setSelected('');
            setSelectedBoundary('');
            setAsOf(null);
        }
        setPage(keep.page);
        const c = new AbortController();
        setBusy(true);
        setError('');
        Promise.all([
            request<Page<BoundaryRecord>>(
                query('/catalogue', {
                    site_id: site,
                    status: retired ? 'all' : 'available',
                    size: 300,
                }),
                'GET',
                undefined,
                c.signal,
            ),
            request<Page<ResourceRecord> & { as_of: string }>(
                query('/resources', {
                    site_id: site,
                    q: search,
                    kind,
                    size: 100,
                }),
                'GET',
                undefined,
                c.signal,
            ),
        ])
            .then(async ([a, r]) => {
                // Refresh every explicitly loaded page, retaining a searched-for selection.
                const [extraAreas, extraRecords, pinned] = await Promise.all([
                    Promise.all(
                        Array.from({ length: keep.areaPage - 1 }, (_, i) =>
                            request<Page<BoundaryRecord>>(
                                query('/catalogue', {
                                    site_id: site,
                                    status: retired ? 'all' : 'available',
                                    size: 300,
                                    page: i + 2,
                                }),
                                'GET',
                                undefined,
                                c.signal,
                            ),
                        ),
                    ),
                    Promise.all(
                        Array.from({ length: keep.recordPage - 1 }, (_, i) =>
                            request<Page<ResourceRecord>>(
                                query('/resources', {
                                    site_id: site,
                                    q: search,
                                    kind,
                                    size: 100,
                                    page: i + 2,
                                }),
                                'GET',
                                undefined,
                                c.signal,
                            ),
                        ),
                    ),
                    keep.selected
                        ? request<Page<ResourceRecord>>(
                              query('/resources', {
                                  id: keep.selected,
                                  site_id: site,
                                  q: search,
                                  kind,
                                  size: 1,
                              }),
                              'GET',
                              undefined,
                              c.signal,
                          )
                        : Promise.resolve(null),
                ]);
                const nextAreas = [a, ...extraAreas].flatMap((p) => p.data);
                const nextRecords = [r, ...extraRecords].flatMap((p) => p.data);
                if (
                    pinned?.data[0] &&
                    !nextRecords.some((row) => row.id === pinned.data[0].id)
                )
                    nextRecords.unshift(pinned.data[0]);
                const missing =
                    pinned?.data[0]?.boundary_ids
                        .filter((id) => !nextAreas.some((b) => b.id === id))
                        .slice(0, 30) ?? [];
                const linked = await Promise.all(
                    missing.map((id) =>
                        request<{ boundary: BoundaryRecord }>(
                            base + '/' + id,
                            'GET',
                            undefined,
                            c.signal,
                        ),
                    ),
                );
                nextAreas.push(...linked.map((v) => v.boundary));
                if (!c.signal.aborted) {
                    setAreas(nextAreas);
                    setAreaTotal(a.total);
                    setRecords(nextRecords);
                    setPage(
                        Math.min(
                            keep.page,
                            Math.max(1, Math.ceil(nextRecords.length / 8)),
                        ),
                    );
                    if (
                        keep.selected &&
                        !nextRecords.some(
                            (row) => String(row.id) === keep.selected,
                        )
                    )
                        setSelected('');
                    setDetail((current) =>
                        current
                            ? (nextRecords.find(
                                  (row) => row.id === current.id,
                              ) ?? null)
                            : null,
                    );
                    setRecordTotal(r.total);
                    setAsOf(r.as_of);
                    if (changedScope) setFit((n) => n + 1);
                }
            })
            .catch((e) => {
                if (!c.signal.aborted) {
                    setError(e.message);
                    setAreas([]);
                    setRecords([]);
                    setAreaTotal(0);
                    setRecordTotal(0);
                    setSelected('');
                    setSelectedBoundary('');
                    setAsOf(null);
                    setDetail(null);
                }
            })
            .finally(() => {
                if (!c.signal.aborted) setBusy(false);
            });
        return () => c.abort();
    }, [site, retired, search, kind, revision, refresh]);
    useEffect(() => {
        const refreshVisible = () => {
            if (document.visibilityState === 'visible')
                setRevision((n) => n + 1);
        };
        const timer = setInterval(refreshVisible, 60000);
        window.addEventListener('focus', refreshVisible);
        return () => {
            clearInterval(timer);
            window.removeEventListener('focus', refreshVisible);
        };
    }, []);
    const boundaries = useMemo(
        () => areas.map(mapBoundary).filter((b): b is Boundary => !!b),
        [areas],
    );
    const resources = useMemo(() => records.map(mapResource), [records]);
    const visible = resources.filter(
        (r) =>
            (r.kind === 'Vehicle' ? vehicles : assets) &&
            (!status || resourceStatus(r, boundaries).key === status),
    );
    const selectedRecord = resources.find((r) => r.id === selected);
    const displayed = showAreas
        ? boundaries.filter(
              (b) =>
                  scope === 'all' || selectedRecord?.boundaries.includes(b.id),
          )
        : [];
    const paged = visible.slice((page - 1) * 8, page * 8);
    const pin = useCallback(
        async (r: ResourceRecord) => {
            const token = generation.current;
            setSelectedBoundary('');
            setRecords((rows) => [r, ...rows.filter((x) => x.id !== r.id)]);
            setSelected(String(r.id));
            setStatus('');
            setPage(1);
            setFocus((n) => n + 1);
            const missing = r.boundary_ids.filter(
                (id) => !areas.some((b) => b.id === id),
            );
            if (missing.length) {
                try {
                    const a = await Promise.all(
                        missing
                            .slice(0, 30)
                            .map((id) =>
                                request<{ boundary: BoundaryRecord }>(
                                    base + '/' + id,
                                ),
                            ),
                    );
                    if (token !== generation.current) return;
                    setAreas((old) => [
                        ...old,
                        ...a
                            .map((v) => v.boundary)
                            .filter((b) => !old.some((x) => x.id === b.id)),
                    ]);
                } catch {
                    if (token !== generation.current) return;
                    setError(
                        'Some linked areas could not be loaded. Open the source profile to review them.',
                    );
                }
            }
        },
        [areas],
    );
    useEffect(() => {
        if (
            !initialResource ||
            busy ||
            !asOf ||
            initialPin.current === initialResource
        )
            return;
        initialPin.current = initialResource;
        const existing = records.find((r) => r.id === initialResource);
        if (existing) {
            void pin(existing);
            return;
        }
        const token = generation.current;
        request<Page<ResourceRecord>>(
            query('/resources', { id: initialResource, size: 1 }),
        )
            .then((result) => {
                if (token !== generation.current) return;
                if (result.data[0]) void pin(result.data[0]);
                else setError('The requested record is unavailable.');
            })
            .catch((e) => {
                if (token === generation.current) setError(e.message);
            });
    }, [initialResource, busy, asOf, records, pin]);
    useEffect(() => {
        if (
            !initialBoundary ||
            initialResource ||
            busy ||
            !asOf ||
            initialBoundaryPin.current === initialBoundary.id ||
            (site !== null && initialBoundary.site_id !== site) ||
            (initialBoundary.retired_at && !retired)
        )
            return;
        initialBoundaryPin.current = initialBoundary.id;
        setAreas((rows) =>
            rows.some((b) => b.id === initialBoundary.id)
                ? rows
                : [...rows, initialBoundary],
        );
        setSelected('');
        setSelectedBoundary(String(initialBoundary.id));
        setShowAreas(true);
        setFocus((n) => n + 1);
    }, [initialBoundary, initialResource, busy, asOf, site, retired]);
    const actions = (r: MapResource): MenuItem[] => [
        {
            label: 'View location evidence',
            icon: Eye,
            onClick: () =>
                setDetail(records.find((x) => String(x.id) === r.id) ?? null),
        },
        {
            label: 'Open source profile',
            icon: ExternalLink,
            onClick: () => {
                window.location.assign(r.href);
            },
        },
        ...(canManage
            ? [
                  {
                      label: 'Add inactive purpose rule',
                      icon: Plus,
                      onClick: () => {
                          const raw = records.find(
                              (x) => String(x.id) === r.id,
                          );
                          if (raw) onRule(raw);
                      },
                  },
              ]
            : []),
    ];
    const more = async (type: 'areas' | 'records') => {
        const token = generation.current;
        setBusy(true);
        setError('');
        try {
            if (type === 'areas') {
                const r = await request<Page<BoundaryRecord>>(
                    query('/catalogue', {
                        site_id: site,
                        status: retired ? 'all' : 'available',
                        size: 300,
                        page: areaPage + 1,
                    }),
                );
                if (token !== generation.current) return;
                setAreas((old) => [
                    ...old,
                    ...r.data.filter((b) => !old.some((x) => x.id === b.id)),
                ]);
                setAreaPage(r.page);
                setAreaTotal(r.total);
            } else {
                const r = await request<Page<ResourceRecord>>(
                    query('/resources', {
                        site_id: site,
                        q: search,
                        kind,
                        size: 100,
                        page: recordPage + 1,
                    }),
                );
                if (token !== generation.current) return;
                setRecords((old) => [
                    ...old,
                    ...r.data.filter((b) => !old.some((x) => x.id === b.id)),
                ]);
                setRecordPage(r.page);
                setRecordTotal(r.total);
            }
        } catch (e) {
            if (token === generation.current) setError((e as Error).message);
        } finally {
            if (token === generation.current) setBusy(false);
        }
    };
    const pinnedState = selectedRecord
        ? resourceStatus(selectedRecord, boundaries)
        : null;
    const pinnedArea = areas.find((b) => String(b.id) === selectedBoundary);
    const positionCount = visible.filter((r) => r.position).length;
    const noPosition = visible.length - positionCount;
    const lastPage = Math.max(1, Math.ceil(visible.length / 8));
    const statusTone = (key: string) =>
        key === 'inside'
            ? ('success' as const)
            : ['outside', 'uncertain'].includes(key)
              ? ('warning' as const)
              : ('neutral' as const);
    const boundaryFinder = (
        <RemotePicker<BoundaryRecord>
            label="Find a boundary"
            value={pinnedArea?.name}
            url={(q) =>
                query('/catalogue', {
                    q,
                    site_id: site,
                    status: retired ? 'all' : 'available',
                    size: 20,
                })
            }
            describe={(b) => ({
                id: b.id,
                name: b.name,
                detail: 'BG-' + b.id + ' · ' + (b.site ?? 'Resource-owned'),
            })}
            onSelect={(b) => {
                setAreas((a) => (a.some((x) => x.id === b.id) ? a : [...a, b]));
                setSelected('');
                setSelectedBoundary(String(b.id));
                setFocus((n) => n + 1);
            }}
        />
    );
    return (
        <section className="ops-studio" aria-label="Geofence map studio">
            <div className="ops-heading">
                <div>
                    <span className="eyebrow">Map workspace</span>
                    <h2>Every area. Every permitted record.</h2>
                    <p className="text-xs text-muted-foreground">
                        Recorded positions · checked {formatDateTime(asOf)} ·
                        Pacific/Auckland
                    </p>
                </div>
                <div className="inline-row">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        onClick={() => setRevision((n) => n + 1)}
                    >
                        <RefreshCw />
                        Refresh
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setFit((n) => n + 1)}
                    >
                        <LocateFixed />
                        Fit results
                    </Button>
                    {canManage && (
                        <Button size="sm" onClick={onStart}>
                            <Plus />
                            Build boundary
                        </Button>
                    )}
                </div>
            </div>
            {error && (
                <Notice tone="critical" title={error}>
                    <Button
                        variant="outline"
                        onClick={() => setRevision((n) => n + 1)}
                    >
                        Retry
                    </Button>
                </Notice>
            )}
            {busy && (
                <p className="text-xs text-muted-foreground" role="status">
                    {asOf
                        ? 'Refreshing permitted map data…'
                        : 'Loading permitted map data…'}
                </p>
            )}
            <div className="ops-layout">
                <aside className="ops-layer-panel rounded-xl border bg-card">
                    <div className="ops-panel-heading">
                        <Layers size={18} />
                        <h3>Map layers</h3>
                    </div>
                    <div className="ops-layer-toggles">
                        <label>
                            <Checkbox
                                checked={showAreas}
                                onCheckedChange={(v) =>
                                    setShowAreas(v === true)
                                }
                            />
                            <Shapes size={16} />
                            Boundaries<strong>{areas.length}</strong>
                        </label>
                        <label>
                            <Checkbox
                                checked={vehicles}
                                onCheckedChange={(v) => {
                                    setVehicles(v === true);
                                    setPage(1);
                                }}
                            />
                            <Truck size={16} />
                            Vehicles
                            <strong>
                                {
                                    resources.filter(
                                        (r) => r.kind === 'Vehicle',
                                    ).length
                                }
                            </strong>
                        </label>
                        <label>
                            <Checkbox
                                checked={assets}
                                onCheckedChange={(v) => {
                                    setAssets(v === true);
                                    setPage(1);
                                }}
                            />
                            <Package size={16} />
                            Assets
                            <strong>
                                {
                                    resources.filter((r) => r.kind === 'Asset')
                                        .length
                                }
                            </strong>
                        </label>
                        <small className="text-muted-foreground">
                            {areas.length} / {areaTotal} areas ·{' '}
                            {records.length} / {recordTotal} records loaded
                        </small>
                    </div>
                    <div className="ops-scope flow-stack">
                        <label className="field">
                            Boundary scope
                            <select
                                value={scope}
                                onChange={(e) => setScope(e.target.value)}
                            >
                                <option value="all">
                                    All loaded boundaries
                                </option>
                                <option value="linked">
                                    Linked to selected record
                                </option>
                            </select>
                        </label>
                        <small>
                            Choose an individual area with “Find a boundary” on
                            the map.
                        </small>
                        <label className="check-row">
                            <Checkbox
                                checked={retired}
                                onCheckedChange={(v) => setRetired(v === true)}
                            />
                            Include retired boundaries
                        </label>
                        <label className="check-row">
                            <Checkbox
                                checked={clustering}
                                onCheckedChange={(v) =>
                                    setClustering(v === true)
                                }
                            />
                            Group nearby records
                        </label>
                        <label className="check-row">
                            <Checkbox
                                checked={accuracy}
                                onCheckedChange={(v) => setAccuracy(v === true)}
                            />
                            Show selected accuracy
                        </label>
                        {areas.length < areaTotal && (
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => more('areas')}
                            >
                                Load next 300 areas
                            </Button>
                        )}
                    </div>
                    <div className="ops-record-heading">
                        <h3>Vehicles & assets</h3>
                        <span>{recordTotal}</span>
                    </div>
                    <div className="ops-directory-controls flow-stack">
                        <RemotePicker<ResourceRecord>
                            label="Find a vehicle or asset"
                            value={selectedRecord?.name}
                            url={(q) =>
                                query('/resources', {
                                    q,
                                    site_id: site,
                                    kind:
                                        kind ||
                                        (vehicles && !assets
                                            ? 'Vehicle'
                                            : assets && !vehicles
                                              ? 'Asset'
                                              : ''),
                                    size: 20,
                                })
                            }
                            describe={(r) => ({
                                id: r.id,
                                name: r.name,
                                detail: [r.kind, r.tag, r.site]
                                    .filter(Boolean)
                                    .join(' · '),
                            })}
                            onSelect={(r) => {
                                if (r.kind === 'Vehicle') setVehicles(true);
                                else setAssets(true);
                                void pin(r);
                            }}
                        />
                        <small>
                            Search name, registration or asset ID across
                            permitted records.
                        </small>
                        <Input
                            aria-label="Filter resource directory"
                            placeholder="Filter names or tags"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                        <select
                            aria-label="Resource kind"
                            value={kind}
                            onChange={(e) => setKind(e.target.value)}
                        >
                            <option value="">Vehicles and assets</option>
                            <option>Vehicle</option>
                            <option>Asset</option>
                        </select>
                        <select
                            aria-label="Location status in loaded results"
                            value={status}
                            onChange={(e) => {
                                setStatus(e.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">All location states</option>
                            {[
                                ['inside', 'Inside linked areas'],
                                ['outside', 'Outside linked areas'],
                                ['uncertain', 'Position uncertain'],
                                ['stale', 'Location out of date'],
                                ['missing', 'No location'],
                                ['unlinked', 'No linked areas'],
                                ['unavailable', 'Boundary unavailable'],
                            ].map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="ops-records">
                        {paged.map((r) => {
                            const state = resourceStatus(r, boundaries);
                            return (
                                <div
                                    className={
                                        'ops-record-line ' +
                                        (selected === r.id ? 'selected' : '')
                                    }
                                    key={r.id}
                                >
                                    {/* eslint-disable-next-line no-restricted-syntax -- Resource directory row uses custom icon, text and status columns. */}
                                    <button
                                        onClick={() => {
                                            const raw = records.find(
                                                (x) => String(x.id) === r.id,
                                            );
                                            if (raw) void pin(raw);
                                            setSelectedBoundary('');
                                        }}
                                    >
                                        <span
                                            className={
                                                'ops-record-icon ' + state.key
                                            }
                                        >
                                            {r.kind === 'Vehicle' ? (
                                                <Truck size={17} />
                                            ) : (
                                                <Package size={17} />
                                            )}
                                        </span>
                                        <span>
                                            <strong>{r.name}</strong>
                                            <small>
                                                {r.tag} · {state.label}
                                            </small>
                                        </span>
                                        {selected === r.id && (
                                            <ArrowRight size={14} />
                                        )}
                                    </button>
                                    <EntityKebab actions={actions(r)} />
                                </div>
                            );
                        })}
                        {!paged.length && !busy && (
                            <p className="p-4 text-xs text-muted-foreground">
                                No records match these layers and filters.
                            </p>
                        )}
                    </div>
                    <div className="ops-directory-pagination">
                        <span>
                            {visible.length ? (page - 1) * 8 + 1 : 0}–
                            {Math.min(page * 8, visible.length)} of{' '}
                            {visible.length}
                        </span>
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label="Previous map records"
                            disabled={page <= 1}
                            onClick={() => setPage((n) => n - 1)}
                        >
                            <ChevronLeft />
                        </Button>
                        <select
                            aria-label="Map record page"
                            value={Math.min(page, lastPage)}
                            onChange={(e) => setPage(Number(e.target.value))}
                        >
                            {Array.from({ length: lastPage }, (_, i) => (
                                <option key={i} value={i + 1}>
                                    Page {i + 1}
                                </option>
                            ))}
                        </select>
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label="Next map records"
                            disabled={page >= lastPage}
                            onClick={() => setPage((n) => n + 1)}
                        >
                            <ChevronRight />
                        </Button>
                    </div>
                    {records.length < recordTotal && (
                        <div className="p-3">
                            <Button
                                className="w-full"
                                variant="outline"
                                disabled={busy}
                                onClick={() => more('records')}
                            >
                                Load next 100 records
                            </Button>
                        </div>
                    )}
                    <p className="px-4 pb-4 text-[10px] text-muted-foreground">
                        Status filters apply to loaded records. Unlocated
                        records stay in the directory.
                    </p>
                </aside>
                <div className="ops-map-column">
                    <div className="ops-map-summary">
                        <span>
                            <Shapes size={14} />
                            {displayed.length} boundaries displayed
                        </span>
                        <span>
                            <MapPin size={14} />
                            {positionCount} recorded positions
                        </span>
                        {/* eslint-disable-next-line no-restricted-syntax -- Inline map-legend action matches the adjacent count labels. */}
                        <button
                            onClick={() => {
                                setStatus('missing');
                                setPage(1);
                            }}
                        >
                            {noPosition} without a position
                        </button>
                    </div>
                    <div className="ops-map-stage">
                        <OperationalCanvas
                            boundaries={displayed}
                            statusBoundaries={boundaries}
                            resources={visible}
                            rules={[]}
                            selectedResource={selected}
                            selectedBoundary={selectedBoundary}
                            fit={fit}
                            focus={focus}
                            clustering={clustering}
                            accuracy={accuracy}
                            imagery
                            onResource={(id) => {
                                const r = records.find(
                                    (x) => String(x.id) === id,
                                );
                                if (r) void pin(r);
                                setSelectedBoundary('');
                            }}
                            onBoundary={(id) => {
                                setSelected('');
                                setSelectedBoundary(id);
                            }}
                            onCreate={canManage ? onCreate : undefined}
                            boundaryActions={(b) => boundaryActions(b.raw)}
                            resourceActions={actions}
                            onChooseBoundary={() =>
                                document
                                    .querySelector<HTMLButtonElement>(
                                        '[aria-label="Find a boundary"]',
                                    )
                                    ?.click()
                            }
                            onFit={() => setFit((n) => n + 1)}
                        />
                        <div className="ops-boundary-finder">
                            {boundaryFinder}
                        </div>
                        {selectedRecord && pinnedState ? (
                            <aside
                                className="ops-inspector rounded-xl border bg-card"
                                aria-label="Pinned map details"
                            >
                                <div className="ops-inspector-title">
                                    <span className="icon-tile">
                                        {selectedRecord.kind === 'Vehicle' ? (
                                            <Truck />
                                        ) : (
                                            <Package />
                                        )}
                                    </span>
                                    <div>
                                        <span className="eyebrow">
                                            {selectedRecord.kind} ·{' '}
                                            {selectedRecord.tag}
                                        </span>
                                        <h3>{selectedRecord.name}</h3>
                                    </div>
                                    <EntityKebab
                                        actions={actions(selectedRecord)}
                                    />
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label="Close pinned map details"
                                        onClick={() => setSelected('')}
                                    >
                                        <X />
                                    </Button>
                                </div>
                                <div className="my-3">
                                    <StatusBadge
                                        variant={statusTone(pinnedState.key)}
                                    >
                                        {pinnedState.label}
                                    </StatusBadge>
                                </div>
                                <p className="text-xs leading-relaxed">
                                    {pinnedState.detail}
                                </p>
                                <Facts
                                    rows={[
                                        [
                                            'Observed',
                                            formatDateTime(
                                                selectedRecord.observed,
                                            ),
                                        ],
                                        [
                                            'Received',
                                            formatDateTime(
                                                selectedRecord.received,
                                            ),
                                        ],
                                        [
                                            'Accuracy',
                                            selectedRecord.accuracy === null
                                                ? 'Not supplied'
                                                : '±' +
                                                  selectedRecord.accuracy +
                                                  ' m',
                                        ],
                                        ['Site', selectedRecord.site],
                                    ]}
                                />
                                <div className="ops-relations">
                                    {selectedRecord.boundaries
                                        .slice(0, 30)
                                        .map((id) => {
                                            const b = areas.find(
                                                (x) => String(x.id) === id,
                                            );
                                            const state =
                                                pinnedState.relations.find(
                                                    (x) => x.boundary.id === id,
                                                )?.state;
                                            return b ? (
                                                // eslint-disable-next-line no-restricted-syntax -- Linked-area row lays out the name and evidence state.
                                                <button
                                                    key={id}
                                                    onClick={() => onInspect(b)}
                                                >
                                                    <span>
                                                        <strong>
                                                            {b.name}
                                                        </strong>
                                                        <small>
                                                            {state &&
                                                            pinnedState.key ===
                                                                'stale'
                                                                ? `Last recorded ${state}`
                                                                : state ||
                                                                  'Position needs review'}{' '}
                                                            · geometry v
                                                            {b.geometry_version}
                                                        </small>
                                                    </span>
                                                    <ArrowRight size={14} />
                                                </button>
                                            ) : null;
                                        })}
                                </div>
                                <p className="ops-rule-summary">
                                    {selectedRecord.boundaries.length} linked
                                    areas · rules and monitoring stay with the
                                    owning profile.
                                </p>
                                <div className="ops-inspector-actions">
                                    <Button asChild variant="outline">
                                        <a href={selectedRecord.href}>
                                            Open profile
                                            <ExternalLink />
                                        </a>
                                    </Button>
                                    {canManage && (
                                        <Button
                                            onClick={() => {
                                                const r = records.find(
                                                    (x) =>
                                                        String(x.id) ===
                                                        selected,
                                                );
                                                if (r) onRule(r);
                                            }}
                                        >
                                            <Plus />
                                            Add rule
                                        </Button>
                                    )}
                                </div>
                                <Button
                                    variant="link"
                                    size="sm"
                                    className="mt-2"
                                    onClick={() =>
                                        setDetail(
                                            records.find(
                                                (x) =>
                                                    String(x.id) === selected,
                                            ) ?? null,
                                        )
                                    }
                                >
                                    View source evidence
                                </Button>
                            </aside>
                        ) : pinnedArea ? (
                            <aside
                                className="ops-inspector rounded-xl border bg-card"
                                aria-label="Pinned boundary details"
                            >
                                <div className="ops-inspector-title">
                                    <span className="icon-tile">
                                        <Shapes />
                                    </span>
                                    <div>
                                        <span className="eyebrow">
                                            Shared boundary · BG-{pinnedArea.id}
                                        </span>
                                        <h3>{pinnedArea.name}</h3>
                                    </div>
                                    <EntityKebab
                                        actions={boundaryActions(pinnedArea)}
                                    />
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label="Close pinned boundary details"
                                        onClick={() => setSelectedBoundary('')}
                                    >
                                        <X />
                                    </Button>
                                </div>
                                <Facts
                                    rows={[
                                        [
                                            'Owning site',
                                            pinnedArea.site ??
                                                'Owning resource',
                                        ],
                                        [
                                            'Address',
                                            pinnedArea.address ??
                                                'Not recorded',
                                        ],
                                        [
                                            'Permitted uses',
                                            pinnedArea.uses.join(', '),
                                        ],
                                        [
                                            'Geometry',
                                            'Version ' +
                                                pinnedArea.geometry_version,
                                        ],
                                    ]}
                                />
                                <Button
                                    className="w-full"
                                    onClick={() => onInspect(pinnedArea)}
                                >
                                    View boundary
                                    <ArrowRight />
                                </Button>
                            </aside>
                        ) : null}
                    </div>
                    <div className="ops-legend">
                        <span>
                            <i className="inside" />
                            Inside
                        </span>
                        <span>
                            <i className="outside" />
                            Outside
                        </span>
                        <span>
                            <i className="uncertain" />
                            Uncertain
                        </span>
                        <span>
                            <i className="stale" />
                            Out of date
                        </span>
                        <span>
                            Hover or focus · click to pin · right-click for
                            actions
                        </span>
                    </div>
                    <p className="ops-truth text-xs text-muted-foreground">
                        <ShieldCheck size={15} />
                        Geofence status describes recorded position relative to
                        linked areas. Rules, timing, evidence and authority
                        determine any response. Assets without a position remain
                        in the list.
                    </p>
                </div>
            </div>
            {detail && (
                <SectionDialog
                    title={detail.name}
                    description="Recorded source evidence and the existing profile"
                    onClose={() => setDetail(null)}
                    sections={[
                        {
                            key: 'location',
                            label: 'Location evidence',
                            blurb: 'Timing, accuracy and status',
                            icon: Eye,
                            content: (
                                <div className="bnd-form flow-stack">
                                    <Facts
                                        rows={[
                                            [
                                                'Geofence status',
                                                resourceStatus(
                                                    mapResource(detail),
                                                    boundaries,
                                                ).label,
                                            ],
                                            [
                                                'Observed',
                                                formatDateTime(
                                                    detail.observed_at,
                                                ),
                                            ],
                                            [
                                                'Received',
                                                formatDateTime(
                                                    detail.received_at,
                                                ),
                                            ],
                                            [
                                                'Accuracy',
                                                detail.accuracy_m === null
                                                    ? 'Not supplied'
                                                    : `±${detail.accuracy_m} m`,
                                            ],
                                            [
                                                'Owning site',
                                                detail.site ?? 'Not recorded',
                                            ],
                                        ]}
                                    />
                                    <Notice title="A last position is not a live guarantee">
                                        {
                                            resourceStatus(
                                                mapResource(detail),
                                                boundaries,
                                            ).detail
                                        }
                                    </Notice>
                                    <Button asChild>
                                        <a href={detail.href}>
                                            Open source profile
                                            <ExternalLink />
                                        </a>
                                    </Button>
                                </div>
                            ),
                        },
                    ]}
                />
            )}
        </section>
    );
}
