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
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Braces } from 'lucide-react';
import { useRef, useState } from 'react';

const operations = {
    create: 'Create ticket',
    read: 'Read ticket',
    update: 'Update ticket',
    link: 'Link tickets',
    comment: 'Add reply',
    transition: 'Change status',
    unknown: 'Unclassified request',
};
const outcomes = {
    committed: 'Completed',
    not_applied: 'Not applied',
    pending: 'No completed outcome',
    unknown: 'Outcome unverified',
};
const recovery = {
    read_again:
        'Read requests do not apply changes. The client can request the current record again; current credentials and access are checked on every read.',
    already_applied:
        'This command was applied. If the client lost its response, retrieve the result using the original request and Idempotency-Key; current access is checked again.',
    retry_same_request:
        'The command was rolled back. The original client can retry the unchanged request with the same Idempotency-Key after resolving the failure. Current credentials, permissions and record access are checked again.',
    correct_request:
        'Review the reported category in the original client and correct the request. This completed rejection is retained for its key; submit the corrected intent with a new Idempotency-Key.',
    reconcile:
        'The stored evidence does not prove whether work was applied. Reconcile the original client request and canonical work before retrying. Do not create a replacement intent to bypass this uncertainty.',
    review_identity:
        'This identity is expired or revoked. Review its access before recovery, and reconcile the original intent before attempting another command.',
};

export interface ApiOperationsHealth {
    viewer_user_id: number;
    available: boolean;
    checked_at: string;
    total: number | null;
    history_total: number | null;
    search_query: string;
    links: { label: string; url: string | null; active: boolean }[];
    failures: number | null;
    pending: number | null;
    oldest_pending_at: string | null;
    last_success_at: string | null;
    identities_url: string | null;
    page: number;
    last_page: number;
    previous_url: string | null;
    next_url: string | null;
    rows: {
        id: number;
        identity_name: string;
        operation: keyof typeof operations;
        response_status: number | null;
        outcome: keyof typeof outcomes;
        failure_category: string | null;
        attempt_count: number | null;
        last_attempt_at: string | null;
        created_at: string | null;
        completed_at: string | null;
        recovery: keyof typeof recovery;
    }[];
}

function ApiReceiptList({ health }: { health: ApiOperationsHealth }) {
    type Row = ApiOperationsHealth['rows'][number];
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const returnFocus = useRef<HTMLElement | null>(null);
    const context = useEntityContextMenu<Row>();
    const selected = health.rows.find((row) => row.id === selectedId);
    const open = (row: Row) => {
        // Menu items unmount when selected. Return to the stable record row,
        // including when this dialog was opened from its context menu.
        returnFocus.current =
            document
                .getElementById(`it-api-receipt-${row.id}`)
                ?.closest<HTMLElement>('[role="row"]') ?? null;
        context.close();
        setSelectedId(row.id);
    };
    const actionsFor = (row: Row): MenuItem[] => [
        {
            label: 'Review outcome and recovery',
            icon: Braces,
            onClick: () => open(row),
        },
    ];
    const tone = (row: Row) =>
        row.outcome === 'committed'
            ? 'success'
            : row.outcome === 'not_applied'
              ? 'critical'
              : 'warning';
    const category = (row: Row) =>
        `${row.response_status ?? 'Not recorded'} · ${row.failure_category?.replaceAll('_', ' ') ?? 'No recorded failure'}`;

    return (
        <div id="it-api-request-history" className="scroll-mt-20 space-y-5">
            <ListCaption
                title="Request history"
                caption={`${health.rows.length} of ${health.history_total} ${health.search_query ? 'matching requests ' : ''}shown · page ${health.page} of ${health.last_page}`}
            />
            {health.search_query && (
                <p className="text-caption">
                    Search results for “{health.search_query}”. Health totals
                    above include all requests for your manageable identities.
                </p>
            )}
            {health.rows.length === 0 ? (
                <p className="text-subtle">
                    {health.search_query
                        ? 'No requests match this search. Change or clear the header search to see other requests.'
                        : 'No requests have been recorded for your manageable identities.'}
                </p>
            ) : (
                <EntityTable
                    rows={health.rows}
                    rowKey={(row) => row.id}
                    identityLabel="Request"
                    identityWidth="2fr"
                    identity={(row) => ({
                        icon: Braces,
                        name: operations[row.operation],
                        subline: (
                            <span id={`it-api-receipt-${row.id}`}>
                                {row.identity_name} · receipt {row.id}
                            </span>
                        ),
                    })}
                    actionsFor={actionsFor}
                    onOpen={open}
                    onRowContextMenu={context.open}
                    columns={[
                        {
                            key: 'outcome',
                            label: 'Outcome',
                            width: '1.2fr',
                            cell: (row) => (
                                <EntityStatusChip variant={tone(row)}>
                                    {outcomes[row.outcome]}
                                </EntityStatusChip>
                            ),
                        },
                        {
                            key: 'category',
                            label: 'Response / category',
                            width: '1.5fr',
                            cell: (row) => (
                                <EntityChip>{category(row)}</EntityChip>
                            ),
                        },
                        {
                            key: 'attempts',
                            label: 'Attempts',
                            width: '90px',
                            cell: (row) => (
                                <EntityChip>
                                    {row.attempt_count ?? 'Unrecorded'}
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
                    title="API request actions"
                    items={actionsFor(context.ctx.record)}
                    onClose={context.close}
                />
            ) : null}
            <LaravelPagination
                lastPage={health.last_page}
                preserveState={false}
                links={health.links}
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
                        if (returnFocus.current?.isConnected) {
                            event.preventDefault();
                            returnFocus.current.focus();
                        }
                    }}
                >
                    {selected ? (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Request outcome · receipt {selected.id}
                                </DialogTitle>
                                <DialogDescription>
                                    {selected.identity_name} ·{' '}
                                    {operations[selected.operation]}
                                </DialogDescription>
                            </DialogHeader>
                            <div className="space-y-5">
                                <StatusBadge variant={tone(selected)}>
                                    {outcomes[selected.outcome]}
                                </StatusBadge>
                                <dl className="grid grid-cols-2 gap-5">
                                    <div>
                                        <dt className="text-caption">
                                            Response / category
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {category(selected)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-caption">
                                            Execution attempts
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {selected.attempt_count ??
                                                'Not recorded'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-caption">
                                            Last attempt
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {formatDateTime(
                                                selected.last_attempt_at,
                                                'Not recorded',
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-caption">
                                            Outcome recorded
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {formatDateTime(
                                                selected.completed_at,
                                                'Not recorded',
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                                <p className="text-subtle">
                                    {recovery[selected.recovery]}
                                </p>
                            </div>
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={() => setSelectedId(null)}
                                >
                                    Close
                                </Button>
                            </DialogFooter>
                        </>
                    ) : null}
                </DialogContent>
            </Dialog>
        </div>
    );
}

export function ItApiOperations({
    health,
    viewerId,
}: {
    health?: ApiOperationsHealth;
    viewerId: number | null;
}) {
    if (!health) return null;
    const visible = health.viewer_user_id === viewerId;
    return (
        <Card role="region" aria-label="API command diagnostics">
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <CardTitle className="text-section-title flex items-center gap-2">
                        <Braces className="size-5 text-primary" /> API commands
                    </CardTitle>
                    {visible && health.identities_url ? (
                        <Button variant="outline" asChild>
                            <a href={health.identities_url}>
                                Review API identities
                            </a>
                        </Button>
                    ) : null}
                </div>
                <p className="text-subtle">
                    Recorded API requests for identities you can currently
                    manage, including reads. Requests rejected before recording
                    are outside these totals. Original request payloads are not
                    retained for operator replay.
                </p>
            </CardHeader>
            <CardContent className="space-y-5">
                {!visible ? (
                    <p className="text-subtle">
                        Refresh the page to load diagnostics for your current
                        account.
                    </p>
                ) : !health.available ? (
                    <p className="text-subtle">
                        API diagnostics are unavailable. No healthy result has
                        been recorded.
                    </p>
                ) : (
                    <>
                        <dl className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <div>
                                <dt className="text-caption">
                                    Recorded commands
                                </dt>
                                <dd className="text-subtle text-foreground">
                                    {health.total} total · {health.failures}{' '}
                                    errors
                                </dd>
                            </div>
                            <div>
                                <dt className="text-caption">
                                    Last verified success
                                </dt>
                                <dd className="text-subtle text-foreground">
                                    {formatDateTime(
                                        health.last_success_at,
                                        'No verified success recorded',
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-caption">
                                    Without completed outcome
                                </dt>
                                <dd className="text-subtle text-foreground">
                                    {health.pending}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-caption">
                                    Oldest pending command
                                </dt>
                                <dd className="text-subtle text-foreground">
                                    {health.pending === 0
                                        ? 'None awaiting'
                                        : health.oldest_pending_at
                                          ? `${formatDateTime(health.oldest_pending_at)} · ${formatRelative(health.oldest_pending_at, Date.parse(health.checked_at))} at this check`
                                          : 'Time not recorded'}
                                </dd>
                            </div>
                        </dl>
                        <ApiReceiptList key={health.page} health={health} />
                    </>
                )}
            </CardContent>
        </Card>
    );
}
