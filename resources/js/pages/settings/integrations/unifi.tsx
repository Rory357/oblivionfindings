import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Building2, CheckCircle, Loader2, RefreshCw, ShieldAlert, Wifi, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';

type TenantSecret = {
    status: 'connected' | 'disconnected' | 'error';
    secret_last4?: string;
    last_tested_at?: string;
    last_synced_at?: string;
    sites_synced_at?: string;
    config?: {
        refresh_interval_minutes?: number;
        alert_motion_events?: boolean;
        alert_device_offline?: boolean;
        quiet_hours_start?: string;
        quiet_hours_end?: string;
    };
} | null;

type DiscoveredSite = {
    external_id: string;
    name: string;
    meta?: { device_count?: number | null };
};

type SiteConfig = {
    id: number;
    site_id: number;
    site_name: string;
    site_type?: string | null;
    status: string;
    mapped_external_site_id?: string;
    mapped_external_site_name?: string;
    is_active: boolean;
};

type SiteLite = { id: number; name: string; type?: string };
type RoomLite = { id: number; site_id: number; name: string };
type SyncedDevice = {
    id: number;
    site_id: number;
    site_name: string;
    site_type?: string | null;
    room_id?: number | null;
    name: string;
    category: string;
    status: string;
    provider_entity_id?: string | null;
    provider_type?: string | null;
    model?: string | null;
    last_seen_at?: string | null;
};

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
    discoveredSites: DiscoveredSite[];
    siteConfigs: SiteConfig[];
    sites: SiteLite[];
    rooms: RoomLite[];
    syncedDevices: SyncedDevice[];
    syncLogs: SyncLog[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Integrations', href: '/settings/integrations' },
    { title: 'UniFi', href: '/settings/integrations/unifi' },
];

const connectionStatusConfig: Record<string, { label: string; className: string; icon: typeof CheckCircle }> = {
    connected: { label: 'Connected', className: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400', icon: CheckCircle },
    disconnected: { label: 'Disconnected', className: 'bg-gray-100 text-gray-800 dark:bg-gray-800/50 dark:text-gray-400', icon: XCircle },
    error: { label: 'Error', className: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400', icon: ShieldAlert },
};

const syncStatusConfig: Record<string, string> = {
    success: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    partial: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    started: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
};

function siteTypeLabel(type?: string | null): string {
    if (type === 'head_office') return 'Head Office';
    if (type === 'house') return 'House';
    if (type === 'facility') return 'Facility';
    return 'Location';
}

function fmt(value?: string | null): string {
    if (!value) return '---';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleString();
}

export default function UnifiIntegration({
    tenantSecret,
    discoveredSites,
    siteConfigs,
    sites,
    rooms,
    syncedDevices,
    syncLogs,
}: Props) {
    const [showRotateForm, setShowRotateForm] = useState(false);
    const [showMapForm, setShowMapForm] = useState(false);
    const [testingConnection, setTestingConnection] = useState(false);
    const [syncingSites, setSyncingSites] = useState(false);
    const [syncingSiteConfigId, setSyncingSiteConfigId] = useState<number | null>(null);
    const [assigningDeviceId, setAssigningDeviceId] = useState<number | null>(null);
    const [savingDefaults, setSavingDefaults] = useState(false);
    const [deviceSiteFilter, setDeviceSiteFilter] = useState<string>('all');
    const [deviceRoomDraft, setDeviceRoomDraft] = useState<Record<number, string>>(
        () => syncedDevices.reduce<Record<number, string>>((acc, d) => ({ ...acc, [d.id]: d.room_id ? String(d.room_id) : 'unassigned' }), {}),
    );

    const hasKey = !!tenantSecret;
    const connStatus = tenantSecret ? connectionStatusConfig[tenantSecret.status] ?? connectionStatusConfig.disconnected : null;
    const roomsBySite = useMemo(() => rooms.reduce<Record<number, RoomLite[]>>((acc, room) => {
        (acc[room.site_id] ??= []).push(room);
        return acc;
    }, {}), [rooms]);
    const filteredDevices = useMemo(() => {
        if (deviceSiteFilter === 'all') return syncedDevices;
        const siteId = Number(deviceSiteFilter);
        return syncedDevices.filter((d) => d.site_id === siteId);
    }, [syncedDevices, deviceSiteFilter]);

    const saveKeyForm = useForm({ api_key: '' });
    const rotateKeyForm = useForm({ api_key: '' });
    const mapForm = useForm({ site_id: '', external_site_id: '', external_site_name: '' });
    const defaultsForm = useForm({
        refresh_interval_minutes: tenantSecret?.config?.refresh_interval_minutes ?? 15,
        alert_motion_events: tenantSecret?.config?.alert_motion_events ?? false,
        alert_device_offline: tenantSecret?.config?.alert_device_offline ?? true,
        quiet_hours_start: tenantSecret?.config?.quiet_hours_start ?? '',
        quiet_hours_end: tenantSecret?.config?.quiet_hours_end ?? '',
    });

    const handleSaveDefaults = (e: React.FormEvent) => {
        e.preventDefault();
        setSavingDefaults(true);
        router.put(
            '/settings/integrations/unifi/defaults',
            {
                config: {
                    refresh_interval_minutes: defaultsForm.data.refresh_interval_minutes,
                    alert_motion_events: defaultsForm.data.alert_motion_events,
                    alert_device_offline: defaultsForm.data.alert_device_offline,
                    quiet_hours_start: defaultsForm.data.quiet_hours_start || null,
                    quiet_hours_end: defaultsForm.data.quiet_hours_end || null,
                },
            },
            {
                preserveScroll: true,
                onFinish: () => setSavingDefaults(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="UniFi Integration" />
            <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <div>
                    <Link href="/settings/integrations" className="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="h-4 w-4" />
                        Back to Integrations
                    </Link>
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted"><Wifi className="h-5 w-5 text-muted-foreground" /></div>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">UniFi Integration</h1>
                            <p className="text-sm text-muted-foreground">Dynamic location to room assignment for synced UniFi devices.</p>
                        </div>
                    </div>
                </div>

                <Card>
                    <CardHeader><CardTitle>API Key</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        {!hasKey ? (
                            <form onSubmit={(e) => { e.preventDefault(); saveKeyForm.post('/settings/integrations/unifi/key', { preserveScroll: true, onSuccess: () => saveKeyForm.reset('api_key') }); }} className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="api_key">API Key</Label>
                                    <Input id="api_key" type="password" value={saveKeyForm.data.api_key} onChange={(e) => saveKeyForm.setData('api_key', e.target.value)} placeholder="Enter UniFi API key" />
                                </div>
                                <Button type="submit" disabled={saveKeyForm.processing || !saveKeyForm.data.api_key}>{saveKeyForm.processing ? 'Saving...' : 'Save Key'}</Button>
                            </form>
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center gap-3">
                                    <span className="text-sm">Key ending in <code className="rounded bg-muted px-1.5 py-0.5 text-xs">•••{tenantSecret?.secret_last4}</code></span>
                                    {connStatus && <Badge className={connStatus.className}><connStatus.icon className="mr-1 h-3 w-3" />{connStatus.label}</Badge>}
                                </div>
                                <div className="text-sm text-muted-foreground space-y-1">
                                    <p>Last tested: {fmt(tenantSecret?.last_tested_at)}</p>
                                    <p>Last sync: {fmt(tenantSecret?.last_synced_at)}</p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" onClick={() => { setTestingConnection(true); router.post('/settings/integrations/unifi/test', {}, { preserveScroll: true, onFinish: () => setTestingConnection(false) }); }} disabled={testingConnection}>
                                        {testingConnection ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-2 h-4 w-4" />}Test Connection
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => setShowRotateForm((p) => !p)}>Rotate Key</Button>
                                </div>
                                {showRotateForm && (
                                    <form onSubmit={(e) => { e.preventDefault(); rotateKeyForm.post('/settings/integrations/unifi/rotate', { preserveScroll: true, onSuccess: () => { rotateKeyForm.reset('api_key'); setShowRotateForm(false); } }); }} className="space-y-3 rounded-lg border p-4">
                                        <Label htmlFor="rotate_api_key">New API Key</Label>
                                        <Input id="rotate_api_key" type="password" value={rotateKeyForm.data.api_key} onChange={(e) => rotateKeyForm.setData('api_key', e.target.value)} />
                                        <div className="flex gap-2">
                                            <Button type="submit" size="sm" disabled={rotateKeyForm.processing || !rotateKeyForm.data.api_key}>{rotateKeyForm.processing ? 'Saving...' : 'Save New Key'}</Button>
                                            <Button type="button" variant="ghost" size="sm" onClick={() => { setShowRotateForm(false); rotateKeyForm.reset('api_key'); }}>Cancel</Button>
                                        </div>
                                    </form>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>

                {hasKey && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <CardTitle>Location Mapping</CardTitle>
                            <Button variant="outline" size="sm" onClick={() => { setSyncingSites(true); router.post('/settings/integrations/unifi/sync-sites', {}, { preserveScroll: true, onFinish: () => setSyncingSites(false) }); }} disabled={syncingSites}>
                                {syncingSites ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-2 h-4 w-4" />}Sync UniFi Locations
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader><TableRow><TableHead>Location</TableHead><TableHead>UniFi Site</TableHead><TableHead>Status</TableHead><TableHead>Actions</TableHead></TableRow></TableHeader>
                                    <TableBody>
                                        {siteConfigs.map((c) => (
                                            <TableRow key={c.id}>
                                                <TableCell><div className="font-medium">{c.site_name}</div><div className="text-xs text-muted-foreground">{siteTypeLabel(c.site_type)}</div></TableCell>
                                                <TableCell><div className="font-medium">{c.mapped_external_site_name || 'Unknown name'}</div><code className="rounded bg-muted px-1.5 py-0.5 text-xs">{c.mapped_external_site_id || '---'}</code></TableCell>
                                                <TableCell><Badge variant={c.is_active ? 'default' : 'secondary'}>{c.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                                <TableCell><div className="flex flex-wrap gap-2">
                                                    <Button size="sm" variant="outline" onClick={() => { setSyncingSiteConfigId(c.id); router.post('/settings/integrations/unifi/sync-devices', { site_config_id: c.id }, { preserveScroll: true, onFinish: () => setSyncingSiteConfigId(null) }); }} disabled={syncingSiteConfigId === c.id}>{syncingSiteConfigId === c.id ? 'Syncing...' : 'Sync Devices'}</Button>
                                                    <Button size="sm" variant="ghost" onClick={() => router.delete(`/settings/integrations/unifi/map-site/${c.id}`, { preserveScroll: true })}>Remove</Button>
                                                </div></TableCell>
                                            </TableRow>
                                        ))}
                                        {siteConfigs.length === 0 && <TableRow><TableCell colSpan={4} className="text-sm text-muted-foreground">No mapped locations yet.</TableCell></TableRow>}
                                    </TableBody>
                                </Table>
                            </div>
                            {!showMapForm ? (
                                <Button variant="outline" size="sm" onClick={() => setShowMapForm(true)}><Building2 className="mr-2 h-4 w-4" />Map Location</Button>
                            ) : (
                                <form onSubmit={(e) => { e.preventDefault(); mapForm.post('/settings/integrations/unifi/map-site', { preserveScroll: true, onSuccess: () => { mapForm.reset(); setShowMapForm(false); } }); }} className="space-y-4 rounded-lg border p-4">
                                    <div className="grid gap-4 md:grid-cols-3">
                                        <div className="space-y-2">
                                            <Label>Platform Location</Label>
                                            <Select value={mapForm.data.site_id || undefined} onValueChange={(v) => mapForm.setData('site_id', v)}>
                                                <SelectTrigger><SelectValue placeholder="Select location" /></SelectTrigger>
                                                <SelectContent>{sites.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name} ({siteTypeLabel(s.type)})</SelectItem>)}</SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>UniFi Location</Label>
                                            {discoveredSites.length > 0 ? (
                                                <Select value={mapForm.data.external_site_id || undefined} onValueChange={(v) => {
                                                    const d = discoveredSites.find((s) => s.external_id === v);
                                                    mapForm.setData({ ...mapForm.data, external_site_id: v, external_site_name: d?.name ?? '' });
                                                }}>
                                                    <SelectTrigger><SelectValue placeholder="Select UniFi location" /></SelectTrigger>
                                                    <SelectContent>{discoveredSites.map((d) => <SelectItem key={d.external_id} value={d.external_id}>{d.name}{d.meta?.device_count !== undefined && d.meta?.device_count !== null ? ` (${d.meta.device_count} devices)` : ''}</SelectItem>)}</SelectContent>
                                                </Select>
                                            ) : (
                                                <Input value={mapForm.data.external_site_id} onChange={(e) => mapForm.setData('external_site_id', e.target.value)} placeholder="Enter UniFi site ID" />
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button type="submit" size="sm" disabled={mapForm.processing}>{mapForm.processing ? 'Saving...' : 'Save Mapping'}</Button>
                                        <Button type="button" variant="ghost" size="sm" onClick={() => { setShowMapForm(false); mapForm.reset(); }}>Cancel</Button>
                                    </div>
                                </form>
                            )}
                        </CardContent>
                    </Card>
                )}

                {hasKey && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <CardTitle>Synced Devices & Room Assignment</CardTitle>
                            <Select value={deviceSiteFilter} onValueChange={setDeviceSiteFilter}>
                                <SelectTrigger className="w-56"><SelectValue placeholder="Filter by location" /></SelectTrigger>
                                <SelectContent><SelectItem value="all">All Locations</SelectItem>{sites.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader><TableRow><TableHead>Location</TableHead><TableHead>Room</TableHead><TableHead>Device</TableHead><TableHead>Type</TableHead><TableHead>Model</TableHead><TableHead>Status</TableHead><TableHead>Last Seen</TableHead><TableHead>Action</TableHead></TableRow></TableHeader>
                                    <TableBody>
                                        {filteredDevices.map((d) => (
                                            <TableRow key={d.id}>
                                                <TableCell><div className="font-medium">{d.site_name}</div><div className="text-xs text-muted-foreground">{siteTypeLabel(d.site_type)}</div></TableCell>
                                                <TableCell className="min-w-[190px]">
                                                    <Select value={deviceRoomDraft[d.id] || 'unassigned'} onValueChange={(v) => setDeviceRoomDraft((p) => ({ ...p, [d.id]: v }))}>
                                                        <SelectTrigger><SelectValue placeholder="Select room" /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="unassigned">Unassigned</SelectItem>
                                                            {(roomsBySite[d.site_id] ?? []).map((r) => <SelectItem key={r.id} value={String(r.id)}>{r.name}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell><div className="font-medium">{d.name}</div>{d.provider_entity_id && <div className="text-xs text-muted-foreground font-mono">{d.provider_entity_id}</div>}</TableCell>
                                                <TableCell>{d.provider_type || d.category || '---'}</TableCell>
                                                <TableCell>{d.model || '---'}</TableCell>
                                                <TableCell><Badge variant="outline" className={d.status === 'online' ? 'border-emerald-500/30 text-emerald-500' : d.status === 'offline' ? 'border-red-500/30 text-red-500' : 'border-slate-500/30 text-slate-500'}>{d.status}</Badge></TableCell>
                                                <TableCell>{fmt(d.last_seen_at)}</TableCell>
                                                <TableCell><Button size="sm" variant="outline" onClick={() => { const raw = deviceRoomDraft[d.id]; const roomId = !raw || raw === 'unassigned' ? null : Number(raw); setAssigningDeviceId(d.id); router.put(`/settings/integrations/unifi/hardware/${d.id}/room`, { room_id: roomId }, { preserveScroll: true, onFinish: () => setAssigningDeviceId(null) }); }} disabled={assigningDeviceId === d.id}>{assigningDeviceId === d.id ? 'Saving...' : 'Save Room'}</Button></TableCell>
                                            </TableRow>
                                        ))}
                                        {filteredDevices.length === 0 && <TableRow><TableCell colSpan={8} className="text-sm text-muted-foreground">No synced devices for this filter yet.</TableCell></TableRow>}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {hasKey && (
                    <Card>
                        <CardHeader><CardTitle>Refresh & Alert Defaults</CardTitle></CardHeader>
                        <CardContent>
                            <form onSubmit={handleSaveDefaults} className="space-y-6">
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-2"><Label htmlFor="refresh_interval">Refresh Interval (minutes)</Label><Input id="refresh_interval" type="number" min={1} value={defaultsForm.data.refresh_interval_minutes} onChange={(e) => defaultsForm.setData('refresh_interval_minutes', Math.max(1, parseInt(e.target.value || '1', 10)))} /></div>
                                    <div className="space-y-2"><Label htmlFor="quiet_start">Quiet Hours Start</Label><Input id="quiet_start" type="time" value={defaultsForm.data.quiet_hours_start} onChange={(e) => defaultsForm.setData('quiet_hours_start', e.target.value)} /></div>
                                    <div className="space-y-2"><Label htmlFor="quiet_end">Quiet Hours End</Label><Input id="quiet_end" type="time" value={defaultsForm.data.quiet_hours_end} onChange={(e) => defaultsForm.setData('quiet_hours_end', e.target.value)} /></div>
                                </div>
                                <div className="space-y-3">
                                    <div className="flex items-center gap-2"><Checkbox id="alert_motion" checked={defaultsForm.data.alert_motion_events} onCheckedChange={(v) => defaultsForm.setData('alert_motion_events', !!v)} /><Label htmlFor="alert_motion" className="cursor-pointer">Alert on motion events</Label></div>
                                    <div className="flex items-center gap-2"><Checkbox id="alert_offline" checked={defaultsForm.data.alert_device_offline} onCheckedChange={(v) => defaultsForm.setData('alert_device_offline', !!v)} /><Label htmlFor="alert_offline" className="cursor-pointer">Alert when a device goes offline</Label></div>
                                </div>
                                <Button type="submit" disabled={savingDefaults}>{savingDefaults ? 'Saving...' : 'Save Defaults'}</Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {syncLogs.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle>Recent Sync Activity</CardTitle></CardHeader>
                        <CardContent>
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader><TableRow><TableHead>Action</TableHead><TableHead>Status</TableHead><TableHead>Items</TableHead><TableHead>Started</TableHead><TableHead>Completed</TableHead></TableRow></TableHeader>
                                    <TableBody>{syncLogs.map((log) => (
                                        <TableRow key={log.id}>
                                            <TableCell className="font-medium">{log.action}</TableCell>
                                            <TableCell><Badge className={syncStatusConfig[log.status] ?? syncStatusConfig.started}>{log.status}</Badge>{log.error_message && <p className="mt-1 text-xs text-red-400">{log.error_message}</p>}</TableCell>
                                            <TableCell className="text-xs text-muted-foreground">{log.items_processed} processed{log.items_created > 0 && `, ${log.items_created} created`}{log.items_updated > 0 && `, ${log.items_updated} updated`}{log.items_errored > 0 && `, ${log.items_errored} errored`}</TableCell>
                                            <TableCell className="text-sm">{log.started_at}</TableCell>
                                            <TableCell className="text-sm">{log.completed_at ?? '---'}</TableCell>
                                        </TableRow>
                                    ))}</TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
