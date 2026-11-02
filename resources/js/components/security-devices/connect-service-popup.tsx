/**
 * Pop-up 15, Connect a service (S&D rebuild drawing sdr-15): one wizard for every service that has
 * a backend. Choose it; sign in once (stored encrypted through the service's own save, then tested
 * on the spot); then match its sites to yours and bring its devices in. Each step runs the service's
 * own settings action through ConnectServiceController.
 *
 * Milesight gateways and MQTT sensors go on to pop-up 98 (Add a sensor gateway), which makes their
 * login on the Site's hub. Queclink trackers report to Oblivion by themselves: no sign-in, no test.
 * Inner Range, Gallagher, Ajax and other alarm panels aren't offered: nothing saves them as a service
 * yet. A saved key or secret never comes back to the browser, only whether one is saved.
 */
import { VehicleSearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import { DiscardDraftConfirm, PopupCallout, PopupFacts, PopupField, PopupLens, PopupSection, usePopupClose } from '@/components/popup';
import { ChoiceTiles, useDeviceFormOptions, type ChoiceTile } from '@/components/security-devices/device-popups';
import { AddSensorGateway } from '@/components/security-devices/sensor-gateway-popup';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { SkeletonCard } from '@/components/ui/skeleton-card';
import { StatusBadge } from '@/components/ui/status-badge';
import { WizardShell, WizardStepPane, WizardSuccessPane, type WizardStep } from '@/components/wizard/shell';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowRightLeft,
    Boxes,
    House,
    KeyRound,
    LayoutGrid,
    LoaderCircle,
    MapPin,
    Monitor,
    MonitorSmartphone,
    PlugZap,
    Radio,
    Thermometer,
    Wifi,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  What the server sends (ConnectServicePresenter)                    */
/* ------------------------------------------------------------------ */

export type ServiceKey = 'unifi' | 'milesight' | 'queclink' | 'intune' | 'mqtt';

type LastSync = { status: string; created: number; updated: number; errored: number; id: number };

export type RemoteSite = {
    token: string;
    name: string;
    devices: number | null;
    match: { elsewhere: boolean; site_id: number | null; site_name: string | null; config_id: number | null; last_sync: LastSync | null } | null;
};

export type ConnectState = {
    services: ServiceKey[];
    sites: { id: number; name: string }[];
    can: { gateway: boolean; import_computers: boolean };
    unifi: {
        has_key: boolean;
        status: string;
        tested_at: string | null;
        consoles: number | null;
        sites_found_at: string | null;
        remote_sites: RemoteSite[];
        taken_site_ids: number[];
    };
    intune: {
        has_key: boolean;
        status: string;
        tested_at: string | null;
        ids_saved: boolean;
        found_at: string | null;
        computers: { id: string; name: string; sub: string; linked: boolean; site: string | null }[];
    };
    queclink: { port: number; address_set: boolean };
};

type Outcome = 'done' | 'warning' | 'failed';

const CONNECT_URL = '/security-devices/setup/services/connect';
const JSON_HEADERS = { headers: { Accept: 'application/json' } };
const SKIP = 'skip';

const STEPS: readonly WizardStep[] = [
    { key: 'choose', label: 'Choose', blurb: 'Which service', icon: LayoutGrid },
    { key: 'sign-in', label: 'Sign in', blurb: 'Key and test', icon: KeyRound },
    { key: 'match', label: 'Match', blurb: 'Sites and what to bring in', icon: ArrowRightLeft },
];

const TILES: Record<ServiceKey, ChoiceTile<ServiceKey>> = {
    unifi: { value: 'unifi', label: 'UniFi', description: 'Network gear and Protect cameras', icon: Wifi },
    milesight: { value: 'milesight', label: 'Milesight', description: 'Sensor gateways send to Oblivion’s private broker', icon: Thermometer },
    queclink: { value: 'queclink', label: 'Queclink trackers', description: 'Care trackers report to Oblivion', icon: MapPin },
    intune: { value: 'intune', label: 'Microsoft Intune', description: 'Staff Windows computers, read only', icon: MonitorSmartphone },
    mqtt: { value: 'mqtt', label: 'MQTT sensors', description: 'Plugs and sensors send to Oblivion’s private broker', icon: Radio },
};

const NAMES: Record<ServiceKey, string> = { unifi: 'UniFi', milesight: 'Milesight', queclink: 'Queclink trackers', intune: 'Microsoft Intune', mqtt: 'MQTT sensors' };

/* ------------------------------------------------------------------ */
/*  Small parts                                                        */
/* ------------------------------------------------------------------ */

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

function listWords(names: string[]): string {
    if (names.length <= 1) return names[0] ?? '';
    return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

/** The step's question with its round lens (the drawn step head). */
function StepQuestion({ icon, title, line }: { icon: LucideIcon; title: string; line: string }) {
    return (
        <div className="mb-5 flex items-start gap-3.5">
            <PopupLens icon={icon} />
            <div className="min-w-0 pt-0.5">
                <h2 className="text-section-title leading-snug">{title}</h2>
                <p className="mt-0.5 text-sm text-muted-foreground">{line}</p>
            </div>
        </div>
    );
}

/** A failed call keeps every entry and says what went wrong in plain words. */
function errorsOf(error: unknown): Record<string, string> {
    if (!axios.isAxiosError(error)) return { form: 'It didn’t work. Try again.' };
    const data = error.response?.data as { errors?: Record<string, string[]> } | undefined;
    if (error.response?.status === 422 && data?.errors) {
        return Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]]));
    }
    if (error.response?.status === 403) return { form: 'You can’t connect services. Ask someone who manages Security & devices.' };
    if (error.response?.status === 429) return { form: 'Too many tries in a minute. Wait a moment, then try again.' };
    if (!error.response) return { form: 'Result not confirmed. Check Setup › Connected services before trying again.' };
    return { form: 'It didn’t work. Try again.' };
}

/** Errors shown beside their own field; anything else goes in the callout at the top. */
const INLINE = ['service', 'api_key', 'directory_id', 'client_id', 'client_secret', 'site_id', 'managed_device_ids'];

function topErrorOf(errors: Record<string, string>): string | undefined {
    return errors.form ?? Object.entries(errors).find(([key]) => !INLINE.includes(key))?.[1];
}

/** "✓ Works · 2 consoles found · tested 9:48 am", from the service's own test. */
function testWords(status: string, testedAt: string | null, extra?: string | null): { tone: 'success' | 'critical' | 'neutral'; text: string } {
    if (!testedAt) return { tone: 'neutral', text: 'Not tested yet' };
    if (status === 'connected') return { tone: 'success', text: ['✓ Works', extra, `tested ${seenTime(testedAt)}`].filter(Boolean).join(' · ') };
    return { tone: 'critical', text: `Didn’t work · tested ${seenTime(testedAt)}` };
}

/* ------------------------------------------------------------------ */
/*  15 · Connect a service                                             */
/* ------------------------------------------------------------------ */

type Done =
    | { service: 'unifi'; created: number; updated: number; errored: number; sites: { id: number; name: string }[] }
    | { service: 'intune'; brought: number; site: { id: number; name: string } | null; found: number; imported: boolean };

export function ConnectService({ open, onClose, onChanged }: { open: boolean; onClose: () => void; onChanged?: () => void }) {
    const [state, setState] = useState<ConnectState | null>(null);
    const [loadFailed, setLoadFailed] = useState(false);
    const [step, setStep] = useState(0);
    const [service, setService] = useState<ServiceKey | ''>('');
    const [apiKey, setApiKey] = useState('');
    const [directoryId, setDirectoryId] = useState('');
    const [clientId, setClientId] = useState('');
    const [clientSecret, setClientSecret] = useState('');
    const [testedNow, setTestedNow] = useState(false);
    const [note, setNote] = useState<string | null>(null);
    const [choices, setChoices] = useState<Record<string, string>>({});
    const [intuneSite, setIntuneSite] = useState('');
    const [picked, setPicked] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [changed, setChanged] = useState(false);
    const [done, setDone] = useState<Done | null>(null);
    const [gateway, setGateway] = useState(false);
    const { form, load } = useDeviceFormOptions();

    const read = () => {
        setLoadFailed(false);
        axios
            .get<ConnectState>(CONNECT_URL, JSON_HEADERS)
            .then(({ data }) => setState(data))
            .catch(() => setLoadFailed(true));
    };

    /** `another`: "Connect another service" keeps what was read. */
    const reset = (another = false) => {
        setStep(0);
        setService('');
        setApiKey('');
        setDirectoryId('');
        setClientId('');
        setClientSecret('');
        setTestedNow(false);
        setNote(null);
        setChoices({});
        setIntuneSite('');
        setPicked([]);
        setErrors({});
        setDone(null);
        setGateway(false);
        if (!another) {
            setState(null);
            setChanged(false);
            read();
        }
    };

    useEffect(() => {
        if (open) reset();
        // Only when it opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    /** One step through the service's own settings action. */
    const run = async (name: 'unifi' | 'intune', action: string, payload: Record<string, unknown> = {}): Promise<Outcome> => {
        const { data } = await axios.post<{ outcome: Outcome; state: ConnectState }>(`${CONNECT_URL}/${name}/${action}`, payload, JSON_HEADERS);
        setState(data.state);
        // A test or a read changes what Connected services shows too.
        setChanged(true);
        return data.outcome;
    };

    const options = (state?.services ?? []).map((key) => TILES[key]);
    const sites = useMemo(() => state?.sites ?? [], [state]);

    /* ---------- Step 2: sign in, then test on the spot ---------- */

    const typedIntune = directoryId.trim() !== '' || clientId.trim() !== '' || clientSecret !== '';
    const dirty = open && !done && (apiKey !== '' || typedIntune);

    const signIn = async () => {
        if (!state || (service !== 'unifi' && service !== 'intune')) return;
        const next: Record<string, string> = {};
        if (service === 'unifi' && apiKey.trim() === '' && !state.unifi.has_key) next.api_key = 'Paste the API key from unifi.ui.com.';
        if (service === 'intune' && (typedIntune || !state.intune.has_key || !state.intune.ids_saved)) {
            if (directoryId.trim() === '') next.directory_id = 'Paste the Microsoft directory ID.';
            if (clientId.trim() === '') next.client_id = 'Paste the application (client) ID.';
            if (clientSecret === '') next.client_secret = 'Paste the client secret.';
        }
        setErrors(next);
        setNote(null);
        if (Object.keys(next).length > 0) return;
        setProcessing(true);
        try {
            if (service === 'unifi') {
                if (apiKey.trim() !== '' && (await run('unifi', 'key', { api_key: apiKey.trim() })) === 'failed') {
                    setErrors({ form: 'The key wasn’t saved, so nothing changed. Try again in a minute.' });
                    return;
                }
                setApiKey('');
                const disabled = state.unifi.status === 'disabled' && apiKey.trim() === '';
                const tested = await run('unifi', 'test');
                setTestedNow(true);
                if (tested === 'failed') {
                    setErrors({
                        form: disabled
                            ? 'This connection was switched off. Paste a new key to turn it back on.'
                            : 'UniFi didn’t accept the key, or couldn’t be reached. Check the key and try again.',
                    });
                    return;
                }
                const found = await run('unifi', 'sites');
                if (found === 'warning') setNote('It works, but UniFi didn’t list any sites for this key.');
                if (found === 'failed') setNote('It works, but UniFi’s sites couldn’t be read. Try again in a minute.');
            } else {
                if (typedIntune) {
                    const saved = await run('intune', 'key', { directory_id: directoryId.trim(), client_id: clientId.trim(), client_secret: clientSecret });
                    if (saved === 'failed') {
                        setErrors({ form: 'The secret wasn’t saved, so nothing changed. Try again in a minute.' });
                        return;
                    }
                    setDirectoryId('');
                    setClientId('');
                    setClientSecret('');
                }
                const tested = await run('intune', 'test');
                setTestedNow(true);
                if (tested === 'failed') {
                    setErrors({ form: 'Microsoft didn’t accept these details. Check the two IDs, the client secret and the app’s admin consent.' });
                }
            }
        } catch (failure) {
            setErrors(errorsOf(failure));
        } finally {
            setProcessing(false);
        }
    };

    const worked = !!state && testedNow && (service === 'unifi' ? state.unifi.status === 'connected' : service === 'intune' ? state.intune.status === 'connected' : false);

    /* ---------- Step 3: match and bring in ---------- */

    const remote = state?.unifi.remote_sites ?? [];
    const mine = remote.filter((site) => site.match && !site.match.elsewhere && site.match.config_id !== null);
    const chosen = remote.filter((site) => !site.match && (choices[site.token] ?? SKIP) !== SKIP);
    const takenIds = new Set([...(state?.unifi.taken_site_ids ?? []), ...chosen.map((site) => Number(choices[site.token]))]);
    const siteOptions = (token: string) => [
        ...sites.filter((site) => !takenIds.has(site.id) || String(site.id) === choices[token]).map((site) => ({ value: String(site.id), label: site.name })),
        { value: SKIP, label: 'Don’t bring in' },
    ];

    const findComputers = async () => {
        setProcessing(true);
        setErrors({});
        try {
            const outcome = await run('intune', 'find');
            if (outcome === 'failed') setErrors({ form: 'Intune’s computers couldn’t be read. Try again in a minute.' });
        } catch (failure) {
            setErrors(errorsOf(failure));
        } finally {
            setProcessing(false);
        }
    };

    // Step 3 for Intune: find its computers once, and tick the ones not in Oblivion yet.
    useEffect(() => {
        if (!open || step !== 2 || service !== 'intune' || !state) return;
        if (!state.intune.found_at && !processing) void findComputers();
        // Only when the step opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, step, service]);
    useEffect(() => {
        if (service === 'intune' && state) setPicked(state.intune.computers.filter((computer) => !computer.linked).map((computer) => computer.id));
        // When its list arrives.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [state?.intune.found_at, service]);

    const connectUnifi = async () => {
        if (!state) return;
        if (chosen.length === 0 && mine.length === 0) {
            setErrors({ form: 'Choose a Site for at least one UniFi site.' });
            return;
        }
        setProcessing(true);
        setErrors({});
        try {
            for (const site of chosen) {
                await run('unifi', 'match', { site_id: Number(choices[site.token]), mapping_token: site.token });
            }
            // Read the matches back, then bring each matched Site's devices in.
            const { data } = await axios.get<ConnectState>(CONNECT_URL, JSON_HEADERS);
            setState(data);
            const matched = data.unifi.remote_sites.filter((site) => site.match && !site.match.elsewhere && site.match.config_id !== null);
            const before = new Map(matched.map((site) => [site.token, site.match?.last_sync?.id ?? 0]));
            const failedAt: string[] = [];
            for (const site of matched) {
                const outcome = await run('unifi', 'bring-in', { site_config_id: site.match!.config_id });
                if (outcome === 'failed') failedAt.push(site.name);
            }
            const { data: after } = await axios.get<ConnectState>(CONNECT_URL, JSON_HEADERS);
            setState(after);
            const synced = after.unifi.remote_sites.filter((site) => site.match?.last_sync && site.match.last_sync.id !== before.get(site.token));
            if (failedAt.length === matched.length) {
                setErrors({ form: `Bringing devices in from ${listWords(failedAt)} didn’t finish. The matches are saved; try again in a minute.` });
                return;
            }
            setDone({
                service: 'unifi',
                created: synced.reduce((sum, site) => sum + (site.match?.last_sync?.created ?? 0), 0),
                updated: synced.reduce((sum, site) => sum + (site.match?.last_sync?.updated ?? 0), 0),
                errored: synced.reduce((sum, site) => sum + (site.match?.last_sync?.errored ?? 0), 0),
                sites: after.unifi.remote_sites
                    .filter((site) => site.match && !site.match.elsewhere && site.match.site_id !== null)
                    .map((site) => ({ id: site.match!.site_id!, name: site.match!.site_name ?? '' })),
            });
        } catch (failure) {
            const found = errorsOf(failure);
            setErrors(found.mapping_token || found.site_id ? { form: found.mapping_token ?? found.site_id } : found);
        } finally {
            setProcessing(false);
        }
    };

    const connectIntune = async () => {
        if (!state) return;
        const site = sites.find((option) => String(option.id) === intuneSite) ?? null;
        if (!state.can.import_computers) {
            setDone({ service: 'intune', brought: 0, site: null, found: state.intune.computers.length, imported: false });
            return;
        }
        const next: Record<string, string> = {};
        if (!site) next.site_id = 'Choose the Site these computers are at.';
        if (picked.length === 0) next.managed_device_ids = 'Tick at least one computer.';
        setErrors(next);
        if (Object.keys(next).length > 0 || !site) return;
        setProcessing(true);
        try {
            const outcome = await run('intune', 'bring-in', { site_id: site.id, managed_device_ids: picked });
            if (outcome === 'failed') {
                setErrors({ form: 'None of the ticked computers could be brought in. Check the connection, then try again.' });
                return;
            }
            const { data } = await axios.get<ConnectState>(CONNECT_URL, JSON_HEADERS);
            setState(data);
            const brought = data.intune.computers.filter((computer) => picked.includes(computer.id) && computer.linked).length;
            setDone({ service: 'intune', brought, site, found: data.intune.computers.length, imported: true });
        } catch (failure) {
            setErrors(errorsOf(failure));
        } finally {
            setProcessing(false);
        }
    };

    /* ---------- Moving between steps ---------- */

    const choose = () => {
        if (!service) {
            setErrors({ service: 'Choose the service to connect.' });
            return;
        }
        setErrors({});
        if (service === 'milesight' || service === 'mqtt') {
            // Pop-up 98 makes the gateway's own login on its Site's hub.
            load();
            setGateway(true);
            return;
        }
        setStep(1);
    };

    const next = () => {
        if (step === 0) choose();
        else if (step === 1) {
            if (service === 'queclink' || worked) {
                setErrors({});
                setStep(2);
            } else void signIn();
        } else if (service === 'unifi') void connectUnifi();
        else if (service === 'intune') void connectIntune();
        else if (service === 'queclink') router.visit('/security-devices/integrations/queclink');
    };

    const close = () => {
        if (changed) onChanged?.();
        onClose();
    };
    const guard = usePopupClose({ dirty, processing, onClose: close });

    const primaryLabel = (() => {
        if (step === 0) return 'Continue';
        if (step === 1) {
            if (service === 'queclink' || worked) return 'Continue';
            if (service === 'unifi') return apiKey.trim() === '' && state?.unifi.has_key ? 'Test the saved key' : 'Save and test';
            return !typedIntune && state?.intune.has_key && state.intune.ids_saved ? 'Test the saved sign-in' : 'Save and test';
        }
        if (service === 'queclink') return 'Open trackers';
        if (service === 'intune' && state && !state.can.import_computers) return 'Finish';
        return 'Connect';
    })();
    const busyLabel = step === 1 ? 'Testing…' : step === 2 && service !== 'queclink' ? 'Connecting…' : primaryLabel;
    const primaryDisabled = processing || !state || (step === 2 && service === 'intune' && !state.intune.found_at && state.can.import_computers);

    const topError = topErrorOf(errors);
    const servicesHref = '/security-devices/setup/services?tab=connected';
    const onServicesPage = typeof window !== 'undefined' && window.location.pathname === '/security-devices/setup/services';

    const success = done ? (
        <WizardSuccessPane
            title={`${NAMES[done.service]} is connected`}
            blurb={
                done.service === 'unifi'
                    ? `${plural(done.created, 'device', 'devices')} brought in at ${listWords(done.sites.map((site) => site.name)) || 'your Sites'}, and ${done.updated} matched to devices you already had.${done.errored > 0 ? ` ${plural(done.errored, 'device', 'devices')} couldn’t be read.` : ''}`
                    : done.imported
                      ? `${plural(done.brought, 'computer', 'computers')} brought in at ${done.site?.name ?? 'the Site'}.`
                      : `${plural(done.found, 'computer', 'computers')} found. Someone who can add devices and place them at Sites can bring them in.`
            }
            actions={
                <>
                    <Button type="button" variant="ghost" onClick={close}>
                        Close
                    </Button>
                    <Button type="button" variant="outline" onClick={() => reset(true)}>
                        Connect another service
                    </Button>
                    {!onServicesPage ? (
                        <Button type="button" variant="outline" onClick={() => router.visit(servicesHref)}>
                            Go to Connected services
                        </Button>
                    ) : null}
                    {done.service === 'unifi' || done.imported ? (
                        <Button
                            type="button"
                            onClick={() => {
                                const one = done.service === 'unifi' ? (done.sites.length === 1 ? done.sites[0] : null) : done.site;
                                const query = new URLSearchParams({ ...(done.service === 'intune' ? { tab: 'computers' } : {}), ...(one ? { site: String(one.id) } : {}) }).toString();
                                router.visit(`/security-devices/devices${query ? `?${query}` : ''}`);
                            }}
                        >
                            See the devices
                        </Button>
                    ) : null}
                </>
            }
        />
    ) : undefined;

    const unifiTest = state ? testWords(state.unifi.status, testedNow ? state.unifi.tested_at : null, state.unifi.consoles !== null ? `${plural(state.unifi.consoles, 'console', 'consoles')} found` : null) : null;
    const intuneTest = state ? testWords(state.intune.status, testedNow ? state.intune.tested_at : null) : null;

    let body: ReactNode = null;
    if (!state) {
        body = loadFailed ? (
            <PopupCallout tone="critical">
                <p>The services couldn’t be read.</p>
                <Button type="button" variant="outline" size="sm" className="mt-2" onClick={read}>
                    Try again
                </Button>
            </PopupCallout>
        ) : (
            <SkeletonCard rows={4} />
        );
    } else if (step === 0) {
        body = (
            <>
                <StepQuestion icon={LayoutGrid} title="What do you want to connect?" line="Pick the system your devices already use. Cameras that speak ONVIF don’t need one." />
                <div className="grid gap-5">
                    <PopupSection icon={PlugZap} title="Services">
                        <PopupField label="Service" required error={errors.service}>
                            <ChoiceTiles
                                label="Service"
                                columns={3}
                                options={options}
                                value={service}
                                onChange={(value) => {
                                    setService(value);
                                    setTestedNow(false);
                                    setNote(null);
                                    setErrors({});
                                }}
                            />
                        </PopupField>
                        <PopupCallout tone="info">Hikvision and Dahua cameras need no account. Add them by a scan or by their address.</PopupCallout>
                    </PopupSection>
                </div>
            </>
        );
    } else if (step === 1 && service === 'unifi') {
        body = (
            <>
                <StepQuestion icon={KeyRound} title="Sign in to UniFi" line="Paste an API key from unifi.ui.com. It’s stored encrypted in Oblivion." />
                <PopupSection icon={KeyRound} title="UniFi account" columns={2}>
                    <PopupField label="API key" htmlFor="sd-connect-unifi-key" required={!state.unifi.has_key} hint="from unifi.ui.com" error={errors.api_key} wide>
                        <Input
                            id="sd-connect-unifi-key"
                            type="password"
                            autoComplete="off"
                            spellCheck={false}
                            value={apiKey}
                            maxLength={4096}
                            placeholder={state.unifi.has_key ? 'A key is saved · paste a new one to replace it' : undefined}
                            onChange={(event) => {
                                setApiKey(event.target.value);
                                setTestedNow(false);
                            }}
                        />
                    </PopupField>
                    <PopupField label="Test connection" wide>
                        <p className="flex min-h-9 items-center" role="status">
                            {unifiTest ? <StatusBadge variant={unifiTest.tone}>{unifiTest.text}</StatusBadge> : null}
                        </p>
                    </PopupField>
                    {note ? (
                        <div className="sm:col-span-2">
                            <PopupCallout tone="warning">{note}</PopupCallout>
                        </div>
                    ) : null}
                    <div className="sm:col-span-2">
                        <PopupCallout tone="info">Your key is stored encrypted in Oblivion. Nobody can read it back; you can only replace it.</PopupCallout>
                    </div>
                </PopupSection>
            </>
        );
    } else if (step === 1 && service === 'intune') {
        const replacing = typedIntune || !state.intune.has_key || !state.intune.ids_saved;
        body = (
            <>
                <StepQuestion icon={KeyRound} title="Sign in to Microsoft Intune" line="Paste your app registration’s IDs and client secret from Microsoft Entra. The secret is stored encrypted in Oblivion." />
                <PopupSection icon={KeyRound} title="Microsoft app registration" columns={2}>
                    <PopupField label="Microsoft directory ID" htmlFor="sd-connect-intune-directory" required={replacing} error={errors.directory_id}>
                        <Input id="sd-connect-intune-directory" autoComplete="off" spellCheck={false} value={directoryId} onChange={(event) => {
                            setDirectoryId(event.target.value);
                            setTestedNow(false);
                        }} />
                    </PopupField>
                    <PopupField label="Application (client) ID" htmlFor="sd-connect-intune-client" required={replacing} error={errors.client_id}>
                        <Input id="sd-connect-intune-client" autoComplete="off" spellCheck={false} value={clientId} onChange={(event) => {
                            setClientId(event.target.value);
                            setTestedNow(false);
                        }} />
                    </PopupField>
                    <PopupField label="Client secret" htmlFor="sd-connect-intune-secret" required={replacing} error={errors.client_secret} wide>
                        <Input
                            id="sd-connect-intune-secret"
                            type="password"
                            autoComplete="off"
                            spellCheck={false}
                            value={clientSecret}
                            maxLength={4096}
                            placeholder={state.intune.has_key ? 'A secret is saved · paste all three to replace them' : undefined}
                            onChange={(event) => {
                                setClientSecret(event.target.value);
                                setTestedNow(false);
                            }}
                        />
                    </PopupField>
                    <PopupField label="Test connection" wide>
                        <p className="flex min-h-9 items-center" role="status">
                            {intuneTest ? <StatusBadge variant={intuneTest.tone}>{intuneTest.text}</StatusBadge> : null}
                        </p>
                    </PopupField>
                    <div className="sm:col-span-2">
                        <PopupCallout tone="info">The secret is stored encrypted in Oblivion. Nobody can read it back; you can only replace it. Oblivion only reads from Intune.</PopupCallout>
                    </div>
                </PopupSection>
            </>
        );
    } else if (step === 1 && service === 'queclink') {
        body = (
            <>
                <StepQuestion icon={MapPin} title="Point your trackers at Oblivion" line="Trackers report to Oblivion themselves, so there’s nothing to sign in to and no test." />
                <PopupSection icon={MapPin} title="Where trackers report">
                    <PopupFacts
                        rows={[
                            ['Port', String(state.queclink.port)],
                            ['Oblivion’s public address', state.queclink.address_set ? <StatusBadge variant="success">Set</StatusBadge> : <StatusBadge variant="warning">Not set yet</StatusBadge>],
                        ]}
                    />
                    <PopupCallout tone={state.queclink.address_set ? 'info' : 'warning'}>
                        {state.queclink.address_set
                            ? 'Apply the server settings to each tracker through your secure device-management process. Oblivion doesn’t show the address here.'
                            : 'Set Oblivion’s public address in the tracker settings first. Open trackers on the next step.'}
                    </PopupCallout>
                </PopupSection>
            </>
        );
    } else if (step === 2 && service === 'unifi') {
        body = (
            <>
                <StepQuestion icon={ArrowRightLeft} title="Match its sites to yours" line="Tell us which UniFi site is which house, then bring its devices in." />
                <div className="grid gap-5">
                    <PopupSection icon={House} title="Sites" columns={2}>
                        {remote.length === 0 ? (
                            <div className="sm:col-span-2">
                                <PopupCallout tone="warning">UniFi didn’t list any sites for this key. Check the key belongs to the right UniFi account.</PopupCallout>
                            </div>
                        ) : (
                            remote.map((site) => (
                                <PopupField key={site.token} label={`UniFi “${site.name}”`} hint={site.devices !== null ? plural(site.devices, 'device', 'devices') : undefined}>
                                    {site.match ? (
                                        <p className="flex min-h-9 items-center gap-2 text-sm">
                                            <StatusBadge variant={site.match.elsewhere ? 'neutral' : 'success'}>{site.match.elsewhere ? 'Matched to a Site you can’t see' : 'Matched'}</StatusBadge>
                                            {site.match.site_name}
                                        </p>
                                    ) : (
                                        <VehicleSearchSelect
                                            label={`Site for UniFi “${site.name}”`}
                                            value={choices[site.token] ?? SKIP}
                                            options={siteOptions(site.token)}
                                            onChange={(value) => setChoices((now) => ({ ...now, [site.token]: value }))}
                                        />
                                    )}
                                </PopupField>
                            ))
                        )}
                    </PopupSection>
                    <PopupSection icon={Boxes} title="What to bring in">
                        <PopupFacts
                            rows={[
                                ['Comes in', 'Network gear, Wi-Fi points and UniFi Protect cameras at each matched site'],
                                ['Never comes in', 'Phones and laptops on the Wi-Fi, so people’s own phones stay private'],
                                ['Doors', 'UniFi Access doors aren’t brought in from here: they answer only on each Site’s own network'],
                            ]}
                        />
                        <PopupCallout tone="info">Devices we already have are matched, so nothing is added twice.</PopupCallout>
                    </PopupSection>
                </div>
            </>
        );
    } else if (step === 2 && service === 'intune') {
        const computers = state.intune.computers;
        body = (
            <>
                <StepQuestion icon={ArrowRightLeft} title="Choose where they are and what to bring in" line="Pick the Site these computers are at, then tick the ones to bring in." />
                {!state.intune.found_at ? (
                    processing ? (
                        <p className="flex items-center gap-2.5 text-sm font-medium" role="status">
                            <LoaderCircle aria-hidden="true" className="size-4 animate-spin text-primary motion-reduce:animate-none" />
                            Finding Intune’s Windows computers…
                        </p>
                    ) : (
                        <Button type="button" variant="outline" size="sm" onClick={() => void findComputers()}>
                            Find computers
                        </Button>
                    )
                ) : !state.can.import_computers ? (
                    <PopupCallout tone="info">
                        {plural(computers.length, 'computer', 'computers')} found. Bringing them in also adds and places devices, so someone who can do that finishes this
                        part.
                    </PopupCallout>
                ) : (
                    <div className="grid gap-5">
                        <PopupSection icon={House} title="Site">
                            <PopupField label="Site" required error={errors.site_id}>
                                <VehicleSearchSelect label="Site" value={intuneSite} options={sites.map((site) => ({ value: String(site.id), label: site.name }))} onChange={setIntuneSite} />
                            </PopupField>
                        </PopupSection>
                        <PopupSection icon={Monitor} title="Computers" hint={`${computers.length} found`}>
                            {errors.managed_device_ids ? <p className="text-sm text-status-critical">{errors.managed_device_ids}</p> : null}
                            {computers.length === 0 ? (
                                <PopupCallout tone="info">Intune has no Windows computers for this sign-in.</PopupCallout>
                            ) : (
                                <ul className="grid gap-2.5">
                                    {computers.map((computer) => (
                                        <li key={computer.id} className="flex items-start gap-2.5">
                                            <Checkbox
                                                id={`sd-connect-pc-${computer.id}`}
                                                checked={computer.linked || picked.includes(computer.id)}
                                                disabled={computer.linked}
                                                onCheckedChange={(on) => setPicked((now) => (on === true ? [...now, computer.id] : now.filter((id) => id !== computer.id)))}
                                                className="mt-0.5"
                                            />
                                            <label htmlFor={`sd-connect-pc-${computer.id}`} className="min-w-0 text-sm">
                                                <span className="block font-medium">{computer.name}</span>
                                                <span className="text-caption block">
                                                    {[computer.sub, computer.linked ? `already in Oblivion${computer.site ? ` at ${computer.site}` : ''}` : null].filter(Boolean).join(' · ')}
                                                </span>
                                            </label>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </PopupSection>
                        <PopupCallout tone="info">Computers already in Oblivion are matched, so nothing is added twice.</PopupCallout>
                    </div>
                )}
            </>
        );
    } else if (step === 2 && service === 'queclink') {
        body = (
            <>
                <StepQuestion icon={ArrowRightLeft} title="Nothing to match" line="Each new tracker waits until you pair it with a person or a vehicle." />
                <PopupCallout tone="info">Pair new trackers, and set the public address if it isn’t set, on the trackers page.</PopupCallout>
            </>
        );
    }

    return (
        <>
            <WizardShell
                open={open && !guard.asking && !gateway}
                onClose={guard.requestClose}
                title="Connect a service"
                description="Bring in devices from UniFi, Milesight, trackers and more"
                railIcon={PlugZap}
                railTitle="Connect a service"
                railSub="Bring in devices from UniFi, Milesight, trackers and more"
                steps={STEPS}
                stepIndex={step}
                onStepClick={(index) => {
                    if (index === 0 || (index === 1 && service && service !== 'milesight' && service !== 'mqtt') || (index === 2 && (worked || service === 'queclink'))) setStep(index);
                }}
                maxWidth="min(94vw, 1100px)"
                railExtra={<p className="rounded-lg border border-border bg-card px-3 py-2.5 text-caption">Devices you already have are matched, never added twice.</p>}
                success={success}
                footerStart={
                    step === 0 ? (
                        <span className="text-caption">Fields marked * are required</span>
                    ) : (
                        <Button type="button" variant="outline" onClick={() => setStep(step - 1)} disabled={processing}>
                            Back
                        </Button>
                    )
                }
                footerEnd={
                    <>
                        <Button type="button" variant="outline" onClick={guard.requestClose} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="button" onClick={next} disabled={primaryDisabled}>
                            {processing ? <LoaderCircle aria-hidden="true" className="animate-spin motion-reduce:animate-none" /> : null}
                            {processing ? busyLabel : primaryLabel}
                        </Button>
                    </>
                }
            >
                <WizardStepPane key={`${step}-${service}`}>
                    {topError ? (
                        <PopupCallout tone="critical" className="mb-4">
                            {topError}
                        </PopupCallout>
                    ) : null}
                    {body}
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftConfirm open={open && guard.asking} what="Connect a service" onKeepEditing={guard.keepEditing} onDiscard={guard.discard} />

            <AddSensorGateway
                open={open && gateway}
                form={form}
                onClose={() => {
                    setGateway(false);
                    onClose();
                }}
            />
        </>
    );
}
