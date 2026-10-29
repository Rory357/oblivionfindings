import { PopupConfirm } from '@/components/popup';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { KeyRound, ShieldAlert } from 'lucide-react';
import { useState } from 'react';

export interface SshHostKeyState {
    trusted: string | null;
    offered: {
        fingerprint: string;
        seen_at: string;
        changed: boolean;
    } | null;
    can_trust: boolean;
    trust_url?: string | null;
}

/**
 * Phase A, item A6 (docs/security-devices/GOAL.md): a device's saved SSH login is only sent once
 * someone has trusted its key. Shows the key waiting to be checked, and "Trust this key" (or, for an
 * IT admin, "Replace trusted key" after a change) behind the kit's confirm pop-up.
 */
export function SshHostKeyNotice({
    state,
}: {
    state: SshHostKeyState | null | undefined;
}) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    if (!state || (!state.offered && !state.trusted)) return null;

    const offered = state.offered;
    if (!offered) {
        return (
            <p
                className="text-caption flex items-center gap-2 text-muted-foreground"
                data-testid="ssh-key-trusted"
            >
                <KeyRound className="size-3.5" aria-hidden="true" />
                SSH key trusted:{' '}
                <code className="font-mono">{state.trusted}</code>
            </p>
        );
    }

    const changed = offered.changed;
    const canAct = state.can_trust && Boolean(state.trust_url);

    const trust = () => {
        if (!state.trust_url || processing) return;
        setProcessing(true);
        setError(undefined);
        router.post(
            state.trust_url,
            { fingerprint: offered.fingerprint },
            {
                preserveScroll: true,
                onSuccess: () => setConfirming(false),
                onError: (errors) =>
                    setError(
                        (errors.fingerprint as string | undefined) ??
                            'The key could not be trusted. Try the backup again.',
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Alert
                variant={changed ? 'critical' : 'warning'}
                data-testid="ssh-key-notice"
            >
                <ShieldAlert aria-hidden="true" />
                <AlertTitle>
                    {changed
                        ? 'This device’s SSH key has changed'
                        : 'This device’s SSH key isn’t trusted yet'}
                </AlertTitle>
                <AlertDescription>
                    <p>
                        {changed
                            ? 'Backups are refused until someone checks the new key. If the change wasn’t planned, don’t trust it: something else may be answering at this address.'
                            : 'No login has been sent to it. Check that this key matches the one shown on the device itself, then trust it.'}
                    </p>
                    <p>
                        {changed ? 'New key: ' : 'Key: '}
                        <code className="font-mono">{offered.fingerprint}</code>
                        {offered.seen_at
                            ? ` · seen ${formatDateTime(offered.seen_at)}`
                            : null}
                    </p>
                    {changed && state.trusted ? (
                        <p>
                            Trusted key:{' '}
                            <code className="font-mono">{state.trusted}</code>
                        </p>
                    ) : null}
                    {canAct ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="mt-1"
                            onClick={() => setConfirming(true)}
                        >
                            {changed ? 'Replace trusted key' : 'Trust this key'}
                        </Button>
                    ) : changed ? (
                        <p>Only an IT admin can replace a trusted key.</p>
                    ) : null}
                </AlertDescription>
            </Alert>

            <PopupConfirm
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={trust}
                variant={changed ? 'warning' : 'plain'}
                icon={KeyRound}
                title={
                    changed
                        ? 'Replace the trusted SSH key?'
                        : 'Trust this device’s SSH key?'
                }
                description={
                    changed
                        ? 'Oblivion will send this device’s saved login to the new key from now on. Only do this if you know why the key changed. It’s recorded in the audit log.'
                        : 'Oblivion will send this device’s saved login only while it shows this key. If the key ever changes, backups stop until an IT admin checks it.'
                }
                context={{
                    title: offered.fingerprint,
                    tag: 'SSH key',
                    icon: KeyRound,
                    note: 'The key the device showed when Oblivion last connected.',
                }}
                confirmLabel={
                    changed ? 'Replace trusted key' : 'Trust this key'
                }
                processing={processing}
                processingLabel="Saving…"
                error={error}
                testId="ssh-key-trust-confirm"
            />
        </>
    );
}
