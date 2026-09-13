import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import type { ItMergeIdentity } from '@/hooks/it-ticket-merge-contract';
import { useItTicketMergeCommand } from '@/hooks/use-it-ticket-merge-command';
import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';

/** Receipt recovery remains available on a closed original after a lost merge acknowledgement. */
export function TicketMergeRecovery(props: {
    actorId: number;
    sourceId: number;
}) {
    return (
        <RecoveryBody key={`${props.actorId}:${props.sourceId}`} {...props} />
    );
}

function RecoveryBody({
    actorId,
    sourceId,
}: {
    actorId: number;
    sourceId: number;
}) {
    const [cancel, setCancel] = useState<ItMergeIdentity | null>(null);
    const opener = useRef<HTMLButtonElement | null>(null);
    const heading = useRef<HTMLHeadingElement | null>(null);
    const command = useItTicketMergeCommand({
        actorId,
        sourceId,
        onSettled: () => {},
        onConceal: () => {},
    });
    if (!command.references.length && !command.message && !command.result)
        return null;
    return (
        <section
            aria-label="Merge result recovery"
            className="space-y-3 rounded-2xl border border-border bg-card p-4"
        >
            <h2 ref={heading} tabIndex={-1} className="text-sm font-semibold">
                Recover a merge result
            </h2>
            <p className="text-sm text-muted-foreground">
                Check the saved outcome of your earlier command. Checking does
                not submit another merge.
            </p>
            {command.message && (
                <p role="status" className="text-sm">
                    {command.stage === 'unknown' &&
                    command.references.length > 0
                        ? 'The merge result is not confirmed. Check its saved result again or cancel the pending command.'
                        : command.message}
                </p>
            )}
            {command.references.map((reference, index) => (
                <div
                    key={reference.requestUuid}
                    className="flex flex-wrap gap-2"
                >
                    <Button
                        variant="outline"
                        disabled={command.busy}
                        onClick={() => void command.check(reference)}
                    >
                        Check merge result {index + 1}
                    </Button>
                    <Button
                        variant="outline"
                        disabled={command.busy}
                        onClick={(event) => {
                            opener.current = event.currentTarget;
                            setCancel(reference);
                        }}
                    >
                        Cancel pending merge {index + 1}
                    </Button>
                </div>
            ))}
            {command.busy && (
                <Button variant="outline" onClick={command.stop}>
                    Stop waiting for merge result
                </Button>
            )}
            {command.result?.status === 'committed' && (
                <Button
                    onClick={() => {
                        if (command.result?.status === 'committed')
                            router.visit(command.result.url);
                    }}
                >
                    Open confirmed surviving ticket
                </Button>
            )}
            <ConfirmDialog
                open={cancel !== null}
                onClose={() => setCancel(null)}
                onConfirm={() => {
                    if (cancel) void command.cancel(cancel);
                }}
                title="Cancel this pending merge?"
                description="If the merge already committed, its saved result will be shown. Otherwise cancellation prevents this command from merging. It does not undo a recorded merge."
                confirmText="Cancel merge command"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    if (opener.current?.isConnected && !opener.current.disabled)
                        opener.current.focus();
                    else heading.current?.focus();
                }}
            />
        </section>
    );
}
