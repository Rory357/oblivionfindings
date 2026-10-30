import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import axios from 'axios';
import { Check, Copy, Monitor, RefreshCw, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export type RemoteSupportConfiguration = {
    version: number;
    endpoint_management_enabled: boolean;
    obli_helper_enabled: boolean;
    obli_technician_enabled: boolean;
    remote_support_enabled: boolean;
    relay_urls: string[];
    effective_endpoint_enabled: boolean;
    effective_obli_helper_enabled: boolean;
    effective_obli_technician_enabled: boolean;
    effective_remote_support_enabled: boolean;
    relay_ready: boolean;
    relay_secret_saved: boolean;
    relay_configured_via_env: boolean;
    effective_relay_urls: string[];
    command_signing_ready: boolean;
    server_url: string | null;
    organisation_label: string;
    script_execution_enabled: boolean;
};

type VerificationResult = {
    ok: boolean;
    status: string;
    message: string;
    relay_url_count?: number;
};

export function defaultRemoteSupport(
    helperAccessEnabled = false,
): RemoteSupportConfiguration {
    return {
        version: 0,
        endpoint_management_enabled: helperAccessEnabled,
        obli_helper_enabled: helperAccessEnabled,
        obli_technician_enabled: false,
        remote_support_enabled: false,
        relay_urls: [],
        effective_endpoint_enabled: helperAccessEnabled,
        effective_obli_helper_enabled: helperAccessEnabled,
        effective_obli_technician_enabled: false,
        effective_remote_support_enabled: false,
        relay_ready: false,
        relay_secret_saved: false,
        relay_configured_via_env: false,
        effective_relay_urls: [],
        command_signing_ready: false,
        server_url: null,
        organisation_label: 'Oblivion Findings',
        script_execution_enabled: false,
    };
}

function isValidTurnUrl(raw: string): boolean {
    if (raw.length === 0 || raw.length > 512) return false;
    const match =
        /^(turns?):(\[[a-f\d:]+\]|[a-z\d.-]+):(\d{1,5})(?:\?transport=(udp|tcp))?$/i.exec(
            raw,
        );
    if (!match) return false;
    const scheme = match[1].toLowerCase();
    const port = Number(match[3]);
    const transport = match[4]?.toLowerCase();
    if (port < 1 || port > 65535) return false;
    if (scheme === 'turns' && transport === 'udp') return false;
    try {
        const host = new URL('https://' + match[2]).hostname;
        if (!host || host.length > 253) return false;
        if (!host.startsWith('[')) {
            return host
                .split('.')
                .every((part) => /^[a-z\d](?:[a-z\d-]*[a-z\d])?$/i.test(part));
        }
        return true;
    } catch {
        return false;
    }
}

export function ObliRemoteSupportCard({
    actorId,
    initial,
    onHelperAccessChange,
}: {
    actorId: number;
    initial: RemoteSupportConfiguration;
    onHelperAccessChange?: (enabled: boolean) => void;
}) {
    const [saved, setSaved] = useState<RemoteSupportConfiguration>(initial);
    const [open, setOpen] = useState(false);
    const [endpointEnabled, setEndpointEnabled] = useState(
        initial.endpoint_management_enabled,
    );
    const [helperEnabled, setHelperEnabled] = useState(
        initial.obli_helper_enabled,
    );
    const [technicianEnabled, setTechnicianEnabled] = useState(
        initial.obli_technician_enabled,
    );
    const [remoteEnabled, setRemoteEnabled] = useState(
        initial.remote_support_enabled,
    );
    const [relayUrlsText, setRelayUrlsText] = useState(
        initial.relay_urls.join('\n'),
    );
    const [relaySecret, setRelaySecret] = useState('');
    const [generateSecret, setGenerateSecret] = useState(false);
    const [clearSecret, setClearSecret] = useState(false);
    const [busy, setBusy] = useState(false);
    const [verifying, setVerifying] = useState(false);
    const [notice, setNotice] = useState('');
    const [dialogError, setDialogError] = useState('');
    const [requiresReload, setRequiresReload] = useState(false);
    const [generatedSecret, setGeneratedSecret] = useState<string | null>(null);
    const [copiedSecret, setCopiedSecret] = useState(false);
    const [verification, setVerification] = useState<VerificationResult | null>(
        null,
    );
    const abort = useRef<AbortController | null>(null);

    useEffect(() => () => abort.current?.abort(), []);

    function openDialog() {
        if (requiresReload) return;
        setEndpointEnabled(saved.endpoint_management_enabled);
        setHelperEnabled(saved.obli_helper_enabled);
        setTechnicianEnabled(saved.obli_technician_enabled);
        setRemoteEnabled(saved.remote_support_enabled);
        setRelayUrlsText(saved.relay_urls.join('\n'));
        setRelaySecret('');
        setGenerateSecret(false);
        setClearSecret(false);
        setDialogError('');
        setOpen(true);
    }

    function parsedUrls(): string[] {
        return Array.from(
            new Set(
                relayUrlsText
                    .split(/\r?\n/)
                    .map((line) => line.trim())
                    .filter(Boolean),
            ),
        );
    }

    async function saveRemoteSettings() {
        if (busy || requiresReload) return;
        const urls = parsedUrls();
        if (urls.length > 4) {
            setDialogError('Enter at most four TURN relay URLs.');
            return;
        }
        const invalidUrl = urls.find((url) => !isValidTurnUrl(url));
        if (invalidUrl) {
            setDialogError(
                `Invalid relay URL "${invalidUrl}". Use turn:host:port or turns:host:port?transport=tcp.`,
            );
            return;
        }
        if (
            relaySecret !== '' &&
            !/^[\x21-\x7e]{32,512}$/.test(relaySecret.trim())
        ) {
            setDialogError(
                'Enter a 32–512 character printable ASCII shared secret, or turn on automatic generation.',
            );
            return;
        }
        if (
            (helperEnabled || technicianEnabled || remoteEnabled) &&
            !endpointEnabled
        ) {
            setDialogError(
                'Turn on Windows computer management before enabling the Obli helper or attended remote support.',
            );
            return;
        }
        const willHaveSecret =
            saved.relay_configured_via_env ||
            (!clearSecret &&
                (generateSecret ||
                    relaySecret.trim() !== '' ||
                    saved.relay_secret_saved));
        const willHaveUrls = saved.relay_configured_via_env || urls.length > 0;
        if (remoteEnabled && (!willHaveUrls || !willHaveSecret)) {
            setDialogError(
                'Attended remote support requires at least one TURN relay URL and a 32+ character shared secret.',
            );
            return;
        }

        setBusy(true);
        setDialogError('');
        const controller = new AbortController();
        abort.current = controller;
        const submittedSecret = relaySecret.trim() || null;
        setRelaySecret('');

        try {
            const response = await axios.put<{
                actor_id: number;
                remote_support: RemoteSupportConfiguration;
                generated_relay_secret: string | null;
            }>(
                '/settings/obli/remote-support',
                {
                    expected_actor_id: actorId,
                    expected_version: saved.version,
                    endpoint_management_enabled: endpointEnabled,
                    obli_helper_enabled: helperEnabled,
                    obli_technician_enabled: technicianEnabled,
                    remote_support_enabled: remoteEnabled,
                    relay_urls: urls,
                    relay_secret: submittedSecret,
                    generate_relay_secret: generateSecret,
                    clear_relay_secret: clearSecret,
                },
                {
                    signal: controller.signal,
                    timeout: 20000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (
                response.data.actor_id !== actorId ||
                response.data.remote_support.version !== saved.version + 1
            ) {
                throw new Error('Reload required');
            }
            if (controller.signal.aborted) return;
            setSaved(response.data.remote_support);
            setGeneratedSecret(response.data.generated_relay_secret ?? null);
            setCopiedSecret(false);
            setVerification(null);
            setNotice('Computer management and remote support settings saved.');
            setOpen(false);
            onHelperAccessChange?.(
                response.data.remote_support.effective_obli_helper_enabled,
            );
        } catch (error) {
            if (!controller.signal.aborted) {
                if (
                    axios.isAxiosError(error) &&
                    error.response?.status === 422
                ) {
                    setDialogError(
                        'Review the TURN relay URLs and shared secret details before saving.',
                    );
                } else {
                    setRequiresReload(true);
                    setOpen(false);
                    setNotice(
                        'The remote support save could not be confirmed. Reload saved settings before trying again.',
                    );
                }
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }

    async function reloadRemoteSettings() {
        if (busy) return;
        setBusy(true);
        const controller = new AbortController();
        abort.current = controller;
        try {
            const response = await axios.get<{
                actor_id: number;
                remote_support?: RemoteSupportConfiguration;
            }>('/settings/obli', {
                signal: controller.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            });
            if (response.data.actor_id !== actorId) {
                window.location.reload();
                return;
            }
            if (!controller.signal.aborted && response.data.remote_support) {
                setSaved(response.data.remote_support);
                setRequiresReload(false);
                setNotice('Saved remote support settings reloaded.');
                onHelperAccessChange?.(
                    response.data.remote_support.effective_obli_helper_enabled,
                );
            }
        } catch {
            if (!controller.signal.aborted) {
                setNotice(
                    'Remote support settings could not be reloaded. Check your sign-in and permissions.',
                );
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }

    async function testRelaySigning() {
        if (verifying || busy) return;
        setVerifying(true);
        const controller = new AbortController();
        abort.current = controller;
        try {
            const response = await axios.post<{
                actor_id: number;
                verification: VerificationResult;
            }>(
                '/settings/obli/remote-support/verify-relay',
                {},
                {
                    signal: controller.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (!controller.signal.aborted) {
                setVerification(response.data.verification);
            }
        } catch {
            if (!controller.signal.aborted) {
                setVerification({
                    ok: false,
                    status: 'error',
                    message:
                        'Relay verification could not be completed. Check your sign-in and saved settings.',
                });
            }
        } finally {
            if (!controller.signal.aborted) setVerifying(false);
        }
    }

    async function copyGeneratedSecret() {
        if (!generatedSecret) return;
        try {
            await navigator.clipboard.writeText(generatedSecret);
            setCopiedSecret(true);
        } catch {
            setCopiedSecret(false);
        }
    }

    const relayHost = (() => {
        const first = saved.effective_relay_urls[0];
        if (!first) return 'turn.example.com';
        const match = /^turns?:(\[[a-f\d:]+\]|[a-z\d.-]+):/i.exec(first);
        return match ? match[1] : 'turn.example.com';
    })();

    return (
        <>
            <Card data-testid="remote-support-settings-card">
                <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <CardTitle className="flex items-center gap-2">
                                <Monitor
                                    className="h-4 w-4 text-primary"
                                    aria-hidden="true"
                                />
                                Computer management &amp; remote support
                            </CardTitle>
                            <StatusBadge
                                variant={
                                    saved.effective_remote_support_enabled &&
                                    saved.relay_ready
                                        ? 'success'
                                        : saved.effective_endpoint_enabled
                                          ? 'info'
                                          : 'neutral'
                                }
                            >
                                {saved.effective_remote_support_enabled &&
                                saved.relay_ready
                                    ? 'Attended remote support on'
                                    : saved.effective_endpoint_enabled
                                      ? 'Computer management on'
                                      : 'Switched off'}
                            </StatusBadge>
                        </div>
                        <p className="text-subtle">
                            Windows computer enrolment, Obli desktop tray
                            helper, and TURN-relayed attended remote support.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={verifying || busy || !saved.relay_ready}
                            onClick={() => void testRelaySigning()}
                        >
                            <RefreshCw
                                className="h-3.5 w-3.5"
                                aria-hidden="true"
                            />
                            {verifying ? 'Testing…' : 'Test relay'}
                        </Button>
                        <Button
                            size="sm"
                            disabled={busy || requiresReload}
                            onClick={openDialog}
                        >
                            Configure remote support
                        </Button>
                    </div>
                </CardHeader>
                <CardContent className="space-y-4">
                    {notice && (
                        <Alert>
                            <AlertDescription role="status">
                                {notice}
                            </AlertDescription>
                        </Alert>
                    )}
                    {requiresReload && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() => void reloadRemoteSettings()}
                        >
                            Reload remote support settings
                        </Button>
                    )}
                    {generatedSecret && (
                        <div className="space-y-3 rounded-lg border bg-muted/40 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-sm font-medium">
                                    New TURN shared secret generated — copy this
                                    now
                                </p>
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            void copyGeneratedSecret()
                                        }
                                    >
                                        {copiedSecret ? (
                                            <Check
                                                className="h-3.5 w-3.5"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <Copy
                                                className="h-3.5 w-3.5"
                                                aria-hidden="true"
                                            />
                                        )}
                                        {copiedSecret
                                            ? 'Copied'
                                            : 'Copy shared secret'}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setGeneratedSecret(null)}
                                    >
                                        Done
                                    </Button>
                                </div>
                            </div>
                            <Input
                                readOnly
                                value={generatedSecret}
                                aria-label="Generated TURN shared secret"
                                className="font-mono text-xs"
                            />
                            <p className="text-caption">
                                Paste into your Coturn{' '}
                                <code>/etc/turnserver.conf</code> on{' '}
                                <code>{relayHost}</code> and restart{' '}
                                <code>coturn</code>. Oblivion stores this secret
                                encrypted and will not show it again.
                            </p>
                            <pre className="overflow-x-auto rounded-md border bg-background p-3 font-mono text-xs">
                                {[
                                    'listening-port=3478',
                                    'tls-listening-port=5349',
                                    'use-auth-secret',
                                    `static-auth-secret=${generatedSecret}`,
                                    `realm=${relayHost}`,
                                    'no-multicast-peers',
                                ].join('\n')}
                            </pre>
                        </div>
                    )}
                    {verification && (
                        <Alert>
                            <AlertDescription
                                role="status"
                                data-testid="relay-test-result"
                                className="flex flex-wrap items-center gap-2"
                            >
                                <StatusBadge
                                    variant={
                                        verification.ok ? 'success' : 'warning'
                                    }
                                >
                                    {verification.ok
                                        ? 'Relay answered'
                                        : 'Needs attention'}
                                </StatusBadge>
                                <span>{verification.message}</span>
                            </AlertDescription>
                        </Alert>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-lg border p-3.5">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-sm font-medium">
                                    Windows computer management
                                </span>
                                <StatusBadge
                                    variant={
                                        saved.effective_endpoint_enabled
                                            ? 'success'
                                            : 'neutral'
                                    }
                                >
                                    {saved.effective_endpoint_enabled
                                        ? 'On'
                                        : 'Off'}
                                </StatusBadge>
                            </div>
                            <p className="text-caption mt-1">
                                Enrols Windows workstations and servers, tracks
                                health, and signs computer commands.
                            </p>
                        </div>
                        <div className="rounded-lg border p-3.5">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-sm font-medium">
                                    Obli Windows tray helper
                                </span>
                                <StatusBadge
                                    variant={
                                        saved.effective_obli_helper_enabled
                                            ? 'success'
                                            : 'neutral'
                                    }
                                >
                                    {saved.effective_obli_helper_enabled
                                        ? 'On'
                                        : 'Off'}
                                </StatusBadge>
                            </div>
                            <p className="text-caption mt-1">
                                Lets staff sign in to{' '}
                                <code>obli.exe --tray</code>, read approved IT
                                guides, and log support requests.
                            </p>
                        </div>
                        <div className="rounded-lg border p-3.5">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-sm font-medium">
                                    Technician diagnostic checks
                                </span>
                                <StatusBadge
                                    variant={
                                        saved.effective_obli_technician_enabled
                                            ? 'success'
                                            : 'neutral'
                                    }
                                >
                                    {saved.effective_obli_technician_enabled
                                        ? 'On'
                                        : 'Off'}
                                </StatusBadge>
                            </div>
                            <p className="text-caption mt-1">
                                Allows authorised IT staff to preview approved
                                system, software, and service checks.
                            </p>
                        </div>
                        <div className="rounded-lg border p-3.5">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-sm font-medium">
                                    Attended remote support &amp; TURN relay
                                </span>
                                {/* Saved settings alone never show green: only a relay test that got an answer does (Phase A, A2). */}
                                <StatusBadge
                                    data-testid="relay-tile-status"
                                    variant={
                                        !saved.effective_remote_support_enabled
                                            ? 'neutral'
                                            : !saved.relay_ready
                                              ? 'warning'
                                              : verification?.ok
                                                ? 'success'
                                                : verification
                                                  ? 'warning'
                                                  : 'neutral'
                                    }
                                >
                                    {!saved.effective_remote_support_enabled
                                        ? 'Off'
                                        : !saved.relay_ready
                                          ? 'Relay needed'
                                          : verification?.ok
                                            ? 'Relay answered'
                                            : verification
                                              ? 'No answer'
                                              : `Saved (${saved.effective_relay_urls.length}), not tested`}
                                </StatusBadge>
                            </div>
                            <p className="text-caption mt-1">
                                {saved.effective_relay_urls.length > 0
                                    ? saved.effective_relay_urls.join(' · ')
                                    : 'No TURN relay URLs configured yet.'}{' '}
                                {saved.relay_secret_saved
                                    ? 'Shared secret saved.'
                                    : 'No shared secret saved.'}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3.5">
                        <ShieldCheck
                            className="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <div className="space-y-1">
                            <p className="text-sm font-medium">
                                Staff on the computer always stay in control
                            </p>
                            <p className="text-caption">
                                Every remote connection opens an{' '}
                                <strong>Allow</strong> or{' '}
                                <strong>Decline</strong> prompt in the Windows
                                Obli tray app and keeps a <strong>Stop</strong>{' '}
                                button visible so staff can end sharing
                                immediately. Recording is off, Obli’s private
                                chat and tickets are hidden during sharing, and
                                remote script execution remains switched off.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (!busy) setOpen(next);
                }}
            >
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            Computer management &amp; remote support
                        </DialogTitle>
                        <DialogDescription>
                            Configure Windows computer enrolment, the Obli
                            desktop helper, and the TURN relay used for attended
                            screen sharing.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-1">
                        {dialogError && (
                            <Alert>
                                <AlertDescription role="alert">
                                    {dialogError}
                                </AlertDescription>
                            </Alert>
                        )}
                        <div className="flex items-center justify-between gap-4">
                            <div className="space-y-0.5">
                                <Label htmlFor="remote-endpoint-enabled">
                                    Enable Windows computer management
                                </Label>
                                <p className="text-caption">
                                    Required for Windows agent enrolment,
                                    heartbeats, and signed computer commands.
                                </p>
                            </div>
                            <Switch
                                id="remote-endpoint-enabled"
                                checked={endpointEnabled}
                                disabled={busy}
                                onCheckedChange={(checked) => {
                                    setEndpointEnabled(checked);
                                    if (!checked) {
                                        setHelperEnabled(false);
                                        setTechnicianEnabled(false);
                                        setRemoteEnabled(false);
                                    }
                                }}
                            />
                        </div>
                        <div className="flex items-center justify-between gap-4">
                            <div className="space-y-0.5">
                                <Label htmlFor="remote-obli-helper-enabled">
                                    Enable Obli Windows helper for staff
                                </Label>
                                <p className="text-caption">
                                    Allows staff to sign in from the Windows
                                    tray helper and log IT support requests.
                                </p>
                            </div>
                            <Switch
                                id="remote-obli-helper-enabled"
                                checked={helperEnabled}
                                disabled={busy || !endpointEnabled}
                                onCheckedChange={setHelperEnabled}
                            />
                        </div>
                        <div className="flex items-center justify-between gap-4">
                            <div className="space-y-0.5">
                                <Label htmlFor="remote-obli-technician-enabled">
                                    Allow technician diagnostic checks
                                </Label>
                                <p className="text-caption">
                                    Lets authorised technicians preview approved
                                    computer checks in IT &amp; Support.
                                </p>
                            </div>
                            <Switch
                                id="remote-obli-technician-enabled"
                                checked={technicianEnabled}
                                disabled={busy || !endpointEnabled}
                                onCheckedChange={setTechnicianEnabled}
                            />
                        </div>
                        <div className="flex items-center justify-between gap-4">
                            <div className="space-y-0.5">
                                <Label htmlFor="remote-support-enabled">
                                    Enable attended remote support
                                </Label>
                                <p className="text-caption">
                                    Requires TURN relay URLs, a shared secret,
                                    and on-screen Allow consent from the person
                                    using the PC.
                                </p>
                            </div>
                            <Switch
                                id="remote-support-enabled"
                                checked={remoteEnabled}
                                disabled={busy || !endpointEnabled}
                                onCheckedChange={setRemoteEnabled}
                            />
                        </div>
                        <div className="space-y-2 border-t pt-4">
                            <Label htmlFor="remote-relay-urls">
                                TURN relay URLs (one per line, up to four)
                            </Label>
                            <Textarea
                                id="remote-relay-urls"
                                rows={3}
                                value={relayUrlsText}
                                disabled={busy}
                                onChange={(e) =>
                                    setRelayUrlsText(e.target.value)
                                }
                                placeholder={
                                    'turns:turn.oblivionfindings.com:5349?transport=tcp\nturn:turn.oblivionfindings.com:3478?transport=udp'
                                }
                            />
                            <p className="text-caption">
                                WebRTC screen traffic uses relay-only ICE (
                                <code>turn:</code> or <code>turns:</code>) so
                                workstations and technicians never expose local
                                LAN addresses directly.
                            </p>
                        </div>
                        <div className="space-y-3">
                            <div className="flex items-center justify-between gap-4">
                                <div className="space-y-0.5">
                                    <Label htmlFor="remote-generate-secret">
                                        Generate a new 64-character shared
                                        secret
                                    </Label>
                                    <p className="text-caption">
                                        Creates a fresh random Coturn{' '}
                                        <code>static-auth-secret</code> and
                                        displays it once after saving.
                                    </p>
                                </div>
                                <Switch
                                    id="remote-generate-secret"
                                    checked={generateSecret}
                                    disabled={busy}
                                    onCheckedChange={(checked) => {
                                        setGenerateSecret(checked);
                                        if (checked) {
                                            setRelaySecret('');
                                            setClearSecret(false);
                                        }
                                    }}
                                />
                            </div>
                            {!generateSecret && (
                                <div className="space-y-2">
                                    <Label htmlFor="remote-relay-secret">
                                        {saved.relay_secret_saved
                                            ? 'Replacement TURN shared secret (optional)'
                                            : 'TURN shared secret (32–512 characters)'}
                                    </Label>
                                    <Input
                                        id="remote-relay-secret"
                                        type="password"
                                        autoComplete="new-password"
                                        maxLength={512}
                                        disabled={busy}
                                        value={relaySecret}
                                        onChange={(e) => {
                                            setRelaySecret(e.target.value);
                                            if (e.target.value !== '') {
                                                setClearSecret(false);
                                            }
                                        }}
                                    />
                                    <p className="text-caption">
                                        Must match{' '}
                                        <code>static-auth-secret</code> in your
                                        Coturn server configuration.
                                    </p>
                                </div>
                            )}
                            {saved.relay_secret_saved && (
                                <div className="flex items-center justify-between gap-4">
                                    <Label htmlFor="remote-clear-secret">
                                        Remove saved shared secret
                                    </Label>
                                    <Switch
                                        id="remote-clear-secret"
                                        checked={clearSecret}
                                        disabled={busy}
                                        onCheckedChange={(checked) => {
                                            setClearSecret(checked);
                                            if (checked) {
                                                setGenerateSecret(false);
                                                setRelaySecret('');
                                                setRemoteEnabled(false);
                                            }
                                        }}
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={busy}
                            onClick={() => void saveRemoteSettings()}
                        >
                            {busy ? 'Saving…' : 'Save remote support'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
