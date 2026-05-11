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
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle,
    Clock,
    Loader2,
    MapPin,
    RefreshCw,
    Satellite,
    ShieldAlert,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

// ── Types ─────────────────────────────────────────────────────────

type ConnectionStatus = 'connected' | 'disconnected' | 'error';

type TenantSecret = {
    status: ConnectionStatus;
    secret_last4?: string;
    last_tested_at?: string;
    last_synced_at?: string;
    last_error?: string | null;
    base_url?: string | null;
} | null;

type SyncLog = {
    id: number;
    action: string;
    status: string;
    items_processed: number;
    items_created: number;
    items_updated: number;
    items_errored: number;
    error_message?: string | null;
    started_at: string;
    completed_at?: string | null;
};

type Props = {
    tenantSecret: TenantSecret;
    syncLogs: SyncLog[];
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
    { title: 'Queclink', href: '/security-devices/integrations/queclink' },
];

export default function QueclinkIntegration({
    tenantSecret,
    syncLogs,
    can,
}: Props) {
    const [showRotateForm, setShowRotateForm] = useState(false);
    const [testingConnection, setTestingConnection] = useState(false);

    const saveKeyForm = useForm<{ api_key: string; base_url: string }>({
        api_key: '',
        base_url: tenantSecret?.base_url ?? '',
    });

    const rotateKeyForm = useForm<{ api_key: string }>({ api_key: '' });

    const hasKey = !!tenantSecret;
    const connStatus = tenantSecret
        ? connectionStatusConfig[tenantSecret.status] ??
          connectionStatusConfig.disconnected
        : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Queclink Integration" />
            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <div>
                    <Link
                        href="/security-devices/integrations"
                        className="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to APIs &amp; Integrations
                    </Link>
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                            <Satellite className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Queclink Integration
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Cellular GPS trackers for vehicles, assets, and
                                personal / client use.
                            </p>
                        </div>
                    </div>
                </div>

                {/* ── Scaffold state banner ───────────────────────────── */}
                <Card className="border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30">
                    <CardContent className="flex items-start gap-3 p-4 text-sm">
                        <Clock className="mt-0.5 h-4 w-4 text-status-warning dark:text-status-warning" />
                        <div className="space-y-1 leading-6">
                            <p className="font-medium">
                                Scaffold stage — credential management only.
                            </p>
                            <p className="text-muted-foreground">
                                Save your API key and verify the connection here
                                today. Device sync, fleet mapping, and the
                                event stream ship in a follow-up release. Until
                                then, tracker telemetry continues to flow
                                through the Fleet module's existing webhook
                                pipeline.
                            </p>
                        </div>
                    </CardContent>
                </Card>

                {/* ── Credentials ──────────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>API credentials</CardTitle>
                        <CardDescription>
                            Stored encrypted per tenant. Only the last four
                            characters are ever shown.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {!hasKey ? (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    saveKeyForm.post(
                                        '/security-devices/integrations/queclink/key',
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                saveKeyForm.reset('api_key'),
                                        },
                                    );
                                }}
                                className="space-y-4"
                            >
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
                                        placeholder="https://ims.queclink.com"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Defaults to{' '}
                                        <code className="rounded bg-muted px-1 py-0.5">
                                            https://ims.queclink.com
                                        </code>
                                        . Override if your account lives on a
                                        regional Queclink server.
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="api_key">API key</Label>
                                    <Input
                                        id="api_key"
                                        type="password"
                                        value={saveKeyForm.data.api_key}
                                        onChange={(e) =>
                                            saveKeyForm.setData(
                                                'api_key',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Queclink API key"
                                        required
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={
                                        !can.manage ||
                                        saveKeyForm.processing ||
                                        !saveKeyForm.data.api_key
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
                                        Key ending in{' '}
                                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                            •••{tenantSecret?.secret_last4}
                                        </code>
                                    </span>
                                    {connStatus && (
                                        <Badge className={connStatus.className}>
                                            <connStatus.icon className="mr-1 h-3 w-3" />
                                            {connStatus.label}
                                        </Badge>
                                    )}
                                </div>
                                {tenantSecret?.base_url && (
                                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <MapPin className="h-3.5 w-3.5" />
                                        Server:{' '}
                                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                            {tenantSecret.base_url}
                                        </code>
                                    </div>
                                )}
                                <div className="space-y-1 text-sm text-muted-foreground">
                                    <p>
                                        Last tested:{' '}
                                        <span className="text-foreground">
                                            {fmt(tenantSecret?.last_tested_at)}
                                        </span>
                                    </p>
                                    <p>
                                        Last sync:{' '}
                                        <span className="text-foreground">
                                            {fmt(tenantSecret?.last_synced_at)}
                                        </span>
                                    </p>
                                </div>
                                {tenantSecret?.last_error && (
                                    <div className="flex items-start gap-2 rounded-md border border-status-critical/30 bg-status-critical-bg p-3 text-xs text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical">
                                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>{tenantSecret.last_error}</span>
                                    </div>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setTestingConnection(true);
                                            router.post(
                                                '/security-devices/integrations/queclink/test',
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
                                        Rotate key
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-status-critical hover:text-status-critical"
                                        onClick={() => {
                                            if (
                                                !confirm(
                                                    'Remove Queclink credentials? Devices already synced will remain; only the key is deleted.',
                                                )
                                            )
                                                return;
                                            router.delete(
                                                '/security-devices/integrations/queclink/key',
                                                { preserveScroll: true },
                                            );
                                        }}
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
                                                '/security-devices/integrations/queclink/rotate',
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => {
                                                        rotateKeyForm.reset(
                                                            'api_key',
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
                                        <Label htmlFor="rotate_api_key">
                                            New API key
                                        </Label>
                                        <Input
                                            id="rotate_api_key"
                                            type="password"
                                            value={rotateKeyForm.data.api_key}
                                            onChange={(e) =>
                                                rotateKeyForm.setData(
                                                    'api_key',
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
                                                    !rotateKeyForm.data.api_key
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
                                                        'api_key',
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
                                                    {log.error_message && (
                                                        <p className="mt-1 text-xs text-status-critical">
                                                            {log.error_message}
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

                {/* ── Next-steps info ─────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>What ships next</CardTitle>
                        <CardDescription>
                            Planned capabilities for the Queclink provider.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm md:grid-cols-3">
                        <div className="rounded-xl border p-4">
                            <p className="font-medium">Fleet / site mapping</p>
                            <p className="leading-6 text-muted-foreground">
                                Map Queclink fleet groups onto Oblivion sites so
                                trackers are placed correctly on sync.
                            </p>
                        </div>
                        <div className="rounded-xl border p-4">
                            <p className="font-medium">Device sync</p>
                            <p className="leading-6 text-muted-foreground">
                                Import trackers keyed by IMEI; classify as
                                vehicle / personal / asset tracker; keep
                                provider-owned fields read-only.
                            </p>
                        </div>
                        <div className="rounded-xl border p-4">
                            <p className="font-medium">Event stream</p>
                            <p className="leading-6 text-muted-foreground">
                                Panic / SOS, geofence, tamper, and battery
                                events routed to Control Room via the signal
                                pipeline.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
