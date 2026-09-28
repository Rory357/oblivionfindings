import {
    EntityContextMenu,
    useEntityContextMenu,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Eye,
    KeyRound,
    ListChecks,
    Map,
    RefreshCw,
    Settings2,
    Shield,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    googleConsoleUrl,
    mapConfigurationErrors,
    mapErrorStep,
    type MapErrors,
} from './_map-configuration';
import { MapTools } from './_map-tools';
import type { MapSnapshot, MapValues } from './_types';
import { mergeMapDraft } from './_types';
import { api, Modal, Notice, SettingsError } from './_ui';

const steps = [
    {
        key: 'project',
        label: 'Project & credentials',
        blurb: 'Deployment-owned key references',
        icon: KeyRound,
    },
    {
        key: 'capabilities',
        label: 'Capabilities',
        blurb: 'Enable only the APIs you need',
        icon: Map,
    },
    {
        key: 'check',
        label: 'Check configuration',
        blurb: 'Credentials and API requirements',
        icon: ListChecks,
    },
    {
        key: 'review',
        label: 'Review & save',
        blurb: 'Restrictions, costs and impact',
        icon: Shield,
    },
];
const fallback: Record<string, string> = {
    display: 'OSM basemap and application-owned markers remain usable.',
    places: 'Enter any address manually, choose a saved site or use coordinates.',
    geocoding: 'Coordinates remain usable without a derived address.',
    routes: 'Bookings remain usable without a route or travel estimate.',
};
export function Maps({
    initial,
    canManage,
    query,
    userId,
    onDirty,
}: {
    initial: MapSnapshot;
    canManage: boolean;
    query: string;
    userId: number;
    onDirty: (dirty: boolean) => void;
}) {
    const [saved, setSaved] = useState(initial),
        [draft, setDraft] = useState(initial.values),
        [open, setOpen] = useState(false),
        [step, setStep] = useState(0),
        [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [notice, setNotice] = useState(''),
        [discard, setDiscard] = useState(false),
        [latest, setLatest] = useState<MapSnapshot | null>(null);
    const [detail, setDetail] = useState<
        MapSnapshot['capabilities'][number] | null
    >(null);
    const [toolsOpen, setToolsOpen] = useState(false);
    const [fieldErrors, setFieldErrors] = useState<MapErrors>({});
    const errorRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (error) errorRef.current?.focus();
    }, [error, step]);
    const [recovery, setRecovery] = useState<{
            base: MapValues;
            draft: MapValues;
        } | null>(null),
        [draftReady, setDraftReady] = useState(false);
    const storageKey = `fleet.maps.draft.${userId}`;
    const discardClosesProvider = useRef(false);
    const dirty = JSON.stringify(draft) !== JSON.stringify(saved.values);
    useEffect(() => {
        try {
            const stored = JSON.parse(
                localStorage.getItem(storageKey) ?? 'null',
            );
            const valid = (value: unknown) =>
                value &&
                typeof value === 'object' &&
                Object.entries(initial.values).every(
                    ([key, sample]) =>
                        typeof (value as Record<string, unknown>)[key] ===
                        typeof sample,
                ) &&
                Object.keys(value).length ===
                    Object.keys(initial.values).length;
            if (canManage && valid(stored?.base) && valid(stored?.draft))
                setRecovery(stored);
        } catch {
            /* The configuration remains usable without browser storage. */
        }
        setDraftReady(true);
    }, [storageKey, canManage, initial.values]);
    useEffect(() => {
        onDirty(dirty);
        if (!draftReady || recovery) return;
        try {
            if (dirty)
                localStorage.setItem(
                    storageKey,
                    JSON.stringify({ base: saved.values, draft }),
                );
            else localStorage.removeItem(storageKey);
        } catch {
            /* The open draft remains available. */
        }
    }, [draft, saved.values, dirty, draftReady, recovery, storageKey, onDirty]);
    const context = useEntityContextMenu<MapSnapshot['capabilities'][number]>();
    const configure = () => {
        if (recovery) return;
        setDraft(saved.values);
        setStep(0);
        setError('');
        setFieldErrors({});
        setOpen(true);
    };
    const close = () => {
        if (busy) return;
        if (JSON.stringify(draft) !== JSON.stringify(saved.values))
            setDiscard(true);
        else setOpen(false);
    };
    const edit = <K extends keyof MapValues>(key: K, value: MapValues[K]) => {
        setDraft((current) => ({
            ...current,
            [key]: value,
            ...(key === 'google' && value === true ? { display: true } : {}),
        }));
        setError('');
        setFieldErrors({});
    };
    function validate(all = false) {
        const issues = mapConfigurationErrors(draft, saved.credentials);
        const keys = all
            ? Object.keys(issues)
            : step === 0
              ? ['project', 'google']
              : step === 1
                ? ['display', 'places']
                : [];
        const relevant = Object.fromEntries(
            keys
                .filter((key) => issues[key as keyof MapValues])
                .map((key) => [key, issues[key as keyof MapValues]]),
        ) as MapErrors;
        setFieldErrors(relevant);
        if (Object.keys(relevant).length) {
            setStep(mapErrorStep(relevant));
            setError(Object.values(relevant).join(' '));
            return false;
        }
        setError('');
        return true;
    }
    async function reload() {
        setBusy(true);
        setError('');
        try {
            const current = await api<MapSnapshot>('maps');
            if (dirty && current.revision !== saved.revision)
                setLatest(current);
            else {
                setSaved(current);
                if (!dirty) setDraft(current.values);
                setNotice(
                    'Configuration reloaded. No provider request was made.',
                );
            }
        } catch (problem) {
            setError(
                problem instanceof Error
                    ? problem.message
                    : 'Could not reload. Your draft is retained.',
            );
        } finally {
            setBusy(false);
        }
    }
    async function save() {
        if (!validate(true)) return;
        setBusy(true);
        setError('');
        try {
            const result = await api<MapSnapshot>('maps', 'PUT', {
                revision: saved.revision,
                values: draft,
            });
            setSaved(result);
            setDraft(result.values);
            try {
                localStorage.setItem(
                    'fleet.maps.configuration.changed',
                    result.revision,
                );
            } catch {
                /* Cross-tab refresh is optional. */
            }
            setOpen(false);
            setNotice(
                'Map configuration saved. API availability and provider billing are managed separately.',
            );
            router.reload({ only: ['fleet', 'maps'], preserveScroll: true });
        } catch (problem) {
            if (problem instanceof SettingsError && problem.status === 409)
                setLatest(problem.latest as MapSnapshot);
            else {
                if (problem instanceof SettingsError && problem.errors) {
                    const issues = Object.fromEntries(
                        Object.entries(problem.errors).map(
                            ([key, messages]) => [
                                key.replace(/^values\./, ''),
                                messages[0],
                            ],
                        ),
                    ) as MapErrors;
                    setFieldErrors(issues);
                    setStep(mapErrorStep(issues));
                }
                setError(
                    problem instanceof Error
                        ? problem.message
                        : 'Could not save. Your draft is retained.',
                );
            }
        } finally {
            setBusy(false);
        }
    }
    const rows = saved.capabilities.filter((row) =>
        row.title.toLowerCase().includes(query.toLowerCase()),
    );
    const actions = (row: MapSnapshot['capabilities'][number]) => [
        {
            label: 'Review capability',
            icon: Eye,
            onClick: () => setDetail(row),
        },
        ...(canManage && !recovery
            ? [
                  {
                      label: 'Configure Google',
                      icon: Settings2,
                      onClick: configure,
                  },
              ]
            : []),
    ];
    return (
        <div className="space-y-5">
            {recovery && (
                <Notice>
                    An unsaved map configuration was found in this browser.{' '}
                    <Button
                        variant="link"
                        onClick={() => {
                            setDraft(
                                mergeMapDraft(
                                    recovery.base,
                                    recovery.draft,
                                    saved.values,
                                ),
                            );
                            setRecovery(null);
                            setStep(0);
                            setOpen(true);
                        }}
                    >
                        Recover changes
                    </Button>
                    <Button variant="link" onClick={() => setRecovery(null)}>
                        Discard recovered draft
                    </Button>
                </Notice>
            )}
            {notice && (
                <div role="status">
                    <Notice>{notice}</Notice>
                </div>
            )}
            {error && !open && (
                <div
                    role="alert"
                    aria-label="Map configuration error"
                    ref={errorRef}
                    tabIndex={-1}
                >
                    <Notice role="note">{error}</Notice>
                </div>
            )}
            <ListCaption
                title="Map provider"
                caption="OSM by default · Google is optional"
                right={
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            onClick={reload}
                            disabled={busy || !!recovery}
                        >
                            <RefreshCw className="size-4" />
                            Reload saved
                        </Button>
                        {canManage && (
                            <Button onClick={configure} disabled={!!recovery}>
                                <Settings2 className="size-4" />
                                Configure provider
                            </Button>
                        )}
                    </div>
                }
            />
            <div className="grid gap-5 lg:grid-cols-2">
                <ReviewCard
                    icon={Map}
                    title={
                        saved.values.google
                            ? 'Google requested'
                            : 'OpenStreetMap'
                    }
                >
                    <ReviewRow
                        label="Map display"
                        value={
                            saved.capabilities.find(
                                (capability) => capability.key === 'display',
                            )?.enabled
                                ? 'Google · fallback available'
                                : 'OSM'
                        }
                    />
                    <ReviewRow
                        label="Google project"
                        value={saved.values.project || 'Not configured'}
                    />
                    <p className="text-subtle mt-3">
                        Application-owned geometry and permitted markers remain
                        with their source records. A working map does not imply
                        address search, routing or tracking authority.
                    </p>
                </ReviewCard>
                <ReviewCard icon={Shield} title="Provider health & usage">
                    <p className="text-subtle">
                        Configuration presence does not verify API access,
                        quota, billing or provider health. No paid check runs
                        automatically.
                    </p>
                    <ReviewRow
                        label="Live API verification"
                        value="Not run by this setup"
                    />
                    <ReviewRow
                        label="Quota and billing"
                        value="Check Google Cloud"
                    />
                    <p className="text-caption">
                        Recent request results below expire after 24 hours and
                        apply only to the current configuration. They are not a
                        continuous health check or a billing total.
                    </p>
                    <Button variant="link" asChild>
                        <a
                            href={googleConsoleUrl(
                                '/google/maps-apis/overview',
                                saved.values.project,
                            )}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Review Google API usage{' '}
                            <ArrowUpRight className="size-4" />
                        </a>
                    </Button>
                    <Button variant="link" asChild>
                        <a
                            href={googleConsoleUrl(
                                '/google/maps-apis/quotas',
                                saved.values.project,
                            )}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Review API quotas{' '}
                            <ArrowUpRight className="size-4" />
                        </a>
                    </Button>
                </ReviewCard>
            </div>
            <ListCaption
                title="Separately configured capabilities"
                caption={`${rows.length} of ${saved.capabilities.length} shown`}
            />
            <EntityTable
                rows={rows}
                rowKey={(row) => row.key}
                identity={(row) => ({ icon: Map, name: row.title })}
                identityLabel="Capability"
                identityWidth="1.2fr"
                minWidth={740}
                columns={[
                    {
                        key: 'status',
                        label: 'Google configuration',
                        width: '1.2fr',
                        cell: (row) => (
                            <div className="space-y-1">
                                <StatusBadge
                                    variant={
                                        row.selected && !row.enabled
                                            ? 'warning'
                                            : row.enabled
                                              ? 'info'
                                              : 'neutral'
                                    }
                                >
                                    {row.status}
                                </StatusBadge>
                                <p className="text-caption">
                                    {row.observation
                                        ? `${{ succeeded: 'Last request succeeded', quota: 'Provider quota reached', unavailable: 'Provider unavailable', rejected: 'Provider rejected the request' }[row.observation.status]} · ${formatDateTime(row.observation.observed_at)} NZ`
                                        : 'No recent provider result'}
                                </p>
                            </div>
                        ),
                    },
                    {
                        key: 'fallback',
                        label: 'When unavailable',
                        width: '2fr',
                        cell: (row) => (
                            <span className="text-subtle">
                                {fallback[row.key]}
                            </span>
                        ),
                    },
                ]}
                actionsFor={actions}
                onOpen={setDetail}
                onRowContextMenu={context.open}
            />
            <Notice>
                Boundary editing remains application-owned. Personal tracking
                source, consent and sharing stay with Client Location and People
                Locations. Enabling a provider grants no extra access.
            </Notice>
            {saved.values.google && (
                <Button variant="outline" onClick={() => setToolsOpen(true)}>
                    Explore configured map capabilities
                </Button>
            )}
            {toolsOpen && (
                <MapTools
                    settings={saved}
                    onClose={() => setToolsOpen(false)}
                />
            )}
            <Button
                variant="outline"
                onClick={() => router.visit('/fleet-assets/geofences')}
            >
                Open Maps & boundaries <ArrowUpRight className="size-4" />
            </Button>
            {context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={context.ctx.record.title}
                    items={actions(context.ctx.record)}
                    onClose={context.close}
                />
            )}
            {detail && (
                <Modal
                    title={detail.title}
                    description="Separate API capability"
                    onClose={() => setDetail(null)}
                >
                    <ReviewCard icon={Map} title="Effective configuration">
                        <ReviewRow label="Google" value={detail.status} />
                        <ReviewRow
                            label="Fallback"
                            value={fallback[detail.key]}
                        />
                    </ReviewCard>
                    <p className="text-subtle">
                        Only supported source screens use this capability.
                        Google-derived address and route results are cleared
                        when their provider becomes unavailable;
                        application-owned coordinates and geometry are
                        preserved.
                    </p>
                </Modal>
            )}
            {open && (
                <WizardShell
                    open
                    onClose={close}
                    title="Configure map provider"
                    description="Choose optional Google capabilities without changing tracking authority."
                    railIcon={Map}
                    railTitle="Map provider"
                    railSub="Optional Google setup"
                    steps={steps}
                    stepIndex={step}
                    onStepClick={(index) => {
                        if (!busy) {
                            setStep(index);
                            setError('');
                        }
                    }}
                    pct={null}
                    footerStart={
                        <>
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={close}
                            >
                                Cancel
                            </Button>
                            {step > 0 && (
                                <Button
                                    variant="ghost"
                                    disabled={busy}
                                    onClick={() => {
                                        setStep(step - 1);
                                        setError('');
                                    }}
                                >
                                    Back
                                </Button>
                            )}
                        </>
                    }
                    footerEnd={
                        step < 3 ? (
                            <Button
                                disabled={busy}
                                onClick={() => {
                                    if (validate()) setStep(step + 1);
                                }}
                            >
                                Continue
                            </Button>
                        ) : (
                            <Button disabled={busy} onClick={save}>
                                {busy ? 'Saving…' : 'Save configuration'}
                            </Button>
                        )
                    }
                >
                    <WizardStepPane key={step}>
                        <fieldset disabled={busy} className="min-w-0 space-y-5">
                            {error && (
                                <div
                                    role="alert"
                                    aria-label="Map configuration error"
                                    tabIndex={-1}
                                    ref={errorRef}
                                >
                                    <Notice role="note">{error}</Notice>
                                </div>
                            )}
                            {step === 0 && (
                                <>
                                    <div className="flex items-center justify-between gap-4">
                                        <div>
                                            <Label htmlFor="use-google">
                                                Use Google Maps
                                            </Label>
                                            <p className="text-subtle">
                                                When off, supported maps use
                                                OSM.
                                            </p>
                                        </div>
                                        <Switch
                                            id="use-google"
                                            checked={draft.google}
                                            onCheckedChange={(value) =>
                                                edit('google', value)
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="google-project">
                                            Google project reference
                                        </Label>
                                        <Input
                                            id="google-project"
                                            value={draft.project}
                                            maxLength={100}
                                            aria-invalid={!!fieldErrors.project}
                                            aria-describedby="google-project-help"
                                            onChange={(event) =>
                                                edit(
                                                    'project',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Your approved project reference"
                                        />
                                        <p
                                            id="google-project-help"
                                            className="text-caption"
                                        >
                                            {fieldErrors.project ??
                                                'Use the project ID from Google Cloud. This reference does not create a project or configure a key.'}
                                        </p>
                                    </div>
                                    <ReviewCard
                                        icon={KeyRound}
                                        title="Add your Google API keys in deployment"
                                    >
                                        <ol className="mb-4 list-decimal space-y-2 pl-5 text-sm">
                                            <li>
                                                Choose your Google Cloud project
                                                and review its billing setup.
                                                Keep Google off here until you
                                                are ready.
                                            </li>
                                            <li>
                                                Create a browser key for Maps
                                                JavaScript API. Restrict
                                                websites to your approved
                                                application domains, such as{' '}
                                                <code>
                                                    https://oblivionfindings.com/*
                                                </code>
                                                .
                                            </li>
                                            <li>
                                                For optional services, create a
                                                separate server key restricted
                                                to the deployment server’s
                                                public egress IP addresses and
                                                only the selected APIs.
                                            </li>
                                            <li>
                                                Add the keys to the deployment
                                                environment using the names
                                                below, refresh its configuration
                                                cache, then reload this wizard.
                                            </li>
                                        </ol>
                                        <ReviewRow
                                            label="Restricted browser key"
                                            value={
                                                saved.credentials.browser
                                                    ? (saved.references
                                                          ?.browser ??
                                                      'Present')
                                                    : 'Not configured'
                                            }
                                        />
                                        <ReviewRow
                                            label="Separate server key"
                                            value={
                                                saved.credentials.server
                                                    ? (saved.references
                                                          ?.server ?? 'Present')
                                                    : 'Not configured'
                                            }
                                        />
                                        <p className="text-subtle mt-3">
                                            Add a website-restricted key as{' '}
                                            <code>GOOGLE_MAPS_API_KEY</code> in
                                            your deployment environment. For
                                            address search, reverse geocoding or
                                            routes, add a separate
                                            server-restricted key as{' '}
                                            <code>
                                                GOOGLE_MAPS_SERVER_API_KEY
                                            </code>
                                            . Refresh the deployment
                                            configuration cache, then reload
                                            here. Map display alone does not
                                            need the server key.
                                        </p>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <Button variant="outline" asChild>
                                                <a
                                                    href={googleConsoleUrl(
                                                        '/google/maps-apis/credentials',
                                                        draft.project,
                                                    )}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Open Google credentials{' '}
                                                    <ArrowUpRight className="size-4" />
                                                </a>
                                            </Button>
                                            <Button
                                                variant="outline"
                                                onClick={reload}
                                                disabled={busy}
                                            >
                                                Reload configuration
                                            </Button>
                                        </div>
                                    </ReviewCard>
                                </>
                            )}
                            {step === 1 && (
                                <>
                                    <Notice>
                                        Google map display provides the map
                                        context for Google results. Address
                                        search, reverse geocoding and routing
                                        are separate optional APIs.
                                    </Notice>
                                    {saved.capabilities.map((capability) => (
                                        <Card
                                            key={capability.key}
                                            className="flex flex-row items-center justify-between gap-4 p-4"
                                        >
                                            <div>
                                                <Label
                                                    htmlFor={`google-${capability.key}`}
                                                >
                                                    {capability.title}
                                                </Label>
                                                <p className="text-caption">
                                                    {capability.key ===
                                                    'display'
                                                        ? 'Maps JavaScript API · restricted browser key'
                                                        : `${capability.key === 'places' ? 'Places API (New)' : capability.key === 'routes' ? 'Routes API' : 'Geocoding API'} · separate server key`}
                                                </p>
                                            </div>
                                            <Switch
                                                id={`google-${capability.key}`}
                                                disabled={
                                                    busy ||
                                                    !draft.google ||
                                                    capability.key === 'display'
                                                }
                                                checked={
                                                    draft.google &&
                                                    draft[
                                                        capability.key as
                                                            | 'display'
                                                            | 'places'
                                                            | 'routes'
                                                            | 'geocoding'
                                                    ]
                                                }
                                                onCheckedChange={(value) =>
                                                    edit(
                                                        capability.key as
                                                            | 'display'
                                                            | 'places'
                                                            | 'routes'
                                                            | 'geocoding',
                                                        value,
                                                    )
                                                }
                                            />
                                        </Card>
                                    ))}
                                    <Notice>
                                        Each API must also be enabled for your
                                        Google project. Optional capabilities
                                        can incur provider charges.
                                    </Notice>
                                </>
                            )}
                            {step === 2 && (
                                <>
                                    <h2 className="text-section-title">
                                        Check the setup before saving
                                    </h2>
                                    <Notice>
                                        This checks configuration presence only.
                                        It makes no Google request and cannot
                                        verify key restrictions, enabled APIs,
                                        quota or billing.
                                    </Notice>
                                    {saved.capabilities.map((capability) => {
                                        const selected =
                                            draft.google &&
                                            draft[
                                                capability.key as
                                                    | 'display'
                                                    | 'places'
                                                    | 'geocoding'
                                                    | 'routes'
                                            ];
                                        const present =
                                            saved.credentials[
                                                capability.key === 'display'
                                                    ? 'browser'
                                                    : 'server'
                                            ];
                                        return (
                                            <ReviewCard
                                                key={capability.key}
                                                icon={Map}
                                                title={capability.title}
                                            >
                                                <ReviewRow
                                                    label="Selection"
                                                    value={
                                                        selected
                                                            ? 'Selected'
                                                            : 'Off'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Credential"
                                                    value={
                                                        present
                                                            ? 'Present · API not verified'
                                                            : 'Missing'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="When unavailable"
                                                    value={
                                                        fallback[capability.key]
                                                    }
                                                />
                                            </ReviewCard>
                                        );
                                    })}
                                    <Button variant="outline" asChild>
                                        <a
                                            href={googleConsoleUrl(
                                                '/google/maps-apis/api-list',
                                                draft.project,
                                            )}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Review enabled Google APIs{' '}
                                            <ArrowUpRight className="size-4" />
                                        </a>
                                    </Button>
                                </>
                            )}
                            {step === 3 && (
                                <>
                                    <ReviewCard
                                        icon={Map}
                                        title="Configuration impact"
                                        onEdit={() => setStep(1)}
                                    >
                                        <ReviewRow
                                            label="Provider"
                                            value={
                                                draft.google
                                                    ? 'Google with OSM fallback'
                                                    : 'OSM'
                                            }
                                        />
                                        <ReviewRow
                                            label="Project"
                                            value={draft.project}
                                        />
                                        <ReviewRow
                                            label="Selected APIs"
                                            value={
                                                saved.capabilities
                                                    .filter(
                                                        (capability) =>
                                                            draft.google &&
                                                            draft[
                                                                capability.key as
                                                                    | 'display'
                                                                    | 'places'
                                                                    | 'routes'
                                                                    | 'geocoding'
                                                            ],
                                                    )
                                                    .map(
                                                        (capability) =>
                                                            capability.title,
                                                    )
                                                    .join(', ') || 'None'
                                            }
                                        />
                                        <p className="text-subtle mt-3">
                                            Existing boundaries, bookings and
                                            permitted source markers remain.
                                            Google-derived results must be shown
                                            with the required provider
                                            attribution and map context.
                                        </p>
                                    </ReviewCard>
                                    <div className="flex items-start justify-between gap-4">
                                        <Label htmlFor="restrictions-reviewed">
                                            The deployment owner has restricted
                                            each configured key to its approved
                                            websites or server IP addresses and
                                            selected APIs.
                                        </Label>
                                        <Switch
                                            id="restrictions-reviewed"
                                            checked={
                                                draft.restrictions_reviewed
                                            }
                                            onCheckedChange={(value) =>
                                                edit(
                                                    'restrictions_reviewed',
                                                    value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="flex items-start justify-between gap-4">
                                        <Label htmlFor="terms-reviewed">
                                            The organisation has reviewed
                                            provider terms, privacy notices,
                                            billing and quotas for the selected
                                            APIs.
                                        </Label>
                                        <Switch
                                            id="terms-reviewed"
                                            checked={draft.terms_reviewed}
                                            onCheckedChange={(value) =>
                                                edit('terms_reviewed', value)
                                            }
                                        />
                                    </div>
                                    <Notice>
                                        This saves configuration. It does not
                                        test a provider or change Google project
                                        billing and key restrictions.
                                    </Notice>
                                    <p className="text-subtle">
                                        Set conservative API quotas in Google
                                        Cloud before enabling services. Budget
                                        alerts notify you; they do not cap
                                        spending.{' '}
                                        <a
                                            className="text-primary underline"
                                            href="https://developers.google.com/maps/billing-and-pricing/manage-costs"
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Review Google’s cost controls
                                        </a>
                                        .
                                    </p>
                                    <ReviewCard
                                        icon={Map}
                                        title="Affected workspaces"
                                    >
                                        <ReviewRow
                                            label="Fleet, Assets and shared maps"
                                            value="Map display and permitted source overlays"
                                        />
                                        <ReviewRow
                                            label="Maps & boundaries"
                                            value="Canonical boundary tools and geometry remain with their owner"
                                        />
                                        <ReviewRow
                                            label="Transport"
                                            value="Saved sites and manual addresses remain available"
                                        />
                                        <ReviewRow
                                            label="Client and People locations"
                                            value="Existing consent, source and site permissions still apply"
                                        />
                                    </ReviewCard>
                                </>
                            )}
                        </fieldset>
                    </WizardStepPane>
                </WizardShell>
            )}
            {discard && (
                <Modal
                    title="Discard map changes?"
                    description="Your saved map configuration will be kept."
                    onClose={() => setDiscard(false)}
                    onCloseAutoFocus={(event) => {
                        if (discardClosesProvider.current) {
                            // The closing provider owns restoration to its opener.
                            event.preventDefault();
                            discardClosesProvider.current = false;
                        }
                    }}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDiscard(false)}
                            >
                                Keep editing
                            </Button>
                            <Button
                                onClick={() => {
                                    discardClosesProvider.current = true;
                                    setDiscard(false);
                                    setOpen(false);
                                    setDraft(saved.values);
                                }}
                            >
                                Discard changes
                            </Button>
                        </>
                    }
                >
                    The unsaved provider changes will be removed.
                </Modal>
            )}
            {latest && (
                <Modal
                    title="Map configuration changed"
                    description="Saved settings or deployment credentials changed. Keep your edited fields and review them against the current configuration, or load the saved configuration."
                    onClose={() => setLatest(null)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setSaved(latest);
                                    setDraft(latest.values);
                                    setLatest(null);
                                    setStep(0);
                                }}
                            >
                                Load current
                            </Button>
                            <Button
                                onClick={() => {
                                    setDraft(
                                        mergeMapDraft(
                                            saved.values,
                                            draft,
                                            latest.values,
                                        ),
                                    );
                                    setSaved(latest);
                                    setLatest(null);
                                    setStep(3);
                                }}
                            >
                                Keep my changes
                            </Button>
                        </>
                    }
                >
                    <ReviewRow
                        label="Current provider"
                        value={latest.values.google ? 'Google' : 'OSM'}
                    />
                    <ReviewRow
                        label="Current project"
                        value={latest.values.project}
                    />
                </Modal>
            )}
        </div>
    );
}
