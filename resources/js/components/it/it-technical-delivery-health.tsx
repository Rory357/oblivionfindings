import { EntityChip, EntityStatusChip } from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { Activity, Search } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    deliveryOutcome as outcome,
    deliveryStates as states,
    deliveryTone as tone,
    type DeliveryRow,
} from './it-technical-delivery-contract';
import { ItTechnicalDeliveryRecovery } from './it-technical-delivery-recovery';

interface SourceHealth {
    source: 'device' | 'fleet';
    can_view: boolean;
    available: boolean;
    coverage: 'source_bound_it_intents';
    total: number | null;
    history_total: number | null;
    search_query: string;
    links: { label: string; url: string | null; active: boolean }[];
    pending: number | null;
    failures: number | null;
    legacy_unverified: null;
    last_success_at: string | null;
    oldest_pending_at: string | null;
    rows: DeliveryRow[];
    page: number;
    last_page: number;
    previous_url: string | null;
    next_url: string | null;
}
export interface TechnicalDeliveryHealth {
    viewer_user_id: number;
    checked_at: string;
    sources: SourceHealth[];
}
function DeliveryHistory({
    health,
    actorId,
    onAccessLost,
}: {
    health: SourceHealth;
    actorId: number;
    onAccessLost: () => void;
}) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const returnFocus = useRef<HTMLElement | null>(null);
    const context = useEntityContextMenu<DeliveryRow>();
    const selected = health.rows.find((row) => row.id === selectedId);
    const open = (row: DeliveryRow) => {
        returnFocus.current =
            document
                .getElementById(`it-${health.source}-delivery-${row.id}`)
                ?.closest<HTMLElement>('[role="row"]') ?? null;
        context.close();
        setSelectedId(row.id);
    };
    const actionsFor = (row: DeliveryRow): MenuItem[] => [
        {
            label: 'Review delivery details',
            icon: Search,
            onClick: () => open(row),
        },
    ];
    return (
        <div
            id={`it-${health.source}-delivery-history`}
            className="scroll-mt-20 space-y-5"
        >
            <ListCaption
                title="Delivery history"
                caption={`${health.rows.length} of ${health.history_total} shown · page ${health.page} of ${health.last_page}`}
            />
            {health.search_query ? (
                <p className="text-caption">
                    Search results for “{health.search_query}”. Health totals
                    include all delivery intents in your current scope.
                </p>
            ) : null}
            {health.rows.length === 0 ? (
                <p className="text-subtle">
                    {health.search_query
                        ? 'No deliveries match this search. Change or clear the header search to see other deliveries.'
                        : 'No source-bound IT deliveries are visible for your current permissions.'}
                </p>
            ) : (
                <EntityTable
                    rows={health.rows}
                    rowKey={(row) => row.id}
                    identityLabel="Delivery"
                    identityWidth="1.2fr"
                    identity={(row) => ({
                        icon: Activity,
                        name: `Delivery ${row.id}`,
                        subline: (
                            <span id={`it-${health.source}-delivery-${row.id}`}>
                                {row.source_delivered
                                    ? 'Source acknowledged'
                                    : 'Source delivery incomplete'}
                            </span>
                        ),
                    })}
                    actionsFor={actionsFor}
                    onOpen={open}
                    onRowContextMenu={(event, row) => context.open(event, row)}
                    columns={[
                        {
                            key: 'state',
                            label: 'IT outcome',
                            width: '1.5fr',
                            cell: (row) => (
                                <EntityStatusChip variant={tone(row)}>
                                    {states[row.state]}
                                </EntityStatusChip>
                            ),
                        },
                        {
                            key: 'result',
                            label: 'Recorded result',
                            width: '2fr',
                            cell: (row) => (
                                <EntityChip>{outcome(row)}</EntityChip>
                            ),
                        },
                        {
                            key: 'attempts',
                            label: 'Attempts',
                            width: '90px',
                            cell: (row) => (
                                <EntityChip>
                                    {row.attempts ?? 'Unrecorded'}
                                </EntityChip>
                            ),
                        },
                        {
                            key: 'last',
                            label: 'Last attempt',
                            width: '1.4fr',
                            cell: (row) => (
                                <EntityChip>
                                    {formatDateTime(
                                        row.last_attempt_at,
                                        'Not recorded',
                                    )}
                                </EntityChip>
                            ),
                        },
                    ]}
                />
            )}
            {context.ctx ? (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title="Delivery actions"
                    items={actionsFor(context.ctx.record)}
                    onClose={context.close}
                />
            ) : null}
            <LaravelPagination
                links={health.links}
                lastPage={health.last_page}
                preserveState={false}
            />
            <Dialog
                open={!!selected}
                onOpenChange={(isOpen) => !isOpen && setSelectedId(null)}
            >
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto"
                    style={{
                        width: 'min(92vw, 480px)',
                        maxWidth: 'min(92vw, 480px)',
                    }}
                    onCloseAutoFocus={(event) => {
                        const target = returnFocus.current?.isConnected
                            ? returnFocus.current
                            : document.getElementById(
                                  `it-${health.source}-delivery-unavailable`,
                              );
                        if (target) {
                            event.preventDefault();
                            target.focus();
                        }
                    }}
                >
                    {selected ? (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Delivery {selected.id}
                                </DialogTitle>
                                <DialogDescription>
                                    Recorded technical delivery outcome
                                </DialogDescription>
                            </DialogHeader>
                            <ItTechnicalDeliveryRecovery
                                key={selected.id}
                                actorId={actorId}
                                source={health.source}
                                deliveryId={selected.id}
                                onAccessLost={onAccessLost}
                            />
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={() => setSelectedId(null)}
                                >
                                    Close details
                                </Button>
                            </DialogFooter>
                        </>
                    ) : null}
                </DialogContent>
            </Dialog>
        </div>
    );
}

export function ItTechnicalDeliveryHealth({
    health,
    viewerId,
}: {
    health?: TechnicalDeliveryHealth;
    viewerId: number | null;
}) {
    const [accessLost, setAccessLost] = useState(false);
    if (!health) return null;
    const visible = health.viewer_user_id === viewerId;
    return (
        <div className="space-y-5">
            {health.sources.map((source) => (
                <Card
                    key={source.source}
                    role="region"
                    aria-label={`${source.source === 'device' ? 'Device' : 'Fleet'} technical delivery`}
                >
                    <CardHeader>
                        <CardTitle className="text-section-title flex items-center gap-2">
                            <Activity
                                className="size-5 text-primary"
                                aria-hidden="true"
                            />
                            {source.source === 'device'
                                ? 'Device monitoring'
                                : 'Fleet tracking'}{' '}
                            → IT work
                        </CardTitle>
                        <p className="text-subtle">
                            Recorded IT delivery intents for sources you can
                            currently access. Older source acknowledgements
                            without a proven IT outcome are not included; their
                            delivery remains unverified.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        {!visible || !source.can_view || accessLost ? (
                            <div
                                id={`it-${source.source}-delivery-unavailable`}
                                tabIndex={-1}
                                className="space-y-3 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <p className="text-subtle">
                                    Technical delivery details require current
                                    IT and source-record access.
                                </p>
                                {accessLost ? (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.reload({
                                                preserveState: false,
                                            })
                                        }
                                    >
                                        Refresh delivery access
                                    </Button>
                                ) : null}
                            </div>
                        ) : !source.available ? (
                            <p className="text-subtle">
                                Technical delivery tracking is unavailable. No
                                healthy result has been recorded.
                            </p>
                        ) : (
                            <>
                                <dl className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    <div>
                                        <dt className="text-caption">
                                            Recorded deliveries
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {source.total} total ·{' '}
                                            {source.failures} need attention
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-caption">
                                            Awaiting IT delivery
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {source.pending}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-caption">
                                            Oldest unfinished delivery
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {formatDateTime(
                                                source.oldest_pending_at,
                                                'None recorded',
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-caption">
                                            Last completed IT delivery
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {formatDateTime(
                                                source.last_success_at,
                                                'No verified success recorded',
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                                <p className="text-caption">
                                    Checked {formatDateTime(health.checked_at)}.
                                    These are recorded delivery outcomes, not
                                    device-health measurements.
                                </p>
                                <DeliveryHistory
                                    key={`${viewerId}-${source.source}`}
                                    health={source}
                                    actorId={health.viewer_user_id}
                                    onAccessLost={() => setAccessLost(true)}
                                />
                            </>
                        )}
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
