import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { Download, Laptop, Network, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    issueCollectorSetup,
    loadCollectorSetup,
    type CollectorPlatform,
    type CollectorSetupOptions,
    type CollectorSetupSite,
} from './collector-installation-profile';

type Props = {
    open: boolean;
    onClose: () => void;
    sites: CollectorSetupSite[];
    actorId: number;
};
export function CollectorInstallationDialog(props: Props) {
    return props.open ? (
        <CollectorInstallationBody
            key={
                props.actorId +
                ':' +
                props.sites.map((s) => s.id + ':' + s.name).join('|')
            }
            {...props}
        />
    ) : null;
}
function CollectorInstallationBody({ onClose, sites }: Props) {
    const [siteId, setSiteId] = useState(
        sites.length === 1 ? String(sites[0].id) : '',
    );
    const [platform, setPlatform] = useState<CollectorPlatform>('windows');
    const [step, setStep] = useState(0);
    const [review, setReview] = useState<CollectorSetupOptions | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const [file, setFile] = useState<{
        url: string;
        name: string;
        expires: string;
    } | null>(null);
    const active = useRef(false),
        pending = useRef(false),
        sequence = useRef(0),
        controller = useRef(new AbortController());
    const fileURL = useRef<string | null>(null),
        expiry = useRef<ReturnType<typeof setTimeout> | null>(null);
    const clearFile = useCallback(() => {
        if (fileURL.current) URL.revokeObjectURL(fileURL.current);
        fileURL.current = null;
        if (expiry.current) clearTimeout(expiry.current);
        expiry.current = null;
        if (active.current) setFile(null);
    }, []);
    useEffect(() => {
        active.current = true;
        const hide = () => {
            if (!document.hidden) return;
            sequence.current++;
            controller.current.abort();
            clearFile();
            pending.current = false;
            setBusy(false);
            setReview(null);
            setStep(0);
            setError(
                'Setup details were cleared when this window was hidden. Any profile already created keeps its original expiry.',
            );
        };
        document.addEventListener('visibilitychange', hide);
        return () => {
            active.current = false;
            controller.current.abort();
            clearFile();
            document.removeEventListener('visibilitychange', hide);
        };
    }, [clearFile]);
    const begin = () => {
        if (pending.current) return null;
        pending.current = true;
        setBusy(true);
        setError('');
        controller.current.abort();
        controller.current = new AbortController();
        return { id: ++sequence.current, signal: controller.current.signal };
    };
    const current = (task: { id: number; signal: AbortSignal }) =>
        active.current && sequence.current === task.id && !task.signal.aborted;
    async function load() {
        if (!sites.some((s) => String(s.id) === siteId)) return;
        const task = begin();
        if (!task) return;
        try {
            const result = await loadCollectorSetup(
                Number(siteId),
                platform,
                task.signal,
            );
            if (!current(task)) return;
            setReview(result);
            setUncertain(false);
            setStep(1);
        } catch {
            if (current(task))
                setError(
                    'Setup details could not be loaded. Check your access and try again.',
                );
        } finally {
            if (current(task)) {
                pending.current = false;
                setBusy(false);
            }
        }
    }
    async function create() {
        if (!review?.configured || uncertain) return;
        const task = begin();
        if (!task) return;
        try {
            const result = await issueCollectorSetup(review, task.signal);
            if (!current(task)) return;
            clearFile();
            const url = URL.createObjectURL(
                new Blob([JSON.stringify(result, null, 2) + '\n'], {
                    type: 'application/json',
                }),
            );
            fileURL.current = url;
            setFile({
                url,
                name: 'oblivion-collector-' + result.collector_uuid + '.json',
                expires: result.expires_at,
            });
            setStep(2);
            expiry.current = setTimeout(
                () => {
                    clearFile();
                    setError(
                        'This installation profile has expired. Review the setup again to create a new one.',
                    );
                },
                Math.max(0, Date.parse(result.expires_at) - Date.now()),
            );
        } catch {
            if (current(task)) {
                setUncertain(true);
                setError(
                    'The result could not be confirmed. A profile may already have been created. Review the setup again before retrying.',
                );
            }
        } finally {
            if (current(task)) {
                pending.current = false;
                setBusy(false);
            }
        }
    }
    function back() {
        if (busy) return;
        clearFile();
        setReview(null);
        setStep(0);
        setError('');
        setUncertain(false);
    }
    const steps = [
        {
            key: 'site',
            label: 'Site and computer',
            blurb: 'Where the collector will run',
            icon: Network,
            disabled: busy,
        },
        {
            key: 'review',
            label: 'Review setup',
            blurb: 'Confirm the organisation and server',
            icon: ShieldCheck,
            disabled: busy || !review || step === 2,
        },
        {
            key: 'download',
            label: 'Download profile',
            blurb: 'Use it with the collector installer',
            icon: Download,
            disabled: true,
        },
    ];
    return (
        <WizardShell
            open
            onClose={onClose}
            title="Set up a site collector"
            description="Choose an approved Site, review its server and download an installation profile."
            railIcon={Network}
            railTitle="Site collector"
            railSub="Installation setup"
            steps={steps}
            stepIndex={step}
            onStepClick={(index) => {
                if (index === 0) back();
            }}
            pct={step === 2 ? 100 : step === 1 ? 66 : siteId ? 33 : 0}
            maxWidth="min(92vw, 900px)"
            footerStart={
                <Button variant="ghost" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                <div className="flex flex-wrap justify-end gap-2">
                    {step > 0 && (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={back}
                        >
                            Review again
                        </Button>
                    )}
                    {step === 0 && (
                        <Button
                            disabled={busy || !siteId}
                            onClick={() => void load()}
                        >
                            {busy ? 'Loading…' : 'Review setup'}
                        </Button>
                    )}
                    {step === 1 && (
                        <Button
                            disabled={busy || !review?.configured || uncertain}
                            onClick={() => void create()}
                        >
                            {busy ? 'Creating…' : 'Create profile'}
                        </Button>
                    )}
                </div>
            }
            success={
                step === 2 && file ? (
                    <WizardSuccessPane
                        title="Installation profile ready"
                        blurb={
                            <>
                                Save this file for {review?.site_label}. It
                                expires {formatDateTime(file.expires)}.
                                Monitoring starts after the collector is
                                installed and connected.
                            </>
                        }
                        actions={
                            <div className="flex flex-wrap justify-center gap-3">
                                <Button asChild>
                                    <a href={file.url} download={file.name}>
                                        <Download aria-hidden="true" />
                                        Save installation profile
                                    </a>
                                </Button>
                                <Button variant="outline" onClick={onClose}>
                                    Close
                                </Button>
                            </div>
                        }
                    />
                ) : undefined
            }
        >
            <div className="space-y-5">
                {error && (
                    <Alert className="border-destructive/50">
                        <AlertDescription className="text-foreground">
                            {error}
                        </AlertDescription>
                    </Alert>
                )}
                {step === 0 && (
                    <>
                        <div>
                            <h2 className="text-section-title">
                                Where will this collector run?
                            </h2>
                            <p className="text-subtle mt-1">
                                Choose the Site and operating system of the
                                computer that will monitor its local network.
                            </p>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="collector-setup-site">Site</Label>
                            <Select
                                value={siteId}
                                onValueChange={setSiteId}
                                disabled={busy}
                            >
                                <SelectTrigger id="collector-setup-site">
                                    <SelectValue placeholder="Choose a Site" />
                                </SelectTrigger>
                                <SelectContent>
                                    {sites.map((site) => (
                                        <SelectItem
                                            key={site.id}
                                            value={String(site.id)}
                                        >
                                            {site.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {!sites.length && (
                                <p className="text-subtle">
                                    No approved Sites are available for your
                                    account.
                                </p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="collector-setup-platform">
                                Operating system
                            </Label>
                            <Select
                                value={platform}
                                onValueChange={(value) =>
                                    setPlatform(value as CollectorPlatform)
                                }
                                disabled={busy}
                            >
                                <SelectTrigger id="collector-setup-platform">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="windows">
                                        Windows
                                    </SelectItem>
                                    <SelectItem value="linux">Linux</SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-subtle">
                                Windows can combine site monitoring with the
                                Obli computer helper. Linux runs site
                                monitoring.
                            </p>
                        </div>
                    </>
                )}
                {step === 1 && review && (
                    <>
                        <div>
                            <h2 className="text-section-title">
                                Check the installation details
                            </h2>
                            <p className="text-subtle mt-1">
                                The profile will connect this collector to the
                                server shown below.
                            </p>
                        </div>
                        <ReviewCard icon={Network} title="Site and platform">
                            <ReviewRow label="Site" value={review.site_label} />
                            <ReviewRow
                                label="Operating system"
                                value={
                                    review.platform === 'windows'
                                        ? 'Windows'
                                        : 'Linux'
                                }
                            />
                        </ReviewCard>
                        {review.configured ? (
                            <ReviewCard icon={ShieldCheck} title="Connection">
                                <ReviewRow
                                    label="Organisation"
                                    value={review.organisation}
                                />
                                <ReviewRow
                                    label="Oblivion server"
                                    value={review.deployment_url}
                                />
                                {review.server_url !==
                                    review.deployment_url && (
                                    <ReviewRow
                                        label="Collector server"
                                        value={review.server_url}
                                    />
                                )}
                                <ReviewRow
                                    label="Profile valid for"
                                    value={
                                        review.enrolment_minutes + ' minutes'
                                    }
                                />
                            </ReviewCard>
                        ) : (
                            <Alert>
                                <AlertDescription>
                                    Your administrator needs to finish
                                    configuring the collector server before a
                                    profile can be created.
                                </AlertDescription>
                            </Alert>
                        )}
                        <p className="text-subtle">
                            The file includes a short-lived enrolment secret.
                            Keep it private and delete it after successful
                            installation.
                        </p>
                        <Button asChild variant="link">
                            <a href="/security-devices/agent-downloads">
                                <Laptop aria-hidden="true" />
                                View available installers
                            </a>
                        </Button>
                    </>
                )}
            </div>
        </WizardShell>
    );
}
