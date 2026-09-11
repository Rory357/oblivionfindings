import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { canSubmitApproval } from '@/hooks/it-approval-work';
import type { ItApprovalOperation } from '@/hooks/it-ticket-approval-contract';
import type { useItApprovalEditor } from '@/hooks/use-it-approval-editor';
import { useState } from 'react';
import { TicketApprovalRecord } from './ticket-approval-record';

export type ApprovalEditor = ReturnType<typeof useItApprovalEditor>;
export function TicketApprovalRecovery({
    editor,
    operation,
    approvalId,
}: {
    editor: ApprovalEditor;
    operation: ItApprovalOperation;
    approvalId: number | null;
}) {
    const [cancelUuid, setCancelUuid] = useState<string | null>(null);
    const [discardId, setDiscardId] = useState<string | null>(null);
    const { command, memory } = editor;
    return (
        <div className="space-y-3">
            {(command.message ||
                memory.warning ||
                editor.blocker ||
                editor.reviewMessage) && (
                <div
                    role="status"
                    className="rounded-lg border border-border bg-muted/30 p-3 text-sm"
                >
                    {[
                        command.message,
                        memory.warning,
                        editor.blocker,
                        editor.reviewMessage,
                    ]
                        .filter(Boolean)
                        .map((message) => (
                            <p key={message}>{message}</p>
                        ))}
                </div>
            )}
            {!!Object.keys(editor.errors).length && (
                <div
                    role="alert"
                    className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
                >
                    <p className="font-semibold">Check the approval details.</p>
                    <ul className="mt-1 list-disc pl-5">
                        {[...new Set(Object.values(editor.errors))].map(
                            (message) => (
                                <li key={message}>{message}</li>
                            ),
                        )}
                    </ul>
                </div>
            )}
            {editor.busy && (
                <div className="flex flex-wrap items-center gap-2">
                    <p role="status" className="text-sm">
                        {editor.reviewState === 'loading'
                            ? 'Checking current approval details…'
                            : memory.busy
                              ? 'Checking access to this retained proposal…'
                              : 'Waiting for the approval result…'}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={editor.cancelWait}
                    >
                        Cancel wait
                    </Button>
                </div>
            )}
            {!editor.busy && command.stage !== 'access' && (
                <>
                    {memory.notices.map((notice, index) => (
                        <div
                            key={notice.bufferId}
                            className="flex flex-wrap items-center gap-2 rounded-lg border border-border p-3"
                        >
                            <span className="mr-auto text-sm">
                                Retained approval draft {index + 1}
                                {notice.outcomeUnknown
                                    ? ' · outcome unknown'
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
                                onClick={() => setDiscardId(notice.bufferId)}
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
                                Pending approval command {index + 1}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => void command.recover(uuid)}
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
                            onClick={() => void command.retry()}
                        >
                            Retry original command
                        </Button>
                    )}
                    {(editor.concealed ||
                        editor.stale ||
                        editor.blocker ||
                        [
                            'conflict',
                            'unknown',
                            'session',
                            'cancelled',
                        ].includes(command.stage) ||
                        editor.reviewState === 'failed') && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => void editor.reviewCurrent()}
                        >
                            Review current approval
                        </Button>
                    )}
                </>
            )}
            {!editor.concealed && editor.review && (
                <section
                    aria-label="Current approval review"
                    className="space-y-3 rounded-lg border border-border p-4"
                >
                    <h3 className="text-section-title">
                        Current approval · version {editor.review.version}
                    </h3>
                    {editor.review.work.current ? (
                        <TicketApprovalRecord
                            record={editor.review.work.current}
                            anchor={false}
                        />
                    ) : (
                        <p className="text-sm">
                            No approval request has been recorded.
                        </p>
                    )}
                    {canSubmitApproval(
                        editor.review.work,
                        operation,
                        approvalId,
                    ) ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={
                                editor.busy ||
                                command.outcomeUnknown ||
                                !!command.references.length
                            }
                            onClick={editor.adoptReview}
                        >
                            Use reviewed version
                        </Button>
                    ) : (
                        <p role="status" className="text-sm">
                            This operation is no longer available. Keep or
                            discard the proposal, then return to the current
                            ticket for the next action.
                        </p>
                    )}
                </section>
            )}
            <ConfirmDialog
                open={cancelUuid !== null}
                onClose={() => setCancelUuid(null)}
                onConfirm={() => {
                    if (cancelUuid) void command.cancelCommand(cancelUuid);
                }}
                title="Cancel the original command?"
                description="This checks the saved result first. If it already committed, that result is kept. Otherwise its original reference is fenced so it cannot later save. This does not cancel an existing approval request."
                confirmText="Cancel command"
            />
            <ConfirmDialog
                open={discardId !== null}
                onClose={() => setDiscardId(null)}
                onConfirm={() => {
                    if (discardId) memory.discardLocal(discardId);
                }}
                title="Discard this approval draft?"
                description="Remove this unsent browser copy. Saved approvals and other drafts are preserved."
                confirmText="Discard draft"
            />
        </div>
    );
}
