import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { Download, Loader2, Monitor } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    installationJSON,
    installationOptions,
    installationProfile,
    type InstallationOptions,
} from './installation-profile';

type Props = {
    open: boolean;
    onClose: () => void;
    deviceId: number;
    actorId: number;
};
export function InstallationProfileDialog({
    open,
    onClose,
    deviceId,
    actorId,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-xl">
                {open && (
                    <InstallationBody
                        key={deviceId + ':' + actorId}
                        deviceId={deviceId}
                        actorId={actorId}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
function InstallationBody({ deviceId, actorId, onClose }: Omit<Props, 'open'>) {
    const [options, setOptions] = useState<InstallationOptions | null>(null);
    const [busy, setBusy] = useState<string | null>('load');
    const [error, setError] = useState('');
    const [confirmRevoke, setConfirmRevoke] = useState(false);
    const [reason, setReason] = useState('');
    const [file, setFile] = useState<{ url: string; expires: string } | null>(
        null,
    );
    const active = useRef(false),
        pending = useRef(false),
        abort = useRef(new AbortController());
    const fileUrl = useRef<string | null>(null),
        expiryTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const clearFile = useCallback(() => {
        if (fileUrl.current) URL.revokeObjectURL(fileUrl.current);
        fileUrl.current = null;
        if (expiryTimer.current) clearTimeout(expiryTimer.current);
        expiryTimer.current = null;
        if (active.current) setFile(null);
    }, []);
    useEffect(() => {
        active.current = true;
        pending.current = true;
        const controller = new AbortController();
        abort.current = controller;
        const current = () => active.current && !controller.signal.aborted;
        installationJSON(deviceId, 'bootstrap-options', controller.signal)
            .then((value) => {
                if (current())
                    setOptions(installationOptions(value, deviceId, actorId));
            })
            .catch(() => {
                if (current())
                    setError(
                        'Installation details could not be loaded. Check your access and try again.',
                    );
            })
            .finally(() => {
                if (current()) {
                    pending.current = false;
                    setBusy(null);
                }
            });
        const hide = () => {
            if (document.hidden) {
                clearFile();
            }
        };
        document.addEventListener('visibilitychange', hide);
        return () => {
            active.current = false;
            abort.current.abort();
            clearFile();
            document.removeEventListener('visibilitychange', hide);
        };
    }, [deviceId, actorId, clearFile]);

    async function perform(kind: string, operation: () => Promise<void>) {
        if (pending.current) return;
        pending.current = true;
        setBusy(kind);
        setError('');
        try {
            await operation();
        } catch {
            if (active.current)
                setError(
                    kind === 'load'
                        ? 'Installation details could not be loaded. Check your access and try again.'
                        : 'The result could not be confirmed. Check installation status before trying another action. A profile may already have been created.',
                );
        } finally {
            if (active.current) {
                pending.current = false;
                setBusy(null);
            }
        }
    }
    async function refresh() {
        await perform('load', async () => {
            const value = await installationJSON(
                deviceId,
                'bootstrap-options',
                abort.current.signal,
            );
            if (!active.current) return;
            clearFile();
            setConfirmRevoke(false);
            setReason('');
            setOptions(installationOptions(value, deviceId, actorId));
        });
    }
    async function create() {
        if (
            !options ||
            !options.configured ||
            !['none', 'revoked'].includes(options.enrolment.state)
        )
            return;
        await perform('create', async () => {
            const result = await installationJSON(
                deviceId,
                'bootstrap',
                abort.current.signal,
                { expected_context: options.context_hash },
            );
            if (!active.current) return;
            const profile = installationProfile(result, options);
            setOptions({
                ...options,
                enrolment: {
                    state: 'pending',
                    uuid: profile.agent_uuid,
                    expires_at: profile.expires_at,
                },
            });
            if (document.hidden) return;
            clearFile();
            const url = URL.createObjectURL(
                new Blob([JSON.stringify(profile, null, 2) + '\n'], {
                    type: 'application/json',
                }),
            );
            fileUrl.current = url;
            setFile({ url, expires: profile.expires_at });
            expiryTimer.current = setTimeout(
                () => {
                    clearFile();
                    if (active.current)
                        setError(
                            'This profile has expired. Check installation status before creating a replacement.',
                        );
                },
                Math.max(0, Date.parse(profile.expires_at) - Date.now()),
            );
        });
    }
    async function revoke() {
        if (!options?.enrolment.uuid || reason.trim().length < 10) return;
        await perform('revoke', async () => {
            await installationJSON(deviceId, 'revoke', abort.current.signal, {
                reason: reason.trim(),
                expected_agent_uuid: options.enrolment.uuid,
            });
            if (!active.current) return;
            clearFile();
            setOptions(null);
            setConfirmRevoke(false);
            setReason('');
            const value = await installationJSON(
                deviceId,
                'bootstrap-options',
                abort.current.signal,
            );
            if (active.current)
                setOptions(installationOptions(value, deviceId, actorId));
        });
    }
    const existing =
        options &&
        ['pending', 'expired', 'enrolled'].includes(options.enrolment.state);
    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Monitor
                        className="size-5 text-primary"
                        aria-hidden="true"
                    />
                    Set up Obli on this computer
                </DialogTitle>
                <DialogDescription>
                    Review the destination, then create a Windows installation
                    profile.
                </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 overflow-x-hidden">
                {busy === 'load' && (
                    <p
                        role="status"
                        className="text-subtle flex items-center gap-2"
                    >
                        <Loader2
                            className="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Checking installation details…
                    </p>
                )}
                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                {options && (
                    <>
                        <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 rounded-lg border p-4 text-sm">
                            <dt className="text-muted-foreground">Computer</dt>
                            <dd className="font-medium break-words">
                                {options.device_label}
                            </dd>
                            <dt className="text-muted-foreground">
                                Identifier
                            </dt>
                            <dd className="break-all">{options.device_uid}</dd>
                            <dt className="text-muted-foreground">Site</dt>
                            <dd className="break-words">{options.site.name}</dd>
                            <dt className="text-muted-foreground">
                                Organisation
                            </dt>
                            <dd className="break-words">
                                {options.organisation ?? 'Not configured'}
                            </dd>
                            <dt className="text-muted-foreground">Server</dt>
                            <dd className="break-all">
                                {options.server_url ?? 'Not configured'}
                            </dd>
                        </dl>
                        {!options.configured && (
                            <Alert>
                                <AlertDescription>
                                    Agent installation is not configured for
                                    this server. An administrator must set the
                                    public HTTPS address, organisation name and
                                    signing key.
                                </AlertDescription>
                            </Alert>
                        )}
                        {file ? (
                            <Alert>
                                <AlertDescription>
                                    Profile ready. Save it and use it with the
                                    signed Windows installer before{' '}
                                    {formatDateTime(file.expires)}. Keep this
                                    file private; delete it after installation.
                                    <p className="mt-2">
                                        Closing or leaving this tab clears this
                                        copy. It does not revoke a profile you
                                        have saved.
                                    </p>
                                </AlertDescription>
                            </Alert>
                        ) : existing ? (
                            <Alert>
                                <AlertDescription>
                                    {options.enrolment.state === 'enrolled'
                                        ? 'This computer is already enrolled. Revoking its enrolment stops the existing agent from authenticating. Do this only for an approved replacement or recovery.'
                                        : options.enrolment.state === 'expired'
                                          ? 'The previous installation profile has expired. Revoke that enrolment before creating a replacement.'
                                          : 'An installation profile has already been issued. Its secret cannot be downloaded again from the server. Use the saved file, or revoke that enrolment if the file was lost.'}
                                </AlertDescription>
                            </Alert>
                        ) : (
                            <p className="text-subtle">
                                The profile expires after{' '}
                                {options.enrolment_minutes} minutes and connects
                                only this approved computer to the server shown
                                above. Creating it does not install software or
                                start a remote session. AI provider keys stay on
                                this server.
                            </p>
                        )}
                        <p className="text-subtle">
                            Get the signed software from{' '}
                            <a
                                className="frontline-focus underline"
                                href="/security-devices/agent-downloads"
                            >
                                Agent downloads
                            </a>
                            . This profile is for Windows computer support; site
                            collectors use their own approved enrolment.
                        </p>
                        {confirmRevoke && (
                            <div className="space-y-2 rounded-lg border p-4">
                                <Label htmlFor="revoke-profile-reason">
                                    Reason for revoking this enrolment
                                </Label>
                                <Textarea
                                    id="revoke-profile-reason"
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    maxLength={500}
                                    disabled={!!busy}
                                />
                                <p className="text-caption">
                                    At least 10 characters. Existing credentials
                                    and installation profiles will stop working.
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="destructive"
                                        disabled={
                                            !!busy || reason.trim().length < 10
                                        }
                                        onClick={() => void revoke()}
                                    >
                                        {busy === 'revoke' && (
                                            <Loader2
                                                className="size-4 animate-spin"
                                                aria-hidden="true"
                                            />
                                        )}
                                        Revoke this enrolment
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={!!busy}
                                        onClick={() => setConfirmRevoke(false)}
                                    >
                                        Keep enrolment
                                    </Button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
            <DialogFooter className="flex-wrap gap-2">
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
                <Button
                    variant="outline"
                    disabled={!!busy}
                    onClick={() => void refresh()}
                >
                    Check installation status
                </Button>
                {file && (
                    <Button asChild>
                        <a
                            href={file.url}
                            download={'oblivion-computer-' + deviceId + '.json'}
                        >
                            <Download aria-hidden="true" />
                            Save installation profile
                        </a>
                    </Button>
                )}
                {!file && existing && !confirmRevoke && (
                    <Button
                        variant="outline"
                        disabled={!!busy}
                        onClick={() => setConfirmRevoke(true)}
                    >
                        Revoke enrolment…
                    </Button>
                )}
                {!file && options?.configured && !existing && !error && (
                    <Button disabled={!!busy} onClick={() => void create()}>
                        {busy === 'create' && (
                            <Loader2
                                className="size-4 animate-spin"
                                aria-hidden="true"
                            />
                        )}
                        Create installation profile
                    </Button>
                )}
            </DialogFooter>
        </>
    );
}
