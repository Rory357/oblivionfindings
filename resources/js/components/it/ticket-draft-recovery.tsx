import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import type {
    ItDraftResumed,
    ItDraftSnapshot,
} from '@/hooks/it-ticket-draft-contract';
import type {
    ItDraftBrowserRestored,
    ItTicketDraftClient,
} from '@/hooks/use-it-ticket-draft';
import { useState, type ReactNode } from 'react';

/** Shared controls only. The host owns its fields, focus, autosave and commit. */
export function TicketDraftRecovery({
    draft,
    snapshot,
    hasLocalChanges,
    onResume,
    onResumeMemory,
    onDiscarded,
    onStartNew,
    renderReview,
    onRequestRecovery,
    nextDraftAction,
}: {
    draft: ItTicketDraftClient;
    snapshot: ItDraftSnapshot;
    hasLocalChanges: boolean;
    onResume: (saved: ItDraftResumed) => void;
    onResumeMemory?: (restored: ItDraftBrowserRestored) => void;
    onDiscarded: () => void;
    onStartNew: () => void;
    renderReview: (saved: ItDraftResumed) => ReactNode;
    onRequestRecovery?: () => void;
    nextDraftAction?: {
        label: string;
        onClick: () => void;
        disabled?: boolean;
    };
}) {
    const [confirmation, setConfirmation] = useState<{
        action: 'resume' | 'discard' | 'release_upload';
        uuid: string;
    } | null>(null);
    const [memoryConfirmation, setMemoryConfirmation] = useState<{
        action: 'resume' | 'discard';
        id: string;
    } | null>(null);
    const identity = draft.current?.draft_uuid ?? draft.draft?.draft_uuid;
    const resumed = async () => {
        const saved = await draft.resume();
        if (saved) onResume(saved);
    };
    const discarded = async () => {
        if (await draft.discard()) onDiscarded();
    };
    const resumeBrowser = async (id: string) => {
        if (!onResumeMemory) return;
        const restored = await draft.resumeMemory(id);
        if (restored) onResumeMemory(restored);
    };
    if (
        draft.state === 'disabled' &&
        !draft.message &&
        !draft.memoryNotices.length &&
        !draft.memoryWarning
    )
        return null;
    const recoverable = [
        'conflict',
        'outcome_unknown',
        'session_expired',
    ].includes(draft.state);
    const saved = draft.isSaved(snapshot);
    const metadata = draft.current ?? draft.draft;
    const hasSavedWork =
        metadata?.has_content === true ||
        Object.values(metadata?.files ?? {}).some((count) => count > 0) ||
        draft.attachments.length > 0;
    // Never-saved defaults are not edits. A cleared, previously saved snapshot
    // still needs saving, even when the host now matches its initial defaults.
    const unsaved =
        !saved &&
        (hasLocalChanges || hasSavedWork || draft.lastSavedKey !== null);
    // An untouched slot has no proposal to review or reopen for. Preserve
    // submission gates and reveal the notice as soon as any work exists.
    const emptyContextNotice =
        !!metadata?.blocker &&
        ['ticket_changed', 'ticket_settled'].includes(metadata.blocker.code) &&
        draft.message === metadata.blocker.message;
    if (
        draft.state === 'ready' &&
        !draft.busy &&
        metadata?.state === 'active' &&
        !hasSavedWork &&
        !hasLocalChanges &&
        draft.lastSavedKey === null &&
        (!draft.message || emptyContextNotice) &&
        !Object.keys(draft.errors).length &&
        !draft.reviewed &&
        !draft.memoryNotices.length &&
        !draft.memoryWarning &&
        !draft.memoryFailure &&
        !draft.memoryBlocked &&
        (!metadata.blocker || emptyContextNotice) &&
        !draft.browserBlocker &&
        !draft.browserOutcomeUnknown
    )
        return null;
    const nextAction = draft.state === 'terminal' ? nextDraftAction : undefined;
    const text =
        draft.message ??
        (draft.busy
            ? 'Checking your saved draft…'
            : draft.state === 'available'
              ? 'A saved draft is available. Resume it to recover its text and files.'
              : draft.state === 'ready'
                ? saved
                    ? 'Draft saved.'
                    : unsaved
                      ? 'Your latest changes are not saved to a draft yet.'
                      : 'No changes to save.'
                : draft.state === 'terminal'
                  ? 'This draft has ended.'
                  : 'Saved draft recovery is ready to check.');
    const requestCommitted =
        ((draft.current ?? draft.draft)?.blocker ?? draft.browserBlocker)
            ?.code === 'request_committed';
    return (
        <section
            aria-label="Saved draft"
            className="space-y-3 rounded-lg border border-border bg-muted/30 p-4 text-sm"
        >
            {(draft.memoryNotices.length > 0 || draft.memoryWarning) && (
                <div className="space-y-3" aria-label="Browser work recovery">
                    <p className="font-medium">Unsaved browser work</p>
                    <p className="text-muted-foreground">
                        Work from this open application can be recovered after
                        checking your current access. It is held only in memory
                        and will not survive a full reload or closing the
                        application.
                    </p>
                    {draft.memoryWarning && (
                        <p role="alert">{draft.memoryWarning}</p>
                    )}
                    {draft.memoryNotices.map((notice, index) => (
                        <div
                            key={notice.bufferId}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <span>
                                Browser copy {index + 1}
                                {notice.outcomeUnknown
                                    ? ' · earlier result unconfirmed'
                                    : ''}
                                {notice.selectedFileCount
                                    ? ` · ${notice.selectedFileCount} selected files`
                                    : ''}
                            </span>
                            {onResumeMemory && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={draft.busy}
                                    onClick={() =>
                                        hasLocalChanges
                                            ? setMemoryConfirmation({
                                                  action: 'resume',
                                                  id: notice.bufferId,
                                              })
                                            : void resumeBrowser(
                                                  notice.bufferId,
                                              )
                                    }
                                >
                                    Resume browser work
                                </Button>
                            )}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={draft.busy}
                                onClick={() =>
                                    setMemoryConfirmation({
                                        action: 'discard',
                                        id: notice.bufferId,
                                    })
                                }
                            >
                                Discard browser copy
                            </Button>
                        </div>
                    ))}
                </div>
            )}
            <p
                role={
                    recoverable || draft.state === 'access_denied'
                        ? 'alert'
                        : 'status'
                }
                aria-live="polite"
            >
                {draft.state === 'disabled' && !draft.message
                    ? 'Saved draft recovery is not enabled.'
                    : text}
            </p>
            {Object.keys(draft.errors).length > 0 && (
                <ul className="list-disc space-y-1 pl-5">
                    {Object.entries(draft.errors).map(([key, message]) => (
                        <li key={key}>{message}</li>
                    ))}
                </ul>
            )}
            <div className="flex flex-wrap gap-2">
                {draft.busy ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={draft.cancel}
                    >
                        Cancel wait
                    </Button>
                ) : (
                    <>
                        {draft.state === 'session_expired' && (
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href="/login"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Sign in again
                                </a>
                            </Button>
                        )}
                        {draft.persistenceEnabled &&
                            (recoverable ||
                                draft.state === 'idle' ||
                                (draft.state === 'terminal' &&
                                    !nextAction &&
                                    !draft.draft)) && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => void draft.check()}
                                >
                                    Check saved draft
                                </Button>
                            )}
                        {draft.retryable && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => void draft.retry()}
                            >
                                Retry original command
                            </Button>
                        )}
                        {draft.state === 'available' && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    hasLocalChanges && identity
                                        ? setConfirmation({
                                              action: 'resume',
                                              uuid: identity,
                                          })
                                        : void resumed()
                                }
                            >
                                Resume saved draft
                            </Button>
                        )}
                        {['conflict', 'outcome_unknown'].includes(
                            draft.state,
                        ) &&
                            (draft.current ?? draft.draft)?.state ===
                                'active' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => void draft.review()}
                                >
                                    Review saved draft
                                </Button>
                            )}
                        {draft.state === 'ready' &&
                            draft.draft?.capabilities.save && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={!unsaved || draft.memoryBlocked}
                                    onClick={() => void draft.save(snapshot)}
                                >
                                    Save draft
                                </Button>
                            )}
                        {['ready', 'available', 'terminal'].includes(
                            draft.state,
                        ) &&
                            draft.draft?.capabilities.discard &&
                            hasSavedWork &&
                            identity && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setConfirmation({
                                            action: 'discard',
                                            uuid: identity,
                                        })
                                    }
                                >
                                    Discard saved draft
                                </Button>
                            )}
                        {draft.state === 'terminal' &&
                            !nextAction &&
                            draft.draft?.capabilities.start_new && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={async () => {
                                        if (await draft.startNew())
                                            onStartNew();
                                    }}
                                >
                                    Start a new draft
                                </Button>
                            )}
                        {nextAction && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={
                                    nextAction.disabled || draft.memoryBlocked
                                }
                                onClick={nextAction.onClick}
                            >
                                {nextAction.label}
                            </Button>
                        )}
                        {requestCommitted && onRequestRecovery && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={onRequestRecovery}
                            >
                                Check saved request
                            </Button>
                        )}
                    </>
                )}
            </div>
            {draft.reviewed && (
                <Card className="gap-3 bg-background p-3">
                    <p className="font-medium">Review the saved draft</p>
                    {renderReview(draft.reviewed)}
                    <p className="text-muted-foreground">
                        Using this revision keeps your current form text. Save
                        again to apply it. Saved files use the reviewed draft.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={draft.busy}
                        onClick={draft.adoptReviewed}
                    >
                        Use reviewed revision
                    </Button>
                    {draft.canReleaseUpload && identity && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={draft.busy}
                            onClick={() =>
                                setConfirmation({
                                    action: 'release_upload',
                                    uuid: identity,
                                })
                            }
                        >
                            Release selected upload
                        </Button>
                    )}
                </Card>
            )}
            <ConfirmDialog
                open={confirmation !== null && confirmation.uuid === identity}
                onClose={() => setConfirmation(null)}
                title={
                    confirmation?.action === 'resume'
                        ? 'Replace current text with the saved draft?'
                        : confirmation?.action === 'release_upload'
                          ? 'Release the selected upload?'
                          : 'Discard this saved draft?'
                }
                description={
                    confirmation?.action === 'resume'
                        ? 'Resuming replaces the current form text and step with the saved version. Unsaved changes in this form will be lost.'
                        : confirmation?.action === 'release_upload'
                          ? 'This removes the original selected file from browser memory. Any incomplete saved file remains on the draft and must be removed before submitting. Select the file again deliberately to upload a replacement.'
                          : 'This ends the saved draft and removes its staged files. This cannot be undone.'
                }
                confirmText={
                    confirmation?.action === 'resume'
                        ? 'Resume saved draft'
                        : confirmation?.action === 'release_upload'
                          ? 'Release upload'
                          : 'Discard draft'
                }
                variant={
                    confirmation?.action === 'resume'
                        ? 'default'
                        : 'destructive'
                }
                onConfirm={() => {
                    if (
                        !confirmation ||
                        confirmation.uuid !== identity ||
                        draft.busy
                    )
                        return;
                    if (confirmation.action === 'resume') void resumed();
                    else if (confirmation.action === 'release_upload')
                        draft.releasePendingUpload();
                    else void discarded();
                }}
            />
            <ConfirmDialog
                open={
                    memoryConfirmation !== null &&
                    draft.memoryNotices.some(
                        (notice) => notice.bufferId === memoryConfirmation.id,
                    )
                }
                onClose={() => setMemoryConfirmation(null)}
                title={
                    memoryConfirmation?.action === 'resume'
                        ? 'Replace this form with retained browser work?'
                        : 'Discard this browser copy?'
                }
                description={
                    memoryConfirmation?.action === 'resume'
                        ? 'Your current form will be replaced only after fresh authorization succeeds. This does not save or submit the recovered work.'
                        : 'This removes the selected unsaved text and files from browser memory. Saved drafts and submitted requests are unchanged.'
                }
                confirmText={
                    memoryConfirmation?.action === 'resume'
                        ? 'Resume browser work'
                        : 'Discard browser copy'
                }
                variant={
                    memoryConfirmation?.action === 'resume'
                        ? 'default'
                        : 'destructive'
                }
                onConfirm={() => {
                    if (!memoryConfirmation || draft.busy) return;
                    if (memoryConfirmation.action === 'resume')
                        void resumeBrowser(memoryConfirmation.id);
                    else draft.discardMemory(memoryConfirmation.id);
                }}
            />
        </section>
    );
}
