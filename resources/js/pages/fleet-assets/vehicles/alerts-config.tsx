import PageShell from '@/components/page-shell';
import { FleetCompactHero } from '@/pages/fleet-assets/components/fleet-compact-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Battery,
    Bell,
    Clock,
    Gauge,
    Loader2,
    MapPin,
    Moon,
    Save,
    Shield,
    WifiOff,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

type AlertRule = {
    enabled: boolean;
    threshold: number | string;
    severity: string;
    notify_control_room: boolean;
};

type AlertConfig = {
    speed_limit: AlertRule;
    idle_timeout: AlertRule;
    geofence_breach: AlertRule;
    battery_low: AlertRule;
    offline_timeout: AlertRule;
    harsh_braking: AlertRule;
    after_hours: AlertRule & { start_time: string; end_time: string };
};

type Props = {
    asset: {
        id: number;
        name: string;
        asset_tag: string;
    };
    config: Partial<AlertConfig>;
    geofences: Array<{ id: number; name: string; is_active: boolean }>;
    can: {
        manage: boolean;
    };
};

const DEFAULT_CONFIG: AlertConfig = {
    speed_limit: { enabled: false, threshold: 100, severity: 'medium', notify_control_room: false },
    idle_timeout: { enabled: false, threshold: 15, severity: 'low', notify_control_room: false },
    geofence_breach: { enabled: false, threshold: '', severity: 'high', notify_control_room: true },
    battery_low: { enabled: false, threshold: 20, severity: 'medium', notify_control_room: false },
    offline_timeout: { enabled: false, threshold: 4, severity: 'medium', notify_control_room: false },
    harsh_braking: { enabled: false, threshold: 'medium', severity: 'medium', notify_control_room: false },
    after_hours: { enabled: false, threshold: '', severity: 'high', notify_control_room: true, start_time: '18:00', end_time: '06:00' },
};

const ALERT_TYPES = [
    {
        key: 'speed_limit' as const,
        label: 'Speed Limit',
        description: 'Alert when vehicle exceeds speed threshold',
        icon: Gauge,
        thresholdLabel: 'Max Speed (km/h)',
        thresholdType: 'number' as const,
    },
    {
        key: 'idle_timeout' as const,
        label: 'Idle Timeout',
        description: 'Alert when vehicle idles too long',
        icon: Clock,
        thresholdLabel: 'Max Idle Time (minutes)',
        thresholdType: 'number' as const,
    },
    {
        key: 'geofence_breach' as const,
        label: 'Geofence Breach',
        description: 'Alert when vehicle enters or exits a geofence',
        icon: MapPin,
        thresholdLabel: 'Linked Geofence',
        thresholdType: 'select' as const,
    },
    {
        key: 'battery_low' as const,
        label: 'Battery Low',
        description: 'Alert when tracker battery drops below threshold',
        icon: Battery,
        thresholdLabel: 'Minimum Battery (%)',
        thresholdType: 'number' as const,
    },
    {
        key: 'offline_timeout' as const,
        label: 'Offline Timeout',
        description: 'Alert when vehicle goes offline for too long',
        icon: WifiOff,
        thresholdLabel: 'Max Offline (hours)',
        thresholdType: 'number' as const,
    },
    {
        key: 'harsh_braking' as const,
        label: 'Harsh Braking',
        description: 'Alert on harsh braking events',
        icon: Zap,
        thresholdLabel: 'Sensitivity',
        thresholdType: 'sensitivity' as const,
    },
    {
        key: 'after_hours' as const,
        label: 'After Hours Operation',
        description: 'Alert when vehicle operates outside business hours',
        icon: Moon,
        thresholdLabel: 'Time Range',
        thresholdType: 'time_range' as const,
    },
];

export default function VehicleAlertsConfig({ asset, config: rawConfig, geofences, can }: Props) {
    const canManage = can.manage;
    const [config, setConfig] = useState<AlertConfig>(() => ({
        ...DEFAULT_CONFIG,
        ...Object.fromEntries(
            Object.entries(rawConfig ?? {}).map(([key, val]) => [
                key,
                { ...DEFAULT_CONFIG[key as keyof AlertConfig], ...val },
            ]),
        ),
    } as AlertConfig));
    const [processing, setProcessing] = useState(false);

    const updateRule = <K extends keyof AlertConfig>(key: K, field: string, value: unknown) => {
        setConfig((prev) => ({
            ...prev,
            [key]: { ...prev[key], [field]: value },
        }));
    };

    const handleSave = () => {
        if (!canManage) {
            return;
        }

        setProcessing(true);
        router.post(`/fleet-assets/vehicles/${asset.id}/alerts-config`, { config } as any, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Vehicles', href: '/fleet-assets/vehicles' },
                { title: asset.name, href: `/fleet-assets/vehicles/${asset.id}` },
                { title: 'Alert Config', href: '#' },
            ]}
        >
            <Head title={`Alerts: ${asset.name}`} />
            <PageShell>
                <FleetCompactHero
                    pill="Vehicle alerts · configuration"
                    title={`Alert Configuration: ${asset.name}`}
                    backHref={`/fleet-assets/vehicles/${asset.id}`}
                    backLabel="Vehicle"
                />
                <p className="text-sm text-muted-foreground">
                    Configure alert rules and thresholds for this vehicle.
                </p>

                <div className="space-y-4">
                    {ALERT_TYPES.map(({ key, label, description, icon: Icon, thresholdLabel, thresholdType }) => {
                        const rule = config[key] as AlertRule & { start_time?: string; end_time?: string };
                        return (
                            <Card key={key} className={rule.enabled ? '' : 'opacity-75'}>
                                <CardHeader className="pb-3">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Icon className="h-4 w-4" />
                                            {label}
                                        </CardTitle>
                                        <div className="flex items-center gap-2">
                                            <label className="relative inline-flex cursor-pointer items-center">
                                                <input
                                                    type="checkbox"
                                                    checked={rule.enabled}
                                                    onChange={(e) => updateRule(key, 'enabled', e.target.checked)}
                                                    disabled={!canManage}
                                                    className="peer sr-only"
                                                />
                                                <div className="h-6 w-11 rounded-full bg-muted after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-border after:bg-white after:transition-all after:content-[''] peer-checked:bg-primary peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-ring dark:bg-muted" />
                                            </label>
                                        </div>
                                    </div>
                                    <p className="text-xs text-muted-foreground">{description}</p>
                                </CardHeader>
                                {rule.enabled && (
                                    <CardContent>
                                        <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                                            {/* Threshold */}
                                            <div>
                                                <Label>{thresholdLabel}</Label>
                                                {thresholdType === 'number' && (
                                                    <Input
                                                        type="number"
                                                        value={String(rule.threshold)}
                                                        disabled={!canManage}
                                                        onChange={(e) => updateRule(key, 'threshold', Number(e.target.value))}
                                                    />
                                                )}
                                                {thresholdType === 'sensitivity' && (
                                                    <Select value={String(rule.threshold)} onValueChange={(v) => updateRule(key, 'threshold', v)} disabled={!canManage}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="low">Low</SelectItem>
                                                            <SelectItem value="medium">Medium</SelectItem>
                                                            <SelectItem value="high">High</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                )}
                                                {thresholdType === 'select' && (
                                                    <Select value={String(rule.threshold) || '__all__'} onValueChange={(v) => updateRule(key, 'threshold', v === '__all__' ? '' : v)} disabled={!canManage}>
                                                        <SelectTrigger><SelectValue placeholder="Select geofence" /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="__all__">All geofences</SelectItem>
                                                            {(geofences ?? []).map((g) => (
                                                                <SelectItem key={g.id} value={String(g.id)}>
                                                                    {g.name} {!g.is_active && '(inactive)'}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                )}
                                                {thresholdType === 'time_range' && (
                                                    <div className="flex items-center gap-2">
                                                        <Input
                                                            type="time"
                                                            value={rule.start_time ?? '18:00'}
                                                            disabled={!canManage}
                                                            onChange={(e) => updateRule(key, 'start_time', e.target.value)}
                                                            className="w-auto"
                                                        />
                                                        <span className="text-sm text-muted-foreground">to</span>
                                                        <Input
                                                            type="time"
                                                            value={rule.end_time ?? '06:00'}
                                                            disabled={!canManage}
                                                            onChange={(e) => updateRule(key, 'end_time', e.target.value)}
                                                            className="w-auto"
                                                        />
                                                    </div>
                                                )}
                                            </div>

                                            {/* Severity */}
                                            <div>
                                                <Label>Severity</Label>
                                                <Select value={rule.severity} onValueChange={(v) => updateRule(key, 'severity', v)} disabled={!canManage}>
                                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="low">Low</SelectItem>
                                                        <SelectItem value="medium">Medium</SelectItem>
                                                        <SelectItem value="high">High</SelectItem>
                                                        <SelectItem value="critical">Critical</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            {/* Notify Control Room */}
                                            <div className="flex items-end">
                                                <label className="flex items-center gap-2 text-sm pb-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={rule.notify_control_room}
                                                        disabled={!canManage}
                                                        onChange={(e) => updateRule(key, 'notify_control_room', e.target.checked)}
                                                        className="h-4 w-4 rounded border-border"
                                                    />
                                                    <Bell className="h-3.5 w-3.5 text-muted-foreground" />
                                                    Notify Control Room
                                                </label>
                                            </div>
                                        </div>
                                    </CardContent>
                                )}
                            </Card>
                        );
                    })}
                </div>

                <div className="flex items-center gap-2 pt-4">
                    {canManage ? (
                        <Button onClick={handleSave} disabled={processing}>
                            {processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                            Save Configuration
                        </Button>
                    ) : (
                        <Badge variant="secondary">View-only</Badge>
                    )}
                    <Button variant="outline" asChild>
                        <Link href={`/fleet-assets/vehicles/${asset.id}`}>Cancel</Link>
                    </Button>
                </div>
            </PageShell>
        </AppLayout>
    );
}
