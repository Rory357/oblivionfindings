/* eslint-disable no-restricted-syntax -- Custom connected tabs, location selectors and directory rows follow the approved workspace composition; standard actions use Button. */
import { EntityCard } from '@/components/lists/entity-card';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import {
    Building2,
    ChevronRight,
    ClipboardCheck,
    Clock,
    LayoutGrid,
    List,
    Plus,
    Search,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { api, stamp, type Option, type Page } from './api';
import { ErrorNotice, Paging, SearchPicker } from './controls';
import { StocktakeModal, type Count } from './stocktake-modal';

type CountSummary = {
    id: number;
    title: string;
    site: string;
    room: string | null;
    status: string;
    counter: string;
    total: number;
    answered: number;
    differences: number;
    updated_at: string;
};
type Followup = {
    id: string;
    stocktake_id: number;
    title: string;
    site: string;
    room: string | null;
    name: string;
    asset_tag: string | null;
    reason: string;
    owner: string | null;
    note: string | null;
};
type Coverage = {
    id: number;
    name: string;
    rooms: number;
    checked_rooms: number;
    counts: number;
    last_completed_at: string | null;
};
type Workspace = 'counts' | 'followups' | 'coverage';
type HubData = Page<CountSummary> & {
    summary?: { counts: number; followups: number; sites: number };
    resume?: CountSummary | null;
};

export function Stocktakes({
    initialStatus,
    sites,
    staff,
    canCount,
    initialSite,
    initialRoom,
    selected,
    newCountRequest = 0,
    onNewHandled,
}: {
    initialStatus: string;
    sites: Option[];
    staff: Option[];
    canCount: boolean;
    initialSite: string;
    initialRoom: string;
    selected: number[];
    newCountRequest?: number;
    onNewHandled?: () => void;
}) {
    const [page, setPage] = useState(1);
    const [site, setSite] = useState('');
    const [workspace, setWorkspace] = useState<Workspace>('counts');
    const [status, setStatus] = useState('');
    useEffect(() => {
        const nextWorkspace =
            initialStatus === 'coverage'
                ? 'coverage'
                : initialStatus === 'followups'
                  ? 'followups'
                  : 'counts';
        if (nextWorkspace !== workspace) {
            setLoading(true);
            setData(undefined);
        }
        setWorkspace(nextWorkspace);
        setStatus(
            ['draft', 'completed'].includes(initialStatus) ? initialStatus : '',
        );
        setPage(1);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Only a new external hero filter resets local tab navigation.
    }, [initialStatus]);
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [cards, setCards] = useState(false);
    const [data, setData] = useState<HubData>();
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [revision, setRevision] = useState(0);
    const [modal, setModal] = useState<Count | 'new' | null>(null);
    const [newSite, setNewSite] = useState(initialSite);
    useEffect(() => {
        if (newCountRequest > 0) {
            setNewSite(initialSite);
            setModal('new');
            onNewHandled?.();
        }
    }, [newCountRequest, initialSite, onNewHandled]);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError('');
        void api<HubData>(
            `/stocktakes?${new URLSearchParams({ page: String(page), site_id: site, status, search: query, workspace })}`,
            'GET',
            undefined,
            controller.signal,
        )
            .then(setData)
            .catch((e) => {
                if (e.name !== 'AbortError') setError(e.message);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [page, site, status, query, workspace, revision]);
    async function open(id: number) {
        setError('');
        try {
            setModal(await api<Count>(`/stocktakes/${id}`));
            const url = new URL(location.href);
            url.searchParams.set('stocktake', String(id));
            history.replaceState(history.state, '', url);
        } catch (e) {
            setError((e as Error).message);
        }
    }
    useEffect(() => {
        const id = new URLSearchParams(location.search).get('stocktake');
        if (id && /^\d+$/.test(id)) void open(Number(id));
    }, []);
    function changeWorkspace(value: Workspace) {
        if (value === workspace) return;
        setLoading(true);
        setData(undefined);
        setWorkspace(value);
        setPage(1);
        setSearch('');
        setQuery('');
        setStatus('');
    }
    const actions = (row: CountSummary) => [
        {
            label:
                row.status === 'completed'
                    ? 'View results & export'
                    : 'Resume count',
            icon: ClipboardCheck,
            onClick: () => void open(row.id),
        },
    ];
    const statusBadge = (row: CountSummary) => (
        <StatusBadge
            variant={
                row.status !== 'completed'
                    ? 'info'
                    : row.differences
                      ? 'warning'
                      : 'success'
            }
        >
            {row.status !== 'completed'
                ? 'In progress'
                : row.differences
                  ? 'Finished · follow-up'
                  : 'Finished'}
        </StatusBadge>
    );
    const renderCards = () => (
        <div className="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-3">
            {data?.data.map((row) => (
                <EntityCard
                    meridian={row.differences ? 'warning' : 'success'}
                    key={row.id}
                    icon={ClipboardCheck}
                    name={row.title}
                    subline={`ST-${row.id} · ${row.site}`}
                    actions={actions(row)}
                    onOpen={() => void open(row.id)}
                    openLabel={
                        row.status === 'completed'
                            ? 'View results'
                            : 'Resume count'
                    }
                    chips={
                        <>
                            {statusBadge(row)}
                            <span className="text-xs text-muted-foreground">
                                {row.room || 'Whole site'}
                            </span>
                        </>
                    }
                    metric={{
                        label: 'Items checked',
                        value: `${row.answered} / ${row.total}`,
                        percent: row.total
                            ? (row.answered / row.total) * 100
                            : 0,
                    }}
                    footer={{
                        personName: row.counter,
                        primary: row.counter,
                        secondary: stamp(row.updated_at),
                    }}
                    alerts={
                        row.differences ? (
                            <StatusBadge variant="warning">
                                {row.differences} follow-up items
                            </StatusBadge>
                        ) : undefined
                    }
                />
            ))}
        </div>
    );
    const resume = data?.resume;
    return (
        <section className="asset-hub">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div
                    role="tablist"
                    aria-label="Stocktake workspace"
                    className="asset-hub-tabs"
                >
                    {(
                        [
                            ['counts', 'Counts', data?.summary?.counts],
                            [
                                'followups',
                                'Follow-ups',
                                data?.summary?.followups,
                            ],
                            [
                                'coverage',
                                'Site coverage',
                                data?.summary?.sites ?? sites.length,
                            ],
                        ] as const
                    ).map(([key, label, total], index) => (
                        <button
                            key={key}
                            role="tab"
                            id={`asset-count-tab-${key}`}
                            aria-controls="asset-count-panel"
                            aria-selected={workspace === key}
                            tabIndex={workspace === key ? 0 : -1}
                            onClick={() => changeWorkspace(key)}
                            onKeyDown={(e) => {
                                if (
                                    ![
                                        'ArrowLeft',
                                        'ArrowRight',
                                        'Home',
                                        'End',
                                    ].includes(e.key)
                                )
                                    return;
                                e.preventDefault();
                                const keys: Workspace[] = [
                                    'counts',
                                    'followups',
                                    'coverage',
                                ];
                                const next =
                                    e.key === 'Home'
                                        ? 0
                                        : e.key === 'End'
                                          ? 2
                                          : (index +
                                                (e.key === 'ArrowRight'
                                                    ? 1
                                                    : 2)) %
                                            3;
                                changeWorkspace(keys[next]);
                                document
                                    .getElementById(
                                        `asset-count-tab-${keys[next]}`,
                                    )
                                    ?.focus();
                            }}
                        >
                            {label}
                            <small>{total ?? '—'}</small>
                        </button>
                    ))}
                </div>
                <span className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Clock className="size-3" />
                    Saved counts & reports
                </span>
            </div>
            {workspace === 'counts' && resume && !site && !query && !status && (
                <div className="asset-hub-resume">
                    <span className="asset-hub-resume-icon">
                        <ClipboardCheck className="size-6" />
                    </span>
                    <div className="asset-hub-resume-title">
                        <span className="asset-eyebrow">Continue a count</span>
                        <h2 className="text-section-title">{resume.title}</h2>
                        <p>
                            {resume.site} · ST-{resume.id} · {resume.counter}
                        </p>
                    </div>
                    <div className="asset-hub-progress">
                        <strong>
                            {resume.answered} / {resume.total} checked
                        </strong>
                        <progress
                            aria-label="Saved count progress"
                            max={Math.max(1, resume.total)}
                            value={resume.answered}
                        />
                    </div>
                    <Button onClick={() => void open(resume.id)}>
                        Resume count <ChevronRight className="size-4" />
                    </Button>
                </div>
            )}
            <ErrorNotice
                message={error}
                retry={() => setRevision((v) => v + 1)}
            />
            <div
                id="asset-count-panel"
                role="tabpanel"
                aria-labelledby={`asset-count-tab-${workspace}`}
                className="asset-hub-main"
            >
                <div className="asset-hub-heading">
                    <div>
                        <h2 className="text-section-title">
                            {workspace === 'counts'
                                ? 'Your counts'
                                : workspace === 'followups'
                                  ? 'Work to follow up'
                                  : 'Coverage by site'}
                        </h2>
                        <p>
                            {workspace === 'counts'
                                ? 'Click a count to resume work or open its completed results.'
                                : workspace === 'followups'
                                  ? 'Differences recorded at completion. Open the report and review changes in the owning asset record.'
                                  : 'Rooms checked in finished counts. Selected-asset counts do not establish whole-room coverage.'}
                        </p>
                    </div>
                    {workspace === 'counts' && (
                        <div
                            className="asset-view-switch"
                            role="group"
                            aria-label="Stocktake view"
                        >
                            <button
                                aria-label="Stocktake list view"
                                aria-pressed={!cards}
                                onClick={() => setCards(false)}
                            >
                                <List className="size-4" />
                                List
                            </button>
                            <button
                                aria-label="Stocktake card view"
                                aria-pressed={cards}
                                onClick={() => setCards(true)}
                            >
                                <LayoutGrid className="size-4" />
                                Cards
                            </button>
                        </div>
                    )}
                </div>
                <div className="asset-hub-filters">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            setQuery(search);
                            setPage(1);
                        }}
                    >
                        <div className="relative min-w-0 flex-1">
                            <Search className="absolute top-3.5 left-3 size-4 text-muted-foreground" />
                            <Input
                                aria-label="Search stocktakes"
                                placeholder={
                                    workspace === 'coverage'
                                        ? 'Search site…'
                                        : 'Search count, site or counter…'
                                }
                                className="h-11 pl-10"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                        <Button
                            variant="outline"
                            className="h-11"
                            aria-label="Find stocktakes"
                        >
                            <Search className="size-4" />
                        </Button>
                    </form>
                    <SearchPicker
                        label="Site"
                        hideLabel
                        options={sites}
                        value={site}
                        empty="All permitted sites"
                        onChange={(value) => {
                            setSite(value);
                            setPage(1);
                        }}
                    />
                    {workspace === 'counts' && (
                        <select
                            aria-label="Count status"
                            className="h-11 rounded-md border bg-card px-3 text-xs"
                            value={status}
                            onChange={(e) => {
                                setStatus(e.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">All statuses</option>
                            <option value="draft">Open counts</option>
                            <option value="completed">Finished</option>
                            <option value="followups">With follow-ups</option>
                        </select>
                    )}
                </div>
                {loading ? (
                    <p
                        role="status"
                        className="p-8 text-center text-muted-foreground"
                    >
                        Loading saved stocktakes…
                    </p>
                ) : !data?.data.length ? (
                    <div className="border-t p-10 text-center">
                        <ClipboardCheck className="mx-auto mb-3 size-9 text-primary" />
                        <h3 className="text-section-title">
                            {query || site || status
                                ? 'No matching records'
                                : workspace === 'followups'
                                  ? 'No differences recorded'
                                  : 'Start your first stocktake'}
                        </h3>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Choose one room, scan each asset, then review
                            anything left.
                        </p>
                        {canCount && workspace === 'counts' && (
                            <Button
                                className="mt-5"
                                onClick={() => {
                                    setNewSite(initialSite);
                                    setModal('new');
                                }}
                            >
                                <Plus className="size-4" />
                                Start stocktake
                            </Button>
                        )}
                    </div>
                ) : workspace === 'counts' ? (
                    <>
                        {cards ? (
                            renderCards()
                        ) : (
                            <>
                                <div className="md:hidden">{renderCards()}</div>
                                <div className="hidden md:block">
                                    <EntityTable
                                        rows={data.data}
                                        rowKey={(row) => row.id}
                                        identityLabel="Count / location"
                                        identityWidth="1.6fr"
                                        minWidth={720}
                                        identity={(row) => ({
                                            name: row.title,
                                            subline: (
                                                <>
                                                    <span>
                                                        ST-{row.id} · {row.site}
                                                    </span>
                                                    <span className="mt-1 block">
                                                        {row.room ||
                                                            'All rooms'}
                                                    </span>
                                                </>
                                            ),
                                        })}
                                        columns={[
                                            {
                                                key: 'counter',
                                                label: 'Counted by',
                                                width: '1.1fr',
                                                cell: (row) => (
                                                    <div>
                                                        {row.counter}
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {stamp(
                                                                row.updated_at,
                                                            )}
                                                        </p>
                                                    </div>
                                                ),
                                            },
                                            {
                                                key: 'progress',
                                                label: 'Progress',
                                                width: '1fr',
                                                cell: (row) => (
                                                    <div className="w-full space-y-2 pr-4">
                                                        <span>
                                                            {row.answered} /{' '}
                                                            {row.total} checked
                                                        </span>
                                                        <progress
                                                            aria-label={`Progress for ST-${row.id}`}
                                                            max={Math.max(
                                                                1,
                                                                row.total,
                                                            )}
                                                            value={row.answered}
                                                        />
                                                    </div>
                                                ),
                                            },
                                            {
                                                key: 'status',
                                                label: 'Status',
                                                width: '1fr',
                                                cell: (row) => (
                                                    <div>
                                                        {statusBadge(row)}
                                                        {!!row.differences && (
                                                            <p className="mt-2 text-xs text-muted-foreground">
                                                                {
                                                                    row.differences
                                                                }{' '}
                                                                follow-up items
                                                            </p>
                                                        )}
                                                    </div>
                                                ),
                                            },
                                        ]}
                                        actionsFor={actions}
                                        onOpen={(row) => void open(row.id)}
                                    />
                                </div>
                            </>
                        )}
                    </>
                ) : workspace === 'followups' ? (
                    <div className="divide-y border-t">
                        {(data.data as unknown as Followup[]).map((row) => (
                            <button
                                key={row.id}
                                onClick={() => void open(row.stocktake_id)}
                                className="flex w-full flex-wrap items-center gap-4 px-5 py-5 text-left hover:bg-muted"
                            >
                                <span className="min-w-48 flex-1">
                                    <strong className="block text-sm">
                                        {row.name}
                                    </strong>
                                    <small className="mt-1 block text-muted-foreground">
                                        {row.asset_tag || 'No tag'} · {row.site}{' '}
                                        · ST-{row.stocktake_id}
                                    </small>
                                </span>
                                <StatusBadge variant="warning">
                                    {row.reason}
                                </StatusBadge>
                                <span className="text-xs text-muted-foreground">
                                    {row.owner || 'Owner not recorded'}
                                </span>
                                <ChevronRight className="size-4" />
                            </button>
                        ))}
                    </div>
                ) : (
                    <div className="divide-y border-t">
                        {(data.data as unknown as Coverage[]).map((row) => (
                            <div
                                key={row.id}
                                className="flex flex-wrap items-center gap-4 px-5 py-5"
                            >
                                <Building2 className="size-5 text-primary" />
                                <div className="min-w-40 flex-1">
                                    <strong className="text-sm">
                                        {row.name}
                                    </strong>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {row.last_completed_at
                                            ? `Last finished ${stamp(row.last_completed_at)}`
                                            : 'No completed count recorded'}
                                    </p>
                                </div>
                                <div className="min-w-32 space-y-2 text-xs">
                                    <span>
                                        {row.checked_rooms} / {row.rooms} rooms
                                        checked
                                    </span>
                                    <progress
                                        aria-label={`Room coverage at ${row.name}`}
                                        max={Math.max(1, row.rooms)}
                                        value={row.checked_rooms}
                                    />
                                </div>
                                <StatusBadge
                                    variant={row.counts ? 'info' : 'neutral'}
                                >
                                    {row.counts} finished
                                </StatusBadge>
                                {canCount && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setNewSite(String(row.id));
                                            setModal('new');
                                        }}
                                    >
                                        Start count{' '}
                                        <ChevronRight className="size-3" />
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                )}
                {data && (
                    <div className="asset-hub-pagination">
                        <Paging
                            page={data.current_page}
                            last={data.last_page}
                            total={data.total}
                            onChange={setPage}
                        />
                    </div>
                )}
            </div>
            <p className="asset-evidence-note">
                <ClipboardCheck className="size-4" />
                Completed counts retain the original answers. Assignment,
                custody and condition changes are reviewed in the asset record.
            </p>
            {modal && (
                <StocktakeModal
                    initial={modal === 'new' ? null : modal}
                    sites={sites}
                    staff={staff}
                    canCount={canCount}
                    initialSite={newSite}
                    initialRoom={newSite === initialSite ? initialRoom : ''}
                    selected={selected}
                    onClose={() => {
                        setModal(null);
                        setRevision((v) => v + 1);
                        const url = new URL(location.href);
                        url.searchParams.delete('stocktake');
                        history.replaceState(history.state, '', url);
                        router.reload({ only: ['workflow_metrics'] });
                    }}
                />
            )}
        </section>
    );
}
