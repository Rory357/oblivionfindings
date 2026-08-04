import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    Loader2,
    MapPin,
    RefreshCw,
    ShieldAlert,
    Trash2,
    Webhook,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import {
    SiteCredentialsCard,
    type SiteCredentialRow,
} from './site-credentials';

// ── Types ─────────────────────────────────────────────────────────

type ConnectionStatus = 'connected' | 'disconnected' | 'error';

type ProviderConnection = {
    status: ConnectionStatus;
    secret_last4?: string;
    last_tested_at?: string;
    last_synced_at?: string;
    endpoint_configured: boolean;
    client_id_configured: boolean;
    applications_synced_at?: string | null;
    webhook_configured: boolean;
    webhook_secret_last4?: string | null;
    webhook_url: string;
    last_webhook_received_at?: string | null;
} | null;

type DiscoveredApplication = {
    mapping_token: string;
    name: string;
    device_count?: number | null;
};

type SiteConfig = {
    id: number;
    site_id: number;
    site_name: string;
    site_type?: string | null;
    mapped_external_site_name?: string | null;
    is_active: boolean;
};

type SiteOption = {
    id: number;
    name: string;
    type?: string | null;
};

type SyncLog = {
    id: number;
    action: string;
    status: string;
    items_processed: number;
    items_created: number;
    items_updated: number;
    items_errored: number;
    failure_category?: string | null;
    started_at: string;
    completed_at?: string | null;
};

type Props = {
    providerConnection: ProviderConnection;
    discoveredApplications: DiscoveredApplication[];
    siteConfigs: SiteConfig[];
    sites: SiteOption[];
    syncLogs: SyncLog[];
    siteCredentials: SiteCredentialRow[];
    can: {
        manage: boolean;
    };
};

// ── Helpers ───────────────────────────────────────────────────────

const connectionStatusConfig: Record<
    ConnectionStatus,
    { label: string; className: string; icon: typeof CheckCircle }
> = {
    connected: {
        label: 'Connected',
        className:
            'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
        icon: CheckCircle,
    },
    disconnected: {
        label: 'Disconnected',
        className:
            'bg-muted text-foreground dark:bg-muted/50 dark:text-muted-foreground',
        icon: XCircle,
    },
    error: {
        label: 'Error',
        className:
            'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
        icon: ShieldAlert,
    },
};

function fmt(value?: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleString();
}

// ── Page ──────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Security & Devices', href: '/security-devices' },
    { title: 'APIs & Integrations', href: '/security-devices/integrations' },
    { title: 'Milesight', href: '/security-devices/integrations/milesight' },
];

export default function MilesightIntegration({
    providerConnection,
    discoveredApplications,
    siteConfigs,
    sites,
    syncLogs,
    siteCredentials,
    can,
}: Props) {
    const [showRotateForm, setShowRotateForm] = useState(false);
    const [testingConnection, setTestingConnection] = useState(false);
    const [syncingApplications, setSyncingApplications] = useState(false);
    const [syncingSiteConfigId, setSyncingSiteConfigId] = useState<
        number | null
    >(null);
    const [mappingSites, setMappingSites] = useState<Record<string, string>>(
        {},
    );
    const [removeCredentialsOpen, setRemoveCredentialsOpen] = useState(false);
    const [disableWebhookOpen, setDisableWebhookOpen] = useState(false);
    const [mappingToRemove, setMappingToRemove] = useState<SiteConfig | null>(
        null,
    );

    const saveKeyForm = useForm<{
        client_id: string;
        client_secret: string;
        base_url: string;
    }>({
        client_id: '',
        client_secret: '',
        base_url: '',
    });

    const rotateKeyForm = useForm<{ client_secret: string }>({
        client_secret: '',
    });
    const webhookSecretForm = useForm<{ webhook_secret: string }>({
        webhook_secret: '',
    });

    const hasKey = !!providerConnection?.client_id_configured;
    const connStatus = providerConnection
        ? (connectionStatusConfig[providerConnection.status] ??
          connectionStatusConfig.disconnected)
        : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Milesight Integration" />
            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/security-devices/integrations"
                        backLabel="Back to APIs & Integrations"
                        title="Milesight Integration"
                        description="LoRaWAN sensors and gateways — environmental, occupancy, leak, and resident-support IoT."
                    />
                }
            >
                <Card className="border-status-info/30 bg-status-info-bg dark:border-status-info/30">
                    <CardContent className="flex items-start gap-3 p-4 text-sm">
                        <CheckCircle className="mt-0.5 h-4 w-4 text-status-info dark:text-status-info" />
                        <div className="space-y-1 leading-6">
                            <p className="font-medium">
                                OAuth inventory and Device Registry sync
                            </p>
                            <p className="text-muted-foreground">
                                Connect the Milesight Development Platform,
                                discover applications, map each one to an
                                approved Site, then import its gateways and
                                sensors into the canonical Device Registry.
                            </p>
                        </div>
                    </CardContent>
                </Card>

                {/* ── Credentials ──────────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>API credentials</CardTitle>
                        <CardDescription>
                            Stored encrypted for this application. Only the last
                            four characters are ever shown.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {!hasKey ? (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    saveKeyForm.post(
                                        '/security-devices/integrations/milesight/key',
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                saveKeyForm.reset(
                                                    'client_secret',
                                                ),
                                        },
                                    );
                                }}
                                className="space-y-4"
                            >
                                <div className="space-y-2">
                                    <Label htmlFor="client_id">
                                        OAuth client ID
                                    </Label>
                                    <Input
                                        id="client_id"
                                        value={saveKeyForm.data.client_id}
                                        onChange={(e) =>
                                            saveKeyForm.setData(
                                                'client_id',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Milesight client ID"
                                        required
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="base_url">
                                        Server URL (optional)
                                    </Label>
                                    <Input
                                        id="base_url"
                                        type="url"
                                        value={saveKeyForm.data.base_url}
                                        onChange={(e) =>
                                            saveKeyForm.setData(
                                                'base_url',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="https://mdp-api.milesight.com"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Defaults to the Milesight Development
                                        Platform. Only an HTTPS regional API
                                        endpoint should be entered here.
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="client_secret">
                                        OAuth client secret
                                    </Label>
                                    <Input
                                        id="client_secret"
                                        type="password"
                                        value={saveKeyForm.data.client_secret}
                                        onChange={(e) =>
                                            saveKeyForm.setData(
                                                'client_secret',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Milesight client secret"
                                        required
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={
                                        !can.manage ||
                                        saveKeyForm.processing ||
                                        !saveKeyForm.data.client_id ||
                                        !saveKeyForm.data.client_secret
                                    }
                                >
                                    {saveKeyForm.processing
                                        ? 'Saving…'
                                        : 'Save credentials'}
                                </Button>
                            </form>
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center gap-3">
                                    <span className="text-sm">
                                        Client secret ending in{' '}
                                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                            •••
                                            {providerConnection?.secret_last4}
                                        </code>
                                    </span>
                                    {connStatus && (
                                        <Badge className={connStatus.className}>
                                            <connStatus.icon className="mr-1 h-3 w-3" />
                                            {connStatus.label}
                                        </Badge>
                                    )}
                                </div>
                                {providerConnection?.endpoint_configured && (
                                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <MapPin className="h-3.5 w-3.5" />
                                        Provider endpoint configured
                                    </div>
                                )}
                                <div className="space-y-1 text-sm text-muted-foreground">
                                    <p>
                                        Last tested:{' '}
                                        <span className="text-foreground">
                                            {fmt(
                                                providerConnection?.last_tested_at,
                                            )}
                                        </span>
                                    </p>
                                    <p>
                                        Last sync:{' '}
                                        <span className="text-foreground">
                                            {fmt(
                                                providerConnection?.last_synced_at,
                                            )}
                                        </span>
                                    </p>
                                </div>
                                {providerConnection?.status === 'error' && (
                                    <div className="flex items-start gap-2 rounded-md border border-status-critical/30 bg-status-critical-bg p-3 text-xs text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical">
                                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>
                                            The provider connection needs
                                            attention. Test the connection or
                                            retry.
                                        </span>
                                    </div>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setTestingConnection(true);
                                            router.post(
                                                '/security-devices/integrations/milesight/test',
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    onFinish: () =>
                                                        setTestingConnection(
                                                            false,
                                                        ),
                                                },
                                            );
                                        }}
                                        disabled={
                                            !can.manage || testingConnection
                                        }
                                    >
                                        {testingConnection ? (
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                            <RefreshCw className="mr-2 h-4 w-4" />
                                        )}
                                        Test connection
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setShowRotateForm((p) => !p)
                                        }
                                        disabled={!can.manage}
                                    >
                                        Rotate secret
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-status-critical hover:text-status-critical"
                                        onClick={() =>
                                            setRemoveCredentialsOpen(true)
                                        }
                                        disabled={!can.manage}
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />
                                        Remove
                                    </Button>
                                </div>
                                {showRotateForm && (
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            rotateKeyForm.post(
                                                '/security-devices/integrations/milesight/rotate',
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => {
                                                        rotateKeyForm.reset(
                                                            'client_secret',
                                                        );
                                                        setShowRotateForm(
                                                            false,
                                                        );
                                                    },
                                                },
                                            );
                                        }}
                                        className="space-y-3 rounded-lg border p-4"
                                    >
                                        <Label htmlFor="rotate_client_secret">
                                            New client secret
                                        </Label>
                                        <Input
                                            id="rotate_client_secret"
                                            type="password"
                                            value={
                                                rotateKeyForm.data.client_secret
                                            }
                                            onChange={(e) =>
                                                rotateKeyForm.setData(
                                                    'client_secret',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <div className="flex gap-2">
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={
                                                    rotateKeyForm.processing ||
                                                    !rotateKeyForm.data
                                                        .client_secret
                                                }
                                            >
                                                {rotateKeyForm.processing
                                                    ? 'Saving…'
                                                    : 'Save new key'}
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => {
                                                    setShowRotateForm(false);
                                                    rotateKeyForm.reset(
                                                        'client_secret',
                                                    );
                                                }}
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    </form>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="space-y-1.5">
                                <CardTitle
                                    role="heading"
                                    aria-level={2}
                                    className="flex items-center gap-2"
                                >
                                    <Webhook className="h-4 w-4" />
                                    Real-time monitoring webhook
                                </CardTitle>
                                <CardDescription>
                                    Receive signed Milesight status, sensor and
                                    safety events through Oblivion Findings'
                                    native Monitoring runtime. Events remain
                                    linked to the canonical Device and Site.
                                </CardDescription>
                            </div>
                            <Badge
                                className={
                                    providerConnection?.webhook_configured
                                        ? 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success'
                                        : 'bg-muted text-muted-foreground'
                                }
                            >
                                {providerConnection?.webhook_configured
                                    ? 'Signature verification enabled'
                                    : 'Not configured'}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(22rem,0.8fr)]">
                            <div className="space-y-2">
                                <Label htmlFor="milesight_webhook_url">
                                    Callback URL
                                </Label>
                                <Input
                                    id="milesight_webhook_url"
                                    value={
                                        providerConnection?.webhook_url ??
                                        'Save API credentials to create the callback.'
                                    }
                                    readOnly
                                />
                                <p className="text-xs leading-5 text-muted-foreground">
                                    Add this HTTPS callback to the same
                                    Milesight Development Platform application
                                    that owns the mapped Devices. Then use
                                    Milesight's Test action.
                                </p>
                            </div>
                            <div className="rounded-lg border bg-muted/20 p-4 text-sm">
                                <p className="font-medium">
                                    What happens after verification
                                </p>
                                <p className="mt-1 leading-6 text-muted-foreground">
                                    Signed batches are replay-protected, matched
                                    to one current Device and Site, and queued
                                    through the common Monitoring event path.
                                    Unknown Devices and mismatched Sites are
                                    rejected before anything is stored.
                                </p>
                            </div>
                        </div>

                        {providerConnection?.webhook_configured && (
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-status-success/25 bg-status-success-bg p-4 text-sm">
                                <div className="space-y-1">
                                    <p className="font-medium text-status-success">
                                        Webhook secret ending in •••
                                        {
                                            providerConnection.webhook_secret_last4
                                        }
                                    </p>
                                    <p className="text-muted-foreground">
                                        Last verified event:{' '}
                                        <span className="text-foreground">
                                            {fmt(
                                                providerConnection.last_webhook_received_at,
                                            )}
                                        </span>
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="text-status-critical hover:text-status-critical"
                                    disabled={!can.manage}
                                    onClick={() => setDisableWebhookOpen(true)}
                                >
                                    Disable webhook
                                </Button>
                            </div>
                        )}

                        <form
                            className="space-y-3 rounded-lg border p-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                webhookSecretForm.post(
                                    '/security-devices/integrations/milesight/webhook',
                                    {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            webhookSecretForm.reset(
                                                'webhook_secret',
                                            ),
                                    },
                                );
                            }}
                        >
                            <div className="space-y-2">
                                <Label htmlFor="milesight_webhook_secret">
                                    {providerConnection?.webhook_configured
                                        ? 'Replace webhook secret key'
                                        : 'Webhook secret key'}
                                </Label>
                                <Input
                                    id="milesight_webhook_secret"
                                    type="password"
                                    autoComplete="new-password"
                                    value={
                                        webhookSecretForm.data.webhook_secret
                                    }
                                    onChange={(event) =>
                                        webhookSecretForm.setData(
                                            'webhook_secret',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Paste the Milesight application webhook secret"
                                    required
                                />
                                <p className="text-xs text-muted-foreground">
                                    Stored encrypted and never displayed again.
                                    This is separate from the OAuth client
                                    secret above.
                                </p>
                            </div>
                            <Button
                                type="submit"
                                disabled={
                                    !can.manage ||
                                    !hasKey ||
                                    webhookSecretForm.processing ||
                                    webhookSecretForm.data.webhook_secret
                                        .length < 16
                                }
                            >
                                {webhookSecretForm.processing
                                    ? 'Saving…'
                                    : providerConnection?.webhook_configured
                                      ? 'Replace verification secret'
                                      : 'Enable verified webhook'}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-1.5">
                            <CardTitle>Applications and Site mapping</CardTitle>
                            <CardDescription>
                                Discover Milesight applications, then map each
                                application to the Oblivion Findings Site it
                                belongs to. Imports cannot move a Device across
                                Sites.
                            </CardDescription>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                setSyncingApplications(true);
                                router.post(
                                    '/security-devices/integrations/milesight/applications/sync',
                                    {},
                                    {
                                        preserveScroll: true,
                                        onFinish: () =>
                                            setSyncingApplications(false),
                                    },
                                );
                            }}
                            disabled={
                                !can.manage ||
                                providerConnection?.status !== 'connected' ||
                                syncingApplications
                            }
                        >
                            {syncingApplications ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <RefreshCw className="mr-2 h-4 w-4" />
                            )}
                            Discover applications
                        </Button>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <p className="text-xs text-muted-foreground">
                            Last discovered:{' '}
                            <span className="text-foreground">
                                {fmt(
                                    providerConnection?.applications_synced_at,
                                )}
                            </span>
                        </p>

                        {discoveredApplications.length === 0 ? (
                            <div className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                                Test the connection, then discover applications.
                                Applications are derived from the bounded device
                                inventory returned by Milesight.
                            </div>
                        ) : (
                            <div className="grid gap-3 xl:grid-cols-2">
                                {discoveredApplications.map((application) => {
                                    const selectedSite =
                                        mappingSites[
                                            application.mapping_token
                                        ] ?? '';

                                    return (
                                        <div
                                            key={application.mapping_token}
                                            className="space-y-3 rounded-xl border p-4"
                                        >
                                            <div>
                                                <p className="font-medium">
                                                    {application.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {application.device_count ??
                                                        0}{' '}
                                                    devices reported
                                                </p>
                                            </div>
                                            <div className="flex flex-col gap-2 sm:flex-row">
                                                <select
                                                    aria-label={`Site for ${application.name}`}
                                                    className="h-9 min-w-0 flex-1 rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                    value={selectedSite}
                                                    onChange={(event) =>
                                                        setMappingSites(
                                                            (current) => ({
                                                                ...current,
                                                                [application.mapping_token]:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Select an approved Site
                                                    </option>
                                                    {sites.map((site) => (
                                                        <option
                                                            key={site.id}
                                                            value={site.id}
                                                        >
                                                            {site.name}
                                                        </option>
                                                    ))}
                                                </select>
                                                <Button
                                                    size="sm"
                                                    disabled={
                                                        !can.manage ||
                                                        selectedSite === ''
                                                    }
                                                    onClick={() =>
                                                        router.post(
                                                            '/security-devices/integrations/milesight/applications/map',
                                                            {
                                                                site_id:
                                                                    Number(
                                                                        selectedSite,
                                                                    ),
                                                                mapping_token:
                                                                    application.mapping_token,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <MapPin className="mr-2 h-4 w-4" />
                                                    Map to Site
                                                </Button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}

                        <div className="space-y-3">
                            <h3 className="text-sm font-semibold">
                                Active Site mappings
                            </h3>
                            {siteConfigs.length === 0 ? (
                                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                    No Milesight application is mapped yet.
                                </p>
                            ) : (
                                siteConfigs.map((siteConfig) => (
                                    <div
                                        key={siteConfig.id}
                                        className="flex flex-col gap-3 rounded-xl border p-4 lg:flex-row lg:items-center lg:justify-between"
                                    >
                                        <div>
                                            <p className="font-medium">
                                                {siteConfig.site_name}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {siteConfig.mapped_external_site_name ??
                                                    'Milesight application'}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={
                                                    !can.manage ||
                                                    syncingSiteConfigId !==
                                                        null ||
                                                    providerConnection?.status !==
                                                        'connected'
                                                }
                                                onClick={() => {
                                                    setSyncingSiteConfigId(
                                                        siteConfig.id,
                                                    );
                                                    router.post(
                                                        '/security-devices/integrations/milesight/devices/sync',
                                                        {
                                                            site_config_id:
                                                                siteConfig.id,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                            onFinish: () =>
                                                                setSyncingSiteConfigId(
                                                                    null,
                                                                ),
                                                        },
                                                    );
                                                }}
                                            >
                                                {syncingSiteConfigId ===
                                                siteConfig.id ? (
                                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                ) : (
                                                    <RefreshCw className="mr-2 h-4 w-4" />
                                                )}
                                                Sync Devices
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="text-status-critical hover:text-status-critical"
                                                disabled={!can.manage}
                                                onClick={() =>
                                                    setMappingToRemove(
                                                        siteConfig,
                                                    )
                                                }
                                            >
                                                <Trash2 className="mr-2 h-4 w-4" />
                                                Remove mapping
                                            </Button>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </CardContent>
                </Card>

                <SiteCredentialsCard rows={siteCredentials} />

                {/* ── Recent activity ──────────────────────────────────── */}
                {syncLogs.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent activity</CardTitle>
                            <CardDescription>
                                Most recent connection tests and sync attempts
                                for this provider.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Action</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Started</TableHead>
                                            <TableHead>Completed</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {syncLogs.map((log) => (
                                            <TableRow key={log.id}>
                                                <TableCell className="font-medium">
                                                    {log.action}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {log.status}
                                                    </Badge>
                                                    {log.failure_category && (
                                                        <p className="mt-1 text-xs text-status-critical">
                                                            Provider operation
                                                            failed. Retry or
                                                            review the bounded
                                                            diagnostics.
                                                        </p>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {log.started_at}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {log.completed_at ?? '—'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* ── Sensor coverage ─────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>Imported Device classification</CardTitle>
                        <CardDescription>
                            Imported gateways and sensors appear in the same
                            canonical Device Registry used by Sites, Client
                            profiles, monitoring, maintenance, and IT tickets.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm md:grid-cols-3">
                        <div className="rounded-xl border p-4">
                            <p className="font-medium">Resident support</p>
                            <p className="leading-6 text-muted-foreground">
                                Bed occupancy, fall detection, panic button,
                                door contact.
                            </p>
                        </div>
                        <div className="rounded-xl border p-4">
                            <p className="font-medium">Environmental</p>
                            <p className="leading-6 text-muted-foreground">
                                Temperature / humidity, air quality, CO₂,
                                occupancy counting.
                            </p>
                        </div>
                        <div className="rounded-xl border p-4">
                            <p className="font-medium">Facilities</p>
                            <p className="leading-6 text-muted-foreground">
                                Water leak, valve control, power monitoring,
                                cold-chain temperature loggers.
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <ConfirmDialog
                    open={removeCredentialsOpen}
                    onClose={() => setRemoveCredentialsOpen(false)}
                    onConfirm={() =>
                        router.delete(
                            '/security-devices/integrations/milesight/key',
                            { preserveScroll: true },
                        )
                    }
                    title="Remove Milesight credentials?"
                    description="Remove the OAuth connection? Devices already synced remain in the canonical registry, but discovery, monitoring and future syncs stop until credentials are configured again."
                    confirmText="Remove credentials"
                />

                <ConfirmDialog
                    open={disableWebhookOpen}
                    onClose={() => setDisableWebhookOpen(false)}
                    onConfirm={() =>
                        router.delete(
                            '/security-devices/integrations/milesight/webhook',
                            { preserveScroll: true },
                        )
                    }
                    title="Disable Milesight webhook verification?"
                    description="New callbacks will be rejected until a verified webhook secret is configured again. Existing Device and monitoring history is preserved."
                    confirmText="Disable webhook"
                />

                <ConfirmDialog
                    open={mappingToRemove !== null}
                    onClose={() => setMappingToRemove(null)}
                    onConfirm={() => {
                        if (mappingToRemove) {
                            router.delete(
                                `/security-devices/integrations/milesight/applications/${mappingToRemove.id}`,
                                { preserveScroll: true },
                            );
                        }
                    }}
                    title="Remove Milesight Site mapping?"
                    description={
                        mappingToRemove
                            ? `Remove the Milesight application mapping for “${mappingToRemove.site_name}”? Imported Devices remain in the canonical registry, but this Site will no longer receive Milesight sync or monitoring updates.`
                            : ''
                    }
                    confirmText="Remove mapping"
                />
            </PageLayout>
        </AppLayout>
    );
}
