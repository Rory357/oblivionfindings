import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, Link, router } from '@inertiajs/react';
import { Edit, GitBranch, Minus, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { type DeviceListItem, type Paginated, DeviceCard } from '../devices/shared';

// ── Types ─────────────────────────────────────────────────────────

type GroupDetail = {
    id: number;
    name: string;
    type: string;
    description: string | null;
    created_at: string | null;
    auto_rules: Record<string, unknown> | null;
    auto_rule_condition_count: number;
};

type AvailableDevice = {
    id: number;
    name: string;
    device_uid: string;
    domain: string;
    category: string;
};

type Props = {
    group: GroupDetail;
    members: Paginated<DeviceListItem>;
    availableDevices: AvailableDevice[];
};

function typeLabel(t: string): string {
    return { location: 'Location', functional: 'Functional', vendor: 'Vendor', maintenance: 'Maintenance', custom: 'Custom' }[t] ?? t;
}

function domainLabel(d: string): string {
    return { security: 'Security', tracking: 'Tracking', iot_healthcare: 'IoT / Healthcare', it_infrastructure: 'IT Infra', facilities: 'Facilities' }[d] ?? d;
}

// ── Component ─────────────────────────────────────────────────────

export default function DeviceGroupShow({ group, members, availableDevices }: Props) {
    const [addOpen, setAddOpen] = useState(false);
    const [selectedDevice, setSelectedDevice] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [syncingAuto, setSyncingAuto] = useState(false);

    const hasAutoRules = group.auto_rule_condition_count > 0;

    const syncAutoRules = () => {
        if (!confirm('Apply auto-rules to this group? Devices not matching the rules will be removed; new matches will be added.')) return;
        setSyncingAuto(true);
        router.post(`/security-devices/device-groups/${group.id}/auto-rules/sync`, {}, {
            preserveScroll: true,
            onFinish: () => setSyncingAuto(false),
        });
    };

    const submitAdd = () => {
        if (!selectedDevice) return;
        setSubmitting(true);
        router.post(`/security-devices/device-groups/${group.id}/members`, {
            device_id: selectedDevice,
        }, {
            preserveScroll: true,
            onSuccess: () => { setAddOpen(false); setSelectedDevice(''); },
            onFinish: () => setSubmitting(false),
        });
    };

    const removeMember = (deviceId: number, deviceName: string) => {
        if (!confirm(`Remove "${deviceName}" from this group?`)) return;
        router.delete(`/security-devices/device-groups/${group.id}/members/${deviceId}`, { preserveScroll: true });
    };

    const deleteGroup = () => {
        if (!confirm(`Delete group "${group.name}"? This cannot be undone.`)) return;
        router.delete(`/security-devices/device-groups/${group.id}`);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Device Groups', href: '/security-devices/device-groups' },
                { title: group.name },
            ]}
        >
            <Head title={`${group.name} - Device Groups`} />

            <PageShell>
                <PageHeader
                    title={
                        <div className="flex items-center gap-3">
                            <GitBranch className="h-6 w-6 text-primary" />
                            <span>{group.name}</span>
                            <Badge variant="outline" className="text-xs">{typeLabel(group.type)}</Badge>
                        </div>
                    }
                    description={group.description}
                    backHref="/security-devices/device-groups"
                    backLabel="Device Groups"
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/security-devices/device-groups/${group.id}/edit`}>
                                    <Edit className="mr-2 h-4 w-4" /> Edit
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" onClick={deleteGroup}>
                                <Trash2 className="mr-2 h-4 w-4" /> Delete
                            </Button>
                        </div>
                    }
                />

                {/* Members section */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="flex items-center gap-3">
                                    Members ({members.meta.total})
                                    {hasAutoRules && (
                                        <Badge variant="outline" className="text-xs">
                                            Auto-rules: {group.auto_rule_condition_count} condition{group.auto_rule_condition_count === 1 ? '' : 's'}
                                        </Badge>
                                    )}
                                </CardTitle>
                                <CardDescription>Devices in this group</CardDescription>
                            </div>
                            <div className="flex items-center gap-2">
                                {hasAutoRules && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={syncAutoRules}
                                        disabled={syncingAuto}
                                        title="Apply auto-rules — devices not matching the rules will be removed; new matches will be added."
                                    >
                                        {syncingAuto ? 'Syncing…' : 'Sync auto-rules'}
                                    </Button>
                                )}
                                <Button size="sm" onClick={() => { setSelectedDevice(''); setAddOpen(true); }}>
                                    <Plus className="mr-1 h-3 w-3" /> Add Device
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {members.data.length > 0 ? (
                            <div className="space-y-2">
                                {members.data.map((device) => (
                                    <div key={device.id} className="flex items-center gap-3 rounded-lg border p-3">
                                        <div className="min-w-0 flex-1">
                                            <Link
                                                href={`/security-devices/devices/${device.id}`}
                                                className="text-sm font-medium text-primary hover:underline"
                                            >
                                                {device.name}
                                            </Link>
                                            <div className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                <Badge variant="outline" className="font-mono text-[10px]">{device.device_uid}</Badge>
                                                <span>{domainLabel(device.domain)} / {device.category.replace(/_/g, ' ')}</span>
                                                {device.assigned_to && <span>| {device.assignment_type}: {device.assigned_to}</span>}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 shrink-0">
                                            <Badge variant={device.status === 'active' ? 'default' : device.status === 'offline' ? 'secondary' : 'outline'} className="text-[10px]">
                                                {device.status?.replace(/_/g, ' ')}
                                            </Badge>
                                            <Badge variant={device.health_status === 'healthy' ? 'default' : device.health_status === 'critical' ? 'destructive' : 'outline'} className="text-[10px]">
                                                {device.health_status}
                                            </Badge>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 w-7 p-0 text-muted-foreground hover:text-destructive"
                                                onClick={() => removeMember(device.id, device.name)}
                                            >
                                                <Minus className="h-3 w-3" />
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <EmptyState
                                icon={GitBranch}
                                title="No members"
                                description="Add devices to this group to start organising your hardware."
                                variant="compact"
                                action={<Button size="sm" onClick={() => setAddOpen(true)}>Add Device</Button>}
                            />
                        )}

                        {/* Pagination */}
                        {(members.meta.last_page ?? 1) > 1 && (
                            <div className="mt-4 flex items-center justify-center gap-1">
                                {members.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Add member dialog */}
                <Dialog open={addOpen} onOpenChange={setAddOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Add Device to Group</DialogTitle>
                            <DialogDescription>Select a device to add to "{group.name}".</DialogDescription>
                        </DialogHeader>

                        <div>
                            <label className="text-sm font-medium mb-1.5 block">Device <span className="text-destructive">*</span></label>
                            <Select value={selectedDevice} onValueChange={setSelectedDevice}>
                                <SelectTrigger><SelectValue placeholder="Select a device..." /></SelectTrigger>
                                <SelectContent>
                                    {availableDevices.map((d) => (
                                        <SelectItem key={d.id} value={String(d.id)}>
                                            {d.name} ({d.device_uid}) — {domainLabel(d.domain)} / {d.category.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {availableDevices.length === 0 && (
                                <p className="mt-2 text-xs text-muted-foreground italic">No available devices to add. All operational devices are already members.</p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button variant="outline" onClick={() => setAddOpen(false)}>Cancel</Button>
                            <Button onClick={submitAdd} disabled={submitting || !selectedDevice}>
                                {submitting ? 'Adding...' : 'Add to Group'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}
