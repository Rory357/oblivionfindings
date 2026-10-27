import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import axios from 'axios';
import {
    Check,
    ChevronsUpDown,
    Copy,
    Cpu,
    Download,
    KeyRound,
    Laptop,
    Loader2,
    Network,
    RadioTower,
    RefreshCw,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type HubSiteOption = {
    id: number;
    name: string;
};

export type HubWizardPlatform = 'windows' | 'linux';

export type HubDownloadArtifact = {
    id: string;
    title?: string;
    platform: string;
    architecture: string;
    roles?: string[];
    version: string;
    filename: string;
    sha256: string;
    size?: number;
    size_bytes?: number;
    download_url: string;
};

type HubWizardOptionsResponse = {
    platform: HubWizardPlatform;
    profile_configured: boolean;
    profile_missing_reason: string | null;
    catalogue_available: boolean;
    catalogue_expires_at: string | null;
    deployment_url: string | null;
    server_url: string | null;
    organisation: string;
    enrolment_minutes: number;
    accessible_sites: HubSiteOption[];
    downloads: HubDownloadArtifact[];
    downloads_by_platform: Record<HubWizardPlatform, HubDownloadArtifact[]>;
};

type HubWizardIssueResponse = {
    enrollment_id: number;
    expires_at: string;
    profile_configured: boolean;
    profile_missing_reason: string | null;
    setup_code: string;
    enrolment_token: string;
    collector_uuid: string | null;
    profile_filename: string | null;
    linux_command: string | null;
};

type HubWizardStatusState =
    | 'waiting'
    | 'enrolled'
    | 'connected'
    | 'expired'
    | 'revoked';

type HubWizardStatusResponse = {
    enrollment_id: number;
    state: HubWizardStatusState;
    expires_at: string | null;
    consumed_at: string | null;
    collector: {
        id: number;
        name: string;
        status: string;
        software_version: string | null;
        last_heartbeat_at: string | null;
        covered_site_ids: number[];
    } | null;
};

const WIZARD_STEPS = [
    {
        key: 'details',
        label: 'Hub details',
        blurb: 'Site, platform and SD-WAN reach',
        icon: RadioTower,
    },
    {
        key: 'install',
        label: 'Download & code',
        blurb: 'Installer and one-time setup code',
        icon: Download,
    },
    {
        key: 'connect',
        label: 'Connect',
        blurb: 'Wait for Connected ✓',
        icon: Check,
    },
] as const;

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function extractError(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const validation = error.response?.data?.errors;
        if (validation && typeof validation === 'object') {
            const first = Object.values(validation as Record<string, unknown>)
                .flatMap((item) => (Array.isArray(item) ? item : [item]))
                .find(
                    (item): item is string =>
                        typeof item === 'string' && item.length > 0,
                );
            if (first) return first;
        }
        if (typeof error.response?.data?.message === 'string') {
            return error.response.data.message;
        }
    }
    return fallback;
}

export function AddHubWizard({
    open,
    onClose,
    sites = [],
    defaultSiteId = null,
    optionsUrl = '/security-devices/discovery/collectors/hub-wizard/options',
    issueUrl = '/security-devices/discovery/collectors/hub-wizard/issue',
    onConnected,
}: {
    open: boolean;
    onClose: () => void;
    sites?: HubSiteOption[];
    defaultSiteId?: number | null;
    optionsUrl?: string | null;
    issueUrl?: string | null;
    onConnected?: () => void;
}) {
    if (!open) return null;

    return (
        <AddHubWizardBody
            key={
                String(defaultSiteId ?? 'any') +
                ':' +
                sites.map((site) => `${site.id}:${site.name}`).join('|')
            }
            onClose={onClose}
            sites={sites}
            defaultSiteId={defaultSiteId}
            optionsUrl={
                optionsUrl ??
                '/security-devices/discovery/collectors/hub-wizard/options'
            }
            issueUrl={
                issueUrl ??
                '/security-devices/discovery/collectors/hub-wizard/issue'
            }
            onConnected={onConnected}
        />
    );
}

function AddHubWizardBody({
    onClose,
    sites,
    defaultSiteId,
    optionsUrl,
    issueUrl,
    onConnected,
}: {
    onClose: () => void;
    sites: HubSiteOption[];
    defaultSiteId: number | null;
    optionsUrl: string;
    issueUrl: string;
    onConnected?: () => void;
}) {
    const initialSite =
        (defaultSiteId !== null
            ? sites.find((item) => item.id === defaultSiteId)
            : undefined) ?? (sites.length === 1 ? sites[0] : undefined);

    const [step, setStep] = useState(0);
    const [siteId, setSiteId] = useState(
        initialSite ? String(initialSite.id) : '',
    );
    const [sitePopoverOpen, setSitePopoverOpen] = useState(false);
    const [name, setName] = useState(
        initialSite ? `${initialSite.name} Hub` : '',
    );
    const [nameTouched, setNameTouched] = useState(false);
    const [platform, setPlatform] = useState<HubWizardPlatform>('windows');
    const [coveredSiteIds, setCoveredSiteIds] = useState<number[]>([]);

    const [options, setOptions] = useState<HubWizardOptionsResponse | null>(
        null,
    );
    const [issued, setIssued] = useState<HubWizardIssueResponse | null>(null);
    const [issuedFingerprint, setIssuedFingerprint] = useState('');
    const [status, setStatus] = useState<HubWizardStatusResponse | null>(null);

    const [busy, setBusy] = useState(false);
    const [polling, setPolling] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copiedField, setCopiedField] = useState<string | null>(null);

    const notifiedConnected = useRef(false);
    const mounted = useRef(true);

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);

    useEffect(() => {
        let cancelled = false;
        void axios
            .get<HubWizardOptionsResponse>(optionsUrl, {
                params: {
                    platform,
                    ...(siteId ? { site_id: Number(siteId) } : {}),
                },
                headers: { Accept: 'application/json' },
            })
            .then((response) => {
                if (!cancelled && mounted.current) {
                    setOptions(response.data);
                    const loadedSites = response.data.accessible_sites ?? [];
                    const preferredSite =
                        (defaultSiteId !== null
                            ? loadedSites.find(
                                  (item) => item.id === defaultSiteId,
                              )
                            : undefined) ??
                        (loadedSites.length === 1
                            ? loadedSites[0]
                            : undefined);
                    if (preferredSite) {
                        setSiteId((current) =>
                            current === ''
                                ? String(preferredSite.id)
                                : current,
                        );
                        setName((current) =>
                            current.trim() === ''
                                ? `${preferredSite.name} Hub`
                                : current,
                        );
                    }
                }
            })
            .catch(() => {
                /* Options are also refreshed or surfaced when issuing. */
            });

        return () => {
            cancelled = true;
        };
    }, [defaultSiteId, optionsUrl, platform, siteId]);

    const availableSites =
        options?.accessible_sites && options.accessible_sites.length > 0
            ? options.accessible_sites
            : sites;
    const selectedSite = availableSites.find(
        (item) => String(item.id) === siteId,
    );
    const otherSites = availableSites.filter(
        (item) => String(item.id) !== siteId,
    );

    const currentFingerprint = JSON.stringify({
        siteId,
        name: name.trim(),
        platform,
        coveredSiteIds: [...coveredSiteIds].sort((a, b) => a - b),
    });

    const selectHomeSite = (nextSite: HubSiteOption) => {
        setSiteId(String(nextSite.id));
        setCoveredSiteIds((before) =>
            before.filter((id) => id !== nextSite.id),
        );
        if (!nameTouched || name.trim() === '') {
            setName(`${nextSite.name} Hub`);
        }
        setSitePopoverOpen(false);
        setError(null);
    };

    const toggleCoveredSite = (id: number, checked: boolean) => {
        setCoveredSiteIds((before) =>
            checked
                ? [...new Set([...before, id])]
                : before.filter((existing) => existing !== id),
        );
    };

    const checkConnection = useCallback(async (enrollmentId: number) => {
        if (!mounted.current) return;
        setPolling(true);
        try {
            const response = await axios.get<HubWizardStatusResponse>(
                `/security-devices/discovery/collectors/enrolments/${enrollmentId}/status`,
                { headers: { Accept: 'application/json' } },
            );
            if (!mounted.current) return;
            setStatus(response.data);
            if (
                response.data.state === 'connected' &&
                !notifiedConnected.current
            ) {
                notifiedConnected.current = true;
                onConnected?.();
            }
        } catch {
            /* Keep polling quietly through transient refreshes. */
        } finally {
            if (mounted.current) {
                setPolling(false);
            }
        }
    }, [onConnected]);

    useEffect(() => {
        if (!issued) return;
        const terminal =
            status?.state === 'connected' ||
            status?.state === 'expired' ||
            status?.state === 'revoked';
        if (terminal) return;

        void checkConnection(issued.enrollment_id);
        const timer = window.setInterval(() => {
            void checkConnection(issued.enrollment_id);
        }, 3000);

        return () => {
            window.clearInterval(timer);
        };
    }, [issued, status?.state, checkConnection]);

    const issueCode = async (forceNew = false) => {
        if (!selectedSite) {
            setError('Choose a home Site for this hub.');
            return;
        }
        if (name.trim().length < 2) {
            setError('Enter a hub name of at least 2 characters.');
            return;
        }
        if (
            !forceNew &&
            issued &&
            issuedFingerprint === currentFingerprint &&
            status?.state !== 'expired' &&
            status?.state !== 'revoked'
        ) {
            setStep(1);
            return;
        }

        setBusy(true);
        setError(null);
        setCopiedField(null);
        notifiedConnected.current = false;

        try {
            const response = await axios.post<HubWizardIssueResponse>(
                issueUrl,
                {
                    site_id: selectedSite.id,
                    platform,
                    name: name.trim(),
                    covered_site_ids: coveredSiteIds,
                },
                { headers: { Accept: 'application/json' } },
            );
            if (!mounted.current) return;
            setIssued(response.data);
            setIssuedFingerprint(currentFingerprint);
            setStatus({
                enrollment_id: response.data.enrollment_id,
                state: 'waiting',
                expires_at: response.data.expires_at,
                consumed_at: null,
                collector: null,
            });
            setStep(1);
        } catch (issueError) {
            if (!mounted.current) return;
            setError(
                extractError(
                    issueError,
                    'Could not create a one-time setup code. Check your Site access and try again.',
                ),
            );
        } finally {
            if (mounted.current) {
                setBusy(false);
            }
        }
    };

    const copyValue = async (key: string, value: string) => {
        try {
            await navigator.clipboard.writeText(value);
            setCopiedField(key);
        } catch {
            setError(
                'Browser copy was blocked. Select the code and copy it manually.',
            );
        }
    };

    const downloads =
        options?.downloads_by_platform?.[platform] ?? options?.downloads ?? [];

    const pct =
        status?.state === 'connected'
            ? 100
            : issued
              ? 67
              : selectedSite && name.trim().length >= 2
                ? 33
                : 0;

    const finishAndClose = () => {
        if (status?.state === 'connected' && !notifiedConnected.current) {
            notifiedConnected.current = true;
            onConnected?.();
        }
        onClose();
    };

    return (
        <WizardShell
            open
            onClose={finishAndClose}
            title="Add a Site hub"
            description="Install a Windows or Linux hub at a Site to run local scans and checks, or cover additional Sites over SD-WAN."
            railIcon={RadioTower}
            railTitle="Add a hub"
            railSub={selectedSite?.name ?? 'Select home Site'}
            steps={WIZARD_STEPS.map((item, index) => ({
                ...item,
                disabled: busy || (index > 0 && !issued),
            }))}
            stepIndex={step}
            onStepClick={(next) => {
                if (busy) return;
                if (next === 0 || issued) setStep(next);
            }}
            pct={pct}
            pctLabel="Setup progress"
            footerStart={
                <>
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={finishAndClose}
                    >
                        {status?.state === 'connected' ? 'Close' : 'Cancel'}
                    </Button>
                    {step > 0 ? (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setStep(step - 1)}
                        >
                            Back
                        </Button>
                    ) : null}
                </>
            }
            footerEnd={
                step === 0 ? (
                    <Button
                        disabled={
                            busy || !selectedSite || name.trim().length < 2
                        }
                        onClick={() => void issueCode(false)}
                    >
                        {busy ? (
                            <Loader2 className="mr-1.5 size-4 animate-spin" />
                        ) : null}
                        {busy
                            ? 'Creating setup code…'
                            : issued && issuedFingerprint === currentFingerprint
                              ? 'Continue'
                              : 'Create one-time code'}
                    </Button>
                ) : step === 1 ? (
                    <Button onClick={() => setStep(2)}>
                        Continue to connection check
                    </Button>
                ) : status?.state === 'connected' ? (
                    <Button onClick={finishAndClose}>Done</Button>
                ) : (
                    <Button
                        variant="outline"
                        disabled={polling || !issued}
                        onClick={() =>
                            issued
                                ? void checkConnection(issued.enrollment_id)
                                : undefined
                        }
                    >
                        <RefreshCw
                            className={`mr-1.5 size-4 ${polling ? 'animate-spin' : ''}`}
                        />
                        Check now
                    </Button>
                )
            }
        >
            <WizardStepPane>
                <div className="space-y-5">
                    {error ? (
                        <Alert variant="destructive">
                            <AlertTitle>Hub setup problem</AlertTitle>
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    ) : null}

                    {status?.state === 'connected' && step < 2 ? (
                        <Alert>
                            <AlertTitle className="flex items-center gap-2">
                                <StatusBadge variant="success">
                                    Connected ✓
                                </StatusBadge>
                                <span>
                                    {status.collector?.name ?? name.trim()} is
                                    online
                                </span>
                            </AlertTitle>
                            <AlertDescription className="mt-2 flex flex-wrap items-center justify-between gap-2">
                                <span>
                                    First heartbeat received. Open the final
                                    step to review hub details.
                                </span>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setStep(2)}
                                >
                                    View connection
                                </Button>
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    {step === 0 ? (
                        <>
                            <div className="grid gap-5 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="hub-wizard-site">
                                        Home Site
                                    </Label>
                                    {availableSites.length <= 1 ? (
                                        <div
                                            id="hub-wizard-site"
                                            className="rounded-md border px-3 py-2 text-sm font-medium"
                                        >
                                            {selectedSite?.name ??
                                                'No accessible Site'}
                                        </div>
                                    ) : (
                                        <Popover
                                            open={sitePopoverOpen}
                                            onOpenChange={setSitePopoverOpen}
                                        >
                                            <PopoverTrigger asChild>
                                                <Button
                                                    id="hub-wizard-site"
                                                    type="button"
                                                    variant="outline"
                                                    role="combobox"
                                                    aria-expanded={
                                                        sitePopoverOpen
                                                    }
                                                    className="w-full justify-between font-normal"
                                                >
                                                    <span className="truncate">
                                                        {selectedSite?.name ??
                                                            'Select home Site…'}
                                                    </span>
                                                    <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
                                                </Button>
                                            </PopoverTrigger>
                                            <PopoverContent
                                                className="w-[320px] p-0"
                                                align="start"
                                            >
                                                <Command>
                                                    <CommandInput placeholder="Search Sites…" />
                                                    <CommandList>
                                                        <CommandEmpty>
                                                            No matching Site.
                                                        </CommandEmpty>
                                                        <CommandGroup>
                                                            {availableSites.map(
                                                                (item) => (
                                                                    <CommandItem
                                                                        key={
                                                                            item.id
                                                                        }
                                                                        value={
                                                                            item.name
                                                                        }
                                                                        onSelect={() =>
                                                                            selectHomeSite(
                                                                                item,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Check
                                                                            className={`size-4 ${
                                                                                String(
                                                                                    item.id,
                                                                                ) ===
                                                                                siteId
                                                                                    ? 'opacity-100'
                                                                                    : 'opacity-0'
                                                                            }`}
                                                                        />
                                                                        <span>
                                                                            {
                                                                                item.name
                                                                            }
                                                                        </span>
                                                                    </CommandItem>
                                                                ),
                                                            )}
                                                        </CommandGroup>
                                                    </CommandList>
                                                </Command>
                                            </PopoverContent>
                                        </Popover>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        Where this computer, server, NUC or Pi
                                        is physically installed.
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="hub-wizard-name">
                                        Hub name
                                    </Label>
                                    <Input
                                        id="hub-wizard-name"
                                        value={name}
                                        maxLength={120}
                                        placeholder="Main Office Hub"
                                        onChange={(event) => {
                                            setNameTouched(true);
                                            setName(event.target.value);
                                        }}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Shown as Runs from: hub{' '}
                                        {name.trim() || '…'} on scans and
                                        checks.
                                    </p>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Operating system</Label>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Button
                                        type="button"
                                        variant={
                                            platform === 'windows'
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                        aria-pressed={platform === 'windows'}
                                        className="h-auto min-h-24 flex-col items-start gap-1.5 p-4 text-left whitespace-normal"
                                        onClick={() => setPlatform('windows')}
                                    >
                                        <Laptop className="size-5" />
                                        <span className="font-semibold">
                                            Windows PC or server
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            Unsigned Windows setup wizard (x64
                                            and ARM64). Install Site monitoring
                                            alone or alongside Obli and computer
                                            agent.
                                        </span>
                                    </Button>

                                    <Button
                                        type="button"
                                        variant={
                                            platform === 'linux'
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                        aria-pressed={platform === 'linux'}
                                        className="h-auto min-h-24 flex-col items-start gap-1.5 p-4 text-left whitespace-normal"
                                        onClick={() => setPlatform('linux')}
                                    >
                                        <Cpu className="size-5" />
                                        <span className="font-semibold">
                                            Linux NUC or Raspberry Pi
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            Lightweight systemd service bundle
                                            for x86_64 NUCs and ARM64 Raspberry
                                            Pi appliances.
                                        </span>
                                    </Button>
                                </div>
                            </div>

                            {otherSites.length > 0 ? (
                                <div className="space-y-2">
                                    <Label>
                                        Additional Sites over SD-WAN or MPLS
                                        (optional)
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Tick any other Sites whose private
                                        subnets this hub can reach directly
                                        across your WAN.
                                    </p>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {otherSites.map((other) => {
                                            const checked =
                                                coveredSiteIds.includes(
                                                    other.id,
                                                );
                                            return (
                                                <label
                                                    key={other.id}
                                                    className="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                                                >
                                                    <Checkbox
                                                        checked={checked}
                                                        onCheckedChange={(
                                                            next,
                                                        ) =>
                                                            toggleCoveredSite(
                                                                other.id,
                                                                Boolean(next),
                                                            )
                                                        }
                                                    />
                                                    <span>{other.name}</span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </div>
                            ) : null}
                        </>
                    ) : null}

                    {step === 1 && issued ? (
                        <>
                            <ReviewCard
                                icon={Download}
                                title={
                                    platform === 'windows'
                                        ? '1. Download Windows installer'
                                        : '1. Download Linux service package'
                                }
                            >
                                {downloads.length > 0 ? (
                                    <div className="space-y-3">
                                        {downloads.map((artifact) => (
                                            <div
                                                key={artifact.id}
                                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                                            >
                                                <div className="min-w-0">
                                                    <p className="font-medium">
                                                        {(artifact.title ??
                                                            (artifact.platform ===
                                                            'windows'
                                                                ? 'Obli & Site Hub Setup'
                                                                : 'Oblivion Site Hub Service')) +
                                                            ' · ' +
                                                            artifact.architecture}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        Version{' '}
                                                        {artifact.version} ·{' '}
                                                        {formatBytes(
                                                            artifact.size_bytes ??
                                                                artifact.size ??
                                                                0,
                                                        )}{' '}
                                                        · {artifact.filename}
                                                    </p>
                                                </div>
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <a
                                                        href={
                                                            artifact.download_url
                                                        }
                                                    >
                                                        <Download className="mr-1.5 size-4" />
                                                        Download
                                                    </a>
                                                </Button>
                                            </div>
                                        ))}
                                        {platform === 'windows' ? (
                                            <p className="text-xs text-muted-foreground">
                                                Windows SmartScreen may ask to
                                                confirm running an unsigned
                                                installer: choose More info →
                                                Run anyway, tick Site
                                                monitoring, and paste the
                                                one-time code below.
                                            </p>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">
                                                Extract the archive on your
                                                Linux NUC or Raspberry Pi, then
                                                run the install command below.
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No signed installer package is published
                                        in the in-app catalogue yet. You can
                                        still connect an existing collector
                                        runtime using the one-time setup code
                                        below.
                                    </p>
                                )}
                            </ReviewCard>

                            <ReviewCard
                                icon={KeyRound}
                                title="2. Enter one-time setup code"
                            >
                                <div className="space-y-3">
                                    <p className="text-sm text-muted-foreground">
                                        This code works once and expires at{' '}
                                        {formatDateTime(issued.expires_at)} NZ
                                        time.
                                    </p>
                                    {!issued.profile_configured &&
                                    issued.profile_missing_reason ? (
                                        <Alert>
                                            <AlertTitle>
                                                Enrolment token issued
                                            </AlertTitle>
                                            <AlertDescription>
                                                {issued.profile_missing_reason}{' '}
                                                Use the one-time token below
                                                with your collector server
                                                settings.
                                            </AlertDescription>
                                        </Alert>
                                    ) : null}

                                    <div className="space-y-1.5">
                                        <Label htmlFor="hub-setup-code">
                                            One-time setup code
                                        </Label>
                                        <div className="flex flex-wrap gap-2">
                                            <Input
                                                id="hub-setup-code"
                                                readOnly
                                                value={issued.setup_code}
                                                className="min-w-0 flex-1 font-mono text-xs"
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    void copyValue(
                                                        'code',
                                                        issued.setup_code,
                                                    )
                                                }
                                            >
                                                <Copy className="mr-1.5 size-4" />
                                                {copiedField === 'code'
                                                    ? 'Copied'
                                                    : 'Copy code'}
                                            </Button>
                                        </div>
                                    </div>

                                    {platform === 'linux' &&
                                    issued.linux_command ? (
                                        <div className="space-y-1.5">
                                            <Label htmlFor="hub-linux-command">
                                                Linux install command
                                            </Label>
                                            <div className="flex flex-wrap gap-2">
                                                <Input
                                                    id="hub-linux-command"
                                                    readOnly
                                                    value={issued.linux_command}
                                                    className="min-w-0 flex-1 font-mono text-xs"
                                                />
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        void copyValue(
                                                            'linux',
                                                            issued.linux_command!,
                                                        )
                                                    }
                                                >
                                                    <Copy className="mr-1.5 size-4" />
                                                    {copiedField === 'linux'
                                                        ? 'Copied'
                                                        : 'Copy command'}
                                                </Button>
                                            </div>
                                        </div>
                                    ) : null}

                                    <details className="rounded-lg border p-3 text-xs">
                                        <summary className="cursor-pointer font-medium">
                                            Advanced: raw enrolment token
                                        </summary>
                                        <div className="mt-2 flex flex-wrap items-center gap-2">
                                            <Input
                                                readOnly
                                                aria-label="Raw enrolment token"
                                                value={issued.enrolment_token}
                                                className="min-w-0 flex-1 font-mono text-xs"
                                            />
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    void copyValue(
                                                        'token',
                                                        issued.enrolment_token,
                                                    )
                                                }
                                            >
                                                {copiedField === 'token'
                                                    ? 'Copied'
                                                    : 'Copy token'}
                                            </Button>
                                        </div>
                                    </details>
                                </div>
                            </ReviewCard>
                        </>
                    ) : null}

                    {step === 2 && issued ? (
                        <ReviewCard
                            icon={Network}
                            title="3. Hub connection status"
                        >
                            <div className="space-y-4">
                                <div className="flex flex-wrap items-center gap-3">
                                    {status?.state === 'connected' ? (
                                        <StatusBadge variant="success">
                                            Connected ✓
                                        </StatusBadge>
                                    ) : status?.state === 'enrolled' ? (
                                        <StatusBadge variant="info">
                                            Enrolled · waiting for first
                                            heartbeat
                                        </StatusBadge>
                                    ) : status?.state === 'expired' ? (
                                        <StatusBadge variant="warning">
                                            Setup code expired
                                        </StatusBadge>
                                    ) : status?.state === 'revoked' ? (
                                        <StatusBadge variant="critical">
                                            Enrolment revoked
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge variant="neutral">
                                            <Loader2 className="mr-1 size-3 animate-spin" />
                                            Waiting for hub…
                                        </StatusBadge>
                                    )}
                                </div>

                                <ReviewRow
                                    label="Hub name"
                                    value={
                                        status?.collector?.name ?? name.trim()
                                    }
                                />
                                <ReviewRow
                                    label="Home Site"
                                    value={selectedSite?.name ?? 'Unavailable'}
                                />
                                <ReviewRow
                                    label="Additional SD-WAN Sites"
                                    value={
                                        coveredSiteIds.length > 0
                                            ? otherSites
                                                  .filter((item) =>
                                                      coveredSiteIds.includes(
                                                          item.id,
                                                      ),
                                                  )
                                                  .map((item) => item.name)
                                                  .join(', ')
                                            : 'None'
                                    }
                                />
                                <ReviewRow
                                    label="Software version"
                                    value={
                                        status?.collector?.software_version ??
                                        'Pending first heartbeat'
                                    }
                                />
                                <ReviewRow
                                    label="Last heartbeat"
                                    value={
                                        status?.collector?.last_heartbeat_at
                                            ? formatDateTime(
                                                  status.collector
                                                      .last_heartbeat_at,
                                              )
                                            : 'Not received yet'
                                    }
                                />

                                {status?.state === 'connected' ? (
                                    <p className="text-sm text-muted-foreground">
                                        This hub is connected and ready. You can
                                        now assign discovery scans and device
                                        checks to run from{' '}
                                        <strong>
                                            hub{' '}
                                            {status.collector?.name ??
                                                name.trim()}
                                        </strong>
                                        .
                                    </p>
                                ) : status?.state === 'expired' ||
                                  status?.state === 'revoked' ? (
                                    <div className="flex flex-wrap items-center gap-3">
                                        <p className="text-sm text-muted-foreground">
                                            Create a new one-time setup code to
                                            finish connecting this hub.
                                        </p>
                                        <Button
                                            size="sm"
                                            onClick={() => void issueCode(true)}
                                        >
                                            Issue new code
                                        </Button>
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Leave this window open while the
                                        installer finishes. Status refreshes
                                        every few seconds automatically.
                                    </p>
                                )}
                            </div>
                        </ReviewCard>
                    ) : null}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}
