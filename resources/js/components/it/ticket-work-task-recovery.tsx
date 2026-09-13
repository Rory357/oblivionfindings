import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { type ItWorkTaskReview } from '@/hooks/it-work-task-command';
import { useItWorkTaskEditor } from '@/hooks/use-it-work-task-editor';
import { formatDateTime } from '@/lib/datetime';
import { ClipboardCheck, ListOrdered } from 'lucide-react';
import { useState } from 'react';
import { TicketWorkTaskReadiness } from './ticket-work-task-history';

export type WorkTaskEditor = ReturnType<typeof useItWorkTaskEditor>;

/** Metadata first; private current fields render only after the canonical review. */
export function TicketWorkTaskRecovery({
    editor,
    taskId,
    onAdopt,
}: {
    editor: WorkTaskEditor;
    taskId: number | null;
    onAdopt?: (review: ItWorkTaskReview) => void;
}) {
    const [cancelUuid, setCancelUuid] = useState<string | null>(null);
    const [discardBuffer, setDiscardBuffer] = useState<string | null>(null);
    const { command, memory } = editor;
    const currentTask = command.review?.tasks.find(({ id }) => id === taskId);
    return (
        <div className="space-y-3">
            {(command.message || memory.warning || editor.restoredBlocker) && (
                <div
                    role="status"
                    aria-live="polite"
                    className="rounded-lg border border-border bg-muted/30 p-3 text-sm"
                >
                    {command.message && <p>{command.message}</p>}
                    {memory.warning && <p>{memory.warning}</p>}
                    {editor.restoredBlocker && <p>{editor.restoredBlocker}</p>}
                </div>
            )}
            {Object.keys(editor.errors).length > 0 && (
                <div
                    role="alert"
                    className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
                >
                    <p className="font-semibold">Check the task details.</p>
                    <ul className="mt-1 list-disc pl-5">
                        {[...new Set(Object.values(editor.errors))].map(
                            (message) => (
                                <li key={message}>{message}</li>
                            ),
                        )}
                    </ul>
                </div>
            )}
            {(command.busy || memory.busy) && (
                <div className="flex items-center gap-3">
                    <p role="status" className="text-sm">
                        {memory.busy
                            ? 'Checking access to the retained task work…'
                            : command.stage === 'reviewing'
                              ? 'Loading current task details…'
                              : 'Waiting for the task command result…'}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            memory.busy ? memory.cancel() : command.cancelWait()
                        }
                    >
                        Cancel wait
                    </Button>
                </div>
            )}
            {command.stage !== 'access' && !command.busy && !memory.busy && (
                <>
                    {memory.notices.map((notice, index) => (
                        <div
                            key={notice.bufferId}
                            className="flex flex-wrap items-center gap-2 rounded-lg border border-border p-3"
                        >
                            <span className="mr-auto text-sm">
                                Retained task draft {index + 1}
                                {notice.outcomeUnknown
                                    ? ' · command outcome unknown'
                                    : ''}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    void editor.resume(notice.bufferId)
                                }
                            >
                                Resume draft {index + 1}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                disabled={notice.outcomeUnknown}
                                onClick={() =>
                                    setDiscardBuffer(notice.bufferId)
                                }
                            >
                                Discard draft {index + 1}
                            </Button>
                        </div>
                    ))}
                    {editor.recoveredWaiting && !command.references.length && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={editor.startSeparateDraft}
                        >
                            Start a separate draft
                        </Button>
                    )}
                    {command.references.map((uuid, index) => (
                        <div
                            key={uuid}
                            className="flex flex-wrap items-center gap-2 rounded-lg border border-border p-3"
                        >
                            <span className="mr-auto text-sm">
                                Pending task command {index + 1}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => command.recover(uuid)}
                            >
                                Check result {index + 1}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCancelUuid(uuid)}
                            >
                                Cancel command {index + 1}
                            </Button>
                        </div>
                    ))}
                    {command.pendingIntent && !editor.concealed && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={command.retry}
                        >
                            Retry original command
                        </Button>
                    )}
                    {(editor.concealed ||
                        editor.restoredBlocker ||
                        ['conflict', 'unknown', 'reviewed', 'session'].includes(
                            command.stage,
                        )) && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => void command.reviewCurrent()}
                        >
                            Review current task
                        </Button>
                    )}
                </>
            )}
            {!editor.concealed && command.review && (
                <section
                    aria-label="Current task review"
                    className="space-y-3 rounded-lg border border-border bg-muted/20 p-4"
                >
                    <h3 className="text-section-title">
                        Current work · version {command.review.version}
                    </h3>
                    {currentTask ? (
                        <>
                            <TicketWorkTaskReadiness
                                readiness={currentTask.readiness}
                                ticketId={command.review.ticketId}
                            />
                            <ReviewCard
                                icon={ClipboardCheck}
                                title="Current saved task"
                            >
                                <ReviewRow
                                    label="Linked approval"
                                    value={
                                        currentTask.approval
                                            ? `Request #${currentTask.approval.id} · ${currentTask.approval.status}`
                                            : 'None'
                                    }
                                />
                                <ReviewRow
                                    label="Title"
                                    value={
                                        <span className="break-words">
                                            {currentTask.title}
                                        </span>
                                    }
                                />
                                <ReviewRow
                                    label="Description"
                                    value={
                                        <span className="break-words whitespace-pre-wrap">
                                            {currentTask.description ??
                                                'No description recorded.'}
                                        </span>
                                    }
                                />
                                <ReviewRow
                                    label="Status"
                                    value={currentTask.status.replaceAll(
                                        '_',
                                        ' ',
                                    )}
                                />
                                <ReviewRow
                                    label="Assigned technician"
                                    value={
                                        <span className="break-words">
                                            {currentTask.assignee?.name ??
                                                'Unassigned'}
                                        </span>
                                    }
                                />
                                <ReviewRow
                                    label="Responsible team"
                                    value={
                                        <span className="break-words">
                                            {currentTask.team?.name ??
                                                'Unassigned'}
                                        </span>
                                    }
                                />
                                <ReviewRow
                                    label="Due (New Zealand time)"
                                    value={formatDateTime(
                                        currentTask.due_at,
                                        'Not set',
                                    )}
                                />
                                <ReviewRow
                                    label="Required before settlement"
                                    value={
                                        currentTask.is_required ? 'Yes' : 'No'
                                    }
                                />
                                <ReviewRow
                                    label="Completion evidence required"
                                    value={
                                        currentTask.evidence_required
                                            ? 'Yes'
                                            : 'No'
                                    }
                                />
                                <ReviewRow
                                    label="Prerequisites"
                                    value={
                                        currentTask.dependencies.length ? (
                                            <span className="block space-y-1">
                                                {currentTask.dependencies.map(
                                                    (dependency) => (
                                                        <span
                                                            key={dependency.id}
                                                            className="block break-words"
                                                        >
                                                            {dependency.title} ·{' '}
                                                            {dependency.status.replaceAll(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </span>
                                                    ),
                                                )}
                                            </span>
                                        ) : (
                                            'None'
                                        )
                                    }
                                />
                                <ReviewRow
                                    label="Order position"
                                    value={`${command.review.tasks.findIndex(({ id }) => id === currentTask.id) + 1} of ${command.review.tasks.length}`}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ClipboardCheck}
                                title="Current completion evidence"
                            >
                                <ReviewRow
                                    label="Completion note"
                                    value={
                                        <span className="break-words whitespace-pre-wrap">
                                            {currentTask.completion_note ??
                                                'No completion note recorded.'}
                                        </span>
                                    }
                                />
                                <ReviewRow
                                    label="Evidence references"
                                    value={
                                        currentTask.evidence?.length ? (
                                            <span className="block space-y-1">
                                                {currentTask.evidence.map(
                                                    (item, index) => (
                                                        <span
                                                            key={index}
                                                            className="block break-words"
                                                        >
                                                            {item}
                                                        </span>
                                                    ),
                                                )}
                                            </span>
                                        ) : (
                                            'None recorded'
                                        )
                                    }
                                />
                                <ReviewRow
                                    label="Completed by"
                                    value={
                                        <span className="break-words">
                                            {currentTask.completed_by?.name ??
                                                'Not recorded'}
                                        </span>
                                    }
                                />
                                <ReviewRow
                                    label="Completed (New Zealand time)"
                                    value={formatDateTime(
                                        currentTask.completed_at,
                                        'Not recorded',
                                    )}
                                />
                            </ReviewCard>
                        </>
                    ) : (
                        <ReviewCard
                            icon={ListOrdered}
                            title="Current saved task order"
                        >
                            {command.review.tasks.length ? (
                                <ol
                                    aria-label="Current saved task order"
                                    className="list-decimal space-y-2 pl-5 text-sm"
                                >
                                    {command.review.tasks.map((task) => (
                                        <li
                                            key={task.id}
                                            className="break-words"
                                        >
                                            {task.title} ·{' '}
                                            {task.status.replaceAll('_', ' ')}
                                        </li>
                                    ))}
                                </ol>
                            ) : (
                                <EmptyState
                                    variant="inline"
                                    icon={ListOrdered}
                                    title="No saved tasks in the current register"
                                />
                            )}
                        </ReviewCard>
                    )}
                    <p className="text-sm text-muted-foreground">
                        Your proposal is retained in the form. Only fields you
                        changed will be applied after a separate submission.
                    </p>
                    <Button
                        type="button"
                        disabled={
                            !command.review.canManage ||
                            command.outcomeUnknown ||
                            command.references.length > 0
                        }
                        onClick={() => {
                            const reviewed = editor.adoptReview();
                            if (reviewed) onAdopt?.(reviewed);
                        }}
                    >
                        Use reviewed version
                    </Button>
                </section>
            )}
            <ConfirmDialog
                open={cancelUuid !== null}
                onClose={() => setCancelUuid(null)}
                title="Cancel the pending task command?"
                description="The server will check its saved outcome first. A command that already committed stays committed. Otherwise cancellation permanently prevents that original command from applying. Your task proposal is retained."
                confirmText="Check and cancel command"
                variant="default"
                onConfirm={() => {
                    if (cancelUuid) command.cancelCommand(cancelUuid);
                }}
            />
            <ConfirmDialog
                open={discardBuffer !== null}
                onClose={() => setDiscardBuffer(null)}
                title="Discard this retained task draft?"
                description="This removes only this unsent browser copy. It does not change the ticket or another draft."
                confirmText="Discard draft"
                onConfirm={() => {
                    if (discardBuffer) memory.discardLocal(discardBuffer);
                }}
            />
        </div>
    );
}
