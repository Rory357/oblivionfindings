import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarSync,
    Check,
    Copy,
    Link2,
    Plug,
    RefreshCw,
    RotateCcw,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

type ProviderKey = 'google' | 'microsoft';

type ProviderCard = {
    key: ProviderKey;
    label: string;
    configured: boolean;
    connected: boolean;
    accountEmail: string | null;
    accountName: string | null;
    lastSyncedAt: string | null;
    status: string;
};

type SourceDef = {
    key: string;
    label: string;
    short: string;
    group: string;
    icon: string;
    origin: string;
};

type Mapping = {
    id: number;
    provider: ProviderKey;
    externalCalendarId: string | null;
    externalCalendarName: string | null;
    syncDirection: 'one_way' | 'two_way';
    sources: string[] | null;
    isActive: boolean;
    lastSyncedAt: string | null;
    lastError: string | null;
    feedUrl: string | null;
};

type SiteRow = {
    id: number;
    name: string;
    type: string;
    mapping: Mapping | null;
};
type Direction = { key: string; label: string };
type SyncSettings = { cadence_minutes: number; conflict_policy: string };

type PageProps = {
    providers: ProviderCard[];
    sites: SiteRow[];
    sources: SourceDef[];
    directions: Direction[];
    settings: SyncSettings;
    anyConnected: boolean;
};

type ResourceOption = { id: string; name: string };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Calendar Sync' },
];

function fmtDate(iso: string | null): string {
    if (!iso) return 'never';
    try {
        return new Date(iso).toLocaleString('en-NZ', {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return iso;
    }
}

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

export default function CalendarSyncSettings() {
    const { providers, sites, sources, directions, settings, anyConnected } =
        usePage<PageProps>().props;
    const page = usePage<{
        flash?: { success?: string; error?: string };
        errors?: Record<string, string>;
    }>().props;
    const flash = page.flash;
    const errors = page.errors ?? {};
    const errorList = Object.values(errors);

    // Resource calendars per provider, lazily fetched.
    const [resources, setResources] = useState<
        Record<string, ResourceOption[]>
    >({});
    const [loadingResources, setLoadingResources] = useState<
        Record<string, boolean>
    >({});

    const loadResources = useCallback(
        async (provider: ProviderKey) => {
            if (resources[provider] || loadingResources[provider]) return;
            setLoadingResources((s) => ({ ...s, [provider]: true }));
            try {
                const res = await fetch(
                    `/settings/calendar-sync/resources/${provider}`,
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken(),
                        },
                        credentials: 'same-origin',
                    },
                );
                const data = await res.json().catch(() => ({ resources: [] }));
                setResources((s) => ({
                    ...s,
                    [provider]: data.resources ?? [],
                }));
            } catch {
                setResources((s) => ({ ...s, [provider]: [] }));
            } finally {
                setLoadingResources((s) => ({ ...s, [provider]: false }));
            }
        },
        [resources, loadingResources],
    );

    const connectedProviders = useMemo(
        () => providers.filter((p) => p.connected),
        [providers],
    );
    const mappedCount = sites.filter(
        (s) => s.mapping?.isActive && s.mapping.externalCalendarId,
    ).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Calendar Sync" />
            <SettingsLayout>
                <div className="space-y-6">
                    <header className="space-y-1">
                        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                            <CalendarSync className="h-6 w-6 text-primary" />
                            Calendar Sync
                        </h1>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Connect Google Workspace or Microsoft 365 and map
                            each house to its <strong>resource calendar</strong>{' '}
                            (a Google resource calendar or an Outlook room
                            mailbox). Approved house events push out to the
                            resource calendar; staff keep their own “add to my
                            calendar” options on the calendar page.
                        </p>
                    </header>

                    {flash?.success && (
                        <div className="flex items-center gap-2 rounded-lg border border-status-success/30 bg-status-success-bg px-4 py-2.5 text-sm text-status-success">
                            <Check className="h-4 w-4 shrink-0" />
                            {flash.success}
                        </div>
                    )}
                    {(flash?.error || errorList.length > 0) && (
                        <div className="flex items-start gap-2 rounded-lg border border-status-critical/30 bg-status-critical-bg px-4 py-2.5 text-sm text-status-critical">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <div>
                                {flash?.error}
                                {errorList.map((e, i) => (
                                    <div key={i}>{e}</div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Provider connections */}
                    <section className="space-y-3">
                        <h2 className="text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                            Providers
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {providers.map((p) => (
                                <ProviderConnectCard key={p.key} provider={p} />
                            ))}
                        </div>
                    </section>

                    {/* Per-house mapping */}
                    <section className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                                House → resource calendar ({mappedCount} mapped)
                            </h2>
                            {anyConnected && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            '/settings/calendar-sync/sync-now',
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <RefreshCw className="mr-1.5 h-4 w-4" />{' '}
                                    Sync now
                                </Button>
                            )}
                        </div>

                        {!anyConnected ? (
                            <Card>
                                <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
                                    <Plug className="h-8 w-8 text-muted-foreground" />
                                    <p className="text-sm text-muted-foreground">
                                        Connect a provider above to start
                                        mapping houses to resource calendars.
                                    </p>
                                </CardContent>
                            </Card>
                        ) : (
                            <Card>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>House</TableHead>
                                            <TableHead>Provider</TableHead>
                                            <TableHead>
                                                Resource calendar
                                            </TableHead>
                                            <TableHead>Direction</TableHead>
                                            <TableHead>Sources</TableHead>
                                            <TableHead className="text-right">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {sites.map((site) => (
                                            <MappingRow
                                                key={site.id}
                                                site={site}
                                                sources={sources}
                                                directions={directions}
                                                connectedProviders={
                                                    connectedProviders
                                                }
                                                resources={resources}
                                                loadingResources={
                                                    loadingResources
                                                }
                                                onNeedResources={loadResources}
                                            />
                                        ))}
                                    </TableBody>
                                </Table>
                            </Card>
                        )}
                    </section>

                    {/* Global settings */}
                    <GlobalSettingsCard settings={settings} />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function ProviderConnectCard({ provider }: { provider: ProviderCard }) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <CardTitle className="text-base">
                        {provider.label}
                    </CardTitle>
                    {provider.connected ? (
                        <Badge className="bg-status-success-bg text-status-success hover:bg-status-success-bg">
                            Connected
                        </Badge>
                    ) : (
                        <Badge variant="outline">Not connected</Badge>
                    )}
                </div>
                <CardDescription>
                    {provider.connected
                        ? `${provider.accountName ?? provider.accountEmail ?? 'Connected account'} · last sync ${fmtDate(provider.lastSyncedAt)}`
                        : provider.configured
                          ? 'Authorise an admin/service account that can manage your resource calendars.'
                          : 'OAuth client credentials are not configured for this provider yet.'}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {provider.connected ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.delete(
                                `/settings/calendar-sync/connect/${provider.key}`,
                                { preserveScroll: true },
                            )
                        }
                    >
                        Disconnect
                    </Button>
                ) : provider.configured ? (
                    <Button size="sm" asChild>
                        <a
                            href={`/settings/calendar-sync/connect/${provider.key}`}
                        >
                            <Plug className="mr-1.5 h-4 w-4" /> Connect{' '}
                            {provider.label}
                        </a>
                    </Button>
                ) : (
                    <Button
                        type="button"
                        size="sm"
                        disabled
                        title="Set OAuth client credentials in the environment first"
                    >
                        Not configured
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

function MappingRow({
    site,
    sources,
    directions,
    connectedProviders,
    resources,
    loadingResources,
    onNeedResources,
}: {
    site: SiteRow;
    sources: SourceDef[];
    directions: Direction[];
    connectedProviders: ProviderCard[];
    resources: Record<string, ResourceOption[]>;
    loadingResources: Record<string, boolean>;
    onNeedResources: (p: ProviderKey) => void;
}) {
    const m = site.mapping;
    const [provider, setProvider] = useState<string>(
        m?.isActive ? m.provider : '',
    );
    const [calendarId, setCalendarId] = useState<string>(
        m?.externalCalendarId ?? '',
    );
    const [calendarName, setCalendarName] = useState<string>(
        m?.externalCalendarName ?? '',
    );
    const [direction, setDirection] = useState<string>(
        m?.syncDirection ?? 'one_way',
    );
    const [selectedSources, setSelectedSources] = useState<string[]>(
        m?.sources ?? sources.map((s) => s.key),
    );
    const [showSources, setShowSources] = useState(false);
    const [saving, setSaving] = useState(false);
    const sourcesRef = useRef<HTMLDivElement>(null);

    // Close the sources popover on outside-click / Escape.
    useEffect(() => {
        if (!showSources) return;
        const onDocClick = (e: MouseEvent) => {
            if (
                sourcesRef.current &&
                !sourcesRef.current.contains(e.target as Node)
            )
                setShowSources(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setShowSources(false);
        };
        document.addEventListener('mousedown', onDocClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDocClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [showSources]);

    const providerOptions = resources[provider] ?? [];

    const onProviderChange = (value: string) => {
        setProvider(value);
        if (value === 'google' || value === 'microsoft') onNeedResources(value);
    };

    const toggleSource = (key: string) =>
        setSelectedSources((cur) =>
            cur.includes(key) ? cur.filter((k) => k !== key) : [...cur, key],
        );

    const save = () => {
        setSaving(true);
        const chosen = providerOptions.find((o) => o.id === calendarId);
        router.put(
            '/settings/calendar-sync/mapping',
            {
                site_id: site.id,
                provider: provider || null,
                external_calendar_id: calendarId || null,
                external_calendar_name: chosen?.name ?? (calendarName || null),
                sync_direction: direction,
                sources: selectedSources,
                is_active: true,
            },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    const copyFeed = () => {
        if (m?.feedUrl) navigator.clipboard?.writeText(m.feedUrl);
    };

    return (
        <TableRow>
            <TableCell className="font-medium">
                {site.name}
                <span className="block text-xs text-muted-foreground capitalize">
                    {site.type?.replace(/_/g, ' ')}
                </span>
            </TableCell>
            <TableCell>
                <select
                    aria-label={`Calendar provider for ${site.name}`}
                    value={provider}
                    onChange={(e) => onProviderChange(e.target.value)}
                    className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option value="">Not synced</option>
                    {connectedProviders.map((p) => (
                        <option key={p.key} value={p.key}>
                            {p.label}
                        </option>
                    ))}
                </select>
            </TableCell>
            <TableCell>
                {!provider ? (
                    <span className="text-xs text-muted-foreground">—</span>
                ) : loadingResources[provider] ? (
                    <span className="text-xs text-muted-foreground">
                        Loading…
                    </span>
                ) : providerOptions.length > 0 ? (
                    <select
                        aria-label={`Resource calendar for ${site.name}`}
                        value={calendarId}
                        onChange={(e) => setCalendarId(e.target.value)}
                        className="h-9 w-full min-w-[12rem] rounded-md border border-input bg-background px-2 text-sm"
                    >
                        <option value="">Select calendar…</option>
                        {providerOptions.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                ) : (
                    <Input
                        aria-label={`Resource calendar id for ${site.name}`}
                        value={calendarId}
                        onChange={(e) => setCalendarId(e.target.value)}
                        placeholder="Resource calendar id / room mailbox"
                        className="h-9 min-w-[12rem]"
                    />
                )}
                {m?.lastError && (
                    <span className="mt-1 block text-xs text-status-critical">
                        {m.lastError}
                    </span>
                )}
            </TableCell>
            <TableCell>
                <select
                    aria-label={`Sync direction for ${site.name}`}
                    value={direction}
                    onChange={(e) => setDirection(e.target.value)}
                    disabled={!provider}
                    className="h-9 rounded-md border border-input bg-background px-2 text-sm disabled:opacity-50"
                >
                    {directions.map((d) => (
                        <option key={d.key} value={d.key}>
                            {d.label}
                        </option>
                    ))}
                </select>
            </TableCell>
            <TableCell>
                <div className="relative" ref={sourcesRef}>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!provider}
                        aria-expanded={showSources}
                        aria-label={`Choose which sources sync for ${site.name}`}
                        onClick={() => setShowSources((v) => !v)}
                    >
                        {selectedSources.length === sources.length
                            ? 'All sources'
                            : `${selectedSources.length} sources`}
                    </Button>
                    {showSources && (
                        <div className="absolute z-20 mt-1 w-56 rounded-lg border bg-popover p-2 shadow-md">
                            {sources.map((s) => (
                                <label
                                    key={s.key}
                                    className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted"
                                >
                                    <Checkbox
                                        checked={selectedSources.includes(
                                            s.key,
                                        )}
                                        onCheckedChange={() =>
                                            toggleSource(s.key)
                                        }
                                    />
                                    {s.label}
                                </label>
                            ))}
                        </div>
                    )}
                </div>
            </TableCell>
            <TableCell>
                <div className="flex items-center justify-end gap-1.5">
                    {m?.feedUrl && (
                        <>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                title="Copy house feed URL"
                                onClick={copyFeed}
                            >
                                <Copy className="h-4 w-4" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                title="Reset house feed link"
                                onClick={() =>
                                    router.post(
                                        `/settings/calendar-sync/mapping/${m.id}/reset-feed`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <RotateCcw className="h-4 w-4" />
                            </Button>
                        </>
                    )}
                    <Button
                        type="button"
                        size="sm"
                        onClick={save}
                        disabled={saving}
                    >
                        Save
                    </Button>
                </div>
            </TableCell>
        </TableRow>
    );
}

function GlobalSettingsCard({ settings }: { settings: SyncSettings }) {
    const [cadence, setCadence] = useState<number>(settings.cadence_minutes);
    const [conflict, setConflict] = useState<string>(settings.conflict_policy);
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        router.put(
            '/settings/calendar-sync/settings',
            { cadence_minutes: cadence, conflict_policy: conflict },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-base">Sync settings</CardTitle>
                <CardDescription>
                    How often background sync runs, and how external busy time
                    is treated.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="cadence">Sync cadence</Label>
                        <select
                            id="cadence"
                            value={cadence}
                            onChange={(e) => setCadence(Number(e.target.value))}
                            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value={5}>Every 5 minutes</option>
                            <option value={15}>Every 15 minutes</option>
                            <option value={30}>Every 30 minutes</option>
                            <option value={60}>Hourly</option>
                            <option value={180}>Every 3 hours</option>
                            <option value={360}>Every 6 hours</option>
                        </select>
                        <p className="flex items-center gap-1 text-xs text-muted-foreground">
                            <Link2 className="h-3 w-3" /> Background sync also
                            runs on the system schedule.
                        </p>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="conflict">
                            Conflict policy (two-way)
                        </Label>
                        <select
                            id="conflict"
                            value={conflict}
                            onChange={(e) => setConflict(e.target.value)}
                            className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="external_busy_counts">
                                External busy blocks count as clashes
                            </option>
                            <option value="ignore">
                                Ignore external busy blocks
                            </option>
                        </select>
                    </div>
                </div>
                <Button
                    type="button"
                    size="sm"
                    onClick={save}
                    disabled={saving}
                >
                    Save settings
                </Button>
            </CardContent>
        </Card>
    );
}
