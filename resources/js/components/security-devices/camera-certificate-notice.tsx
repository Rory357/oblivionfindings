import { PopupConfirm } from '@/components/popup';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import axios from 'axios';
import { ShieldAlert, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

export interface CameraCertificateTrust {
    trusted_fingerprint: string | null;
    offered: {
        fingerprint: string;
        subject: string | null;
        valid_to: string | null;
        seen_at: string | null;
        changed: boolean;
    } | null;
    can_trust: boolean;
    host: string;
    port: number;
    trust_url: string;
}

/**
 * Phase A, item A5 (docs/security-devices/GOAL.md): a camera or Protect console with a self-signed
 * certificate gets no password until someone trusts it. Shows the certificate it offered and "Trust this
 * camera" (or, for an IT admin after a change, "Replace trusted certificate") behind the kit confirm.
 */
export function CameraCertificateNotice({
    trust,
    onTrusted,
}: {
    trust: CameraCertificateTrust | null | undefined;
    onTrusted: (message: string) => void;
}) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const offered = trust?.offered;
    if (!trust || !offered) return null;

    const changed = offered.changed;

    const confirm = async () => {
        if (processing) return;
        setProcessing(true);
        setError(undefined);
        try {
            const response = await axios.post<{ message: string }>(
                trust.trust_url,
                {
                    host: trust.host,
                    port: trust.port,
                    fingerprint: offered.fingerprint,
                },
                { headers: { Accept: 'application/json' } },
            );
            setConfirming(false);
            onTrusted(response.data.message);
        } catch (failure: unknown) {
            let message = 'The camera could not be trusted. Try again.';
            if (axios.isAxiosError(failure) && failure.response?.data) {
                const data = failure.response.data as {
                    message?: string;
                    errors?: { fingerprint?: string[] };
                };
                message =
                    data.errors?.fingerprint?.[0] ?? data.message ?? message;
            }
            setError(message);
        } finally {
            setProcessing(false);
        }
    };

    return (
        <>
            <Alert
                variant={changed ? 'critical' : 'warning'}
                data-testid="camera-certificate-notice"
            >
                <ShieldAlert aria-hidden="true" />
                <AlertTitle>
                    {changed
                        ? 'This camera’s certificate has changed'
                        : 'This camera isn’t trusted yet'}
                </AlertTitle>
                <AlertDescription>
                    <p>
                        {changed
                            ? 'No password was sent. If the change wasn’t planned, don’t trust it: something else may be answering at this address.'
                            : 'No password was sent. Check that this certificate matches the one your browser shows for the camera or console, then trust it.'}
                    </p>
                    <p>
                        <code className="font-mono break-all">
                            {offered.fingerprint}
                        </code>
                    </p>
                    <p>
                        {trust.host}:{trust.port}
                        {offered.subject ? ` · ${offered.subject}` : null}
                        {offered.valid_to
                            ? ` · valid until ${formatDateTime(offered.valid_to)}`
                            : null}
                    </p>
                    {trust.can_trust ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="mt-1"
                            onClick={() => setConfirming(true)}
                        >
                            {changed
                                ? 'Replace trusted certificate'
                                : 'Trust this camera'}
                        </Button>
                    ) : (
                        <p>
                            {changed
                                ? 'Only an IT admin can replace a trusted certificate.'
                                : 'Ask someone who manages devices to trust it.'}
                        </p>
                    )}
                </AlertDescription>
            </Alert>

            <PopupConfirm
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={() => void confirm()}
                variant={changed ? 'warning' : 'plain'}
                icon={ShieldCheck}
                title={
                    changed
                        ? 'Replace the trusted certificate?'
                        : 'Trust this camera?'
                }
                description={
                    changed
                        ? 'Oblivion will send this address its password using the new certificate from now on. Only do this if you know why it changed. It’s recorded in the audit log.'
                        : 'Oblivion will send this camera its password only while it shows this certificate. If it ever changes, Oblivion stops until an IT admin checks it. Every camera on this address uses the same trust.'
                }
                context={{
                    title: offered.fingerprint,
                    tag: `${trust.host}:${trust.port}`,
                    icon: ShieldCheck,
                    note: offered.seen_at
                        ? `Seen ${formatDateTime(offered.seen_at)}.`
                        : 'The certificate the camera showed when Oblivion last connected.',
                }}
                confirmLabel={
                    changed
                        ? 'Replace trusted certificate'
                        : 'Trust this camera'
                }
                processing={processing}
                processingLabel="Saving…"
                error={error}
                testId="camera-certificate-confirm"
            />
        </>
    );
}
