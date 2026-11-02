/**
 * The hub pop-ups (S&D rebuild drawings sdr-12, 13, 14, 23, 73, 74 and 79), on the pop-up kit and
 * WizardShell. They use the hub routes in HubAdminController (setup codes, approve, remove,
 * replace, edit, "Check now", a computer install) and the hub panel's read (HubPanelPresenter).
 * Networks and the first scan use the Found devices routes, as Add a network and Scan a network
 * do. Nothing here offers a feature that isn't built.
 */
import { VehicleSearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import {
    DiscardDraftConfirm,
    PopupCallout,
    PopupConfirm,
    PopupFacts,
    PopupField,
    PopupFooter,
    PopupForm,
    PopupLens,
    PopupSection,
    usePopupClose,
} from '@/components/popup';
import { ChoiceTiles, type ChoiceTile } from '@/components/security-devices/device-popups';
import { ScanningViewer } from '@/components/security-devices/scan-popups';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ErrorState } from '@/components/ui/error-state';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { SkeletonCard } from '@/components/ui/skeleton-card';
import { StatusBadge } from '@/components/ui/status-badge';
import { WizardShell, WizardStepPane, WizardSuccessPane, type WizardStep } from '@/components/wizard/shell';
import { formatDateTime, formatTime } from '@/lib/datetime';
import { agoWords, durationWords, seenTime } from '@/lib/security-devices/device-time';
import { cn } from '@/lib/utils';
import type { DeviceFormData } from '@/pages/security-devices/devices/_types';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    AppWindow,
    Check,
    CircleCheck,
    Copy,
    Cpu,
    Download,
    Hand,
    History,
    House,
    Link as LinkIcon,
    ListChecks,
    LoaderCircle,
    MapPin,
    Monitor,
    Network,
    Package,
    Pencil,
    PlugZap,
    Radar,
    RefreshCw,
    Replace,
    Server,
    ServerOff,
    ShieldCheck,
    SquareTerminal,
    Tag,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Shared words and bits                                              */
/* ------------------------------------------------------------------ */

export type HubPlatform = 'windows' | 'linux';

type FormSite = DeviceFormData['sites'][number];

const JSON_HEADERS = { headers: { Accept: 'application/json' } };

const PLATFORM_TILES: ChoiceTile<HubPlatform>[] = [
    { value: 'windows', label: 'Windows PC or server', description: 'Windows 10 or 11, or Windows Server', icon: Monitor },
    { value: 'linux', label: 'Raspberry Pi or NUC (Linux)', description: 'A Raspberry Pi 4 or 5, or a small Linux computer', icon: Cpu },
];

export function platformWords(platform: HubPlatform | null | undefined): string {
    if (platform === 'windows') return 'Windows PC or server';
    if (platform === 'linux') return 'Raspberry Pi or NUC (Linux)';
    return 'Not available';
}

function computerWord(platform: HubPlatform | null | undefined): string {
    return platform === 'linux' ? 'Raspberry Pi or NUC' : 'computer';
}

/** "6:00 am" for a stored "06:00". */
export function clockWords(value: string | null | undefined): string {
    if (!value || !/^\d{2}:\d{2}/.test(value)) return '—';
    const [hour, minute] = value.split(':').map(Number);
    return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour >= 12 ? 'pm' : 'am'}`;
}

/** "Aurora House", "Aurora House and Kōwhai Lodge", "A, B and C". */
function listWords(names: string[]): string {
    if (names.length <= 1) return names[0] ?? '';
    return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** A failed call keeps every entry and says what went wrong in plain words. */
function errorsOf(error: unknown, lost = 'Result not confirmed. Check the hub before trying again.'): Record<string, string> {
    if (!axios.isAxiosError(error)) return { form: 'It didn’t work. Try again.' };
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    if (data?.errors) {
        return Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]]));
    }
    if (error.response?.status === 403) return { form: 'You can’t do this here.' };
    if (error.response?.status === 409) return { form: 'This hub has already been removed.' };
    if (!error.response) return { form: lost };
    return { form: 'It didn’t work. Try again.' };
}

function firstError(errors: Record<string, string>): string | undefined {
    return errors.form ?? Object.values(errors)[0];
}

/** Start a download without leaving the page. */
function startDownload(url: string, fileName?: string | null) {
    const link = document.createElement('a');
    link.href = url;
    if (fileName) link.download = fileName;
    link.rel = 'noopener';
    document.body.appendChild(link);
    link.click();
    link.remove();
}

function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
                void navigator.clipboard
                    ?.writeText(text)
                    .then(() => {
                        setCopied(true);
                        window.setTimeout(() => setCopied(false), 2000);
                    })
                    .catch(() => undefined);
            }}
        >
            {copied ? <Check aria-hidden="true" /> : <Copy aria-hidden="true" />}
            {copied ? 'Copied' : label}
        </Button>
    );
}

/** A read-only value with a Copy button beside it. */
function CopyRow({ id, value, copyLabel, mono }: { id: string; value: string; copyLabel?: string; mono?: boolean }) {
    return (
        <div className="flex items-center gap-2">
            <Input id={id} readOnly value={value} className={cn('min-w-0 flex-1', mono && 'font-mono text-xs')} onFocus={(event) => event.currentTarget.select()} />
            <CopyButton text={value} label={copyLabel} />
        </div>
    );
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

/** Info cards that show what's coming (not choices). */
function InfoTiles({ items }: { items: { icon: LucideIcon; title: string; line: string }[] }) {
    return (
        <ol className="grid gap-2 sm:grid-cols-3">
            {items.map((item) => {
                const Icon = item.icon;
                return (
                    <li key={item.title} className="flex items-start gap-3 rounded-xl border border-border p-3">
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                            <Icon className="size-4" aria-hidden="true" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold">{item.title}</span>
                            <span className="text-caption block">{item.line}</span>
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

/** A segmented choice (pop-up kit), as Add a network draws it. */
function Segmented<K extends string>({
    label,
    value,
    options,
    onChange,
    disabled,
}: {
    label: string;
    value: K;
    options: { value: K; label: string; disabled?: boolean }[];
    onChange: (value: K) => void;
    disabled?: boolean;
}) {
    return (
        <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-2">
            {options.map((option) => (
                // eslint-disable-next-line no-restricted-syntax -- a segmented choice (pop-up kit), not a form button.
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={value === option.value}
                    disabled={disabled || option.disabled}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'inline-flex min-h-9 items-center rounded-lg border px-3 py-1.5 text-left text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                        value === option.value ? 'border-primary bg-primary-fill/10 text-primary' : 'border-border bg-card',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

/** One tick-box row with its hint, as the drawings list Sites and networks. */
function TickRow({
    id,
    label,
    hint,
    checked,
    disabled,
    onChange,
    children,
}: {
    id: string;
    label: string;
    hint?: ReactNode;
    checked: boolean;
    disabled?: boolean;
    onChange: (checked: boolean) => void;
    children?: ReactNode;
}) {
    return (
        <div className="flex items-start gap-2.5">
            <Checkbox id={id} checked={checked} disabled={disabled} onCheckedChange={(next) => onChange(next === true)} className="mt-0.5" />
            <div className="min-w-0 flex-1">
                <label htmlFor={id} className={cn('block text-sm font-medium', disabled && 'text-muted-foreground')}>
                    {label}
                </label>
                {hint ? <p className="text-caption">{hint}</p> : null}
                {children}
            </div>
        </div>
    );
}

const RANGE = /^[0-9a-fA-F:.]+\/\d{1,3}$/;

/* ------------------------------------------------------------------ */
/*  Server shapes                                                      */
/* ------------------------------------------------------------------ */

type IssuedCode = {
    setup_code_id: number;
    code: string;
    platform: HubPlatform;
    expires_at: string;
    file_name: string | null;
    download_url: string | null;
    share_link: string | null;
    linux_command: string | null;
};

/** HubAdminController::hubPayload. */
type HubStatus = {
    id: number;
    name: string;
    site_id: number;
    covered_site_ids: number[];
    state: string;
    software_version: string | null;
    last_heartbeat_at: string | null;
    approved_at: string | null;
};

type SetupStatus = {
    setup_code_id: number;
    code_state: 'waiting' | 'used' | 'expired' | 'cancelled';
    expires_at: string;
    hub: HubStatus | null;
};

export type HubPanelState = 'online' | 'not_answering' | 'starting' | 'waiting_for_approval' | 'removed';

/** HubPanelPresenter. */
export type HubPanelData = {
    hub: {
        id: number;
        name: string;
        label: string;
        site: { id: number; name: string };
        platform: HubPlatform | null;
        state: HubPanelState;
        heard_at: string | null;
        version: string | null;
        installed: { by: string | null; at: string | null };
        replaces: string | null;
        removed_at: string | null;
    };
    updates: {
        ring: 'everyone' | 'early';
        window_start: string;
        window_end: string;
        last: { outcome: 'installed' | 'rolled_back' | 'failed'; from: string | null; to: string | null; at: string | null } | null;
        check_requested_at: string | null;
        held: boolean;
        held_at: string | null;
        /** A hub that isn't test-first: what the newest release waits for (Stephan, 3 Nov: "Wait for the test hub"). */
        waiting: { version: string; state: 'day' | 'waiting' | 'held' | 'ready'; ready_at: string; test_hub: string | null } | null;
    };
    watching: { devices: number; sites: number; cant_check: number };
    sites: { id: number; name: string; own: boolean }[];
    networks: { key: string; site: string; own: boolean; range: string | null; daily_at: string | null }[];
    events: { key: string; at: string; tone: string; text: string }[];
    can: { edit: boolean; approve: boolean; remove: boolean; replace: boolean; check_update: boolean };
};

/** What the approve, remove and replace pop-ups need to say about a hub. */
export type HubTarget = {
    id: number;
    label: string;
    platform: HubPlatform | null;
    sites: string[];
    devices: number | null;
    stateWord?: string;
    installedBy?: string | null;
    heardAt?: string | null;
    replaces?: string | null;
};

function targetOf(data: HubPanelData): HubTarget {
    return {
        id: data.hub.id,
        label: data.hub.label,
        platform: data.hub.platform,
        sites: data.sites.map((site) => site.name),
        devices: data.watching.devices,
        stateWord: stateBadge(data.hub.state, data.hub.heard_at, new Date()).short,
        installedBy: data.hub.installed.by,
        heardAt: data.hub.installed.at ?? data.hub.heard_at,
        replaces: data.hub.replaces,
    };
}

function stateBadge(state: HubPanelState, heardAt: string | null, now: Date): { tone: 'success' | 'critical' | 'warning' | 'neutral'; text: string; short: string } {
    switch (state) {
        case 'online':
            return { tone: 'success', text: heardAt ? `Online · heard ${agoWords(heardAt, now)}` : 'Online', short: 'Online' };
        case 'not_answering':
            return { tone: 'critical', text: heardAt ? `Offline · last heard ${seenTime(heardAt, now)}` : 'Offline', short: 'Offline' };
        case 'waiting_for_approval':
            return { tone: 'warning', text: 'Waiting for approval', short: 'Waiting for approval' };
        case 'removed':
            return { tone: 'neutral', text: 'Removed', short: 'Removed' };
        default:
            return { tone: 'neutral', text: 'Approved · waiting for its first check-in', short: 'Approved' };
    }
}

/* ------------------------------------------------------------------ */
/*  79 · Approve hub                                                   */
/* ------------------------------------------------------------------ */

export function ApproveHub({
    open,
    hub,
    installedFromYou,
    canRemove,
    onClose,
    onApproved,
    onRemove,
}: {
    open: boolean;
    hub: HubTarget | null;
    /** Opened from Add a hub: the download was the viewer's own. */
    installedFromYou?: boolean;
    canRemove: boolean;
    onClose: () => void;
    onApproved: (hub: HubStatus) => void;
    onRemove: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();
    useEffect(() => {
        if (open) setError(undefined);
    }, [open]);

    const heard = hub?.heardAt ? ` · said hello at ${seenTime(hub.heardAt)}` : '';
    const installed = installedFromYou ? `From your download${heard}` : hub?.installedBy ? `From ${hub.installedBy}’s download${heard}` : `Not available${heard}`;

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={() => {
                if (!hub) return;
                setProcessing(true);
                axios
                    .post<{ hub: HubStatus }>(`/security-devices/hubs/${hub.id}/approve`, {}, JSON_HEADERS)
                    .then(({ data }) => onApproved(data.hub))
                    .catch((failure) => setError(firstError(errorsOf(failure))))
                    .finally(() => setProcessing(false));
            }}
            variant="warning"
            icon={ShieldCheck}
            title={`Approve ${hub?.label ?? 'this hub'}?`}
            description={
                <>
                    Until you approve it, it can’t receive any saved logins. Once approved, it gets the logins for{' '}
                    {hub?.sites.length ? listWords(hub.sites) : 'its Sites'} only, encrypted on the hub. Removing it wipes them.
                </>
            }
            error={error}
            confirmLabel="Approve hub"
            processingLabel="Approving…"
            cancelLabel="Later"
            processing={processing}
            footerStart={
                canRemove ? (
                    <Button type="button" variant="ghost" className="text-status-critical hover:text-status-critical" onClick={onRemove} disabled={processing}>
                        Remove it
                    </Button>
                ) : undefined
            }
            testId="sd-approve-hub"
        >
            <PopupFacts
                rows={[
                    ['Computer', platformWords(hub?.platform)],
                    ['Installed', installed],
                ]}
            />
            {hub?.replaces ? (
                <PopupCallout tone="info">
                    Once approved, it takes over from {hub.replaces}. That hub is then removed and its saved logins are wiped.
                </PopupCallout>
            ) : null}
            {canRemove ? <PopupCallout tone="info">Not expecting a new hub? Remove it.</PopupCallout> : null}
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  14 · Remove hub                                                    */
/* ------------------------------------------------------------------ */

export function RemoveHub({
    open,
    hub,
    onClose,
    onRemoved,
}: {
    open: boolean;
    hub: HubTarget | null;
    onClose: () => void;
    onRemoved: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();
    useEffect(() => {
        if (open) setError(undefined);
    }, [open]);

    const sites = hub?.sites ?? [];
    const what =
        hub?.devices && hub.devices > 0
            ? `${plural(hub.devices, 'device', 'devices')} at ${listWords(sites)}`
            : sites.length > 0
              ? `the devices at ${listWords(sites)}`
              : 'its devices';

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={() => {
                if (!hub) return;
                setProcessing(true);
                axios
                    .post(`/security-devices/hubs/${hub.id}/remove`, {}, JSON_HEADERS)
                    .then(() => onRemoved())
                    .catch((failure) => setError(firstError(errorsOf(failure))))
                    .finally(() => setProcessing(false));
            }}
            variant="destructive"
            icon={ServerOff}
            title={`Remove ${hub?.label ?? 'this hub'}?`}
            description={
                <>
                    <b className="font-semibold text-foreground">{hub?.label}</b> will stop checking {what}. The devices and their history stay in Oblivion.
                </>
            }
            error={error}
            confirmLabel="Remove hub"
            processingLabel="Removing…"
            processing={processing}
            testId="sd-remove-hub"
        >
            <PopupCallout tone="warning">
                Until another hub covers {sites.length === 1 ? 'this Site' : 'these Sites'}, their devices show Can’t check.
            </PopupCallout>
            <PopupCallout tone="info">
                Its setup key stops working at once. Uninstall Oblivion from the {computerWord(hub?.platform)} when you’re ready.
            </PopupCallout>
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  73 · Replace hub                                                   */
/* ------------------------------------------------------------------ */

export function ReplaceHub({ open, hub, onClose }: { open: boolean; hub: HubTarget | null; onClose: () => void }) {
    const [platform, setPlatform] = useState<HubPlatform>('windows');
    const [issued, setIssued] = useState<IssuedCode | null>(null);
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    useEffect(() => {
        if (open) {
            setPlatform(hub?.platform ?? 'windows');
            setIssued(null);
            setError(undefined);
        }
    }, [open, hub?.platform]);

    const devices = hub?.devices && hub.devices > 0 ? plural(hub.devices, 'device', 'devices') : 'its devices';

    const submit = () => {
        if (!hub) return;
        if (issued) {
            if (issued.download_url) startDownload(issued.download_url, issued.file_name);
            else if (issued.linux_command) void navigator.clipboard?.writeText(issued.linux_command).catch(() => undefined);
            return;
        }
        setProcessing(true);
        setError(undefined);
        axios
            .post<IssuedCode>(`/security-devices/hubs/${hub.id}/replace`, { platform }, JSON_HEADERS)
            .then(({ data }) => {
                setIssued(data);
                if (data.download_url) startDownload(data.download_url, data.file_name);
            })
            .catch((failure) => setError(firstError(errorsOf(failure))))
            .finally(() => setProcessing(false));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Replace}
            title={`Replace ${hub?.label ?? 'this hub'} with a new computer`}
            description="One download for the new computer. Nothing to set up again."
            context={
                hub
                    ? {
                          title: hub.label,
                          tag: hub.stateWord,
                          note: [platformWords(hub.platform), hub.devices !== null ? `${plural(hub.devices, 'device', 'devices')} at ${plural(hub.sites.length, 'Site', 'Sites')}` : null]
                              .filter(Boolean)
                              .join(' · '),
                          icon: Server,
                      }
                    : undefined
            }
            error={error}
            submitLabel={issued ? (issued.download_url ? 'Download again' : 'Copy command') : 'Download for the new computer'}
            processingLabel="Making your download…"
            processing={processing}
            cancelLabel={issued ? 'Close' : 'Cancel'}
            onSubmit={submit}
            testId="sd-replace-hub"
        >
            <PopupSection icon={Monitor} title="New computer">
                <PopupField label="What will run it" required>
                    <ChoiceTiles label="What will run it" columns={2} options={PLATFORM_TILES} value={platform} onChange={(next) => !issued && setPlatform(next)} />
                </PopupField>
                {issued?.file_name ? (
                    <PopupField label="File name" htmlFor="sd-replace-file" hint={`Windows · works once, stops ${formatDateTime(issued.expires_at)}`}>
                        <CopyRow id="sd-replace-file" value={issued.file_name} />
                    </PopupField>
                ) : issued?.linux_command ? (
                    <PopupField label="One command to copy" htmlFor="sd-replace-command" hint={`Run it on the new Raspberry Pi or NUC · works once, stops ${formatDateTime(issued.expires_at)}`}>
                        <CopyRow id="sd-replace-command" value={issued.linux_command} mono />
                    </PopupField>
                ) : (
                    <PopupField label={platform === 'linux' ? 'One command to copy' : 'File name'}>
                        <p className="text-subtle">Made for this hub when you download · works once, stops after 24 hours</p>
                    </PopupField>
                )}
            </PopupSection>
            <PopupSection icon={ListChecks} title="What happens">
                <PopupFacts
                    rows={[
                        ['Until then', 'The old hub keeps working as it is'],
                        ['New hub says hello', 'You approve it, as with any new hub'],
                        ['Once approved', `It takes over ${devices}, their checks and history`],
                        ['Then', 'The old hub is removed and its saved logins are wiped'],
                    ]}
                />
            </PopupSection>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  74 · Edit hub                                                      */
/* ------------------------------------------------------------------ */

export function EditHub({
    open,
    panel,
    form,
    onClose,
    onSaved,
}: {
    open: boolean;
    panel: HubPanelData | null;
    form: DeviceFormData | undefined;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [name, setName] = useState('');
    const [covered, setCovered] = useState<number[]>([]);
    const [ring, setRing] = useState<'everyone' | 'early'>('everyone');
    const [start, setStart] = useState('02:00');
    const [end, setEnd] = useState('05:00');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [checkAsked, setCheckAsked] = useState<string | null>(null);
    const [checking, setChecking] = useState(false);
    const [initial, setInitial] = useState('');

    useEffect(() => {
        if (!open || !panel) return;
        const own = panel.sites.filter((site) => !site.own).map((site) => site.id);
        setName(panel.hub.name);
        setCovered(own);
        setRing(panel.updates.ring);
        setStart(panel.updates.window_start);
        setEnd(panel.updates.window_end);
        setErrors({});
        setCheckAsked(panel.updates.check_requested_at);
        setInitial(JSON.stringify([panel.hub.name, [...own].sort(), panel.updates.ring, panel.updates.window_start, panel.updates.window_end]));
    }, [open, panel]);

    const current = JSON.stringify([name, [...covered].sort(), ring, start, end]);
    const dirty = open && !!panel && initial !== '' && current !== initial;
    const homeId = panel?.hub.site.id;
    const homeName = panel?.hub.site.name ?? 'its Site';
    // Sites it can cover: the ones you can see, with the ones it covers now first.
    const options: FormSite[] = useMemo(() => {
        const now = new Set((panel?.sites ?? []).map((site) => site.id));
        return [...(form?.sites ?? [])].filter((site) => site.id !== homeId).sort((a, b) => Number(now.has(b.id)) - Number(now.has(a.id)));
    }, [form, homeId, panel]);

    const submit = () => {
        if (!panel) return;
        const next: Record<string, string> = {};
        if (name.trim().length < 2) next.name = 'Give the hub a name.';
        if (start === end) next.update_window_end = 'Pick two different times.';
        setErrors(next);
        if (Object.keys(next).length > 0) return;
        setProcessing(true);
        axios
            .patch(
                `/security-devices/hubs/${panel.hub.id}`,
                { name: name.trim(), covered_site_ids: covered, update_ring: ring, update_window_start: start, update_window_end: end },
                JSON_HEADERS,
            )
            .then(() => onSaved())
            .catch((failure) => setErrors(errorsOf(failure)))
            .finally(() => setProcessing(false));
    };

    const checkNow = () => {
        if (!panel) return;
        setChecking(true);
        axios
            .post<{ hub: { update_check_requested_at: string | null } }>(`/security-devices/hubs/${panel.hub.id}/check-update`, {}, JSON_HEADERS)
            .then(({ data }) => setCheckAsked(data.hub.update_check_requested_at ?? new Date().toISOString()))
            .catch((failure) => setErrors(errorsOf(failure)))
            .finally(() => setChecking(false));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Pencil}
            title="Edit hub"
            description="Its name, the Sites it covers and when it updates."
            context={
                panel
                    ? {
                          title: panel.hub.label,
                          tag: stateBadge(panel.hub.state, panel.hub.heard_at, new Date()).short,
                          note: [platformWords(panel.hub.platform), panel.hub.version ? `version ${panel.hub.version}` : null].filter(Boolean).join(' · '),
                          icon: Server,
                      }
                    : undefined
            }
            error={errors.form ?? errors.covered_site_ids ?? errors.update_ring}
            submitLabel="Save changes"
            processing={processing}
            submitDisabled={!panel}
            dirty={dirty}
            draftName="Edit hub"
            onSubmit={submit}
            testId="sd-edit-hub"
        >
            <PopupSection icon={Tag} title="Name">
                <PopupField label="Name" htmlFor="sd-edit-hub-name" required error={errors.name}>
                    <Input id="sd-edit-hub-name" value={name} maxLength={120} onChange={(event) => setName(event.target.value)} />
                </PopupField>
            </PopupSection>
            <PopupSection icon={Network} title="Sites it covers">
                <TickRow id={`sd-edit-hub-site-${homeId ?? 0}`} label={homeName} hint="its own network · always covered" checked disabled onChange={() => undefined} />
                {!form ? (
                    <p className="text-subtle" role="status">
                        Getting your Sites…
                    </p>
                ) : (
                    options.map((site) => (
                        <TickRow
                            key={site.id}
                            id={`sd-edit-hub-site-${site.id}`}
                            label={site.name}
                            hint={[site.suggested_range, `over the link to ${homeName}`].filter(Boolean).join(' · ')}
                            checked={covered.includes(site.id)}
                            onChange={(on) => setCovered((now) => (on ? [...now, site.id] : now.filter((id) => id !== site.id)))}
                        />
                    ))
                )}
            </PopupSection>
            <PopupSection icon={RefreshCw} title="Updates" columns={2}>
                <PopupField label="Gets updates" wide>
                    <Segmented
                        label="Gets updates"
                        value={ring}
                        onChange={setRing}
                        options={[
                            { value: 'everyone', label: 'With everyone' },
                            { value: 'early', label: 'Test first: gets updates first; the others wait for it' },
                        ]}
                    />
                </PopupField>
                <PopupField label="Update between" htmlFor="sd-edit-hub-start">
                    <TimePicker id="sd-edit-hub-start" label="Update between" value={start} onChange={setStart} />
                </PopupField>
                <PopupField label="And" htmlFor="sd-edit-hub-end" error={errors.update_window_end}>
                    <TimePicker id="sd-edit-hub-end" label="And" value={end} onChange={setEnd} invalid={!!errors.update_window_end} />
                </PopupField>
                <PopupCallout tone="info" className="sm:col-span-full">
                    Updates install themselves in this window. If one fails, the hub goes back to the version before.
                </PopupCallout>
                {panel?.can.check_update ? (
                    <div className="flex flex-wrap items-center gap-3 sm:col-span-full">
                        <Button type="button" variant="outline" onClick={checkNow} disabled={checking}>
                            <RefreshCw aria-hidden="true" />
                            {checking ? 'Asking…' : 'Check now'}
                        </Button>
                        <span className="text-caption">
                            {checkAsked
                                ? `Asked at ${seenTime(checkAsked)}. It looks for its update the next time it checks in.`
                                : 'Looks for its update the next time it checks in, without waiting for the night.'}
                        </span>
                    </div>
                ) : null}
            </PopupSection>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  13 · The hub panel                                                 */
/* ------------------------------------------------------------------ */

type PanelTab = 'status' | 'networks' | 'updates' | 'activity';

const PANEL_TABS: { key: PanelTab; label: string }[] = [
    { key: 'status', label: 'Status' },
    { key: 'networks', label: 'Networks' },
    { key: 'updates', label: 'Updates' },
    { key: 'activity', label: 'Activity' },
];

/** Plain label / value rows, as the side panel draws them. */
function PanelFacts({ rows }: { rows: ReadonlyArray<readonly [string, ReactNode]> }) {
    return (
        <dl className="grid grid-cols-[minmax(0,8.5rem)_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
            {rows.map(([label, value], index) => (
                <div key={`${label}-${index}`} className="contents">
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd className="min-w-0 break-words text-foreground">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function networkRow(network: HubPanelData['networks'][number], home: string): readonly [string, ReactNode] {
    if (!network.range) return [network.site, 'No network added yet'] as const;
    return [network.site, `${network.range} · ${network.own ? 'its own network' : `over the link to ${home}`}`] as const;
}

function updateOutcome(last: HubPanelData['updates']['last']): string | null {
    if (!last) return null;
    if (last.outcome === 'installed') return last.to ? `Updated to ${last.to}` : 'Updated';
    if (last.outcome === 'rolled_back') return `Update${last.to ? ` to ${last.to}` : ''} didn’t work, so it went back${last.from ? ` to ${last.from}` : ''}`;
    return `Update${last.to ? ` to ${last.to}` : ''} didn’t install${last.from ? `, still on ${last.from}` : ''}`;
}

/** What the newest release waits for, for a hub that isn't test-first. */
function waitingWords(waiting: NonNullable<HubPanelData['updates']['waiting']>): string {
    const test = waiting.test_hub ?? 'the test hub';
    switch (waiting.state) {
        case 'held':
            return `Stays on its version: ${waiting.version} failed on ${test}. It goes once a test hub has ${waiting.version} working.`;
        case 'waiting':
            return `Waits for ${test} to have ${waiting.version} working first`;
        case 'day':
            return `${waiting.version} from ${formatDateTime(waiting.ready_at)}, a day after it came out`;
        default:
            return `${waiting.version} in its next update window`;
    }
}

export function HubPanel({
    open,
    hubId,
    form,
    onClose,
    onChanged,
}: {
    open: boolean;
    hubId: number | null;
    form: DeviceFormData | undefined;
    onClose: () => void;
    /** After an approve, edit, replace or remove, so the page can refresh. */
    onChanged: () => void;
}) {
    const [data, setData] = useState<HubPanelData | null>(null);
    const [failure, setFailure] = useState<'failed' | 'forbidden' | null>(null);
    const [reload, setReload] = useState(0);
    const [tab, setTab] = useState<PanelTab>('status');
    const [child, setChild] = useState<'approve' | 'remove' | 'replace' | 'edit' | null>(null);
    const now = new Date();
    const tabRefs = useRef<(HTMLButtonElement | null)[]>([]);

    useEffect(() => {
        if (open) {
            setTab('status');
            setChild(null);
        } else {
            setData(null);
        }
    }, [open, hubId]);

    useEffect(() => {
        if (!open || !hubId) return;
        let cancelled = false;
        setFailure(null);
        axios
            .get<HubPanelData>(`/security-devices/hubs/${hubId}/panel`, JSON_HEADERS)
            .then(({ data: next }) => {
                if (!cancelled) setData(next);
            })
            .catch((error) => {
                if (cancelled) return;
                const status = axios.isAxiosError(error) ? error.response?.status : undefined;
                setFailure(status === 403 || status === 404 ? 'forbidden' : 'failed');
            });
        return () => {
            cancelled = true;
        };
    }, [open, hubId, reload]);

    const changed = () => {
        setChild(null);
        setReload((n) => n + 1);
        onChanged();
    };

    const hub = data?.hub;
    const badge = hub ? stateBadge(hub.state, hub.heard_at, now) : null;
    const target = data ? targetOf(data) : null;
    const lastUpdate = data ? updateOutcome(data.updates.last) : null;

    const moveTab = (index: number) => {
        const next = PANEL_TABS[(index + PANEL_TABS.length) % PANEL_TABS.length];
        setTab(next.key);
        tabRefs.current[(index + PANEL_TABS.length) % PANEL_TABS.length]?.focus();
    };

    let body: ReactNode;
    if (failure === 'forbidden') {
        body = <p className="text-subtle">You can’t see this hub.</p>;
    } else if (failure === 'failed') {
        body = <ErrorState title="Couldn’t load the hub" message="Check your connection, then try again." onRetry={() => setReload((n) => n + 1)} />;
    } else if (!data || !hub) {
        body = (
            <div role="status" aria-label="Loading the hub">
                <SkeletonCard rows={5} />
            </div>
        );
    } else if (tab === 'status') {
        body = (
            <div className="grid gap-4">
                {hub.state === 'not_answering' ? (
                    <PopupCallout tone="critical">
                        <b className="font-semibold">
                            {hub.label} last heard {hub.heard_at ? seenTime(hub.heard_at, now) : 'Not available'}.
                        </b>{' '}
                        {data.watching.devices > 0
                            ? `Its ${plural(data.watching.devices, 'device', 'devices')} show “Can’t check” rather than offline.`
                            : 'The devices it checks show “Can’t check” rather than offline.'}
                    </PopupCallout>
                ) : null}
                {hub.state === 'waiting_for_approval' ? (
                    <PopupCallout tone="warning">Until you approve it, the hub can’t receive any saved logins.</PopupCallout>
                ) : null}
                {hub.state === 'removed' ? (
                    <PopupCallout tone="info">
                        Removed{hub.removed_at ? ` ${formatDateTime(hub.removed_at)}` : ''}. It no longer checks anything and the saved logins it held were wiped.
                    </PopupCallout>
                ) : null}
                {hub.state === 'not_answering' ? (
                    <PanelFacts
                        rows={[
                            ['Last heard', hub.heard_at ? `${seenTime(hub.heard_at, now)} · ${durationWords(hub.heard_at, now)} ago` : 'Not available'],
                            ['Devices', data.watching.devices > 0 ? `${data.watching.devices} at ${plural(data.watching.sites, 'Site', 'Sites')} · showing Can’t check` : 'None checked by it yet'],
                        ]}
                    />
                ) : (
                    <PanelFacts
                        rows={[
                            ['Status', badge?.text ?? '—'],
                            ['Version', hub.version ? [hub.version, 'updates itself overnight', lastUpdate && data.updates.last?.outcome !== 'installed' ? lastUpdate : null].filter(Boolean).join(' · ') : 'Not available'],
                            ['Computer', `${platformWords(hub.platform)} · CPU, memory and disk: Not available`],
                            ['Watching', data.watching.devices > 0 ? `${plural(data.watching.devices, 'device', 'devices')} at ${plural(data.watching.sites, 'Site', 'Sites')}` : 'No devices yet'],
                        ]}
                    />
                )}
                {hub.state === 'not_answering' ? (
                    <PopupSection icon={ListChecks} title="What to try">
                        <PanelFacts
                            rows={[
                                ['1', `Check the ${computerWord(hub.platform)} is on and has power`],
                                ['2', 'Check its network cable'],
                                ['3', `Restart the ${computerWord(hub.platform)}. The hub starts by itself.`],
                            ]}
                        />
                    </PopupSection>
                ) : null}
                {/* A silent hub shows only what to try (the drawing's offline view); the other tabs keep the rest. */}
                {hub.state !== 'not_answering' ? (
                    <>
                        <PopupSection icon={Network} title="Networks it covers">
                            {data.networks.length === 0 ? (
                                <p className="text-subtle">No Sites you can see.</p>
                            ) : (
                                <PanelFacts rows={data.networks.slice(0, 4).map((network) => networkRow(network, hub.site.name))} />
                            )}
                        </PopupSection>
                        <PopupSection icon={History} title="Recent events">
                            {data.events.length === 0 ? (
                                <p className="text-subtle">Nothing recorded yet.</p>
                            ) : (
                                <PanelFacts rows={data.events.slice(0, 6).map((event) => [seenTime(event.at, now), event.text] as const)} />
                            )}
                        </PopupSection>
                    </>
                ) : null}
            </div>
        );
    } else if (tab === 'networks') {
        body =
            data.networks.length === 0 ? (
                <p className="text-subtle">No Sites you can see.</p>
            ) : (
                <PanelFacts
                    rows={data.networks.map((network) => {
                        const [site, words] = networkRow(network, hub.site.name);
                        const scans = network.range ? (network.daily_at ? `scanned every day at ${clockWords(network.daily_at)}` : 'scanned when you ask') : null;
                        return [site, scans ? `${words} · ${scans}` : words] as const;
                    })}
                />
            );
    } else if (tab === 'updates') {
        body = (
            <div className="grid gap-4">
                <PanelFacts
                    rows={[
                        ['Version', hub.version ?? 'Not available'],
                        ['Last update', lastUpdate ? `${lastUpdate}${data.updates.last?.at ? ` · ${formatDateTime(data.updates.last.at)}` : ''}` : 'None reported yet'],
                        ['Gets updates', data.updates.ring === 'early' ? 'Test first: before the other hubs' : 'With everyone'],
                        ...(data.updates.waiting ? [['Next update', waitingWords(data.updates.waiting)] as const] : []),
                        ['Updates between', `${clockWords(data.updates.window_start)} – ${clockWords(data.updates.window_end)}, New Zealand time`],
                        ['Every hub', data.updates.held ? `On hold${data.updates.held_at ? ` since ${formatDateTime(data.updates.held_at)}` : ''}` : 'Updating as usual'],
                        ...(data.updates.check_requested_at ? [['Check now', `Asked ${seenTime(data.updates.check_requested_at, now)} · it looks the next time it checks in`] as const] : []),
                    ]}
                />
                <PopupCallout tone="info">Updates install themselves in this window. If one fails, the hub goes back to the version before. Test-first hubs get a new version first; the others take it a day after it comes out, and only once a test-first hub has it working. If the test hub’s update fails, they keep their version.</PopupCallout>
            </div>
        );
    } else {
        body =
            data.events.length === 0 ? (
                <p className="text-subtle">Nothing recorded yet.</p>
            ) : (
                <PanelFacts rows={data.events.map((event) => [seenTime(event.at, now), event.text] as const)} />
            );
    }

    return (
        <>
            <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
                <SheetContent
                    side="right"
                    closeLabel="Close panel"
                    closeButtonClassName="text-band-foreground opacity-90"
                    className="w-full gap-0 bg-card p-0 sm:max-w-[640px]"
                    data-testid="sd-hub-panel"
                >
                    <header className="eh-header shrink-0 text-band-foreground" style={{ borderRadius: 0 }}>
                        <div className="relative z-[1] px-5 pt-[18px] pr-14">
                            <p className="text-xs font-semibold tracking-wide text-band-foreground/80 uppercase">
                                Hub · {hub?.site.name ?? '…'}
                            </p>
                            <SheetTitle className="mt-1 text-section-title text-band-foreground">{hub?.label ?? 'Hub'}</SheetTitle>
                            <SheetDescription className="mt-0.5 text-sm text-band-foreground/85">
                                {hub ? `${platformWords(hub.platform)} · at ${hub.site.name}` : 'Getting the hub…'}
                            </SheetDescription>
                            {hub && badge ? (
                                <div className="mt-2.5 flex flex-wrap gap-1.5">
                                    <StatusBadge variant={badge.tone}>{badge.text}</StatusBadge>
                                    <StatusBadge variant="neutral">{hub.version ? `Version ${hub.version}` : 'Version not available'}</StatusBadge>
                                </div>
                            ) : null}
                            <div role="tablist" aria-label="About this hub" className="mt-3 flex gap-1">
                                {PANEL_TABS.map((item, index) => (
                                    // eslint-disable-next-line no-restricted-syntax -- a connected tab on the panel's band, not a form button.
                                    <button
                                        key={item.key}
                                        ref={(node) => {
                                            tabRefs.current[index] = node;
                                        }}
                                        type="button"
                                        role="tab"
                                        id={`sd-hub-tab-${item.key}`}
                                        aria-selected={tab === item.key}
                                        aria-controls="sd-hub-tabpanel"
                                        tabIndex={tab === item.key ? 0 : -1}
                                        disabled={!data}
                                        onClick={() => setTab(item.key)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'ArrowRight') moveTab(index + 1);
                                            if (event.key === 'ArrowLeft') moveTab(index - 1);
                                        }}
                                        className={cn(
                                            'rounded-t-[10px] px-3 pt-2 pb-2.5 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-band-foreground disabled:opacity-60',
                                            tab === item.key ? 'bg-card text-primary' : 'text-band-foreground/85 hover:bg-band-foreground/10',
                                        )}
                                    >
                                        {item.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </header>
                    <div id="sd-hub-tabpanel" role="tabpanel" aria-labelledby={`sd-hub-tab-${tab}`} className="min-h-0 flex-1 overflow-y-auto px-5 py-4 scrollbar-pretty">
                        {body}
                    </div>
                    <PopupFooter
                        start={
                            data ? (
                                <>
                                    {data.can.remove ? (
                                        <Button type="button" variant="ghost" className="text-status-critical hover:text-status-critical" onClick={() => setChild('remove')}>
                                            Remove hub
                                        </Button>
                                    ) : null}
                                    {data.can.replace ? (
                                        <Button type="button" variant="ghost" onClick={() => setChild('replace')}>
                                            Replace hub
                                        </Button>
                                    ) : null}
                                </>
                            ) : undefined
                        }
                    >
                        <Button type="button" variant="outline" onClick={onClose}>
                            Close
                        </Button>
                        {data?.can.approve ? (
                            <Button type="button" onClick={() => setChild('approve')}>
                                Approve hub
                            </Button>
                        ) : data?.can.edit ? (
                            <Button type="button" onClick={() => setChild('edit')}>
                                Edit hub
                            </Button>
                        ) : null}
                    </PopupFooter>
                </SheetContent>
            </Sheet>

            <ApproveHub
                open={open && child === 'approve'}
                hub={target}
                canRemove={!!data?.can.remove}
                onClose={() => setChild(null)}
                onApproved={changed}
                onRemove={() => setChild('remove')}
            />
            <RemoveHub open={open && child === 'remove'} hub={target} onClose={() => setChild(null)} onRemoved={changed} />
            <ReplaceHub
                open={open && child === 'replace'}
                hub={target}
                onClose={() => {
                    setChild(null);
                    setReload((n) => n + 1);
                }}
            />
            <EditHub open={open && child === 'edit'} panel={data} form={form} onClose={() => setChild(null)} onSaved={changed} />
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  12 · Add a hub                                                     */
/* ------------------------------------------------------------------ */

const WIZARD_STEPS: WizardStep[] = [
    { key: 'where', label: 'Where', blurb: 'Site and computer', icon: MapPin },
    { key: 'download', label: 'Download', blurb: 'One file for this Site', icon: Download },
    { key: 'install', label: 'Install', blurb: 'Next, Next, Finish', icon: Package },
    { key: 'connected', label: 'Connected', blurb: 'Approve, then networks', icon: PlugZap },
];

/** After this long, step 4 says what to check (the drawing's "Still waiting?"). */
export const STILL_WAITING_MS = 120_000;
const POLL_MS = 4000;

type NetworkChoice = { on: boolean; range: string };

export function AddHubWizard({
    open,
    form,
    initialSiteId,
    canApprove,
    onClose,
    onSeeScan,
    onChanged,
}: {
    open: boolean;
    form: DeviceFormData | undefined;
    initialSiteId?: number | null;
    /** securityDevices.hubs.approve: Approve hub (79) and its "Remove it". */
    canApprove: boolean;
    onClose: () => void;
    /** "See the scan" on the last screen: the page opens the scan's progress (C1's pop-up 4). */
    onSeeScan: (runId: number) => void;
    /** A hub was approved or networks were added. */
    onChanged: () => void;
}) {
    const [step, setStep] = useState(0);
    const [siteId, setSiteId] = useState('');
    const [name, setName] = useState('');
    const [nameTouched, setNameTouched] = useState(false);
    const [platform, setPlatform] = useState<HubPlatform>('windows');
    const [covered, setCovered] = useState<number[]>([]);
    const [issued, setIssued] = useState<IssuedCode | null>(null);
    const [issuedFor, setIssuedFor] = useState('');
    const [status, setStatus] = useState<SetupStatus | null>(null);
    const [statusFailed, setStatusFailed] = useState(false);
    const [waitingSince, setWaitingSince] = useState<number | null>(null);
    const [tick, setTick] = useState(() => Date.now());
    const [approved, setApproved] = useState<HubStatus | null>(null);
    const [removed, setRemoved] = useState(false);
    const [child, setChild] = useState<'approve' | 'remove' | null>(null);
    const [networks, setNetworks] = useState<Record<number, NetworkChoice>>({});
    const [schedule, setSchedule] = useState<'daily' | 'ask'>('daily');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [done, setDone] = useState<{ runIds: number[]; count: number } | null>(null);

    const sites = useMemo(() => form?.sites ?? [], [form]);
    const site = sites.find((option) => String(option.id) === siteId);
    const inputsKey = JSON.stringify([siteId, name.trim(), platform, [...covered].sort()]);

    /** `another`: "Add another hub", for a Site this one can't reach, so no Site is picked. */
    const reset = (another = false) => {
        setStep(0);
        const first = another ? undefined : (sites.find((option) => option.id === initialSiteId) ?? (sites.length === 1 ? sites[0] : undefined));
        setSiteId(first ? String(first.id) : '');
        setName(first ? `${first.name} hub` : '');
        setNameTouched(false);
        setPlatform('windows');
        setCovered([]);
        setIssued(null);
        setIssuedFor('');
        setStatus(null);
        setStatusFailed(false);
        setWaitingSince(null);
        setApproved(null);
        setRemoved(false);
        setChild(null);
        setNetworks({});
        setSchedule('daily');
        setErrors({});
        setDone(null);
    };

    useEffect(() => {
        if (open) reset();
        // Only when it opens, or when the Sites arrive after it opened.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, form ? 1 : 0]);

    // The hub's name follows the Site until someone types one.
    useEffect(() => {
        if (!nameTouched && site) setName(`${site.name} hub`);
    }, [site, nameTouched]);

    const liveHub = approved ?? status?.hub ?? null;
    const isApproved = !!approved || (!!liveHub?.approved_at && liveHub.state !== 'removed');
    const hubRemoved = removed || liveHub?.state === 'removed';
    const codeGone = !liveHub && !!status && (status.code_state === 'expired' || status.code_state === 'cancelled');

    // Step 4 waits live for the hub (status every few seconds) once there's a download.
    useEffect(() => {
        if (!open || !issued || done || isApproved || hubRemoved || codeGone) return;
        let cancelled = false;
        let timer: number | undefined;
        const read = () => {
            axios
                .get<SetupStatus>(`/security-devices/hubs/setup-codes/${issued.setup_code_id}`, JSON_HEADERS)
                .then(({ data }) => {
                    if (cancelled) return;
                    setStatus(data);
                    setStatusFailed(false);
                    timer = window.setTimeout(read, POLL_MS);
                })
                .catch(() => {
                    if (cancelled) return;
                    setStatusFailed(true);
                    timer = window.setTimeout(read, POLL_MS * 2);
                });
        };
        read();
        return () => {
            cancelled = true;
            if (timer) window.clearTimeout(timer);
        };
    }, [open, issued, done, isApproved, hubRemoved, codeGone]);

    // "Still waiting?" after two minutes on step 4 with no hello.
    useEffect(() => {
        if (!open || step !== 3 || liveHub) return;
        setWaitingSince((since) => since ?? Date.now());
        const timer = window.setInterval(() => setTick(Date.now()), 5000);
        return () => window.clearInterval(timer);
    }, [open, step, liveHub]);
    const stillWaiting = waitingSince !== null && tick - waitingSince >= STILL_WAITING_MS;

    // The networks to suggest: the hub's Site first, then the Sites it covers (as the hub itself
    // reports them once it has said hello), with what we know about each.
    const homeId = liveHub ? liveHub.site_id : Number(siteId);
    const coveredIds = liveHub ? liveHub.covered_site_ids : covered;
    const hubSites: FormSite[] = useMemo(() => {
        const home = sites.find((option) => option.id === homeId);
        const others = sites.filter((option) => option.id !== homeId && coveredIds.includes(option.id));
        return home ? [home, ...others] : others;
    }, [sites, homeId, coveredIds]);

    useEffect(() => {
        setNetworks((current) => {
            const next: Record<number, NetworkChoice> = {};
            for (const option of hubSites) {
                next[option.id] = current[option.id] ?? { on: !!option.suggested_range, range: option.suggested_range ?? '' };
            }
            return next;
        });
    }, [hubSites]);

    const chosenNetworks = hubSites.filter((option) => networks[option.id]?.on && RANGE.test(networks[option.id].range.trim()));

    const issue = () => {
        const next: Record<string, string> = {};
        if (!siteId) next.site_id = 'Choose the Site the hub will run at.';
        if (name.trim().length < 2) next.name = 'Give the hub a name.';
        setErrors(next);
        if (Object.keys(next).length > 0) return;
        // Once the hub has said hello its Site and name are set: no second download.
        if (issued && (issuedFor === inputsKey || liveHub) && !codeGone) {
            setStep(1);
            return;
        }
        if (issued && !liveHub) {
            // The old download stops working: one computer per code (D2).
            void axios.delete(`/security-devices/hubs/setup-codes/${issued.setup_code_id}`, JSON_HEADERS).catch(() => undefined);
        }
        setProcessing(true);
        axios
            .post<IssuedCode>(
                '/security-devices/hubs/setup-codes',
                { site_id: Number(siteId), platform, name: name.trim(), covered_site_ids: covered },
                JSON_HEADERS,
            )
            .then(({ data }) => {
                setIssued(data);
                setIssuedFor(inputsKey);
                setStatus(null);
                setWaitingSince(null);
                setStep(1);
            })
            .catch((failure) => setErrors(errorsOf(failure, 'Result not confirmed. Try again; an unused download stops by itself after 24 hours.')))
            .finally(() => setProcessing(false));
    };

    const startScans = async () => {
        const hubId = approved?.id ?? liveHub?.id;
        if (!hubId || chosenNetworks.length === 0) return;
        setProcessing(true);
        setErrors({});
        const runIds: number[] = [];
        try {
            for (const option of chosenNetworks) {
                const range = networks[option.id].range.trim();
                await axios.post('/security-devices/networks', { site_id: option.id, range, hub_id: hubId, schedule, look_for: ['everything'] }, JSON_HEADERS);
                const { data } = await axios.post<{ run_id: number }>('/security-devices/scans', { site_id: option.id, range, hub_id: hubId, look_for: ['everything'] }, JSON_HEADERS);
                runIds.push(data.run_id);
            }
            setDone({ runIds, count: chosenNetworks.length });
            onChanged();
        } catch (failure) {
            const found = errorsOf(failure, 'Result not confirmed. Check Setup › Networks & scans before trying again.');
            setErrors(runIds.length > 0 ? { form: `${firstError(found)} ${plural(runIds.length, 'scan', 'scans')} already started.` } : found);
        } finally {
            setProcessing(false);
        }
    };

    const dirty = open && !issued && (nameTouched || covered.length > 0);
    const guard = usePopupClose({ dirty, processing, onClose });
    const hubLabel = liveHub ? (/\bhub$/i.test(liveHub.name) ? liveHub.name : `${liveHub.name} hub`) : name.trim() || 'The hub';
    const homeName = site?.name ?? 'its Site';

    const next = () => {
        if (step === 0) issue();
        else if (step < 3) setStep(step + 1);
        else void startScans();
    };

    const primaryLabel = step === 0 ? 'Continue' : step < 3 ? 'Continue' : 'Start the first scan';
    const primaryDisabled = processing || (step === 0 && !form) || (step === 3 && (!isApproved || hubRemoved || chosenNetworks.length === 0));

    return (
        <>
            <WizardShell
                open={open && !guard.asking && child === null}
                onClose={guard.requestClose}
                title="Add a hub"
                description="A small program at a Site that checks your devices"
                railIcon={Server}
                railTitle="Add a hub"
                railSub="A small program at a Site that checks your devices"
                steps={WIZARD_STEPS}
                stepIndex={step}
                onStepClick={(index) => {
                    if (index === 0 || (issued && index <= 3)) setStep(index);
                }}
                maxWidth="min(94vw, 1100px)"
                railExtra={<p className="rounded-lg border border-border bg-card px-3 py-2.5 text-caption">About 5 minutes. Nothing to type during install.</p>}
                success={
                    done ? (
                        <WizardSuccessPane
                            title={`${hubLabel} is watching ${plural(done.count, 'network', 'networks')}`}
                            blurb="The first scan has started. Found devices shows what it finds, usually within a few minutes."
                            actions={
                                <>
                                    <Button type="button" variant="ghost" onClick={onClose}>
                                        Close
                                    </Button>
                                    <Button type="button" variant="outline" onClick={() => reset(true)}>
                                        Add another hub
                                    </Button>
                                    <Button type="button" onClick={() => onSeeScan(done.runIds[0])}>
                                        <Radar aria-hidden="true" />
                                        See the scan
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
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
                            {processing ? (step === 0 ? 'Making your download…' : step === 3 ? 'Starting…' : primaryLabel) : primaryLabel}
                        </Button>
                    </>
                }
            >
                <WizardStepPane key={step}>
                    {firstError(errors) && (step !== 0 || errors.form) ? (
                        <PopupCallout tone="critical" className="mb-4">
                            {firstError(errors)}
                        </PopupCallout>
                    ) : null}

                    {step === 0 ? (
                        <>
                            <StepQuestion icon={MapPin} title="Where will the hub run?" line="Pick the Site and the computer it will live on. One hub can also watch other Sites." />
                            {liveHub ? (
                                <PopupCallout tone="info" className="mb-4">
                                    {hubLabel} has already said hello, so changes here don’t reach it. Edit the hub once it’s approved.
                                </PopupCallout>
                            ) : null}
                            {!form ? (
                                <p className="text-subtle" role="status">
                                    Getting your Sites…
                                </p>
                            ) : (
                                <div className="grid gap-5">
                                    <PopupSection icon={House} title="Site" columns={2}>
                                        <PopupField label="Site" required error={errors.site_id}>
                                            <VehicleSearchSelect
                                                label="Site"
                                                value={siteId}
                                                options={sites.map((option) => ({ value: String(option.id), label: option.name }))}
                                                onChange={(value) => {
                                                    setSiteId(value);
                                                    setCovered((now) => now.filter((id) => String(id) !== value));
                                                }}
                                            />
                                        </PopupField>
                                        <PopupField label="Hub name" htmlFor="sd-add-hub-name" hint="what you’ll see in Oblivion" error={errors.name}>
                                            <Input
                                                id="sd-add-hub-name"
                                                value={name}
                                                maxLength={120}
                                                onChange={(event) => {
                                                    setName(event.target.value);
                                                    setNameTouched(true);
                                                }}
                                            />
                                        </PopupField>
                                    </PopupSection>
                                    <PopupSection icon={Monitor} title="What will run it">
                                        <PopupField label="Computer" required error={errors.platform}>
                                            <ChoiceTiles label="Computer" columns={2} options={PLATFORM_TILES} value={platform} onChange={setPlatform} />
                                        </PopupField>
                                        <PopupCallout tone="info">Pick a computer that stays on all day and is plugged into the Site’s network.</PopupCallout>
                                    </PopupSection>
                                    {sites.length > 1 ? (
                                        <PopupSection icon={Network} title="Other Sites it can reach over SD-WAN or MPLS">
                                            {errors.covered_site_ids ? <p className="text-sm text-status-critical">{errors.covered_site_ids}</p> : null}
                                            {sites
                                                .filter((option) => String(option.id) !== siteId)
                                                .map((option) => (
                                                    <TickRow
                                                        key={option.id}
                                                        id={`sd-add-hub-cover-${option.id}`}
                                                        label={option.name}
                                                        hint={
                                                            [option.suggested_range, option.suggested_hub_id ? `watched by ${option.checked_by} now` : null].filter(Boolean).join(' · ') ||
                                                            'no network added yet'
                                                        }
                                                        checked={covered.includes(option.id)}
                                                        onChange={(on) => setCovered((now) => (on ? [...now, option.id] : now.filter((id) => id !== option.id)))}
                                                    />
                                                ))}
                                        </PopupSection>
                                    ) : null}
                                </div>
                            )}
                        </>
                    ) : null}

                    {step === 1 && issued ? (
                        <>
                            <StepQuestion
                                icon={Download}
                                title={issued.platform === 'linux' ? `Set up Oblivion for ${homeName}` : `Download Oblivion for ${homeName}`}
                                line={
                                    issued.platform === 'linux'
                                        ? 'The Site, our server and a setup code are in this command, so there’s nothing else to type.'
                                        : 'The Site, our server and a setup code are in this download, so there’s nothing to type.'
                                }
                            />
                            <div className="grid gap-5">
                                {issued.download_url && issued.file_name ? (
                                    <>
                                        <PopupSection icon={Download} title="Download">
                                            <PopupField label="Your download">
                                                <a
                                                    href={issued.download_url}
                                                    download={issued.file_name}
                                                    className="flex items-center gap-3 rounded-xl border border-primary bg-primary-fill/10 p-3 ring-1 ring-primary/40 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                >
                                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                                        <Download className="size-4" aria-hidden="true" />
                                                    </span>
                                                    <span className="min-w-0">
                                                        <span className="block text-sm font-semibold text-primary">Download Oblivion for {homeName}</span>
                                                        <span className="text-caption block">Windows · made for this Site</span>
                                                    </span>
                                                </a>
                                            </PopupField>
                                            <PopupField label="File name" htmlFor="sd-add-hub-file">
                                                <Input id="sd-add-hub-file" readOnly value={issued.file_name} onFocus={(event) => event.currentTarget.select()} />
                                            </PopupField>
                                            <PopupCallout tone="info">
                                                Keep the file name as it is: it carries the setup code. The link and the code work once and stop at {formatDateTime(issued.expires_at)}.
                                            </PopupCallout>
                                        </PopupSection>
                                        {issued.share_link ? (
                                            <PopupSection icon={LinkIcon} title="Or send it to whoever will install it">
                                                <PopupField label="Link" htmlFor="sd-add-hub-link" hint="works once · stops after 24 hours">
                                                    <CopyRow id="sd-add-hub-link" value={issued.share_link} copyLabel="Copy link" />
                                                </PopupField>
                                            </PopupSection>
                                        ) : null}
                                    </>
                                ) : null}
                                {issued.linux_command ? (
                                    <PopupSection icon={SquareTerminal} title="Raspberry Pi or NUC">
                                        <PopupField label="One command to copy" htmlFor="sd-add-hub-command" hint={`works once · stops at ${formatDateTime(issued.expires_at)}`}>
                                            <CopyRow id="sd-add-hub-command" value={issued.linux_command} mono />
                                        </PopupField>
                                    </PopupSection>
                                ) : null}
                            </div>
                        </>
                    ) : null}

                    {step === 2 && issued ? (
                        issued.platform === 'linux' ? (
                            <>
                                <StepQuestion
                                    icon={Package}
                                    title="Run the command on the Raspberry Pi or NUC"
                                    line="Paste it into its terminal. It takes about 2 minutes and nothing comes from apt."
                                />
                                <PopupSection icon={AppWindow} title="What you’ll see">
                                    <InfoTiles
                                        items={[
                                            { icon: SquareTerminal, title: '1. Paste the command', line: 'It asks for the sudo password' },
                                            { icon: LoaderCircle, title: '2. Installing', line: 'About 2 minutes' },
                                            { icon: CircleCheck, title: '3. Started', line: 'The hub starts by itself and on every boot' },
                                        ]}
                                    />
                                </PopupSection>
                            </>
                        ) : (
                            <>
                                <StepQuestion
                                    icon={Package}
                                    title="Run it and click Next, Next, Finish"
                                    line="Open the file on the computer that will be the hub, signed in as an administrator. It takes about 2 minutes."
                                />
                                <PopupSection icon={AppWindow} title="What you’ll see">
                                    <InfoTiles
                                        items={[
                                            { icon: Hand, title: '1. Welcome', line: `Says ${name.trim() || 'the hub’s name'} · click Next` },
                                            { icon: LoaderCircle, title: '2. Installing', line: 'About 2 minutes · click Next' },
                                            { icon: CircleCheck, title: '3. All set', line: 'Click Finish · the hub starts by itself' },
                                        ]}
                                    />
                                    <PopupCallout tone="info">Windows may warn that the app isn’t signed yet. Choose More info → Run anyway.</PopupCallout>
                                </PopupSection>
                            </>
                        )
                    ) : null}

                    {step === 3 && issued ? (
                        <>
                            <StepQuestion
                                icon={PlugZap}
                                title={liveHub ? 'Your hub is connected' : 'Waiting for your hub'}
                                line={
                                    !liveHub
                                        ? 'This updates by itself when the hub says hello.'
                                        : isApproved
                                          ? 'Tick the networks it should watch, then start the first scan.'
                                          : 'Approve it first, then tick the networks it should watch.'
                                }
                            />
                            <div className="grid gap-5">
                                {hubRemoved ? (
                                    <PopupCallout tone="critical">{hubLabel} was removed. The saved logins it could hold are wiped.</PopupCallout>
                                ) : codeGone ? (
                                    <PopupCallout tone="critical">
                                        <p>This download has stopped working. Each one works once, for 24 hours.</p>
                                        <Button type="button" variant="outline" size="sm" className="mt-2" onClick={issue} disabled={processing}>
                                            Make a new download
                                        </Button>
                                    </PopupCallout>
                                ) : !liveHub ? (
                                    <div className="grid gap-3">
                                        <p role="status" className="flex items-center gap-2.5 text-sm font-medium">
                                            <LoaderCircle aria-hidden="true" className="size-4 animate-spin text-primary motion-reduce:animate-none" />
                                            Waiting for the hub to say hello…
                                        </p>
                                        {stillWaiting ? (
                                            <PopupCallout tone="warning">Still waiting? Check the computer is on and connected to the network.</PopupCallout>
                                        ) : null}
                                        {statusFailed ? <PopupCallout tone="warning">We can’t check right now. We’ll keep trying.</PopupCallout> : null}
                                    </div>
                                ) : (
                                    <PopupCallout tone="success">
                                        <b className="font-semibold">Connected ✓ {hubLabel}</b> · {platformWords(issued.platform)}
                                        {liveHub.last_heartbeat_at ? ` · said hello at ${formatTime(liveHub.last_heartbeat_at)}` : ''}
                                    </PopupCallout>
                                )}

                                {liveHub && !hubRemoved ? (
                                    <PopupSection icon={ShieldCheck} title={isApproved ? 'Approved' : 'Waiting for your approval'}>
                                        {isApproved ? (
                                            <p className="text-sm">It can now get the saved logins for {listWords(hubSites.map((option) => option.name))}, encrypted on the hub.</p>
                                        ) : (
                                            <>
                                                <PopupCallout tone="warning">Until you approve it, the hub can’t receive any saved logins.</PopupCallout>
                                                <PopupFacts
                                                    rows={[
                                                        ['Computer', platformWords(issued.platform)],
                                                        ['Installed', `From your download${liveHub.last_heartbeat_at ? ` · said hello at ${formatTime(liveHub.last_heartbeat_at)}` : ''}`],
                                                    ]}
                                                />
                                                {canApprove ? (
                                                    <ChoiceTiles
                                                        label="Approve hub"
                                                        options={[{ value: 'approve', label: 'Approve hub', description: 'Check it’s the computer you set up, then approve it', icon: ShieldCheck }]}
                                                        value=""
                                                        onChange={() => setChild('approve')}
                                                    />
                                                ) : (
                                                    <PopupCallout tone="info">Someone who can approve hubs needs to approve it before it starts.</PopupCallout>
                                                )}
                                            </>
                                        )}
                                    </PopupSection>
                                ) : null}

                                {liveHub && !hubRemoved ? (
                                    <>
                                        <PopupSection icon={Network} title="Networks it will watch" hint={isApproved ? undefined : 'after you approve it'}>
                                            {hubSites.map((option) => {
                                                const choice = networks[option.id] ?? { on: false, range: '' };
                                                const own = option.id === homeId;
                                                const suggested = !!option.suggested_range;
                                                return (
                                                    <TickRow
                                                        key={option.id}
                                                        id={`sd-add-hub-net-${option.id}`}
                                                        label={suggested ? `${option.name} · ${option.suggested_range}` : option.name}
                                                        hint={own ? 'the hub’s own network' : `over the link to ${homeName}`}
                                                        checked={choice.on}
                                                        disabled={!isApproved}
                                                        onChange={(on) => setNetworks((now) => ({ ...now, [option.id]: { ...choice, on } }))}
                                                    >
                                                        {!suggested ? (
                                                            <Input
                                                                aria-label={`Network range at ${option.name}`}
                                                                className="mt-1.5 max-w-60"
                                                                placeholder="e.g. 10.0.0.0/24"
                                                                value={choice.range}
                                                                disabled={!isApproved}
                                                                onChange={(event) =>
                                                                    setNetworks((now) => ({ ...now, [option.id]: { on: event.target.value.trim() !== '', range: event.target.value } }))
                                                                }
                                                            />
                                                        ) : null}
                                                    </TickRow>
                                                );
                                            })}
                                        </PopupSection>
                                        <PopupSection icon={Radar} title="Scans" hint={isApproved ? undefined : 'after you approve it'}>
                                            <PopupField label="Scan again" hint="New Zealand time">
                                                <Segmented
                                                    label="Scan again"
                                                    value={schedule}
                                                    onChange={setSchedule}
                                                    disabled={!isApproved}
                                                    options={[
                                                        { value: 'daily', label: 'Every day at 6:00 am' },
                                                        { value: 'ask', label: 'Only when I ask' },
                                                    ]}
                                                />
                                            </PopupField>
                                        </PopupSection>
                                    </>
                                ) : null}
                            </div>
                        </>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftConfirm open={open && guard.asking} what="Add a hub" onKeepEditing={guard.keepEditing} onDiscard={guard.discard} />

            <ApproveHub
                open={open && child === 'approve'}
                hub={
                    liveHub
                        ? { id: liveHub.id, label: hubLabel, platform: issued?.platform ?? null, sites: hubSites.map((option) => option.name), devices: null, heardAt: liveHub.last_heartbeat_at }
                        : null
                }
                installedFromYou
                canRemove={canApprove}
                onClose={() => setChild(null)}
                onApproved={(hub) => {
                    setApproved(hub);
                    setChild(null);
                    onChanged();
                }}
                onRemove={() => setChild('remove')}
            />
            <RemoveHub
                open={open && child === 'remove'}
                hub={liveHub ? { id: liveHub.id, label: hubLabel, platform: issued?.platform ?? null, sites: hubSites.map((option) => option.name), devices: null } : null}
                onClose={() => setChild(null)}
                onRemoved={() => {
                    setRemoved(true);
                    setChild(null);
                    onChanged();
                }}
            />
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  23 · Install Oblivion on a computer                                */
/* ------------------------------------------------------------------ */

type ComputerCode = IssuedCode & { install_mode: 'staff' | 'full' };

export function InstallOnComputer({
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
    const [siteId, setSiteId] = useState('');
    const [issued, setIssued] = useState<ComputerCode | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const issuedSite = useRef('');
    const sites = form?.sites ?? [];

    useEffect(() => {
        if (!open) return;
        const first = sites.find((option) => option.id === initialSiteId) ?? (sites.length === 1 ? sites[0] : undefined);
        setSiteId(first ? String(first.id) : '');
        setIssued(null);
        issuedSite.current = '';
        setErrors({});
        // Only when it opens, or when the Sites arrive.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, form ? 1 : 0]);

    /** One download per Site: Download again and Copy link reuse it. */
    const make = (then: (code: ComputerCode) => void) => {
        if (!siteId) {
            setErrors({ site_id: 'Choose the Site the computer is at.' });
            return;
        }
        if (issued && issuedSite.current === siteId) {
            then(issued);
            return;
        }
        setProcessing(true);
        setErrors({});
        axios
            .post<ComputerCode>('/security-devices/hubs/computer-setup-codes', { site_id: Number(siteId), install_mode: 'full' }, JSON_HEADERS)
            .then(({ data }) => {
                issuedSite.current = siteId;
                setIssued(data);
                then(data);
            })
            .catch((failure) => setErrors(errorsOf(failure, 'Result not confirmed. Try again; an unused download stops by itself after 24 hours.')))
            .finally(() => setProcessing(false));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Download}
            title="Install Oblivion on a computer"
            description="Get a download made for this Site. Nothing to type during install."
            error={errors.form ?? errors.install_mode}
            submitLabel={issued ? 'Download again' : 'Download'}
            processingLabel="Making your download…"
            processing={processing}
            submitDisabled={!form}
            footerStart={
                <Button
                    type="button"
                    variant="ghost"
                    disabled={processing || !form}
                    onClick={() => make((code) => code.share_link && void navigator.clipboard?.writeText(code.share_link).catch(() => undefined))}
                >
                    <LinkIcon aria-hidden="true" />
                    Copy link
                </Button>
            }
            onSubmit={() => make((code) => code.download_url && startDownload(code.download_url, code.file_name))}
            testId="sd-install-computer"
        >
            {!form ? (
                <p className="text-subtle" role="status">
                    Getting your Sites…
                </p>
            ) : (
                <>
                    <PopupField label="Site" required error={errors.site_id}>
                        <VehicleSearchSelect
                            label="Site"
                            value={siteId}
                            options={sites.map((option) => ({ value: String(option.id), label: option.name }))}
                            onChange={(value) => {
                                setSiteId(value);
                                if (value !== issuedSite.current) setIssued(null);
                            }}
                        />
                    </PopupField>
                    <PopupField label="Install" wide hint="Staff installs aren’t ready yet: Obli couldn’t reach IT from them. Use IT’s full install for now.">
                        <Segmented
                            label="Install"
                            value="full"
                            onChange={() => undefined}
                            options={[
                                { value: 'staff', label: 'Obli for staff (no admin password)', disabled: true },
                                { value: 'full', label: 'Full: hub or IT-managed (needs an admin)' },
                            ]}
                        />
                    </PopupField>
                    <PopupField label="Download" htmlFor="sd-install-file">
                        {issued?.file_name ? (
                            <>
                                <Input id="sd-install-file" readOnly value={issued.file_name} onFocus={(event) => event.currentTarget.select()} />
                                <p className="text-caption mt-1">Windows · works once, until {formatDateTime(issued.expires_at)}</p>
                            </>
                        ) : (
                            <p className="text-subtle">Made for this Site when you download · Windows · works once, for 24 hours</p>
                        )}
                    </PopupField>
                    <PopupCallout tone="info">
                        The setup code is in the file name, so the file stays valid when we sign it. Test builds aren’t signed yet, so Windows may say “Windows protected
                        your PC”: choose More info → Run anyway.
                    </PopupCallout>
                </>
            )}
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  Wiring for pages: Add a hub, the hub panel and "See the scan"      */
/* ------------------------------------------------------------------ */

export type HubPopupView = { kind: 'add'; siteId: number | null } | { kind: 'panel'; hubId: number } | { kind: 'scanning'; runId: number } | null;

/** One place for a page's hub pop-ups. `loadForm` fetches the page's lazy Sites list. */
export function useHubPopups(loadForm: () => void) {
    const [view, setView] = useState<HubPopupView>(null);
    return {
        view,
        setView,
        openAdd: (siteId?: number | null) => {
            loadForm();
            setView({ kind: 'add', siteId: siteId ?? null });
        },
        openPanel: (hubId: number) => {
            loadForm();
            setView({ kind: 'panel', hubId });
        },
        close: () => setView(null),
    };
}

export function HubPopups({
    state,
    form,
    canApprove,
    canStopScan,
}: {
    state: ReturnType<typeof useHubPopups>;
    form: DeviceFormData | undefined;
    canApprove: boolean;
    canStopScan: boolean;
}) {
    const { view, setView, close } = state;
    // Keep the lazy Sites list (deviceForm) while the rest of the page refreshes.
    const refresh = () => router.reload({ except: ['deviceForm'] });

    return (
        <>
            <AddHubWizard
                open={view?.kind === 'add'}
                form={form}
                initialSiteId={view?.kind === 'add' ? view.siteId : null}
                canApprove={canApprove}
                onClose={close}
                onSeeScan={(runId) => setView({ kind: 'scanning', runId })}
                onChanged={refresh}
            />
            <HubPanel open={view?.kind === 'panel'} hubId={view?.kind === 'panel' ? view.hubId : null} form={form} onClose={close} onChanged={refresh} />
            <ScanningViewer open={view?.kind === 'scanning'} runId={view?.kind === 'scanning' ? view.runId : null} canStop={canStopScan} onClose={close} />
        </>
    );
}
