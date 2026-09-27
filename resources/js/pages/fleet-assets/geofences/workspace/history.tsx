import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/datetime';
import { Copy, Download, FileClock, History, Shapes } from 'lucide-react';
import { useEffect, useState } from 'react';
import { base, query, useDebounced, useRemote } from './api';
import type {
    BoundaryRecord,
    HistoryEntry,
    Impact,
    Page,
    RuleRecord,
} from './data';
import { BoundaryMap } from './map';
import { RemotePicker } from './remote-picker';
import { RuleHistory } from './rule-history';
import { Button, Card, Empty, Facts, Notice, SectionDialog } from './ui';
export function BoundaryHistory({
    selected,
    onSelect,
    canManage,
    onCopy,
    refresh,
    onEdit,
    onPeople,
    onRule,
    onRules,
    onEvents,
}: {
    selected: BoundaryRecord | null;
    onSelect: (b: BoundaryRecord) => void;
    canManage: boolean;
    onCopy: (b: BoundaryRecord) => void;
    refresh: number;
    onEdit: (b: BoundaryRecord) => void;
    onPeople: (b: BoundaryRecord) => void;
    onRule: (r: RuleRecord) => void;
    onRules: (b: BoundaryRecord) => void;
    onEvents: (b: BoundaryRecord) => void;
}) {
    const [lane, setLane] = useState('boundary');
    const [q, setQ] = useState(''),
        [actor, setActor] = useState(''),
        [category, setCategory] = useState(''),
        [from, setFrom] = useState(''),
        [to, setTo] = useState(''),
        [page, setPage] = useState(1),
        [entry, setEntry] = useState<HistoryEntry | null>(null);
    useEffect(() => {
        setEntry(null);
        setPage(1);
    }, [selected?.id]);
    const search = useDebounced(q),
        by = useDebounced(actor);
    const result = useRemote<
        Page<HistoryEntry> & { notice: string; impact: Impact }
    >(
        selected
            ? query('/' + selected.id + '/history', {
                  q: search,
                  actor: by,
                  category,
                  from,
                  to,
                  page,
              })
            : null,
        refresh,
    );
    const previous = useRemote<{ version: HistoryEntry }>(
        entry && entry.revision > 1
            ? base + '/' + selected?.id + '/versions/' + (entry.revision - 1)
            : null,
    );
    const dependencies = useRemote<Page<RuleRecord>>(
        selected ? query('/rules', { boundary_id: selected.id }) : null,
        refresh,
    );
    const reset = () => setPage(1);
    const copy = (e: HistoryEntry) => {
        if (selected && e.snapshot.geometry)
            onCopy({
                ...selected,
                ...e.snapshot,
                personal_eligible: false,
                legacy_monitoring: false,
                updated_at: e.recorded_at,
            });
    };
    return (
        <div className="flow-stack">
            <div>
                <h2 className="text-section-title">
                    Boundary history & impact
                </h2>
                <p className="text-xs text-muted-foreground">
                    Version evidence, changes and connected follow-up ·
                    Pacific/Auckland
                </p>
            </div>
            <div className="flex gap-2">
                <Button
                    variant={lane === 'boundary' ? 'secondary' : 'outline'}
                    onClick={() => setLane('boundary')}
                >
                    Boundary changes
                </Button>
                <Button
                    variant={lane === 'rules' ? 'secondary' : 'outline'}
                    onClick={() => setLane('rules')}
                >
                    Purpose rule changes
                </Button>
            </div>
            {/* eslint-disable-next-line no-restricted-syntax -- Responsive filter toolbar, not a content card. */}
            <div className="bnd-history-filters grid grid-cols-1 gap-4 rounded-xl border bg-card p-5 lg:grid-cols-4">
                <RemotePicker<BoundaryRecord>
                    label="Boundary history"
                    value={selected?.name}
                    url={(q) =>
                        query('/catalogue', { q, status: 'all', size: 20 })
                    }
                    describe={(b) => ({
                        id: b.id,
                        name: b.name,
                        detail: `BG-${b.id} · ${b.site ?? 'Owning resource'}`,
                    })}
                    onSelect={(b) => {
                        onSelect(b);
                        setPage(1);
                    }}
                />
                <label className="field">
                    Search reason
                    <Input
                        value={q}
                        onChange={(e) => {
                            setQ(e.target.value);
                            reset();
                        }}
                    />
                </label>
                <label className="field">
                    Actor
                    <Input
                        value={actor}
                        onChange={(e) => {
                            setActor(e.target.value);
                            reset();
                        }}
                    />
                </label>
                <label className="field">
                    Change type
                    <select
                        disabled={lane === 'rules'}
                        className="rounded border bg-background p-2"
                        value={category}
                        onChange={(e) => {
                            setCategory(e.target.value);
                            reset();
                        }}
                    >
                        <option value="">All changes</option>
                        {[
                            'created',
                            'baseline',
                            'geometry',
                            'details',
                            'retired',
                        ].map((v) => (
                            <option key={v}>{v}</option>
                        ))}
                    </select>
                </label>
                <DatePicker
                    id="boundary-history-from"
                    label="From date"
                    value={from}
                    onChange={(v) => {
                        setFrom(v);
                        reset();
                    }}
                />
                <DatePicker
                    id="boundary-history-to"
                    label="Through date"
                    value={to}
                    onChange={(v) => {
                        setTo(v);
                        reset();
                    }}
                />
            </div>
            {!selected ? (
                <Empty title="Choose a boundary">
                    Search the full permitted catalogue, including retired
                    areas.
                </Empty>
            ) : (
                <div className="bnd-history-grid">
                    <div className="flow-stack min-w-0">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2 className="text-section-title">
                                    {selected.name}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Retained changes · Pacific/Auckland
                                </p>
                            </div>
                            {canManage && lane === 'boundary' && (
                                <Button asChild variant="outline">
                                    <a
                                        href={query(
                                            '/' +
                                                selected.id +
                                                '/history/export',
                                            {
                                                q: search,
                                                actor: by,
                                                category,
                                                from,
                                                to,
                                            },
                                        )}
                                    >
                                        <Download />
                                        Export filtered history
                                    </a>
                                </Button>
                            )}
                        </div>
                        {lane === 'rules' ? (
                            <RuleHistory
                                canManage={canManage}
                                key={[selected.id, search, by, from, to].join(
                                    '|',
                                )}
                                boundary={selected.id}
                                filters={{ q: search, actor: by, from, to }}
                                refresh={refresh}
                            />
                        ) : result.loading ? (
                            <p role="status">Loading history…</p>
                        ) : result.error ? (
                            <Notice tone="critical" title={result.error}>
                                <Button
                                    variant="outline"
                                    onClick={result.reload}
                                >
                                    Retry
                                </Button>
                            </Notice>
                        ) : (
                            result.data && (
                                <>
                                    <Notice title="Evidence coverage">
                                        {result.data.notice}
                                    </Notice>
                                    {result.data.data.length ? (
                                        <Card className="p-5">
                                            <ul className="audit-list">
                                                {result.data.data.map((e) => (
                                                    <li key={e.id}>
                                                        {/* eslint-disable-next-line no-restricted-syntax -- Full-width timeline event selector with its own grid layout. */}
                                                        <button
                                                            onClick={() =>
                                                                setEntry(e)
                                                            }
                                                        >
                                                            <span className="audit-time">
                                                                {formatDateTime(
                                                                    e.recorded_at,
                                                                )}
                                                                <small>
                                                                    Geometry v
                                                                    {
                                                                        e.geometry_version
                                                                    }{' '}
                                                                    · revision{' '}
                                                                    {e.revision}
                                                                </small>
                                                            </span>
                                                            <span>
                                                                <strong>
                                                                    {e.category[0].toUpperCase() +
                                                                        e.category.slice(
                                                                            1,
                                                                        )}
                                                                </strong>
                                                                <p>
                                                                    {e.reason}
                                                                </p>
                                                                <small>
                                                                    {e.actor
                                                                        ?.name ??
                                                                        'Actor not recorded'}
                                                                </small>
                                                            </span>
                                                            <History
                                                                size={16}
                                                            />
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                            <Pagination
                                                page={page}
                                                last={result.data.last_page}
                                                total={result.data.total}
                                                onChange={setPage}
                                            />
                                        </Card>
                                    ) : (
                                        <Empty title="No retained changes match">
                                            Earlier geometry is not
                                            reconstructed. Clear filters or
                                            choose another boundary.
                                        </Empty>
                                    )}
                                </>
                            )
                        )}
                    </div>
                    <aside className="flow-stack rounded-xl border bg-card p-5">
                        <h3 className="text-sm font-semibold">
                            Current dependencies
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            Current state below. Earlier entries keep their
                            recorded versions.
                        </p>
                        {dependencies.loading ? (
                            <p role="status">Loading linked rules…</p>
                        ) : dependencies.error ? (
                            <Notice tone="warning" title={dependencies.error} />
                        ) : dependencies.data?.data.length ? (
                            <div className="divide-y">
                                {dependencies.data.data.map((r) => (
                                    // eslint-disable-next-line no-restricted-syntax -- Multiline dependency selector in a divided list.
                                    <button
                                        className="block w-full py-3 text-left text-xs"
                                        key={r.id}
                                        onClick={() => onRule(r)}
                                    >
                                        <strong>{r.resource}</strong>
                                        <span className="mt-1 block text-muted-foreground">
                                            {r.label} · rule v{r.revision}
                                            {r.source_changed
                                                ? ' · source review needed'
                                                : ''}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                No permitted vehicle or asset purpose rules are
                                linked.
                            </p>
                        )}
                        {!!dependencies.data?.total && (
                            <Button
                                variant="outline"
                                onClick={() => onRules(selected)}
                            >
                                View all {dependencies.data.total} linked rules
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            onClick={() => onEvents(selected)}
                        >
                            Original events & follow-up
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => onPeople(selected)}
                        >
                            Person / client connection
                        </Button>
                        {canManage && (
                            <Button onClick={() => onEdit(selected)}>
                                Review boundary change
                            </Button>
                        )}
                        <Notice title="Protected dependencies">
                            Personal assignments stay with their authorised
                            owner. Access and dependencies are checked again
                            before changing this shared area.
                        </Notice>
                    </aside>
                </div>
            )}
            {entry && selected && (
                <SectionDialog
                    title={`Boundary revision ${entry.revision}`}
                    description={`${selected.name} · BG-${selected.id} · retained evidence`}
                    onClose={() => setEntry(null)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setEntry(null)}
                            >
                                Back to history
                            </Button>
                            {canManage && entry.snapshot.geometry && (
                                <Button
                                    onClick={() => {
                                        copy(entry);
                                        setEntry(null);
                                    }}
                                >
                                    <Copy />
                                    Copy this snapshot
                                </Button>
                            )}
                        </>
                    }
                    sections={[
                        {
                            key: 'record',
                            label: 'Recorded change',
                            blurb: 'Actor, reason and source',
                            icon: FileClock,
                            content: (
                                <div className="bnd-form flow-stack">
                                    <Facts
                                        rows={[
                                            [
                                                'Recorded',
                                                formatDateTime(
                                                    entry.recorded_at,
                                                ),
                                            ],
                                            [
                                                'Actor',
                                                entry.actor?.name ??
                                                    'Not recorded',
                                            ],
                                            ['Change', entry.category],
                                            ['Reason', entry.reason],
                                            [
                                                'Geometry',
                                                `Version ${entry.geometry_version}`,
                                            ],
                                            [
                                                'Source copy',
                                                entry.snapshot.copy_source
                                                    ? `BG-${entry.snapshot.copy_source.id} · ${entry.snapshot.copy_source.revision ? 'revision ' + entry.snapshot.copy_source.revision : 'source revision not retained'}`
                                                    : 'Original area',
                                            ],
                                        ]}
                                    />
                                    <Notice title="A copy creates a new identity">
                                        It does not roll back this boundary or
                                        transfer its assignments.
                                    </Notice>
                                </div>
                            ),
                        },
                        {
                            key: 'compare',
                            label: 'Compare geometry',
                            blurb: 'Previous and retained shapes',
                            icon: Shapes,
                            content: (
                                <div className="bnd-form flow-stack">
                                    <div className="audit-maps">
                                        <div>
                                            <h3>Previous retained revision</h3>
                                            {previous.loading ? (
                                                <p>
                                                    Loading preceding snapshot…
                                                </p>
                                            ) : previous.data?.version.snapshot
                                                  .geometry ? (
                                                <BoundaryMap
                                                    shape={
                                                        previous.data.version
                                                            .snapshot.geometry
                                                    }
                                                    className="audit-map"
                                                />
                                            ) : (
                                                <Notice title="No preceding snapshot available">
                                                    An absent historical shape
                                                    is not replaced by today’s
                                                    geometry.
                                                </Notice>
                                            )}
                                        </div>
                                        <div>
                                            <h3>Revision {entry.revision}</h3>
                                            {entry.snapshot.geometry ? (
                                                <BoundaryMap
                                                    shape={
                                                        entry.snapshot.geometry
                                                    }
                                                    className="audit-map"
                                                />
                                            ) : (
                                                <Notice title="Shape not recorded" />
                                            )}
                                        </div>
                                    </div>
                                    {previous.data && (
                                        <Facts
                                            rows={[
                                                [
                                                    'Previous name',
                                                    previous.data.version
                                                        .snapshot.name,
                                                ],
                                                [
                                                    'Recorded name',
                                                    entry.snapshot.name,
                                                ],
                                                [
                                                    'Previous address',
                                                    previous.data.version
                                                        .snapshot.address ??
                                                        'Not recorded',
                                                ],
                                                [
                                                    'Recorded address',
                                                    entry.snapshot.address ??
                                                        'Not recorded',
                                                ],
                                                [
                                                    'Previous availability',
                                                    previous.data.version
                                                        .snapshot.retired_at
                                                        ? 'Retired'
                                                        : 'Available',
                                                ],
                                                [
                                                    'Recorded availability',
                                                    entry.snapshot.retired_at
                                                        ? 'Retired'
                                                        : 'Available',
                                                ],
                                                [
                                                    'Previous uses',
                                                    previous.data.version.snapshot.uses.join(
                                                        ', ',
                                                    ),
                                                ],
                                                [
                                                    'Recorded uses',
                                                    entry.snapshot.uses.join(
                                                        ', ',
                                                    ),
                                                ],
                                            ]}
                                        />
                                    )}
                                </div>
                            ),
                        },
                    ]}
                />
            )}
        </div>
    );
}
export function Pagination({
    page,
    last,
    total,
    onChange,
}: {
    page: number;
    last: number;
    total: number;
    onChange: (n: number) => void;
}) {
    return (
        <div className="flex flex-wrap items-center justify-end gap-3 border-t pt-4">
            <span className="mr-auto text-sm text-muted-foreground">
                {total.toLocaleString('en-NZ')} records · page {page} of {last}
            </span>
            <Button
                variant="outline"
                disabled={page <= 1}
                onClick={() => onChange(page - 1)}
            >
                Previous
            </Button>
            <label className="flex items-center gap-2 text-sm">
                Go to page
                <Input
                    className="w-20"
                    type="number"
                    min={1}
                    max={last}
                    key={page}
                    defaultValue={page}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            const n = Number(e.currentTarget.value);
                            if (Number.isInteger(n) && n >= 1 && n <= last)
                                onChange(n);
                        }
                    }}
                />
            </label>
            <Button
                variant="outline"
                disabled={page >= last}
                onClick={() => onChange(page + 1)}
            >
                Next
            </Button>
        </div>
    );
}
