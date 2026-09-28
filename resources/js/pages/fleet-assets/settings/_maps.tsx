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
import { router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Eye,
    KeyRound,
    Map,
    Settings2,
    Shield,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
        key: 'review',
        label: 'Review & save',
        blurb: 'Restrictions, costs and impact',
        icon: Shield,
    },
];
const fallback: Record<string, string> = {
    display: 'OSM basemap and application-owned markers remain usable.',
    places: 'Choose an existing site or permitted coordinates.',
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
        setOpen(true);
    };
    const close = () => {
        if (busy) return;
        if (JSON.stringify(draft) !== JSON.stringify(saved.values))
            setDiscard(true);
        else setOpen(false);
    };
    const edit = <K extends keyof MapValues>(key: K, value: MapValues[K]) =>
        setDraft((current) => ({ ...current, [key]: value }));
    async function save() {
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
            else
                setError(
                    problem instanceof Error
                        ? problem.message
                        : 'Could not save. Your draft is retained.',
                );
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
            <ListCaption
                title="Map provider"
                caption="OSM by default · Google is optional"
                right={
                    canManage && (
                        <Button onClick={configure} disabled={!!recovery}>
                            <Settings2 className="size-4" />
                            Configure provider
                        </Button>
                    )
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
                    <Button variant="link" asChild>
                        <a
                            href="https://console.cloud.google.com/google/maps-apis/overview"
                            target="_blank"
                            rel="noreferrer"
                        >
                            Review Google API usage{' '}
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
                            <StatusBadge
                                variant={row.enabled ? 'info' : 'neutral'}
                            >
                                {row.status}
                            </StatusBadge>
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
                    onStepClick={setStep}
                    pct={null}
                    footerStart={
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                    }
                    footerEnd={
                        step < 2 ? (
                            <Button onClick={() => setStep(step + 1)}>
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
                        <div className="space-y-5">
                            {error && (
                                <div role="alert">
                                    <Notice>{error}</Notice>
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
                                            onChange={(event) =>
                                                edit(
                                                    'project',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Your approved project reference"
                                        />
                                    </div>
                                    <ReviewCard
                                        icon={KeyRound}
                                        title="Deployment-owned credentials"
                                    >
                                        <ReviewRow
                                            label="Restricted browser key"
                                            value={
                                                saved.credentials.browser
                                                    ? 'Present'
                                                    : 'Not configured'
                                            }
                                        />
                                        <ReviewRow
                                            label="Separate server key"
                                            value={
                                                saved.credentials.server
                                                    ? 'Present'
                                                    : 'Not configured'
                                            }
                                        />
                                        <p className="text-subtle mt-3">
                                            Ask the deployment owner to
                                            configure the browser and server
                                            credentials. Secret values are never
                                            entered or shown here. Browser-only
                                            map display does not need a server
                                            key.
                                        </p>
                                    </ReviewCard>
                                </>
                            )}
                            {step === 1 && (
                                <>
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
                                                checked={
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
                                    <ReviewCard
                                        icon={Map}
                                        title="Configuration impact"
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
                                            the browser key by website and API,
                                            and the server key by server access
                                            and API.
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
                                </>
                            )}
                        </div>
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
                    description="Another administrator saved changes. Keep your edited fields and review them against the current configuration, or load the saved configuration."
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
                                    setStep(2);
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
