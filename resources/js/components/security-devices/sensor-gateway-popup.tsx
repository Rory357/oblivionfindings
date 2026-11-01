/**
 * Pop-up 98, Add a sensor gateway (S&D rebuild, Phase E): choose the gateway and its Site; Oblivion
 * makes its own login on that Site's hub; it shows exactly what to type into the gateway; then it
 * waits live for the first reading. Readings never cross the public internet (D21): they go to the
 * Site's hub on the house network, encrypted (TLS on 8883) once the hub has its certificate (E5), or
 * unencrypted on 1883 only while Setup allows that (D22).
 */
import { PopupCallout, PopupFacts, PopupField } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { WizardShell, WizardStepPane, WizardSuccessPane, type WizardStep } from '@/components/wizard/shell';
import type { DeviceFormData } from '@/pages/security-devices/devices/_types';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Activity, ClipboardList, Copy, KeyRound, LoaderCircle, RadioTower } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const STEPS: readonly WizardStep[] = [
    { key: 'gateway', label: 'Gateway', blurb: 'Which and where', icon: RadioTower },
    { key: 'login', label: 'Login', blurb: 'Made for it', icon: KeyRound },
    { key: 'settings', label: 'Gateway settings', blurb: 'Copy these', icon: ClipboardList },
    { key: 'first', label: 'First reading', blurb: 'Check it works', icon: Activity },
];

const MODELS = ['Milesight UG65', 'Milesight UG67', 'Milesight UG56', 'Another MQTT gateway'] as const;
const POLL_MS = 3_000;
const POLL_FOR_MS = 10 * 60_000;

type Made = {
    id: number;
    username: string;
    password: string;
    topic: string;
    hub: { label: string; address: string | null };
    port: number;
    /** S&D Phase E (E5): set when the hub takes readings encrypted (TLS on 8883). */
    tls?: { fingerprint: string; download_url: string } | null;
};

function CopyValue({ value, label }: { value: string; label: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[13px] break-all">{value}</code>
            <Button
                type="button"
                variant="outline"
                size="sm"
                aria-label={`Copy ${label}`}
                onClick={() => {
                    void navigator.clipboard?.writeText(value).then(() => setCopied(true));
                }}
            >
                <Copy className="size-3.5" aria-hidden="true" />
                {copied ? 'Copied' : 'Copy'}
            </Button>
        </span>
    );
}

export function AddSensorGateway({ open, form, onClose }: { open: boolean; form: DeviceFormData | undefined; onClose: () => void }) {
    const [step, setStep] = useState(0);
    const [siteId, setSiteId] = useState('');
    const [model, setModel] = useState<(typeof MODELS)[number]>('Milesight UG65');
    const [name, setName] = useState('');
    const [made, setMade] = useState<Made | null>(null);
    const [heardAt, setHeardAt] = useState<string | null>(null);
    const [waitedOut, setWaitedOut] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const started = useRef<number>(0);

    useEffect(() => {
        if (!open) return;
        setStep(0);
        setMade(null);
        setHeardAt(null);
        setWaitedOut(false);
        setError(null);
        setModel('Milesight UG65');
        const only = form?.sites.length === 1 ? form.sites[0] : undefined;
        setSiteId(only ? String(only.id) : '');
        setName(only ? `${only.name} sensor gateway` : '');
        // Only when it opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // Step 4: wait live for the first reading (every 3 seconds, for up to 10 minutes).
    useEffect(() => {
        if (!open || step !== 3 || !made || heardAt) return;
        started.current = Date.now();
        const timer = window.setInterval(() => {
            if (Date.now() - started.current > POLL_FOR_MS) {
                setWaitedOut(true);
                window.clearInterval(timer);
                return;
            }
            axios
                .get<{ last_reading_at: string | null }>(`/security-devices/setup/broker/gateways/${made.id}`)
                .then((response) => {
                    if (response.data.last_reading_at) {
                        setHeardAt(response.data.last_reading_at);
                        window.clearInterval(timer);
                    }
                })
                .catch(() => undefined);
        }, POLL_MS);
        return () => window.clearInterval(timer);
    }, [open, step, made, heardAt]);

    const site = form?.sites.find((option) => String(option.id) === siteId);

    const makeLogin = async () => {
        setProcessing(true);
        setError(null);
        try {
            const response = await axios.post<Made>('/security-devices/setup/broker/gateways', { site_id: Number(siteId), model, name: name.trim() });
            setMade(response.data);
            setStep(1);
        } catch (failure) {
            const data = axios.isAxiosError(failure) ? (failure.response?.data as { errors?: Record<string, string[]> } | undefined) : undefined;
            const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
            setError(first ?? (axios.isAxiosError(failure) && failure.response?.status === 403 ? 'You can’t make gateway logins.' : 'Its login wasn’t made. Try again.'));
        } finally {
            setProcessing(false);
        }
    };

    const close = () => {
        if (made) router.reload({ except: ['deviceForm'] });
        onClose();
    };

    const primary = () => {
        if (step === 0) return void makeLogin();
        if (step < 3) return setStep(step + 1);
        close();
    };
    const primaryLabel = ['Make its login', 'Next', 'Wait for its first reading', heardAt ? 'Done' : 'Close'][step];
    const canGo = step !== 0 || (siteId !== '' && name.trim().length >= 3);

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Add a sensor gateway"
            description="A gateway sends its sensors’ readings to its Site’s hub"
            railIcon={RadioTower}
            railTitle="Add a sensor gateway"
            railSub="Sensor readings never cross the public internet"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(index) => {
                if (made && index >= 1) setStep(index);
            }}
            maxWidth="min(94vw, 1100px)"
            success={
                step === 3 && heardAt ? (
                    <WizardSuccessPane
                        title={`${name.trim() || 'The gateway'} is sending readings`}
                        blurb={`First reading at ${seenTime(heardAt, new Date())}, through ${made?.hub.label ?? 'its hub'}. New sensors show in Found devices to add.`}
                        actions={
                            <Button type="button" onClick={close}>
                                Done
                            </Button>
                        }
                    />
                ) : undefined
            }
            footerStart={
                step > 0 && step < 3 ? (
                    <Button type="button" variant="outline" onClick={() => setStep(step - 1)}>
                        Back
                    </Button>
                ) : (
                    <span className="text-caption">{step === 0 ? 'Fields marked * are required' : ''}</span>
                )
            }
            footerEnd={
                <>
                    {step === 0 ? (
                        <Button type="button" variant="outline" onClick={close} disabled={processing}>
                            Cancel
                        </Button>
                    ) : null}
                    <Button type="button" onClick={primary} disabled={!canGo || processing}>
                        {processing ? <LoaderCircle aria-hidden="true" className="animate-spin motion-reduce:animate-none" /> : null}
                        {processing ? 'Making its login…' : primaryLabel}
                    </Button>
                </>
            }
        >
            <WizardStepPane key={step}>
                {error ? (
                    <PopupCallout tone="critical" className="mb-4">
                        {error}
                    </PopupCallout>
                ) : null}

                {step === 0 ? (
                    <div className="grid gap-4">
                        <h3 className="text-section-title">Which gateway, and where is it?</h3>
                        <PopupField label="Site" htmlFor="gateway-site" required>
                            <Select
                                value={siteId}
                                onValueChange={(value) => {
                                    setSiteId(value);
                                    const chosen = form?.sites.find((option) => String(option.id) === value);
                                    if (chosen && (name === '' || name.endsWith(' sensor gateway'))) setName(`${chosen.name} sensor gateway`);
                                }}
                            >
                                <SelectTrigger id="gateway-site">
                                    <SelectValue placeholder={form ? 'Choose a Site' : 'Loading Sites…'} />
                                </SelectTrigger>
                                <SelectContent>
                                    {(form?.sites ?? []).map((option) => (
                                        <SelectItem key={option.id} value={String(option.id)}>
                                            {option.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </PopupField>
                        <PopupField label="Model" htmlFor="gateway-model" required>
                            <Select value={model} onValueChange={(value) => setModel(value as (typeof MODELS)[number])}>
                                <SelectTrigger id="gateway-model">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {MODELS.map((option) => (
                                        <SelectItem key={option} value={option}>
                                            {option}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </PopupField>
                        <PopupField label="Name" htmlFor="gateway-name" required>
                            <Input id="gateway-name" value={name} maxLength={80} onChange={(event) => setName(event.target.value)} />
                        </PopupField>
                        <PopupCallout tone="info">
                            <b>How it reaches Oblivion:</b> through {site ? `${site.name}’s` : 'the Site’s'} hub, on the house network. The hub passes readings on, keeping them if its link drops. Never over the public internet.
                        </PopupCallout>
                    </div>
                ) : null}

                {step === 1 && made ? (
                    <div className="grid gap-4">
                        <h3 className="text-section-title">Its login on {made.hub.label}</h3>
                        <PopupFacts
                            rows={[
                                ['Username', <CopyValue key="u" value={made.username} label="username" />],
                                ['Password', <CopyValue key="p" value={made.password} label="password" />],
                                ['May send to', <code key="t" className="font-mono text-[13px] break-all">{made.topic.replace(/up$/, '…')}</code>],
                            ]}
                        />
                        <PopupCallout tone="warning">
                            <b>The password is shown only now.</b> Copy it into the gateway in the next step. Oblivion keeps only a scrambled form: it can be replaced, never read back.
                        </PopupCallout>
                        <p className="text-subtle">It can send only its own readings and can’t read anyone else’s.</p>
                    </div>
                ) : null}

                {step === 2 && made ? (
                    <div className="grid gap-4">
                        <h3 className="text-section-title">Enter these in the gateway</h3>
                        <p className="text-subtle">In the gateway’s web page, open the data transmission settings, choose MQTT and enter:</p>
                        <PopupFacts
                            rows={[
                                ['Broker address', made.hub.address ? <CopyValue key="a" value={made.hub.address} label="broker address" /> : `${made.hub.label}’s address on the house network`],
                                ['Port', String(made.port)],
                                ['Client ID', <CopyValue key="c" value={made.username} label="client ID" />],
                                ['Username and password', 'From the last step'],
                                [
                                    'TLS',
                                    made.tls ? (
                                        <span key="tls" className="grid gap-1">
                                            <span>On. Load the sensors certificate as the CA certificate.</span>
                                            <a className="underline underline-offset-2" href={made.tls.download_url} download>
                                                Download the sensors certificate
                                            </a>
                                            <span className="text-caption break-all">Fingerprint {made.tls.fingerprint}</span>
                                        </span>
                                    ) : (
                                        'Off: the hub takes readings unencrypted on the house network'
                                    ),
                                ],
                                ['Uplink topic', <CopyValue key="t" value={made.topic} label="uplink topic" />],
                            ]}
                        />
                        <p className="text-caption">Menu names differ by firmware. These steps are checked on a real UG65 before this ships.</p>
                    </div>
                ) : null}

                {step === 3 && made && !heardAt ? (
                    <div className="grid gap-4">
                        <h3 className="text-section-title">Waiting for its first reading</h3>
                        {waitedOut ? (
                            <PopupCallout tone="warning">
                                Nothing yet after 10 minutes. Check the broker address, port and topic in the gateway, and that {made.hub.label} is answering. You can close this: readings show on Setup › Sensor broker when they arrive.
                            </PopupCallout>
                        ) : (
                            <p className="flex items-center gap-2 text-subtle" role="status">
                                <LoaderCircle className="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                                Leave this open while you save the gateway’s settings.
                            </p>
                        )}
                    </div>
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}
