import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { StatusBadge } from '@/components/ui/status-badge';
import type { ItWorkTaskReadiness } from '@/hooks/it-work-task-lifecycle';
import { useItWorkTaskHistory } from '@/hooks/use-it-work-task-history';
import { formatDateTime } from '@/lib/datetime';
import { ChevronDown, History } from 'lucide-react';
import { useState } from 'react';

export function TicketWorkTaskReadiness({
    readiness,
    ticketId,
}: {
    readiness?: ItWorkTaskReadiness;
    ticketId: number;
}) {
    if (!readiness)
        return (
            <p role="status" className="text-sm text-muted-foreground">
                Task readiness has not been checked. Refresh the ticket before
                changing work.
            </p>
        );
    return (
        <div className="space-y-2 text-sm">
            {!readiness.storage_ready && (
                <p role="status">
                    Task history storage is not ready. Governed work changes are
                    unavailable.
                </p>
            )}
            {[
                ...readiness.blockers.map((item) => ({
                    ...item,
                    blocked: true,
                })),
                ...readiness.warnings.map((item) => ({
                    ...item,
                    blocked: false,
                })),
            ].map((item, index) => (
                <div
                    key={`${item.code}:${item.task_id}:${item.approval_id}:${index}`}
                    className={
                        item.blocked
                            ? 'text-status-critical'
                            : 'text-status-warning'
                    }
                >
                    <p>{item.message}</p>
                    {item.task_id !== null && (
                        <a
                            href={`/it/tickets/${ticketId}?tab=tasks#task-${item.task_id}`}
                            className="text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            Review task #{item.task_id}
                        </a>
                    )}
                    {item.approval_id !== null && (
                        <a
                            href={`/it/tickets/${ticketId}?tab=approvals#approval-${item.approval_id}`}
                            className="ml-2 text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            Review approval #{item.approval_id}
                        </a>
                    )}
                </div>
            ))}
            {readiness.completion === 'invalid' && (
                <p className="font-medium text-status-critical">
                    The recorded completion needs review before this work can
                    satisfy its requirements.
                </p>
            )}
            {readiness.completion === 'unknown' &&
                !readiness.warnings.length && (
                    <p className="text-status-warning">
                        Historical completion provenance is unknown.
                    </p>
                )}
        </div>
    );
}

export function TaskHistoryCheckControls({
    history,
}: {
    history: ReturnType<typeof useItWorkTaskHistory>;
}) {
    return (
        <div className="space-y-2">
            {history.error && (
                <p role="alert" className="text-sm text-status-critical">
                    {history.error}
                </p>
            )}
            <div className="flex flex-wrap items-center gap-2">
                {history.busy ? (
                    <>
                        <span role="status" className="text-sm">
                            Checking task history…
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={history.cancel}
                        >
                            Cancel check
                        </Button>
                    </>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => void history.load()}
                    >
                        {history.page
                            ? 'Refresh task history'
                            : history.error
                              ? 'Retry history check'
                              : 'Check task history'}
                    </Button>
                )}
            </div>
        </div>
    );
}

export function TicketWorkTaskHistory({
    actorId,
    ticketId,
    taskId,
    version,
    canView,
    onAccessLost,
    onSessionExpired,
}: {
    actorId: number;
    ticketId: number;
    taskId: number;
    version: number;
    canView: boolean;
    onAccessLost?: () => void;
    onSessionExpired?: () => void;
}) {
    const [open, setOpen] = useState(false);
    const history = useItWorkTaskHistory({
        actorId,
        ticketId,
        taskId,
        enabled: canView,
        onAccessLost,
        onSessionExpired,
    });
    const stale = history.page !== null && history.page.lock_version < version;
    if (!canView) return null;
    return (
        <Collapsible
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (
                    next &&
                    (!history.page || stale) &&
                    !history.busy &&
                    !history.concealed
                )
                    void history.load();
            }}
            className="space-y-3"
        >
            <CollapsibleTrigger asChild>
                <Button type="button" variant="outline">
                    <History className="size-4" />
                    Completion history
                    <ChevronDown className="size-4" />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent className="space-y-3">
                <TaskHistoryCheckControls history={history} />
                {stale && !history.concealed && (
                    <p role="status" className="text-sm text-status-warning">
                        The ticket changed. Refresh task history to review the
                        current completion records.
                    </p>
                )}
                {history.page && !history.concealed && !stale && (
                    <>
                        <TicketWorkTaskReadiness
                            readiness={history.page.readiness}
                            ticketId={ticketId}
                        />
                        {history.page.readiness.storage_ready && (
                            <p className="text-caption text-muted-foreground">
                                Showing {history.page.history.entries.length} of{' '}
                                {history.page.history.total_count} recorded
                                completions.
                            </p>
                        )}
                        {history.page.readiness.storage_ready &&
                            history.page.history.total_count === 0 && (
                                <p className="text-sm">
                                    No completion has been recorded for this
                                    task.
                                </p>
                            )}
                        <ol className="space-y-4">
                            {history.page.history.entries.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="space-y-2 border-l-2 border-border pl-3 text-sm"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h4 className="font-semibold">
                                            Completion {entry.sequence}
                                        </h4>
                                        <StatusBadge
                                            variant={
                                                entry.id ===
                                                history.page!
                                                    .current_completion_id
                                                    ? 'info'
                                                    : 'neutral'
                                            }
                                            size="sm"
                                        >
                                            {entry.id ===
                                            history.page!.current_completion_id
                                                ? 'Current recorded completion'
                                                : 'Historical completion'}
                                        </StatusBadge>
                                    </div>
                                    {entry.source === 'legacy_snapshot' && (
                                        <p className="text-status-warning">
                                            Legacy evidence captured{' '}
                                            {formatDateTime(entry.recorded_at)}.
                                            The task definition below was
                                            observed at capture; prior approval
                                            and prerequisite provenance may not
                                            be recorded.
                                        </p>
                                    )}
                                    <p>
                                        Completed{' '}
                                        {entry.completed_at
                                            ? formatDateTime(entry.completed_at)
                                            : 'at an unrecorded time'}{' '}
                                        by{' '}
                                        {entry.completed_by?.name ??
                                            (entry.completed_by_user_id !== null
                                                ? `unavailable account #${entry.completed_by_user_id}`
                                                : 'an unrecorded person')}
                                        .
                                    </p>
                                    <p className="text-caption text-muted-foreground">
                                        Recorded{' '}
                                        {formatDateTime(entry.recorded_at)}
                                        {entry.recorded_by
                                            ? ` by ${entry.recorded_by.name}`
                                            : entry.recorded_by_user_id !== null
                                              ? ` by unavailable account #${entry.recorded_by_user_id}`
                                              : '; recording account not recorded'}
                                        .
                                    </p>
                                    <p className="font-medium break-words">
                                        {entry.task_definition.title}
                                    </p>
                                    {entry.task_definition.description && (
                                        <p className="break-words whitespace-pre-wrap">
                                            {entry.task_definition.description}
                                        </p>
                                    )}
                                    <p>
                                        {entry.task_definition.is_required
                                            ? 'Required work'
                                            : 'Optional work'}{' '}
                                        ·{' '}
                                        {entry.task_definition.evidence_required
                                            ? 'Evidence required'
                                            : 'Evidence optional'}
                                    </p>
                                    <p className="text-caption text-muted-foreground">
                                        Recorded team:{' '}
                                        {entry.task_definition.team_id ??
                                            'none'}{' '}
                                        · Assigned account:{' '}
                                        {entry.task_definition
                                            .assigned_to_user_id ?? 'none'}{' '}
                                        · Due:{' '}
                                        {entry.task_definition.due_at
                                            ? formatDateTime(
                                                  entry.task_definition.due_at,
                                              )
                                            : 'none'}
                                    </p>
                                    {entry.completion_note && (
                                        <p className="break-words whitespace-pre-wrap">
                                            {entry.completion_note}
                                        </p>
                                    )}
                                    {entry.evidence?.length ? (
                                        <ul
                                            className="list-disc space-y-1 pl-5"
                                            aria-label={`Evidence for completion ${entry.sequence}`}
                                        >
                                            {entry.evidence.map(
                                                (reference, index) => (
                                                    <li
                                                        key={index}
                                                        className="break-words whitespace-pre-wrap"
                                                    >
                                                        {reference}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    ) : (
                                        <p className="text-muted-foreground">
                                            No evidence references recorded.
                                        </p>
                                    )}
                                    {entry.prerequisite_completions === null ? (
                                        <p className="text-status-warning">
                                            Prior prerequisite completions were
                                            not recorded.
                                        </p>
                                    ) : entry.prerequisite_completions
                                          .length ? (
                                        <ul
                                            aria-label={`Prerequisites for completion ${entry.sequence}`}
                                        >
                                            {entry.prerequisite_completions.map(
                                                (binding) => (
                                                    <li key={binding.task_id}>
                                                        Task #{binding.task_id}{' '}
                                                        · completion record #
                                                        {binding.completion_id}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    ) : (
                                        <p>
                                            No prerequisites were required by
                                            this completion.
                                        </p>
                                    )}
                                    <p>
                                        {entry.approval_id !== null
                                            ? `Approval request #${entry.approval_id}`
                                            : entry.source === 'legacy_snapshot'
                                              ? 'Prior approval provenance was not recorded.'
                                              : 'No approval request was linked.'}
                                    </p>
                                </li>
                            ))}
                        </ol>
                        {history.page.history.has_more && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={history.busy}
                                onClick={() => void history.load(true)}
                            >
                                Load earlier completions
                            </Button>
                        )}
                    </>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

export function TaskImpactReview({
    history,
    version,
    acknowledged,
    onAcknowledge,
    onReviewCurrent,
    ticketId,
}: {
    history: ReturnType<typeof useItWorkTaskHistory>;
    version: number;
    acknowledged: boolean;
    onAcknowledge: () => void;
    onReviewCurrent: () => void;
    ticketId: number;
}) {
    return (
        <section
            aria-label="Task change consequences"
            className="space-y-3 rounded-lg border border-border bg-muted/20 p-3"
        >
            <h4 className="text-sm font-semibold">Review affected work</h4>
            <TaskHistoryCheckControls history={history} />
            {history.page && !history.concealed && (
                <>
                    <TicketWorkTaskReadiness
                        readiness={history.page.readiness}
                        ticketId={ticketId}
                    />
                    {history.page.lock_version !== version ? (
                        <div className="space-y-2">
                            <p
                                role="alert"
                                className="text-sm text-status-warning"
                            >
                                The ticket changed. Review its current task
                                before accepting these consequences.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onReviewCurrent}
                            >
                                Review current task
                            </Button>
                        </div>
                    ) : (
                        <>
                            <p className="text-sm">
                                Existing completion evidence is preserved.
                                Dependent tasks are not automatically reopened
                                or repaired.
                            </p>
                            {history.page.affected_tasks.length ? (
                                <ul className="list-disc space-y-1 pl-5 text-sm">
                                    {history.page.affected_tasks.map((task) => (
                                        <li key={task.id}>
                                            {task.title} ·{' '}
                                            {task.status.replaceAll('_', ' ')}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-sm">
                                    No dependent tasks are recorded in this
                                    current graph.
                                </p>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                disabled={
                                    history.busy ||
                                    !history.page.readiness.storage_ready ||
                                    acknowledged
                                }
                                onClick={onAcknowledge}
                            >
                                {acknowledged
                                    ? 'Consequences reviewed'
                                    : 'I have reviewed the consequences'}
                            </Button>
                        </>
                    )}
                </>
            )}
        </section>
    );
}
