import { EntityChip, EntityStatusChip } from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { Activity } from 'lucide-react';
import { useRef, useState } from 'react';

const outcomes = {
    succeeded: 'Completed',
    failed: 'Failed',
    running: 'No completed outcome',
    pending: 'Mailbox scan pending',
    skipped: 'Work skipped',
    no_work: 'No eligible work',
    unknown: 'Outcome unverified',
};
type Outcome = keyof typeof outcomes;
export interface AutomationHistory {
    viewer_user_id: number;
    can_view: boolean;
    available: boolean;
    checked_at: string;
    search_query: string;
    total: number | null;
    unfinished: number | null;
    oldest_unfinished_at: string | null;
    page: number;
    last_page: number;
    links: { label: string; url: string | null; active: boolean }[];
    rows: {
        id: number;
        label: string;
        outcome: Outcome;
        execution_status: string;
        started_at: string | null;
        finished_at: string | null;
        runtime_ms: number | null;
        failure_category: string | null;
        error_summary: string | null;
        mailbox_counts: {
            connections: number;
            failed: number;
            skipped: number;
            pending: number;
        } | null;
        recovery_guidance: string;
        recovery_url: string | null;
        recovery_label: string | null;
    }[];
}
type Run = AutomationHistory['rows'][number];
const tone = (outcome: Outcome): StatusVariant =>
    outcome === 'succeeded'
        ? 'success'
        : outcome === 'failed'
          ? 'critical'
          : outcome === 'no_work' || outcome === 'skipped'
            ? 'neutral'
            : 'warning';
const stamp = (value: string | null) => formatDateTime(value, 'Not recorded');

function RunHistory({ health }: { health: AutomationHistory }) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const returnFocus = useRef<HTMLElement | null>(null);
    const context = useEntityContextMenu<Run>();
    const selected = health.rows.find((row) => row.id === selectedId);
    const open = (row: Run) => {
        returnFocus.current =
            document
                .getElementById(`it-automation-run-${row.id}`)
                ?.closest<HTMLElement>('[role="row"]') ?? null;
        context.close();
        setSelectedId(row.id);
    };
    const actionsFor = (row: Run): MenuItem[] => [
        {
            label: 'Review run and recovery',
            icon: Activity,
            onClick: () => open(row),
        },
    ];
    return (
        <div className="space-y-5">
            <ListCaption
                title="Automation run history"
                caption={`${health.rows.length} of ${health.total} ${health.search_query ? 'matching ' : ''}executions shown · page ${health.page} of ${health.last_page}`}
            />
            {health.search_query && (
                <p className="text-caption">
                    Search results for “{health.search_query}” within the
                    selected period.
                </p>
            )}
            <p className="text-subtle">
                Each row is one execution. A finished mailbox batch can still
                leave a scan pending.{' '}
                {health.search_query ? 'Matching unfinished' : 'Unfinished'}{' '}
                executions: {health.unfinished}. A missing outcome does not
                prove that a worker is active.
            </p>
            <p className="text-caption">
                Oldest unfinished execution:{' '}
                {stamp(health.oldest_unfinished_at)} · Checked{' '}
                {stamp(health.checked_at)}
            </p>
            {health.rows.length === 0 ? (
                <p className="text-subtle">
                    {health.search_query
                        ? 'No automation runs match this search in the selected period. Change or clear the header search to see other runs.'
                        : 'No automation runs were recorded for this period.'}
                </p>
            ) : (
                <EntityTable
                    rows={health.rows}
                    rowKey={(row) => row.id}
                    identityLabel="Automation"
                    identityWidth="2fr"
                    identity={(row) => ({
                        icon: Activity,
                        name: row.label,
                        subline: (
                            <span id={`it-automation-run-${row.id}`}>
                                Run {row.id}
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
                            width: '1.5fr',
                            cell: (row) => (
                                <EntityStatusChip variant={tone(row.outcome)}>
                                    {outcomes[row.outcome]}
                                </EntityStatusChip>
                            ),
                        },
                        {
                            key: 'started',
                            label: 'Started',
                            width: '1.4fr',
                            cell: (row) => (
                                <EntityChip>{stamp(row.started_at)}</EntityChip>
                            ),
                        },
                        {
                            key: 'finished',
                            label: 'Execution finished',
                            width: '1.4fr',
                            cell: (row) => (
                                <EntityChip>
                                    {stamp(row.finished_at)}
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
                    title="Automation run actions"
                    items={actionsFor(context.ctx.record)}
                    onClose={context.close}
                />
            ) : null}
            <LaravelPagination
                lastPage={health.last_page}
                preserveState={false}
                preserveScroll
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
                                    Automation run {selected.id}
                                </DialogTitle>
                                <DialogDescription>
                                    {selected.label}
                                </DialogDescription>
                            </DialogHeader>
                            <div className="space-y-5">
                                <StatusBadge variant={tone(selected.outcome)}>
                                    {outcomes[selected.outcome]}
                                </StatusBadge>
                                <dl className="grid grid-cols-2 gap-5">
                                    <div>
                                        <dt className="text-caption">
                                            Started
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {stamp(selected.started_at)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-caption">
                                            Execution finished
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {stamp(selected.finished_at)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-caption">
                                            Recorded duration
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {selected.runtime_ms === null
                                                ? 'Not recorded'
                                                : `${selected.runtime_ms} ms`}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-caption">
                                            Failure category
                                        </dt>
                                        <dd className="text-subtle text-foreground">
                                            {selected.failure_category?.replaceAll(
                                                '_',
                                                ' ',
                                            ) ?? 'No recorded failure'}
                                        </dd>
                                    </div>
                                </dl>
                                {selected.error_summary ? (
                                    <p className="text-subtle text-foreground">
                                        {selected.error_summary}
                                    </p>
                                ) : null}
                                {selected.mailbox_counts ? (
                                    <p className="text-subtle">
                                        {selected.mailbox_counts.connections}{' '}
                                        connections considered ·{' '}
                                        {selected.mailbox_counts.failed} failed
                                        · {selected.mailbox_counts.pending}{' '}
                                        pending ·{' '}
                                        {selected.mailbox_counts.skipped}{' '}
                                        skipped
                                    </p>
                                ) : null}
                                <p className="text-subtle">
                                    {selected.recovery_guidance}
                                </p>
                            </div>
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={() => setSelectedId(null)}
                                >
                                    Close
                                </Button>
                                {selected.recovery_url &&
                                selected.recovery_label ? (
                                    <Button asChild>
                                        <a href={selected.recovery_url}>
                                            {selected.recovery_label}
                                        </a>
                                    </Button>
                                ) : null}
                            </DialogFooter>
                        </>
                    ) : null}
                </DialogContent>
            </Dialog>
        </div>
    );
}

export function ItAutomationHistory({
    health,
    viewerId,
}: {
    health?: AutomationHistory;
    viewerId: number | null;
}) {
    if (!health) return null;
    const visible = health.can_view && health.viewer_user_id === viewerId;
    return (
        <section
            aria-label="Automation run history"
            className="border-t border-border p-5"
        >
            {!visible ? (
                <p className="text-subtle">
                    Refresh the page to load automation history for your current
                    account.
                </p>
            ) : !health.available ? (
                <p className="text-subtle">
                    Automation run history is unavailable. No healthy result is
                    implied.
                </p>
            ) : (
                <RunHistory key={viewerId} health={health} />
            )}
        </section>
    );
}
