import { ConfirmDialog } from '@/components/confirm-dialog';
import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/fleet-utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    ShieldOff,
    ShieldX,
} from 'lucide-react';
import { useState } from 'react';

type DeviceConsent = {
    id: number;
    vendor: string;
    device_uid: string;
    status: string;
    consent_status: 'consented' | 'revoked' | 'pending' | 'expired';
    consent_given_at: string | null;
    consent_withdrawn_at: string | null;
    consent_expires_at: string | null;
    consent_given_by: string | null;
    asset: { id: number; name: string; asset_tag: string | null } | null;
    client_name: string | null;
};

type Props = {
    devices: DeviceConsent[];
    stats: {
        total: number;
        consented: number;
        revoked: number;
        pending: number;
        expired: number;
    };
};

function consentBadge(status: string) {
    switch (status) {
        case 'consented':
            return <Badge variant="default"><CheckCircle2 className="mr-1 h-3 w-3" />Consented</Badge>;
        case 'revoked':
            return <Badge variant="destructive"><ShieldX className="mr-1 h-3 w-3" />Revoked</Badge>;
        case 'expired':
            return <Badge variant="destructive"><ShieldAlert className="mr-1 h-3 w-3" />Expired</Badge>;
        case 'pending':
        default:
            return <Badge variant="outline" className="border-status-warning/30 text-status-warning dark:text-status-warning"><Clock className="mr-1 h-3 w-3" />Pending</Badge>;
    }
}

export default function DeviceConsentManagement({ devices, stats }: Props) {
    const [search, setSearch] = useState('');
    const [revokeTarget, setRevokeTarget] = useState<DeviceConsent | null>(null);
    const [revokeReason, setRevokeReason] = useState('');
    const [grantTarget, setGrantTarget] = useState<DeviceConsent | null>(null);
    const [processing, setProcessing] = useState(false);

    const filtered = devices.filter((d) => {
        const q = search.toLowerCase();
        if (!q) return true;
        return (
            d.vendor?.toLowerCase().includes(q) ||
            d.device_uid?.toLowerCase().includes(q) ||
            d.asset?.name?.toLowerCase().includes(q) ||
            d.client_name?.toLowerCase().includes(q)
        );
    });

    const handleGrant = (device: DeviceConsent) => {
        setProcessing(true);
        router.post(`/fleet-assets/devices/${device.id}/consent/grant`, {}, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setGrantTarget(null);
            },
        });
    };

    const handleRevoke = () => {
        if (!revokeTarget) return;
        setProcessing(true);
        router.post(`/fleet-assets/devices/${revokeTarget.id}/consent/revoke`, {
            reason: revokeReason || undefined,
        }, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setRevokeTarget(null);
                setRevokeReason('');
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Tracking Devices', href: '/fleet-assets/devices' },
                { title: 'Consent Management', href: '/fleet-assets/devices/consent' },
            ]}
        >
            <Head title="Device Consent Management" />
            <PageShell>
                <FleetHero
                    title="Device Consent Management"
                    description="Manage location tracking consent for GPS trackers. Telemetry location data is blocked when consent is not active."
                    backHref="/fleet-assets/devices"
                    backLabel="Devices"
                />

                {/* KPI Cards */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                    <FleetStatCard label="TOTAL DEVICES" value={stats.total} icon={Shield} subtitle="All paired trackers" />
                    <FleetStatCard label="CONSENTED" value={stats.consented} icon={ShieldCheck} color="purple" valueClassName="text-status-success" subtitle="Active consent" />
                    <FleetStatCard label="REVOKED" value={stats.revoked} icon={ShieldOff} color="red" valueClassName="text-status-critical" subtitle="Consent withdrawn" />
                    <FleetStatCard label="PENDING" value={stats.pending} icon={Clock} color="amber" valueClassName="text-status-warning" subtitle="No consent recorded" />
                    <FleetStatCard label="EXPIRED" value={stats.expired} icon={ShieldAlert} color="red" valueClassName="text-status-warning" subtitle="Consent expired" />
                </div>

                {/* Search */}
                <div className="relative max-w-sm">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        placeholder="Search by device, asset, or client..."
                        className="pl-10"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>

                {/* Table */}
                <div className="rounded-lg border overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                <th className="px-4 py-3 text-left font-medium">Device</th>
                                <th className="px-4 py-3 text-left font-medium">Paired Asset</th>
                                <th className="px-4 py-3 text-left font-medium">Client</th>
                                <th className="px-4 py-3 text-left font-medium">Consent Status</th>
                                <th className="px-4 py-3 text-left font-medium">Granted / Revoked</th>
                                <th className="px-4 py-3 text-left font-medium">Granted By</th>
                                <th className="px-4 py-3 text-right font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered.length > 0 ? (
                                filtered.map((device) => (
                                    <tr
                                        key={device.id}
                                        className="border-b transition-colors hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{device.vendor}</div>
                                            <div className="font-mono text-xs text-muted-foreground">{device.device_uid}</div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {device.asset ? (
                                                <Link
                                                    href={`/fleet-assets/assets/${device.asset.id}`}
                                                    className="text-primary hover:underline"
                                                >
                                                    {device.asset.name}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">Not paired</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {device.client_name ?? '---'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {consentBadge(device.consent_status)}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {device.consent_status === 'consented' && device.consent_given_at
                                                ? formatDateTime(device.consent_given_at)
                                                : device.consent_status === 'revoked' && device.consent_withdrawn_at
                                                  ? formatDateTime(device.consent_withdrawn_at)
                                                  : device.consent_status === 'expired' && device.consent_expires_at
                                                    ? `Expired ${formatDateTime(device.consent_expires_at)}`
                                                    : '---'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {device.consent_given_by ?? '---'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {device.consent_status === 'consented' ? (
                                                <Button
                                                    variant="destructive"
                                                    size="sm"
                                                    onClick={() => setRevokeTarget(device)}
                                                    disabled={processing}
                                                >
                                                    <ShieldOff className="mr-1.5 h-3.5 w-3.5" />
                                                    Revoke
                                                </Button>
                                            ) : (
                                                <Button
                                                    variant="default"
                                                    size="sm"
                                                    onClick={() => handleGrant(device)}
                                                    disabled={processing}
                                                >
                                                    <ShieldCheck className="mr-1.5 h-3.5 w-3.5" />
                                                    Grant Consent
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12">
                                        <FleetEmptyState
                                            icon={Shield}
                                            title="No tracking devices found"
                                            description="Pair a GPS tracker to an asset first, then manage its consent here."
                                        />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Revoke Confirmation Dialog */}
                <ConfirmDialog
                    open={!!revokeTarget}
                    onClose={() => {
                        setRevokeTarget(null);
                        setRevokeReason('');
                    }}
                    onConfirm={handleRevoke}
                    title="Revoke Tracking Consent"
                    description={`Are you sure you want to revoke location tracking consent for ${revokeTarget?.vendor ?? ''} ${revokeTarget?.device_uid ?? ''}? Telemetry data will no longer include GPS coordinates.`}
                    confirmText="Revoke Consent"
                    variant="destructive"
                />
            </PageShell>
        </AppLayout>
    );
}
