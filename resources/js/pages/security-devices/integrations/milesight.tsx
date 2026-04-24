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
    HeartPulse,
    Loader2,
    MapPin,
    RefreshCw,
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
    { title: 'Milesight', href: '/security-devices/integrations/milesight' },
];

export default function MilesightIntegration({
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
            <Head title="Milesight Integration" />
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
                            <HeartPulse className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Milesight Integration
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                LoRaWAN sensors and gateways — environmental,
                                occupancy, leak, and resident-support IoT.
                            </p>
                        </div>
                    </div>
                </div>

                {/* ── Scaffold state banner ───────────────────────────── */}
                <Card className="border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30 dark:bg-status-warning">
                    <CardContent className="flex items-start gap-3 p-4 text-sm">
                        <Clock className="mt-0.5 h-4 w-4 text-status-warning dark:text-status-warning" />
                        <div className="space-y-1 leading-6">
                            <p className="font-medium">
                                Scaffold stage — credential management only.
                            </p>
                            <p className="text-muted-foreground">
                                Save your API key and verify the connection
                                here today. Gateway / application mapping,
                                LoRaWAN device import, and payload decoding for
                                bed, fall, door, temp, leak and air-quality
                                sensors ship in a follow-up release.
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
                                        '/security-devices/integrations/milesight/key',
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
                                        placeholder="https://mdp-api.milesight.com"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Defaults to the Milesight Development
                                        Platform. Override for a self-hosted
                                        gateway bridge or regional cloud.
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
                                        placeholder="Milesight API key"
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
                                        Rotate key
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-status-critical hover:text-status-critical"
                                        onClick={() => {
                                            if (
                                                !confirm(
                                                    'Remove Milesight credentials? Any sensors already synced remain; only the key is deleted.',
                                                )
                                            )
                                                return;
                                            router.delete(
                                                '/security-devices/integrations/milesight/key',
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
                                                '/security-devices/integrations/milesight/rotate',
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

                {/* ── Sensor coverage ─────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>Sensor coverage planned</CardTitle>
                        <CardDescription>
                            LoRaWAN device families that will import with
                            decoded payloads once PR D1 lands.
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
            </div>
        </AppLayout>
    );
}
