/**
 * "Back up settings now" for network gear: today's governed network.config.backup request. It
 * only reads the device's settings and keeps a copy. When a hub covers the Site it runs there and
 * nowhere else; when it can't run, the reason is shown and nothing is sent. The SSH key notice
 * (Phase A) stays wherever a backup is offered: the saved login is only used once the key is trusted.
 */
import { PopupCallout, PopupConfirm } from '@/components/popup';
import { SshHostKeyNotice } from '@/components/security-devices/ssh-host-key-notice';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import { HardDriveDownload } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { BackupAction, DevicePageProps } from './_device-page-types';

function idempotencyKey(deviceId: number): string {
    const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : String(Date.now());
    return `sd-backup:${deviceId}:${random}`;
}

export function BackupSettings({
    open,
    device,
    backup,
    onClose,
}: {
    open: boolean;
    device: { id: number; name: string };
    backup: BackupAction;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();
    useEffect(() => {
        if (open) setError(undefined);
    }, [open]);

    const waiting = backup.last_request?.waiting ? backup.last_request : null;
    const keyWaiting = backup.ssh_key.offered !== null;

    const runReady = (requestId: number) => {
        router.post(
            `/security-devices/commands/${requestId}/dispatch`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: () => setError('The backup was asked for, but it couldn’t be started. Try again in a minute.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const ask = () => {
        if (!backup.available || processing) return;
        setProcessing(true);
        setError(undefined);
        router.post(
            `/security-devices/devices/${device.id}/commands`,
            {
                capability: 'network.config.backup',
                parameters: {},
                reason: 'Settings backup asked for from the device page',
                idempotency_key: idempotencyKey(device.id),
                impact_acknowledged: true,
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const next = (page.props as unknown as DevicePageProps).actions?.backup?.last_request;
                    // A backup that needs nobody else's say-so starts straight away.
                    if (next && next.can_run) {
                        runReady(next.id);
                        return;
                    }
                    setProcessing(false);
                    onClose();
                },
                onError: (errors) => {
                    setProcessing(false);
                    setError(String(Object.values(errors)[0] ?? 'The backup couldn’t be asked for. Try again.'));
                },
            },
        );
    };

    let callout: { tone: 'info' | 'warning'; content: string } | null = null;
    if (!backup.available && backup.reason) callout = { tone: 'warning', content: backup.reason };
    else if (waiting) callout = { tone: 'info', content: `A backup asked for ${waiting.at ? seenTime(waiting.at) : 'earlier'} is ${waiting.label.toLowerCase()}.` };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={ask}
            variant="plain"
            icon={HardDriveDownload}
            title="Back up settings now?"
            description={
                <>
                    Oblivion reads <b className="font-semibold text-foreground">{device.name}</b>’s settings
                    {backup.via ? ` through ${backup.via}` : ''} and keeps a copy. Nothing on the device changes.
                </>
            }
            context={{ title: device.name, icon: HardDriveDownload }}
            error={error}
            confirmLabel="Back up now"
            processing={processing}
            processingLabel="Asking…"
            confirmDisabled={!backup.available || waiting !== null}
            testId="sd-backup-settings"
        >
            {callout ? <PopupCallout tone={callout.tone}>{callout.content}</PopupCallout> : null}
            {keyWaiting ? (
                <PopupCallout tone="warning">
                    Its SSH key hasn’t been trusted yet, so the saved login won’t be sent and the backup will stop there.
                </PopupCallout>
            ) : null}
            <SshHostKeyNotice state={backup.ssh_key} />
        </PopupConfirm>
    );
}
