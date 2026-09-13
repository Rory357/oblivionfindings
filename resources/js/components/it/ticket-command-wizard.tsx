import { ConfirmDialog } from '@/components/confirm-dialog';
import { WizardShell } from '@/components/hr/wizard';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { useItTicketCommand } from '@/hooks/use-it-ticket-command';
import { useEffect, useRef, useState, type ComponentProps } from 'react';

type Command = ReturnType<typeof useItTicketCommand>;

export function ticketFormData(
    data: Record<string, string | number | boolean | null | File[] | number[]>,
): FormData {
    const body = new FormData();
    for (const [key, value] of Object.entries(data)) {
        if (value === null) continue;
        if (Array.isArray(value)) {
            value.forEach((item, index) =>
                body.append(
                    `${key}[${index}]`,
                    item instanceof File ? item : String(item),
                ),
            );
        } else {
            body.append(
                key,
                typeof value === 'boolean'
                    ? value
                        ? '1'
                        : '0'
                    : String(value),
            );
        }
    }
    return body;
}

function TicketCommandFeedback({
    command,
    reviewFields,
    forgetReference,
}: {
    command: Command;
    reviewFields: () => void;
    forgetReference: () => void;
}) {
    const summary = useRef<HTMLDivElement>(null);
    const message = command.message;
    useEffect(() => {
        if (message && !command.isBusy) summary.current?.focus();
    }, [message, command.isBusy]);
    if (!message && !command.isBusy) return null;

    return (
        <div
            ref={summary}
            tabIndex={-1}
            role={command.isBusy ? 'status' : 'alert'}
            className="mb-5 rounded-lg border border-border bg-muted/30 p-4 text-sm focus-visible:outline-2 focus-visible:outline-ring"
        >
            <p>{message || 'Saving your request…'}</p>
            {command.canRecover && command.requestId && (
                <p className="mt-2 break-all text-muted-foreground">
                    Request reference: {command.requestId}
                </p>
            )}
            {Object.keys(command.fieldErrors).length > 0 && (
                <ul className="mt-2 list-disc space-y-1 pl-5">
                    {Object.entries(command.fieldErrors).map(
                        ([field, error]) => (
                            <li key={field}>{error}</li>
                        ),
                    )}
                </ul>
            )}
            <div className="mt-3 flex flex-wrap gap-2">
                {command.state === 'session_expired' && (
                    <Button asChild variant="outline">
                        <a
                            href="/login"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Sign in again
                        </a>
                    </Button>
                )}
                {command.state === 'validation_error' && (
                    <Button variant="outline" onClick={reviewFields}>
                        Review fields
                    </Button>
                )}
                {command.canRecover && (
                    <Button
                        variant="outline"
                        onClick={() => void command.recover()}
                        disabled={command.isBusy}
                    >
                        Check saved request
                    </Button>
                )}
                {command.canRetry && (
                    <Button
                        variant="outline"
                        onClick={() => void command.retry()}
                        disabled={command.isBusy}
                    >
                        Retry same request
                    </Button>
                )}
                {command.isBusy && (
                    <Button variant="ghost" onClick={command.cancelWait}>
                        Stop waiting
                    </Button>
                )}
                {command.canForgetReference && (
                    <Button variant="ghost" onClick={forgetReference}>
                        Start a different request
                    </Button>
                )}
            </div>
        </div>
    );
}

export function TicketCommandWizard({
    command,
    dirty,
    children,
    onClose,
    onStepClick,
    draftExit,
    onDiscardBrowserWork,
    ...props
}: ComponentProps<typeof WizardShell> & {
    command: Command;
    dirty: boolean;
    onDiscardBrowserWork?: () => void;
    draftExit?: {
        busy: boolean;
        canSave: boolean;
        canDiscard: boolean;
        save: () => Promise<boolean>;
        discard: () => Promise<boolean>;
        keepReference?: () => boolean;
    };
}) {
    const [confirmDiscard, setConfirmDiscard] = useState(false);
    const [confirmExit, setConfirmExit] = useState(false);
    const [confirmForget, setConfirmForget] = useState(false);
    const [referenceStorageFailed, setReferenceStorageFailed] = useState(false);
    const [confirmDraftExit, setConfirmDraftExit] = useState(false);
    const [finishingDraftExit, setFinishingDraftExit] = useState(false);
    useEffect(() => {
        if ((!dirty && !command.isBusy) || command.result) return;
        const protect = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', protect);
        return () => window.removeEventListener('beforeunload', protect);
    }, [dirty, command.isBusy, command.result]);

    const close = () => {
        if (command.result) {
            onClose();
        } else if (command.state === 'access_denied') {
            command.reset('discard');
            onClose();
        } else if (command.state === 'unavailable') {
            if (dirty) setConfirmDiscard(true);
            else onClose();
        } else if (!command.canEdit) {
            if (command.isBusy) command.cancelWait();
            setReferenceStorageFailed(false);
            setConfirmExit(true);
        } else if (dirty) {
            if (draftExit) setConfirmDraftExit(true);
            else setConfirmDiscard(true);
        } else {
            onClose();
        }
    };

    return (
        <>
            <WizardShell
                {...props}
                onClose={close}
                onStepClick={onStepClick}
                steps={command.restoredFromReference ? [] : props.steps}
                headerLabel={
                    command.restoredFromReference
                        ? 'Check saved request'
                        : props.headerLabel
                }
                pct={command.restoredFromReference ? null : props.pct}
                footerStart={
                    command.restoredFromReference ? null : props.footerStart
                }
                footerEnd={
                    <>
                        <Button variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        {!command.restoredFromReference && props.footerEnd}
                    </>
                }
            >
                <TicketCommandFeedback
                    command={command}
                    reviewFields={() => onStepClick(0)}
                    forgetReference={() => setConfirmForget(true)}
                />
                {command.state !== 'access_denied' &&
                    (!command.restoredFromReference || command.result) && (
                        <fieldset
                            disabled={!command.canEdit}
                            className="min-w-0 border-0 p-0"
                        >
                            {children}
                        </fieldset>
                    )}
            </WizardShell>
            <AlertDialog
                open={confirmDraftExit}
                onOpenChange={setConfirmDraftExit}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Save this draft before closing?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Your latest changes may not be saved yet. Keep
                            editing to review an incomplete upload or uncertain
                            result. Closing with a reference does not confirm
                            that pending text or files were saved.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={finishingDraftExit}>
                            Keep editing
                        </AlertDialogCancel>
                        {draftExit?.canDiscard && (
                            <Button
                                type="button"
                                variant="destructive"
                                disabled={draftExit.busy || finishingDraftExit}
                                onClick={async () => {
                                    setFinishingDraftExit(true);
                                    try {
                                        if (await draftExit.discard()) {
                                            command.reset('discard');
                                            onClose();
                                        } else setConfirmDraftExit(false);
                                    } finally {
                                        setFinishingDraftExit(false);
                                    }
                                }}
                            >
                                Discard draft and close
                            </Button>
                        )}
                        {draftExit?.canSave && (
                            <Button
                                type="button"
                                disabled={draftExit.busy || finishingDraftExit}
                                onClick={async () => {
                                    setFinishingDraftExit(true);
                                    try {
                                        if (await draftExit.save()) onClose();
                                        else setConfirmDraftExit(false);
                                    } finally {
                                        setFinishingDraftExit(false);
                                    }
                                }}
                            >
                                Save draft and close
                            </Button>
                        )}
                        {draftExit?.keepReference && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={finishingDraftExit}
                                onClick={() => {
                                    if (draftExit.keepReference?.()) onClose();
                                    else setConfirmDraftExit(false);
                                }}
                            >
                                Close and keep draft reference
                            </Button>
                        )}
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <ConfirmDialog
                open={confirmDiscard}
                onClose={() => setConfirmDiscard(false)}
                onConfirm={() => {
                    onDiscardBrowserWork?.();
                    command.reset('discard');
                    onClose();
                }}
                title="Discard this unsaved ticket?"
                description="The details and selected files have not been saved. Keep editing to retain them."
                confirmText="Discard ticket"
            />
            <AlertDialog open={confirmExit} onOpenChange={setConfirmExit}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Close before the result is confirmed?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Your request may already be saved. Closing does not
                            cancel it. The details and selected files will be
                            removed from this browser form.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <p className="text-sm text-muted-foreground">
                        {referenceStorageFailed
                            ? 'This browser could not keep the request reference. Copy it before closing, or go back to check the saved request. Closing without it can make recovery harder.'
                            : 'Only the request reference will be kept in this tab for your signed-in account. Reopen the ticket form to check the saved request. Keep this reference if you need to close the browser tab.'}
                    </p>
                    <Input
                        aria-label="Pending request reference"
                        readOnly
                        value={command.requestId ?? ''}
                        onFocus={(event) => event.currentTarget.select()}
                    />
                    <AlertDialogFooter>
                        <AlertDialogCancel>Go back</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={(event) => {
                                if (command.result) {
                                    onClose();
                                } else if (command.state === 'access_denied') {
                                    command.reset('discard');
                                    onClose();
                                } else if (referenceStorageFailed) {
                                    command.reset('discard');
                                    onClose();
                                } else if (command.retainPendingReference()) {
                                    onClose();
                                } else {
                                    event.preventDefault();
                                    setReferenceStorageFailed(true);
                                }
                            }}
                        >
                            {referenceStorageFailed
                                ? 'Close without retained reference'
                                : 'Close and keep reference'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <ConfirmDialog
                open={confirmForget}
                onClose={() => setConfirmForget(false)}
                onConfirm={() => command.reset('new')}
                title="Forget this pending request reference?"
                description="The original request may already be saved. Starting another does not cancel it and may create a duplicate. This removes the retained reference; check the saved request first if you are unsure."
                confirmText="Forget reference and start another"
            />
        </>
    );
}
