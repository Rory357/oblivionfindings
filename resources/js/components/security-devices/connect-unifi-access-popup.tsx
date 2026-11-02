/**
 * Pop-up sdr-91: Connect UniFi Access (S&D Phase H). A 5-step wizard per Site: the hub on that
 * Site's network, the console's address, the API token (pasted by the person, kept in the secret
 * store, never shown again), the console's certificate (read by the hub, trusted here), then the
 * doors and people found and the installer's tick that every door opens from the inside.
 *
 * UniFi Access only answers on the Site's own network, so that Site's hub does the talking; the
 * certificate and the doors arrive on the hub's next rounds, so those steps wait and check again.
 */
import type { AccessConsoleState, AccessPlace } from '@/pages/security-devices/setup/_types';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { ChoiceTiles } from './device-popups';
import axios from 'axios';
import { DoorOpen, House, KeyRound, Loader2, Network, PlugZap, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

const STEPS = [
    { key: 'site', label: 'Site and hub', blurb: 'Where the doors are', icon: House },
    { key: 'console', label: 'Console', blurb: 'Its address on the house network', icon: Network },
    { key: 'token', label: 'API token', blurb: 'Made in UniFi Access', icon: KeyRound },
    { key: 'certificate', label: 'Certificate', blurb: 'Check it’s the right console', icon: ShieldCheck },
    { key: 'doors', label: 'Doors and people', blurb: 'What the hub found', icon: DoorOpen },
] as const;

function errorFrom(failure: unknown, fallback: string): string {
    if (axios.isAxiosError(failure)) {
        const data = failure.response?.data as { errors?: Record<string, string[]>; message?: string } | undefined;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        if (first) return first;
        if (failure.response?.status === 403) return 'You can’t do this step. Ask an IT admin.';
        if (!failure.response) return 'Result not confirmed. Check again in a moment.';
    }
    return fallback;
}

export function ConnectUnifiAccess({
    open,
    places,
    permissions,
    canSaveToken,
    canConfirmDoors,
    startSiteId,
    onClose,
}: {
    open: boolean;
    places: AccessPlace[];
    permissions: string[];
    canSaveToken: boolean;
    canConfirmDoors: boolean;
    startSiteId?: number | null;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [siteId, setSiteId] = useState<number | null>(null);
    const [address, setAddress] = useState('');
    const [port, setPort] = useState('12445');
    const [token, setToken] = useState('');
    const [doorsOk, setDoorsOk] = useState(false);
    const [console_, setConsole] = useState<AccessConsoleState | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [done, setDone] = useState(false);
    const place = places.find((item) => item.site_id === siteId) ?? null;

    useEffect(() => {
        if (!open) return;
        const start = places.find((item) => item.site_id === startSiteId) ?? null;
        setStep(0);
        setSiteId(start?.site_id ?? null);
        setAddress(start?.console?.address ?? '');
        setPort(String(start?.console?.port ?? 12445));
        setToken('');
        setDoorsOk(false);
        setConsole(start?.console ?? null);
        setError(null);
        setDone(false);
    }, [open, places, startSiteId]);

    const refresh = useCallback(async () => {
        if (!console_) return;
        try {
            const response = await axios.get(`/security-devices/setup/access/consoles/${console_.id}`);
            setConsole(response.data.console as AccessConsoleState);
        } catch {
            // Checked again shortly.
        }
    }, [console_]);

    // The certificate and the doors come from the hub's next rounds: check every 5 seconds.
    const waiting = (step === 3 && console_ !== null && !console_.trusted && !console_.certificate?.offered) || (step === 4 && console_ !== null && console_.doors === 0);
    useEffect(() => {
        if (!open || !waiting) return;
        const timer = window.setInterval(() => void refresh(), 5000);
        return () => window.clearInterval(timer);
    }, [open, waiting, refresh]);

    const run = async (action: () => Promise<void>, fallback: string) => {
        setBusy(true);
        setError(null);
        try {
            await action();
        } catch (failure) {
            setError(errorFrom(failure, fallback));
        } finally {
            setBusy(false);
        }
    };

    const saveConsole = () =>
        run(async () => {
            const response = await axios.post('/security-devices/setup/access/consoles', { site_id: siteId, address: address.trim(), port: Number(port) });
            setConsole(response.data.console as AccessConsoleState);
            setStep(2);
        }, 'The console wasn’t saved. Try again.');
    const saveToken = () =>
        run(async () => {
            await axios.put(`/security-devices/setup/access/consoles/${console_?.id}/token`, { token: token.trim() });
            setToken('');
            await refresh();
            setStep(3);
        }, 'The token wasn’t saved. Nothing was kept; try again.');
    const trust = () =>
        run(async () => {
            await axios.post(`/security-devices/setup/access/consoles/${console_?.id}/trust`, { fingerprint: console_?.certificate?.offered?.fingerprint });
            await refresh();
            setStep(4);
        }, 'The certificate wasn’t trusted. Try again.');
    const confirmDoors = () =>
        run(async () => {
            await axios.post(`/security-devices/setup/access/consoles/${console_?.id}/doors-confirmed`);
            setDone(true);
        }, 'That wasn’t saved. Try again.');

    const hubProblem =
        place === null
            ? null
            : place.hub === null
              ? 'This Site has no hub yet. UniFi Access only answers on the Site’s own network, so add a hub in Setup › Hubs & networks first.'
              : place.hub.needs_update
                ? `${place.hub.name} needs its software update before it can read UniFi Access.`
                : null;
    const tokenSaved = console_?.token !== null && console_?.token !== undefined;

    const primary = (() => {
        if (step === 0) return <Button disabled={place === null || hubProblem !== null} onClick={() => setStep(1)}>Continue</Button>;
        if (step === 1)
            return (
                <Button disabled={busy || address.trim() === ''} onClick={() => void saveConsole()}>
                    {busy ? <Loader2 className="mr-1.5 size-4 animate-spin" aria-hidden="true" /> : null}
                    Save the address
                </Button>
            );
        if (step === 2)
            return tokenSaved && token.trim() === '' ? (
                <Button onClick={() => setStep(3)}>Continue</Button>
            ) : (
                <Button disabled={busy || !canSaveToken || token.trim().length < 8} onClick={() => void saveToken()}>
                    {busy ? <Loader2 className="mr-1.5 size-4 animate-spin" aria-hidden="true" /> : null}
                    Save the token
                </Button>
            );
        if (step === 3)
            return console_?.trusted ? (
                <Button onClick={() => setStep(4)}>Continue</Button>
            ) : (
                <Button disabled={busy || !console_?.certificate?.offered || !console_.certificate.can_trust} onClick={() => void trust()}>
                    Trust this certificate
                </Button>
            );
        return (
            <Button disabled={busy || !canConfirmDoors || !doorsOk || (console_?.doors ?? 0) === 0} onClick={() => void confirmDoors()}>
                Finish
            </Button>
        );
    })();

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Connect UniFi Access"
            description="Doors, cards and visitors at one Site, through its hub"
            railIcon={PlugZap}
            railTitle="Connect UniFi Access"
            railSub={place?.site ?? 'Choose a Site'}
            railExtra={<p className="text-xs text-muted-foreground">UniFi Access only answers on the Site’s own network, so that Site’s hub does the talking.</p>}
            steps={STEPS.map((item, index) => ({ ...item, disabled: busy || index > step }))}
            stepIndex={step}
            onStepClick={(next) => {
                if (!busy && next <= step) setStep(next);
            }}
            footerStart={
                <>
                    <Button variant="outline" disabled={busy} onClick={onClose}>
                        Cancel
                    </Button>
                    {step > 0 ? (
                        <Button variant="outline" disabled={busy} onClick={() => setStep(step - 1)}>
                            Back
                        </Button>
                    ) : null}
                </>
            }
            footerEnd={done ? <Button onClick={onClose}>Done</Button> : primary}
            success={
                done ? (
                    <div className="flex flex-col items-center gap-3 p-10 text-center" data-testid="sd-connect-access-done">
                        <StatusBadge variant="success">Connected</StatusBadge>
                        <p className="text-base font-semibold text-foreground">UniFi Access at {place?.site} is connected</p>
                        <p className="max-w-md text-sm text-muted-foreground">
                            The hub keeps doors, people, cards and visitors in step every few minutes. Match people to staff on the Access page so their access ends by itself when they leave.
                        </p>
                    </div>
                ) : undefined
            }
        >
            <WizardStepPane>
                <div className="space-y-5" data-testid="sd-connect-access">
                    {error ? (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    ) : null}

                    {step === 0 ? (
                        <>
                            <p className="text-sm font-semibold text-foreground">Which Site are the doors at?</p>
                            <p className="text-sm text-muted-foreground">Its hub must be on the same network as the UniFi console.</p>
                            <ChoiceTiles<string>
                                label="Site"
                                columns={2}
                                value={siteId === null ? '' : String(siteId)}
                                options={places.map((item) => ({
                                    value: String(item.site_id),
                                    label: item.site,
                                    description: (item.hub ? `Through ${item.hub.name}` : 'No hub yet') + (item.console ? ' · connected before' : ''),
                                    icon: House,
                                }))}
                                onChange={(value) => {
                                    const item = places.find((candidate) => String(candidate.site_id) === value) ?? null;
                                    setSiteId(item?.site_id ?? null);
                                    setAddress(item?.console?.address ?? '');
                                    setPort(String(item?.console?.port ?? 12445));
                                    setConsole(item?.console ?? null);
                                }}
                            />
                            {hubProblem ? (
                                <Alert>
                                    <AlertDescription>{hubProblem}</AlertDescription>
                                </Alert>
                            ) : null}
                        </>
                    ) : null}

                    {step === 1 ? (
                        <>
                            <p className="text-sm font-semibold text-foreground">Where is the UniFi Access console?</p>
                            <p className="text-sm text-muted-foreground">Its address on {place?.site}’s network, like the Cloud Key or console that runs UniFi Access.</p>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-2 sm:col-span-2">
                                    <Label htmlFor="sd-access-address">Address</Label>
                                    <Input id="sd-access-address" value={address} onChange={(event) => setAddress(event.target.value)} placeholder="172.16.0.5" autoComplete="off" />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="sd-access-port">Port</Label>
                                    <Input id="sd-access-port" value={port} onChange={(event) => setPort(event.target.value.replace(/\D/g, ''))} inputMode="numeric" />
                                </div>
                            </div>
                            <p className="text-xs text-muted-foreground">UniFi Access answers on port 12445. Its certificate is made by the console itself, so you check it in step 4.</p>
                        </>
                    ) : null}

                    {step === 2 ? (
                        <>
                            <p className="text-sm font-semibold text-foreground">Make an API token in UniFi Access</p>
                            <p className="text-sm text-muted-foreground">In UniFi Access, open Settings › General › Advanced › API Token, make a token for Oblivion, and tick these permissions:</p>
                            <ul className="grid gap-1 text-sm text-foreground sm:grid-cols-2">
                                {permissions.map((permission) => (
                                    <li key={permission}>· {permission}</li>
                                ))}
                            </ul>
                            {tokenSaved ? <p className="text-sm text-muted-foreground">A token is already saved for this Site. Paste a new one only to replace it.</p> : null}
                            {canSaveToken ? (
                                <div className="space-y-2">
                                    <Label htmlFor="sd-access-token">Token</Label>
                                    <Input id="sd-access-token" type="password" value={token} onChange={(event) => setToken(event.target.value)} autoComplete="off" spellCheck={false} />
                                    <p className="text-xs text-muted-foreground">It goes straight into Oblivion’s secret store and is never shown again.</p>
                                </div>
                            ) : (
                                <Alert>
                                    <AlertDescription>Only an IT admin who manages saved logins can save the token. Ask them to finish this step.</AlertDescription>
                                </Alert>
                            )}
                            <p className="text-xs text-muted-foreground">Upgrading a console to UniFi Identity Enterprise switches its API off. Check with IT before upgrading.</p>
                        </>
                    ) : null}

                    {step === 3 ? (
                        console_?.trusted ? (
                            <Alert>
                                <AlertDescription>This console’s certificate is trusted.</AlertDescription>
                            </Alert>
                        ) : console_?.certificate?.offered ? (
                            <>
                                <p className="text-sm font-semibold text-foreground">{console_.certificate.offered.changed ? 'The console’s certificate has changed' : 'Is this your console?'}</p>
                                <p className="text-sm text-muted-foreground">The hub read this certificate from {console_.address}. Check the fingerprint matches the one in UniFi’s settings, then trust it.</p>
                                <dl className="grid gap-2 rounded-lg border border-border bg-card p-4 text-sm">
                                    <div>
                                        <dt className="text-xs text-muted-foreground">Fingerprint (SHA-256)</dt>
                                        <dd className="font-mono text-xs break-all text-foreground">{console_.certificate.offered.fingerprint}</dd>
                                    </div>
                                    {console_.certificate.offered.subject ? (
                                        <div>
                                            <dt className="text-xs text-muted-foreground">Made for</dt>
                                            <dd className="text-foreground">{console_.certificate.offered.subject}</dd>
                                        </div>
                                    ) : null}
                                </dl>
                                {!console_.certificate.can_trust ? <p className="text-sm text-muted-foreground">Only someone who manages device commands can trust it (an IT admin to replace one).</p> : null}
                            </>
                        ) : (
                            <div className="flex items-center gap-3 text-sm text-muted-foreground" role="status">
                                <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                                Waiting for {place?.hub?.name ?? 'the hub'} to read the console’s certificate. This takes a few minutes.
                                {console_?.last_error ? <span className="text-foreground">{console_.last_error}</span> : null}
                            </div>
                        )
                    ) : null}

                    {step === 4 ? (
                        (console_?.doors ?? 0) === 0 ? (
                            <div className="flex items-center gap-3 text-sm text-muted-foreground" role="status">
                                <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                                Waiting for {place?.hub?.name ?? 'the hub'} to read the doors and people. This takes a few minutes.
                                {console_?.state === 'failed' && console_.last_error ? <span className="text-foreground">{console_.last_error}</span> : null}
                            </div>
                        ) : (
                            <>
                                <p className="text-sm font-semibold text-foreground">
                                    Found {console_?.doors} {console_?.doors === 1 ? 'door' : 'doors'} and {console_?.staff} {console_?.staff === 1 ? 'person' : 'people'}
                                </p>
                                {(console_?.to_match ?? 0) > 0 ? (
                                    <p className="text-sm text-muted-foreground">{console_?.to_match} aren’t matched to staff yet. Match them on the Access page so their access ends by itself when they leave.</p>
                                ) : null}
                                {/* eslint-disable-next-line no-restricted-syntax -- a tick-box row (checkbox + label), not a content card. */}
                                <div className="flex items-start gap-3 rounded-lg border border-border bg-card p-4">
                                    <Checkbox id="sd-access-exit-buttons" checked={doorsOk} disabled={!canConfirmDoors} onCheckedChange={(value) => setDoorsOk(value === true)} />
                                    <Label htmlFor="sd-access-exit-buttons" className="text-sm leading-snug font-normal">
                                        Every one of these doors opens from the inside by its exit button and handle. The installer has checked each door.
                                    </Label>
                                </div>
                                <p className="text-xs text-muted-foreground">Lockdown is offered only after this is ticked, so it never shuts anyone in.</p>
                            </>
                        )
                    ) : null}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}
