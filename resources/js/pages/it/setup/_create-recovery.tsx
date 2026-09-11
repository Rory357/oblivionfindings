import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { useState } from 'react';
import type {
    SetupCommandOutcome,
    useSetupCreateCommand,
} from './use-setup-create-command';

export function SetupCreateRecovery({
    command,
    onOutcome,
}: {
    command: ReturnType<typeof useSetupCreateCommand>;
    onOutcome: (outcome: SetupCommandOutcome | null) => void;
}) {
    const [cancel, setCancel] = useState(false);
    if (!command.pending && !command.message && !command.busy) return null;
    return (
        <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3 text-sm">
            {command.message && <p role="status">{command.message}</p>}
            {Object.values(command.errors).map((message, index) => (
                <p key={index} role="alert">
                    {message}
                </p>
            ))}
            {command.busy ? (
                <div role="status">
                    {command.state === 'sending'
                        ? 'Creating…'
                        : 'Checking the original command…'}{' '}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={command.cancelWait}
                    >
                        Cancel wait
                    </Button>
                </div>
            ) : command.pending ? (
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={async () => onOutcome(await command.recover())}
                    >
                        Check saved result
                    </Button>
                    {command.canRetry && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={async () =>
                                onOutcome(await command.retry())
                            }
                        >
                            Retry exact create
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => setCancel(true)}
                    >
                        Cancel earlier create
                    </Button>
                </div>
            ) : null}
            <ConfirmDialog
                open={cancel}
                onClose={() => setCancel(false)}
                title="Cancel this unconfirmed create?"
                description="This prevents the original command from creating a record if it has not already saved. If it already saved, its exact result will be shown and kept."
                confirmText="Check and cancel create"
                onConfirm={async () => onOutcome(await command.cancelCommand())}
            />
        </div>
    );
}
