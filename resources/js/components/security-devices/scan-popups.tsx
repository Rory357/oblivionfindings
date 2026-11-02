/**
 * Scanning and found devices (S&D rebuild drawings sdr-3, 4, 5, 6 and 75), on the pop-up kit.
 * Scans run on today's discovery scopes and runs; Same device? and Ignore use today's candidate
 * review routes. Nothing on the network is changed by a scan.
 */
import { VehicleSearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import {
    PopupCallout,
    PopupConfirm,
    PopupFacts,
    PopupField,
    PopupForm,
    PopupSection,
    PopupViewer,
} from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import type { DeviceFormData } from '@/pages/security-devices/devices/_types';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { EyeOff, GitMerge, List, Network, Radar } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const LOOK_FOR = [
    { value: 'everything', label: 'Everything' },
    { value: 'cameras', label: 'Cameras' },
    { value: 'network', label: 'Network gear' },
    { value: 'printers', label: 'Printers' },
    { value: 'computers', label: 'Computers' },
    { value: 'sensors', label: 'Sensors' },
] as const;

type LookFor = (typeof LOOK_FOR)[number]['value'];

function errorsOf(error: unknown): Record<string, string> {
    if (!axios.isAxiosError(error)) return { form: 'It didn’t work. Try again.' };
    const data = error.response?.data as { errors?: Record<string, string[]> } | undefined;
    if (data?.errors) {
        return Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]]));
    }
    if (error.response?.status === 403) return { form: 'You can’t do this here.' };
    if (!error.response) return { form: 'Result not confirmed. Check Found devices before trying again.' };
    return { form: 'It didn’t work. Try again.' };
}

/** "Look for" chips: Everything, or any mix of the rest. */
function LookForChips({ value, onChange }: { value: LookFor[]; onChange: (value: LookFor[]) => void }) {
    const toggle = (choice: LookFor) => {
        if (choice === 'everything') return onChange(['everything']);
        const rest = value.filter((item) => item !== 'everything');
        const next = rest.includes(choice) ? rest.filter((item) => item !== choice) : [...rest, choice];
        onChange(next.length === 0 ? ['everything'] : next);
    };
    return (
        <div role="group" aria-label="Look for" className="flex flex-wrap gap-2">
            {LOOK_FOR.map((choice) => {
                const on = value.includes(choice.value);
                return (
                    // eslint-disable-next-line no-restricted-syntax -- a chip (pop-up kit "chips"), not a form button.
                    <button
                        key={choice.value}
                        type="button"
                        aria-pressed={on}
                        onClick={() => toggle(choice.value)}
                        className={cn(
                            'inline-flex h-8 items-center rounded-full border px-3 text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            on
                                ? 'border-primary bg-primary-fill/10 text-primary'
                                : 'border-border bg-card text-foreground hover:border-primary/50',
                        )}
                    >
                        {choice.label}
                    </button>
                );
            })}
        </div>
    );
}

function useSiteChoice(form: DeviceFormData | undefined, open: boolean, initialSiteId?: number | null) {
    const [siteId, setSiteId] = useState('');
    const [range, setRange] = useState('');
    const [hubId, setHubId] = useState('');
    const site = form?.sites.find((option) => String(option.id) === siteId);

    useEffect(() => {
        if (!open || !form) return;
        const first = form.sites.find((option) => option.id === initialSiteId) ?? (form.sites.length === 1 ? form.sites[0] : undefined);
        setSiteId(first ? String(first.id) : '');
        // Only when it opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, form]);

    useEffect(() => {
        setRange(site?.suggested_range ?? '');
        setHubId(site?.suggested_hub_id ? String(site.suggested_hub_id) : '');
    }, [site?.id, site?.suggested_range, site?.suggested_hub_id]);

    return { siteId, setSiteId, site, range, setRange, hubId, setHubId };
}

function HubField({ site, hubId, setHubId, label }: {
    site: DeviceFormData['sites'][number] | undefined;
    hubId: string;
    setHubId: (value: string) => void;
    label: string;
}) {
    if (!site) {
        return <p className="text-subtle">Choose a Site first.</p>;
    }
    if (site.hubs.length === 0) {
        return <p className="text-sm">The Oblivion server · no hub covers {site.name}</p>;
    }
    return (
        <VehicleSearchSelect
            label={label}
            value={hubId}
            options={site.hubs.map((hub) => ({ value: String(hub.id), label: hub.label }))}
            onChange={setHubId}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  3 · Scan a network                                                 */
/* ------------------------------------------------------------------ */

export function ScanNetwork({
    open,
    form,
    initialSiteId,
    onClose,
    onStarted,
}: {
    open: boolean;
    form: DeviceFormData | undefined;
    initialSiteId?: number | null;
    onClose: () => void;
    onStarted: (runId: number) => void;
}) {
    const choice = useSiteChoice(form, open, initialSiteId);
    const [lookFor, setLookFor] = useState<LookFor[]>(['everything']);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    useEffect(() => {
        if (open) {
            setLookFor(['everything']);
            setErrors({});
        }
    }, [open]);

    const submit = () => {
        const next: Record<string, string> = {};
        if (!choice.siteId) next.site_id = 'Choose the Site to scan.';
        if (!choice.range.trim()) next.range = 'Write the range like 10.20.0.0/24.';
        setErrors(next);
        if (Object.keys(next).length > 0) return;
        setProcessing(true);
        axios
            .post<{ run_id: number }>(
                '/security-devices/scans',
                {
                    site_id: Number(choice.siteId),
                    range: choice.range.trim(),
                    hub_id: choice.hubId ? Number(choice.hubId) : null,
                    look_for: lookFor,
                },
                { headers: { Accept: 'application/json' } },
            )
            .then(({ data }) => onStarted(data.run_id))
            .catch((failure) => setErrors(errorsOf(failure)))
            .finally(() => setProcessing(false));
    };

    const loading = !form;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Radar}
            title="Scan a network"
            description="Look for devices on a Site’s network. Nothing on the network is changed."
            error={errors.form ?? errors.scope ?? errors.cidrs ?? errors.protocols ?? errors.collector_id}
            submitLabel="Start scan"
            processingLabel="Starting…"
            processing={processing}
            submitDisabled={loading}
            onSubmit={submit}
            testId="sd-scan-network"
        >
            {loading ? (
                <p className="text-subtle" role="status">Getting the form ready…</p>
            ) : (
                <>
                    <PopupField label="Site" required error={errors.site_id}>
                        <VehicleSearchSelect
                            label="Site"
                            value={choice.siteId}
                            options={form.sites.map((site) => ({ value: String(site.id), label: site.name }))}
                            onChange={choice.setSiteId}
                        />
                    </PopupField>
                    <PopupField
                        label="Network range"
                        htmlFor="sd-scan-range"
                        required
                        hint={choice.site?.suggested_range ? 'suggested from the networks already set up here' : 'like 10.20.0.0/24'}
                        error={errors.range}
                    >
                        <Input
                            id="sd-scan-range"
                            value={choice.range}
                            placeholder="e.g. 10.20.0.0/24"
                            onChange={(event) => choice.setRange(event.target.value)}
                        />
                    </PopupField>
                    <PopupField label="Run from">
                        <HubField site={choice.site} hubId={choice.hubId} setHubId={choice.setHubId} label="Run from" />
                    </PopupField>
                    <PopupField label="Look for">
                        <LookForChips value={lookFor} onChange={setLookFor} />
                    </PopupField>
                    <PopupCallout tone="info">
                        A house network takes a few minutes to scan. You can close the progress
                        and come back to Found devices.
                    </PopupCallout>
                </>
            )}
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  4 · Scanning                                                       */
/* ------------------------------------------------------------------ */

type Progress = {
    id: number;
    site: string | null;
    range: string | null;
    runs_from: string;
    status: string;
    running: boolean;
    planned: number;
    checked: number;
    found: number;
    started_at: string | null;
    completed_at: string | null;
    cancelled: boolean;
    finds: { address: string | null; text: string }[];
};

export function ScanningViewer({
    open,
    runId,
    canStop,
    onClose,
}: {
    open: boolean;
    runId: number | null;
    canStop: boolean;
    onClose: () => void;
}) {
    const [progress, setProgress] = useState<Progress | null>(null);
    const [failed, setFailed] = useState(false);
    const [stopping, setStopping] = useState(false);
    const timer = useRef<number | null>(null);

    useEffect(() => {
        if (!open || !runId) return;
        let cancelled = false;
        const read = () => {
            axios
                .get<Progress>(`/security-devices/scans/${runId}/progress`, { headers: { Accept: 'application/json' } })
                .then(({ data }) => {
                    if (cancelled) return;
                    setProgress(data);
                    setFailed(false);
                    if (data.running) timer.current = window.setTimeout(read, 3000);
                })
                .catch(() => {
                    if (cancelled) return;
                    setFailed(true);
                    timer.current = window.setTimeout(read, 6000);
                });
        };
        setProgress(null);
        read();
        return () => {
            cancelled = true;
            if (timer.current) window.clearTimeout(timer.current);
        };
    }, [open, runId]);

    const stop = () => {
        if (!runId) return;
        setStopping(true);
        axios
            .post(`/security-devices/scans/${runId}/stop`, {}, { headers: { Accept: 'application/json' } })
            .then(() => setProgress((current) => (current ? { ...current, running: false, cancelled: true, status: 'cancelled' } : current)))
            .finally(() => setStopping(false));
    };

    const finished = progress && !progress.running;
    const title = progress?.site ? `Scanning ${progress.site}` : 'Scanning';

    return (
        <PopupViewer
            open={open}
            onClose={onClose}
            size="md"
            icon={Radar}
            title={finished ? (progress.cancelled ? `Scan of ${progress.site ?? 'the Site'} stopped` : `Scan of ${progress.site ?? 'the Site'} finished`) : title}
            description={
                progress
                    ? [progress.range, `from ${progress.runs_from}`, progress.started_at ? `started ${formatTime(progress.started_at)}` : null]
                          .filter(Boolean)
                          .join(' · ')
                    : 'Starting the scan…'
            }
            callout={
                failed
                    ? { tone: 'warning', content: 'Progress isn’t coming through right now. We’ll keep trying; the scan carries on.' }
                    : finished
                      ? undefined
                      : { tone: 'info', content: 'You can close this; the scan carries on and Found devices shows what it finds.' }
            }
            facts={
                progress
                    ? [
                          ['Progress', <b key="p" className="font-semibold">Checked {progress.checked} of {progress.planned} addresses · {progress.found} found so far</b>],
                          ['Status', progress.cancelled ? 'Stopped' : progress.running ? 'Running' : 'Finished'],
                      ]
                    : undefined
            }
            action={
                <>
                    {canStop && progress?.running ? (
                        <Button type="button" variant="outline" onClick={stop} disabled={stopping}>
                            {stopping ? 'Stopping…' : 'Stop scan'}
                        </Button>
                    ) : null}
                    <Button type="button" disabled={!runId} onClick={() => runId && router.visit(`/security-devices/scans/${runId}`)}>
                        See what’s found
                    </Button>
                </>
            }
            testId="sd-scanning"
        >
            <PopupSection icon={List} title="Found so far">
                {progress && progress.finds.length > 0 ? (
                    <PopupFacts rows={progress.finds.map((find) => [find.address ?? '—', find.text] as const)} />
                ) : (
                    <p className="text-subtle">{progress ? 'Nothing found yet.' : 'Waiting for the first answers…'}</p>
                )}
            </PopupSection>
        </PopupViewer>
    );
}

/* ------------------------------------------------------------------ */
/*  75 · Add a network                                                 */
/* ------------------------------------------------------------------ */

export function AddNetwork({
    open,
    form,
    initialSiteId,
    onClose,
}: {
    open: boolean;
    form: DeviceFormData | undefined;
    initialSiteId?: number | null;
    onClose: () => void;
}) {
    const choice = useSiteChoice(form, open, initialSiteId);
    const [schedule, setSchedule] = useState<'daily' | 'ask'>('daily');
    const [lookFor, setLookFor] = useState<LookFor[]>(['everything']);
    const [others, setOthers] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            setSchedule('daily');
            setLookFor(['everything']);
            setErrors({});
            setOthers([]);
        }
    }, [open]);

    useEffect(() => {
        if (!open || !choice.siteId || !/\/\d{1,3}$/.test(choice.range.trim())) {
            setOthers([]);
            return;
        }
        const handle = window.setTimeout(() => {
            axios
                .get<{ sites: string[] }>('/security-devices/networks/range-check', {
                    params: { site_id: choice.siteId, range: choice.range.trim() },
                    headers: { Accept: 'application/json' },
                })
                .then(({ data }) => setOthers(data.sites))
                .catch(() => setOthers([]));
        }, 400);
        return () => window.clearTimeout(handle);
    }, [open, choice.siteId, choice.range]);

    const noHub = (choice.site?.hubs.length ?? 0) === 0;

    const submit = () => {
        const next: Record<string, string> = {};
        if (!choice.siteId) next.site_id = 'Choose the Site.';
        if (!choice.range.trim()) next.range = 'Write the range like 10.30.0.0/24.';
        setErrors(next);
        if (Object.keys(next).length > 0) return;
        setProcessing(true);
        axios
            .post(
                '/security-devices/networks',
                {
                    site_id: Number(choice.siteId),
                    range: choice.range.trim(),
                    hub_id: choice.hubId ? Number(choice.hubId) : null,
                    schedule,
                    look_for: lookFor,
                },
                { headers: { Accept: 'application/json' } },
            )
            .then(() => {
                onClose();
                router.reload();
            })
            .catch((failure) => setErrors(errorsOf(failure)))
            .finally(() => setProcessing(false));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Network}
            title="Add a network"
            description="Tell a hub about a network to check and scan."
            error={errors.form ?? errors.schedule ?? errors.name ?? errors.cidrs ?? errors.protocols ?? errors.collector_id}
            callout={
                others.length > 0
                    ? {
                          tone: 'warning',
                          content: `${choice.range.trim()} is also used at ${others.join(', ')}. That’s fine when each Site is checked by its own hub, so the addresses never mix. If one hub covers both, give one Site its own hub.`,
                      }
                    : undefined
            }
            submitLabel="Add network"
            processingLabel="Adding…"
            processing={processing}
            submitDisabled={!form}
            dirty={!!choice.range && choice.range !== (choice.site?.suggested_range ?? '')}
            draftName="Add a network"
            onSubmit={submit}
            testId="sd-add-network"
        >
            {!form ? (
                <p className="text-subtle" role="status">Getting the form ready…</p>
            ) : (
                <>
                    <PopupField label="Site" required error={errors.site_id}>
                        <VehicleSearchSelect
                            label="Site"
                            value={choice.siteId}
                            options={form.sites.map((site) => ({ value: String(site.id), label: site.name }))}
                            onChange={choice.setSiteId}
                        />
                    </PopupField>
                    <PopupField label="Network range" htmlFor="sd-network-range" required hint="e.g. 10.30.0.0/24" error={errors.range}>
                        <Input id="sd-network-range" value={choice.range} onChange={(event) => choice.setRange(event.target.value)} />
                    </PopupField>
                    <PopupField label="Checked by">
                        <HubField site={choice.site} hubId={choice.hubId} setHubId={choice.setHubId} label="Checked by" />
                    </PopupField>
                    <PopupField
                        label="Scan"
                        hint={noHub ? 'a daily scan needs a hub at or covering this Site' : 'New Zealand time'}
                    >
                        <div role="radiogroup" aria-label="Scan" className="flex flex-wrap gap-2">
                            {([
                                ['daily', 'Every day at 6:00 am'],
                                ['ask', 'Only when I ask'],
                            ] as const).map(([value, label]) => (
                                // eslint-disable-next-line no-restricted-syntax -- a segmented choice (pop-up kit), not a form button.
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={schedule === value}
                                    disabled={value === 'daily' && noHub}
                                    onClick={() => setSchedule(value)}
                                    className={cn(
                                        'inline-flex h-9 items-center rounded-lg border px-3 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50',
                                        schedule === value ? 'border-primary bg-primary-fill/10 text-primary' : 'border-border bg-card',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </PopupField>
                    <PopupField label="Look for">
                        <LookForChips value={lookFor} onChange={setLookFor} />
                    </PopupField>
                </>
            )}
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  5 · Same device? · 6 · Ignore                                      */
/* ------------------------------------------------------------------ */

export type FoundCandidate = {
    id: number;
    guess: string;
    address: string | null;
    maker: string | null;
    sub: string | null;
    closest: { id: number; name: string } | null;
};

export function SameDevice({
    open,
    candidate,
    site,
    devices,
    onClose,
}: {
    open: boolean;
    candidate: FoundCandidate | null;
    site: string | null;
    devices: { value: string; label: string; description: string }[] | undefined;
    onClose: () => void;
}) {
    const [deviceId, setDeviceId] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    useEffect(() => {
        if (open) {
            setDeviceId(candidate?.closest ? String(candidate.closest.id) : '');
            setError(null);
        }
    }, [open, candidate]);

    // Closest match first.
    const options = devices
        ? [...devices].sort((a, b) => Number(b.value === String(candidate?.closest?.id)) - Number(a.value === String(candidate?.closest?.id)))
        : [];
    const chosen = options.find((option) => option.value === deviceId);

    const submit = () => {
        if (!candidate || !deviceId) {
            setError('Choose the device it’s the same as.');
            return;
        }
        setProcessing(true);
        router.post(
            `/security-devices/discovery/candidates/${candidate.id}/same-as`,
            { device_id: Number(deviceId) },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (errors) => setError(String(Object.values(errors)[0] ?? 'It couldn’t be merged. Try again.')),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="sm"
            icon={GitMerge}
            title="Same device?"
            description="Is this the same as a device you already have? We’ll keep one record."
            context={
                candidate
                    ? {
                          title: `${candidate.guess}${candidate.address ? ` · ${candidate.address}` : ''}`,
                          tag: 'Found by scan',
                          note: [site, candidate.maker ? `made by ${candidate.maker}` : null].filter(Boolean).join(' · ') || undefined,
                          icon: Radar,
                      }
                    : undefined
            }
            error={error ?? undefined}
            submitLabel="Merge into this device"
            processingLabel="Merging…"
            processing={processing}
            submitDisabled={!devices}
            onSubmit={submit}
            testId="sd-same-device"
        >
            <PopupField label="Is this the same as…" required hint="closest match first">
                {devices ? (
                    <VehicleSearchSelect label="Your devices" value={deviceId} options={options} onChange={setDeviceId} />
                ) : (
                    <p className="text-subtle" role="status">Getting your devices…</p>
                )}
            </PopupField>
            {chosen ? (
                <PopupCallout tone="info">
                    We’ll keep {chosen.label}’s name and history and add what the scan found.
                </PopupCallout>
            ) : null}
        </PopupForm>
    );
}

export function IgnoreFound({
    open,
    candidate,
    onClose,
}: {
    open: boolean;
    candidate: FoundCandidate | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        if (open) setError(null);
    }, [open]);
    const what = candidate?.address ?? candidate?.guess ?? 'this device';

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={() => {
                if (!candidate) return;
                setProcessing(true);
                router.post(
                    `/security-devices/discovery/candidates/${candidate.id}/ignore`,
                    {},
                    {
                        preserveScroll: true,
                        onSuccess: () => onClose(),
                        onError: (errors) => setError(String(Object.values(errors)[0] ?? 'It couldn’t be ignored. Try again.')),
                        onFinish: () => setProcessing(false),
                    },
                );
            }}
            variant="plain"
            icon={EyeOff}
            title={`Ignore ${what}?`}
            description={
                <>
                    It moves to the Ignored tab and isn’t added. You can still find{' '}
                    <b className="font-semibold text-foreground">{what}</b> there.
                </>
            }
            error={error ?? undefined}
            confirmLabel="Ignore device"
            processing={processing}
            testId="sd-ignore-found"
        />
    );
}
