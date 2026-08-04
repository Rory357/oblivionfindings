import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageHero } from '@/components/page';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    Edit,
    GitBranch,
    Minus,
    Plus,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

import { type DeviceListItem, type Paginated } from '../devices/shared';
import type { AutoRuleCondition, AutoRules } from './auto-rule-builder';

// ── Types ─────────────────────────────────────────────────────────

type GroupDetail = {
    id: number;
    name: string;
    type: string;
    description: string | null;
    created_at: string | null;
    auto_rules: AutoRules | null;
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

type AutoRulePreview = {
    count: number;
    changes: { added: number; removed: number; kept: number; total: number };
    sample: Array<{
        id: number;
        name: string;
        device_uid: string;
        category: string;
    }>;
};

function typeLabel(t: string): string {
    return (
        {
            location: 'Location',
            functional: 'Functional',
            vendor: 'Vendor',
            maintenance: 'Maintenance',
            custom: 'Custom',
        }[t] ?? t
    );
}

function domainLabel(d: string): string {
    return (
        {
            security: 'Security',
            tracking: 'Tracking',
            iot_healthcare: 'IoT / Healthcare',
            it_infrastructure: 'IT Infra',
            facilities: 'Facilities',
        }[d] ?? d
    );
}

function ruleFieldLabel(field: AutoRuleCondition['field']): string {
    return {
        domain: 'Area',
        category: 'Device type',
        subcategory: 'Device subtype',
        provider: 'Provider',
        status: 'Operational status',
        health_status: 'Health status',
    }[field];
}

function ruleOperatorLabel(operator: AutoRuleCondition['op']): string {
    return { equals: 'is', not_equals: 'is not', in: 'is one of' }[operator];
}

function ruleValueLabel(value: AutoRuleCondition['value']): string {
    return Array.isArray(value) ? value.join(', ') : value;
}

// ── Component ─────────────────────────────────────────────────────

export default function DeviceGroupShow({
    group,
    members,
    availableDevices,
}: Props) {
    const [addOpen, setAddOpen] = useState(false);
    const [selectedDevice, setSelectedDevice] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [syncingAuto, setSyncingAuto] = useState(false);
    const [autoPreviewOpen, setAutoPreviewOpen] = useState(false);
    const [autoPreview, setAutoPreview] = useState<AutoRulePreview | null>(
        null,
    );
    const [autoPreviewError, setAutoPreviewError] = useState<string | null>(
        null,
    );
    const [loadingAutoPreview, setLoadingAutoPreview] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [pendingMemberRemoval, setPendingMemberRemoval] = useState<{
        id: number;
        name: string;
    } | null>(null);

    const hasAutoRules = group.auto_rule_condition_count > 0;

    const reviewAutoRules = async () => {
        setAutoPreviewOpen(true);
        setLoadingAutoPreview(true);
        setAutoPreview(null);
        setAutoPreviewError(null);
        try {
            const response = await axios.get<AutoRulePreview>(
                `/security-devices/device-groups/${group.id}/auto-rules/preview`,
            );
            setAutoPreview(response.data);
        } catch {
            setAutoPreviewError(
                'The proposed membership could not be loaded. No devices were changed.',
            );
        } finally {
            setLoadingAutoPreview(false);
        }
    };

    const syncAutoRules = () => {
        setSyncingAuto(true);
        router.post(
            `/security-devices/device-groups/${group.id}/auto-rules/sync`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setAutoPreviewOpen(false),
                onFinish: () => setSyncingAuto(false),
            },
        );
    };

    const submitAdd = () => {
        if (!selectedDevice) return;
        setSubmitting(true);
        router.post(
            `/security-devices/device-groups/${group.id}/members`,
            {
                device_id: selectedDevice,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAddOpen(false);
                    setSelectedDevice('');
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const removeMember = () => {
        if (!pendingMemberRemoval) return;
        router.delete(
            `/security-devices/device-groups/${group.id}/members/${pendingMemberRemoval.id}`,
            { preserveScroll: true },
        );
    };

    const deleteGroup = () => {
        router.delete(`/security-devices/device-groups/${group.id}`);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Device Groups',
                    href: '/security-devices/device-groups',
                },
                { title: group.name },
            ]}
        >
            <Head title={`${group.name} - Device Groups`} />

            <PageShell>
                <PageHero
                    variant="compact"
                    title={
                        <div className="flex items-center gap-3">
                            <GitBranch className="h-6 w-6 text-primary" />
                            <span>{group.name}</span>
                            <Badge variant="outline" className="text-xs">
                                {typeLabel(group.type)}
                            </Badge>
                        </div>
                    }
                    description={group.description}
                    backHref="/security-devices/device-groups"
                    backLabel="Device Groups"
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={`/security-devices/device-groups/${group.id}/edit`}
                                >
                                    <Edit className="mr-2 h-4 w-4" /> Edit
                                </Link>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setDeleteOpen(true)}
                            >
                                <Trash2 className="mr-2 h-4 w-4" /> Delete
                            </Button>
                        </div>
                    }
                />

                {hasAutoRules && group.auto_rules && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <CardTitle>Automatic membership</CardTitle>
                                    <CardDescription className="mt-1">
                                        Saved rule. Devices only change after
                                        you review and apply the proposed
                                        membership.
                                    </CardDescription>
                                </div>
                                <Button
                                    type="button"
                                    onClick={reviewAutoRules}
                                    disabled={loadingAutoPreview}
                                >
                                    <RefreshCw className="mr-2 h-4 w-4" />
                                    Review proposed membership
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <p className="mb-3 text-sm font-medium">
                                Match{' '}
                                {group.auto_rules.match === 'all'
                                    ? 'all'
                                    : 'any'}{' '}
                                of these conditions:
                            </p>
                            <ol className="space-y-2">
                                {group.auto_rules.conditions.map(
                                    (condition, index) => (
                                        <li
                                            key={`${condition.field}-${index}`}
                                            className="rounded-md border bg-muted/20 px-3 py-2 text-sm"
                                        >
                                            <span className="font-medium">
                                                {ruleFieldLabel(
                                                    condition.field,
                                                )}
                                            </span>{' '}
                                            {ruleOperatorLabel(condition.op)}{' '}
                                            <span className="font-medium">
                                                {ruleValueLabel(
                                                    condition.value,
                                                )}
                                            </span>
                                        </li>
                                    ),
                                )}
                            </ol>
                        </CardContent>
                    </Card>
                )}

                {/* Members section */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="flex items-center gap-3">
                                    Members ({members.meta.total})
                                    {hasAutoRules && (
                                        <Badge
                                            variant="outline"
                                            className="text-xs"
                                        >
                                            Auto-rules:{' '}
                                            {group.auto_rule_condition_count}{' '}
                                            condition
                                            {group.auto_rule_condition_count ===
                                            1
                                                ? ''
                                                : 's'}
                                        </Badge>
                                    )}
                                </CardTitle>
                                <CardDescription>
                                    Devices in this group
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-2">
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        setSelectedDevice('');
                                        setAddOpen(true);
                                    }}
                                >
                                    <Plus className="mr-1 h-3 w-3" /> Add Device
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {members.data.length > 0 ? (
                            <div className="space-y-2">
                                {members.data.map((device) => (
                                    <div
                                        key={device.id}
                                        className="flex items-center gap-3 rounded-lg border p-3"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <Link
                                                href={`/security-devices/devices/${device.id}`}
                                                className="text-sm font-medium text-primary hover:underline"
                                            >
                                                {device.name}
                                            </Link>
                                            <div className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                <Badge
                                                    variant="outline"
                                                    className="font-mono text-[10px]"
                                                >
                                                    {device.device_uid}
                                                </Badge>
                                                <span>
                                                    {domainLabel(device.domain)}{' '}
                                                    /{' '}
                                                    {device.category.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </span>
                                                {device.assigned_to && (
                                                    <span>
                                                        |{' '}
                                                        {device.assignment_type}
                                                        : {device.assigned_to}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Badge
                                                variant={
                                                    device.status === 'active'
                                                        ? 'default'
                                                        : device.status ===
                                                            'offline'
                                                          ? 'secondary'
                                                          : 'outline'
                                                }
                                                className="text-[10px]"
                                            >
                                                {device.status?.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </Badge>
                                            <Badge
                                                variant={
                                                    device.health_status ===
                                                    'healthy'
                                                        ? 'default'
                                                        : device.health_status ===
                                                            'critical'
                                                          ? 'destructive'
                                                          : 'outline'
                                                }
                                                className="text-[10px]"
                                            >
                                                {device.health_status}
                                            </Badge>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="min-h-11 min-w-11 p-0 text-muted-foreground hover:text-destructive"
                                                aria-label={`Remove ${device.name} from group`}
                                                onClick={() =>
                                                    setPendingMemberRemoval({
                                                        id: device.id,
                                                        name: device.name,
                                                    })
                                                }
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
                                action={
                                    <Button
                                        size="sm"
                                        onClick={() => setAddOpen(true)}
                                    >
                                        Add Device
                                    </Button>
                                }
                            />
                        )}

                        {/* Pagination */}
                        {(members.meta.last_page ?? 1) > 1 && (
                            <div className="mt-4 flex items-center justify-center gap-1">
                                {members.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() =>
                                            link.url &&
                                            router.get(
                                                link.url,
                                                {},
                                                { preserveState: true },
                                            )
                                        }
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
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
                            <DialogDescription>
                                Select a device to add to "{group.name}".
                            </DialogDescription>
                        </DialogHeader>

                        <div>
                            <label className="mb-1.5 block text-sm font-medium">
                                Device{' '}
                                <span className="text-destructive">*</span>
                            </label>
                            <Select
                                value={selectedDevice}
                                onValueChange={setSelectedDevice}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a device..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {availableDevices.map((d) => (
                                        <SelectItem
                                            key={d.id}
                                            value={String(d.id)}
                                        >
                                            {d.name} ({d.device_uid}) —{' '}
                                            {domainLabel(d.domain)} /{' '}
                                            {d.category.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {availableDevices.length === 0 && (
                                <p className="mt-2 text-xs text-muted-foreground italic">
                                    No available devices to add. All operational
                                    devices are already members.
                                </p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setAddOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={submitAdd}
                                disabled={submitting || !selectedDevice}
                            >
                                {submitting ? 'Adding...' : 'Add to Group'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog
                    open={autoPreviewOpen}
                    onOpenChange={(open) => {
                        if (!syncingAuto) setAutoPreviewOpen(open);
                    }}
                >
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>
                                Review proposed membership
                            </DialogTitle>
                            <DialogDescription>
                                This preview is read-only. Apply only after the
                                additions and removals look correct.
                            </DialogDescription>
                        </DialogHeader>

                        {loadingAutoPreview && (
                            <div
                                className="rounded-lg border p-6 text-center text-sm text-muted-foreground"
                                role="status"
                            >
                                Checking visible devices…
                            </div>
                        )}

                        {autoPreviewError && (
                            <div
                                className="flex gap-3 rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
                                role="alert"
                            >
                                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>{autoPreviewError}</span>
                            </div>
                        )}

                        {autoPreview && (
                            <div className="space-y-4">
                                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                                    {[
                                        ['Add', autoPreview.changes.added],
                                        ['Remove', autoPreview.changes.removed],
                                        ['Keep', autoPreview.changes.kept],
                                        [
                                            'Final total',
                                            autoPreview.changes.total,
                                        ],
                                    ].map(([label, count]) => (
                                        <div
                                            key={label}
                                            className="rounded-lg border p-3 text-center"
                                        >
                                            <p className="text-2xl font-semibold">
                                                {count}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {label}
                                            </p>
                                        </div>
                                    ))}
                                </div>

                                <div className="rounded-lg border p-4">
                                    <p className="text-sm font-medium">
                                        Matching devices ({autoPreview.count})
                                    </p>
                                    {autoPreview.sample.length > 0 ? (
                                        <ul className="mt-2 max-h-52 space-y-2 overflow-y-auto">
                                            {autoPreview.sample.map(
                                                (device) => (
                                                    <li
                                                        key={device.id}
                                                        className="flex items-center justify-between gap-3 text-sm"
                                                    >
                                                        <Link
                                                            href={`/security-devices/devices/${device.id}`}
                                                            className="frontline-focus rounded-sm text-primary hover:underline"
                                                        >
                                                            {device.name}
                                                        </Link>
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {device.device_uid}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    ) : (
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            No visible devices match this rule.
                                        </p>
                                    )}
                                    {autoPreview.count >
                                        autoPreview.sample.length && (
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            Showing the first{' '}
                                            {autoPreview.sample.length} matches.
                                        </p>
                                    )}
                                </div>

                                {autoPreview.changes.removed > 0 && (
                                    <p className="text-sm text-status-warning">
                                        Applying will remove{' '}
                                        {autoPreview.changes.removed} visible{' '}
                                        {autoPreview.changes.removed === 1
                                            ? 'device'
                                            : 'devices'}{' '}
                                        that no longer match.
                                    </p>
                                )}
                            </div>
                        )}

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={syncingAuto}
                                onClick={() => setAutoPreviewOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                onClick={syncAutoRules}
                                disabled={
                                    !autoPreview ||
                                    syncingAuto ||
                                    loadingAutoPreview
                                }
                            >
                                {syncingAuto
                                    ? 'Applying…'
                                    : 'Apply membership changes'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <ConfirmDialog
                    open={deleteOpen}
                    onClose={() => setDeleteOpen(false)}
                    onConfirm={deleteGroup}
                    title="Delete device group?"
                    description={`Delete “${group.name}”? Its membership and saved automatic rule will no longer be available. Device records are not deleted.`}
                    confirmText="Delete group"
                />

                <ConfirmDialog
                    open={pendingMemberRemoval !== null}
                    onClose={() => setPendingMemberRemoval(null)}
                    onConfirm={removeMember}
                    title="Remove device from group?"
                    description={
                        pendingMemberRemoval
                            ? `Remove “${pendingMemberRemoval.name}” from this group? The device record is not deleted. A future automatic-membership apply may add it again if it still matches the saved rule.`
                            : ''
                    }
                    confirmText="Remove device"
                />
            </PageShell>
        </AppLayout>
    );
}
