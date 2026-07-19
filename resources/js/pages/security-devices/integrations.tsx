import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Database,
    MapPin,
    Plug,
    RefreshCw,
} from 'lucide-react';
import type { HTMLAttributes } from 'react';

import { StatCard, formatTimeSince } from './devices/shared';

export type ProviderException = {
    type: string;
    summary: string;
    action: string;
    href: string | null;
    count: number;
};
export type Provider = {
    slug: string;
    name: string;
    vendor: string;
    summary: string;
    implementation_status: 'live' | 'scaffold';
    capabilities: string[];
    device_scope: string[];
    docs_href: string | null;
    connection_status:
        | 'connected'
        | 'disconnected'
        | 'error'
        | 'untested'
        | 'disabled'
        | 'not_configured';
    connected: boolean;
    last_tested_at: string | null;
    last_synced_at: string | null;
    device_count: number;
    events_24h: number;
    credential?: {
        configured: boolean;
        reference: string | null;
        reference_label: string | null;
        display_state:
            | 'tenant_credential_configured'
            | 'site_credentials_configured'
            | 'not_configured';
        rotation_state: string;
        rotation_cadence_days: number;
        rotated_at: string | null;
        created_at: string | null;
        last_tested_at: string | null;
        site_credentials: {
            total: number;
            enabled: number;
            needs_attention: number;
            capabilities: string[];
        };
    };
    site_mapping: {
        total: number;
        mapped: number;
        unmapped: number;
        sites: Array<{ id: number; name: string | null; state: string }>;
    };
    sync: {
        status: string;
        freshness: string;
        last_synced_at: string | null;
        items_processed: number;
        items_errored: number;
        stale_site_count: number;
        affected_site_count: number;
        summary: string | null;
    };
    reconciliation: {
        imported_devices: number;
        unassigned_devices: number;
        duplicate_candidates: number;
        unsupported_checks: number;
    };
    monitoring_support: {
        state: 'not_assessed';
        scope: 'provider';
        note: string;
    };
    exceptions: ProviderException[];
    exception_count: number;
};

function ConnectionBadge({ provider }: { provider: Provider }) {
    if (provider.connection_status === 'connected')
        return (
            <Badge className="gap-1 bg-status-success text-white">
                <CheckCircle2 className="h-3 w-3" />
                Connected
            </Badge>
        );
    if (provider.connection_status === 'error')
        return (
            <Badge variant="destructive" className="gap-1">
                <AlertTriangle className="h-3 w-3" />
                Error
            </Badge>
        );
    if (provider.connection_status === 'untested')
        return (
            <Badge variant="secondary" className="gap-1">
                <Plug className="h-3 w-3" />
                Not tested
            </Badge>
        );
    if (provider.connection_status === 'disabled')
        return (
            <Badge variant="secondary" className="gap-1">
                <Plug className="h-3 w-3" />
                Disabled
            </Badge>
        );
    return (
        <Badge variant="secondary" className="gap-1">
            <Plug className="h-3 w-3" />
            {provider.connection_status === 'not_configured'
                ? 'Not configured'
                : 'Disconnected'}
        </Badge>
    );
}

export function CredentialRotationStatus({
    state,
    cadenceDays,
    ...props
}: {
    state: string;
    cadenceDays: number;
} & HTMLAttributes<HTMLParagraphElement>) {
    const presentation =
        state === 'rotation_due'
            ? {
                  label: `Rotation due (${cadenceDays}-day cadence)`,
                  variant: 'warning',
                  className: 'text-status-warning',
              }
            : state === 'current'
              ? {
                    label: 'Rotation current',
                    variant: 'default',
                    className: 'text-muted-foreground',
                }
              : state === 'not_configured'
                ? {
                      label: 'Credential not configured',
                      variant: 'secondary',
                      className: 'text-muted-foreground',
                  }
                : {
                      label: 'Rotation status unknown',
                      variant: 'secondary',
                      className: 'text-muted-foreground',
                  };

    return (
        <p
            {...props}
            className={`${presentation.className} ${props.className ?? ''}`.trim()}
            data-variant={presentation.variant}
        >
            {presentation.label}
        </p>
    );
}

export function ProviderCard({
    provider,
    canManage,
}: {
    provider: Provider;
    canManage: boolean;
}) {
    return (
        <Card className="min-w-0">
            <CardHeader className="space-y-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle>{provider.name}</CardTitle>
                        <p className="text-xs text-muted-foreground">
                            {provider.vendor}
                        </p>
                    </div>
                    <div className="flex flex-wrap justify-end gap-2">
                        {provider.implementation_status === 'scaffold' ? (
                            <Badge variant="outline">Adapter scaffold</Badge>
                        ) : null}
                        <ConnectionBadge provider={provider} />
                    </div>
                </div>
                <p className="text-sm text-muted-foreground">
                    {provider.summary}
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-3 text-sm">
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground">Imported</p>
                        <p className="text-xl font-semibold">
                            {provider.reconciliation.imported_devices}
                        </p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground">Site mapping</p>
                        <p className="font-semibold">
                            {provider.site_mapping.mapped} of{' '}
                            {provider.site_mapping.total}
                        </p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground">Last sync</p>
                        <p className="font-medium capitalize">
                            {provider.sync.freshness === 'never'
                                ? 'Not run'
                                : formatTimeSince(provider.sync.last_synced_at)}
                        </p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground">Exceptions</p>
                        <p className="text-xl font-semibold">
                            {provider.exception_count}
                        </p>
                    </div>
                </div>

                {provider.credential ? (
                    <div className="rounded-lg bg-muted/40 p-3 text-sm">
                        <p className="font-medium">
                            {provider.credential.reference_label ??
                                (provider.credential.display_state ===
                                'site_credentials_configured'
                                    ? 'Site credentials configured'
                                    : 'Credential not configured')}
                        </p>
                        <CredentialRotationStatus
                            state={provider.credential.rotation_state}
                            cadenceDays={
                                provider.credential.rotation_cadence_days
                            }
                        />
                    </div>
                ) : null}

                <section
                    aria-label={`${provider.name} exceptions`}
                    className="space-y-2"
                >
                    <h3 className="text-sm font-semibold">Required action</h3>
                    {provider.exceptions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No current reconciliation exceptions.
                        </p>
                    ) : (
                        provider.exceptions.map((exception) => (
                            <div
                                key={exception.type}
                                className="rounded-lg border border-status-warning/30 bg-status-warning/5 p-3 text-sm"
                            >
                                <p>{exception.summary}</p>
                                {exception.href ? (
                                    <Link
                                        className="frontline-focus mt-2 inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline"
                                        href={exception.href}
                                    >
                                        {exception.action}
                                    </Link>
                                ) : (
                                    <p className="mt-2 text-muted-foreground">
                                        Ask an integration manager to{' '}
                                        {exception.action.toLowerCase()}.
                                    </p>
                                )}
                            </div>
                        ))
                    )}
                </section>

                {provider.docs_href && canManage ? (
                    <Button asChild className="min-h-11 w-full sm:w-auto">
                        <Link href={provider.docs_href}>
                            Open {provider.name} diagnostics
                        </Link>
                    </Button>
                ) : null}
            </CardContent>
        </Card>
    );
}

type Props = {
    providers: Provider[];
    stats: {
        providers_total: number;
        providers_live: number;
        providers_connected: number;
        providers_errored: number;
        imported_devices: number;
        events_24h: number;
        exceptions: number;
    };
    can: { manage: boolean };
    boundaries: {
        sync_stale_after_hours: number;
        credential_rotation_cadence_days: number;
        alert_owner: string;
    };
};

export default function IntegrationsHub({
    providers,
    stats,
    can,
    boundaries,
}: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Integrations',
                    href: '/security-devices/integrations',
                },
            ]}
        >
            <Head title="Integrations - Security & Devices" />
            <PageShell>
                <PageHero
                    icon={Plug}
                    title="Integrations"
                    description="Connection health, site mapping, sync, and imported-device reconciliation. Provider setup and diagnostics stay in each provider workspace."
                    stats={[
                        {
                            label: 'Connected',
                            value: stats.providers_connected,
                        },
                        {
                            label: 'Imported devices',
                            value: stats.imported_devices,
                        },
                        { label: 'Exceptions', value: stats.exceptions },
                        {
                            label: 'Connection errors',
                            value: stats.providers_errored,
                        },
                    ]}
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Connected providers"
                        value={stats.providers_connected}
                        icon={Plug}
                    />
                    <StatCard
                        label="Imported devices"
                        value={stats.imported_devices}
                        icon={Database}
                    />
                    <StatCard
                        label="Reconciliation exceptions"
                        value={stats.exceptions}
                        icon={AlertTriangle}
                        variant="warning"
                    />
                    <StatCard
                        label="Mapped provider sites"
                        value={providers.reduce(
                            (sum, provider) =>
                                sum + provider.site_mapping.mapped,
                            0,
                        )}
                        icon={MapPin}
                    />
                </div>
                <div className="grid min-w-0 gap-4 xl:grid-cols-3">
                    {providers.map((provider) => (
                        <ProviderCard
                            key={provider.slug}
                            provider={provider}
                            canManage={can.manage}
                        />
                    ))}
                </div>
                <Card>
                    <CardContent className="flex flex-wrap items-start gap-3 p-5 text-sm text-muted-foreground">
                        <RefreshCw className="mt-0.5 h-4 w-4" />
                        <p>
                            Sync is marked stale after{' '}
                            {boundaries.sync_stale_after_hours} hours.
                            Credential rotation uses a declared{' '}
                            {boundaries.credential_rotation_cadence_days}-day
                            review cadence. Correlated operational alerts remain
                            owned by {boundaries.alert_owner}.
                        </p>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
