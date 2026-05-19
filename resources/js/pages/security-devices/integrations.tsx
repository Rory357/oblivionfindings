import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    CheckCircle2,
    Clock,
    Database,
    Link2,
    Plug,
    Radio,
    Shield,
} from 'lucide-react';

import { StatCard, formatTimeSince } from './devices/shared';

// ── Types ─────────────────────────────────────────────────────────

type ConnectionStatus =
    | 'connected'
    | 'disconnected'
    | 'error'
    | 'not_configured';

type ImplementationStatus = 'live' | 'planned';

type Provider = {
    slug: string;
    name: string;
    vendor: string;
    summary: string;
    implementation_status: ImplementationStatus;
    capabilities: string[];
    device_scope: string[];
    docs_href: string | null;

    connection_status: ConnectionStatus;
    connected: boolean;
    last_tested_at: string | null;
    last_synced_at: string | null;
    secret_last4: string | null;
    device_count: number;
    events_24h: number;
};

type Props = {
    providers: Provider[];
    stats: {
        providers_total: number;
        providers_live: number;
        providers_connected: number;
        providers_errored: number;
        imported_devices: number;
        events_24h: number;
    };
    can: {
        manage: boolean;
    };
};

// ── Helpers ───────────────────────────────────────────────────────

function connectionBadge(provider: Provider) {
    if (provider.implementation_status === 'planned') {
        return (
            <Badge variant="outline" className="gap-1">
                <Clock className="h-3 w-3" /> Planned
            </Badge>
        );
    }

    switch (provider.connection_status) {
        case 'connected':
            return (
                <Badge className="gap-1 bg-status-success text-white hover:bg-status-success">
                    <CheckCircle2 className="h-3 w-3" /> Connected
                </Badge>
            );
        case 'error':
            return (
                <Badge variant="destructive" className="gap-1">
                    <AlertTriangle className="h-3 w-3" /> Error
                </Badge>
            );
        case 'disconnected':
            return (
                <Badge variant="outline" className="gap-1">
                    <Plug className="h-3 w-3" /> Disconnected
                </Badge>
            );
        default:
            return (
                <Badge variant="secondary" className="gap-1">
                    <Plug className="h-3 w-3" /> Not configured
                </Badge>
            );
    }
}

function humanCapability(slug: string): string {
    const map: Record<string, string> = {
        network: 'Network',
        cctv: 'CCTV',
        access_control: 'Access Control',
        device_health: 'Device health',
        event_stream: 'Events',
        tracking: 'Tracking',
        telemetry: 'Telemetry',
        iot: 'IoT',
        environmental: 'Environmental',
        healthcare_sensors: 'Healthcare sensors',
        gateway_management: 'Gateway management',
    };
    return map[slug] ?? slug;
}

// ── Page ──────────────────────────────────────────────────────────

export default function IntegrationsHub({ providers, stats, can }: Props) {
    const breadcrumbs = [
        { title: 'Security & Devices', href: '/security-devices' },
        { title: 'APIs & Integrations', href: '/security-devices/integrations' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="APIs & Integrations - Security & Devices" />

            <PageShell>
                <PageHero variant="compact"
                    title={
                        <span className="flex items-center gap-3">
                            <span className="rounded-xl border bg-primary/5 p-2 text-primary">
                                <Link2 className="h-5 w-5" />
                            </span>
                            <span>APIs & Integrations</span>
                        </span>
                    }
                    description="Provider credentials, site mapping, sync controls, and exceptions. Devices pages show the result of sync; this hub controls it."
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant="outline">Source of truth</Badge>
                            {stats.providers_errored > 0 ? (
                                <Badge variant="destructive" className="gap-1">
                                    <AlertTriangle className="h-3 w-3" />
                                    {stats.providers_errored} errored
                                </Badge>
                            ) : null}
                        </div>
                    }
                />

                {/* ── Summary strip ─────────────────────────────── */}
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label={`Providers connected (${stats.providers_live} live in platform)`}
                        value={stats.providers_connected}
                        icon={Check}
                    />
                    <StatCard
                        label="Imported devices"
                        value={stats.imported_devices}
                        icon={Database}
                    />
                    <StatCard
                        label="Provider events (24h)"
                        value={stats.events_24h}
                        icon={Radio}
                    />
                    <StatCard
                        label="Providers with errors"
                        value={stats.providers_errored}
                        icon={AlertTriangle}
                        variant="warning"
                    />
                </div>

                {/* ── Provider cards ────────────────────────────── */}
                <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    {providers.map((provider) => (
                        <Card
                            key={provider.slug}
                            className={
                                provider.implementation_status === 'planned'
                                    ? 'border-dashed bg-muted/20'
                                    : undefined
                            }
                        >
                            <CardHeader className="space-y-3">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <CardTitle className="text-lg">
                                            {provider.name}
                                        </CardTitle>
                                        <CardDescription className="text-xs">
                                            {provider.vendor}
                                        </CardDescription>
                                    </div>
                                    {connectionBadge(provider)}
                                </div>
                                <p className="text-sm leading-6 text-muted-foreground">
                                    {provider.summary}
                                </p>
                            </CardHeader>

                            <CardContent className="space-y-4">
                                {/* Capabilities */}
                                <div className="space-y-2">
                                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                        Capabilities
                                    </p>
                                    <div className="flex flex-wrap gap-1.5">
                                        {provider.capabilities.map((cap) => (
                                            <Badge
                                                key={cap}
                                                variant="secondary"
                                                className="text-xs font-normal"
                                            >
                                                {humanCapability(cap)}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>

                                {/* Device scope */}
                                <div className="space-y-2">
                                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                        Device scope
                                    </p>
                                    <p className="text-sm leading-6 text-muted-foreground">
                                        {provider.device_scope.join(' · ')}
                                    </p>
                                </div>

                                {/* Live stats — only meaningful for live providers */}
                                {provider.implementation_status === 'live' ? (
                                    <div className="grid grid-cols-2 gap-3 rounded-lg border bg-muted/30 p-3">
                                        <div>
                                            <p className="text-xs text-muted-foreground">
                                                Imported devices
                                            </p>
                                            <p className="text-xl font-semibold">
                                                {provider.device_count}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-muted-foreground">
                                                Events (24h)
                                            </p>
                                            <p className="text-xl font-semibold">
                                                {provider.events_24h}
                                            </p>
                                        </div>
                                        <div className="col-span-2 space-y-1 pt-1 text-xs text-muted-foreground">
                                            <p>
                                                Last sync:{' '}
                                                <span className="text-foreground">
                                                    {formatTimeSince(
                                                        provider.last_synced_at,
                                                    )}
                                                </span>
                                            </p>
                                            <p>
                                                Last tested:{' '}
                                                <span className="text-foreground">
                                                    {formatTimeSince(
                                                        provider.last_tested_at,
                                                    )}
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="rounded-lg border border-dashed bg-muted/30 p-3 text-xs text-muted-foreground">
                                        Adapter not yet implemented. Hub entry
                                        reserved so site and device pages can
                                        reference the provider early.
                                    </div>
                                )}

                                {/* Actions */}
                                <div className="flex flex-wrap items-center gap-2 pt-1">
                                    {provider.implementation_status === 'live' &&
                                    provider.docs_href ? (
                                        <Button asChild size="sm">
                                            <Link href={provider.docs_href}>
                                                {provider.connected
                                                    ? 'Manage'
                                                    : 'Configure'}
                                            </Link>
                                        </Button>
                                    ) : (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled
                                        >
                                            Adapter planned
                                        </Button>
                                    )}
                                    {provider.connected && can.manage ? (
                                        <Badge
                                            variant="outline"
                                            className="gap-1 text-xs"
                                        >
                                            Credentials: {'•••• '}
                                            {provider.secret_last4 ?? '----'}
                                        </Badge>
                                    ) : null}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* ── Ownership guidance ───────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>Where integration work lives</CardTitle>
                        <CardDescription>
                            Ownership rules to keep Security & Devices, Sites,
                            and Control Room cleanly separated.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 md:grid-cols-3">
                        <div className="flex items-start gap-3 rounded-xl border p-4">
                            <Shield className="mt-0.5 h-4 w-4 text-primary" />
                            <div className="space-y-1 text-sm">
                                <p className="font-medium">Here</p>
                                <p className="leading-6 text-muted-foreground">
                                    Provider credentials, site mapping, sync
                                    schedules, and exceptions. Nothing else
                                    manages these.
                                </p>
                            </div>
                        </div>
                        <div className="flex items-start gap-3 rounded-xl border p-4">
                            <Plug className="mt-0.5 h-4 w-4 text-muted-foreground" />
                            <div className="space-y-1 text-sm">
                                <p className="font-medium">Sites / Houses</p>
                                <p className="leading-6 text-muted-foreground">
                                    Read-only view of assigned hardware and
                                    provider health at the site level. No
                                    credentials or sync here.
                                </p>
                            </div>
                        </div>
                        <div className="flex items-start gap-3 rounded-xl border p-4">
                            <Radio className="mt-0.5 h-4 w-4 text-muted-foreground" />
                            <div className="space-y-1 text-sm">
                                <p className="font-medium">Control Room</p>
                                <p className="leading-6 text-muted-foreground">
                                    Signals from providers route into Control
                                    Room for triage. Alert state lives there,
                                    not here.
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
