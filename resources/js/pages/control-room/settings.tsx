import { ConfirmChip } from '@/components/control-room/alert-workspace-dialog';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, RefreshCw, Trash2, X } from 'lucide-react';
import { FormEvent, useState } from 'react';

// --- TypeScript Interfaces ---

interface SignalTypeOption {
    id: number;
    code: string;
    name: string;
    category: string;
    default_severity: string;
}

interface SignalSourceOption {
    id: number;
    name: string;
    slug: string;
    vendor: string;
    status: 'active' | 'inactive' | 'maintenance';
    capabilities: string[];
    last_heartbeat_at: string | null;
    last_signal_at: string | null;
    signal_count_24h: number;
    is_healthy: boolean;
}

interface SignalRuleData {
    id: number;
    name: string;
    signal_type_id: number | null;
    signal_type_code: string | null;
    signal_type_name: string | null;
    signal_source_id: number | null;
    signal_source_name: string | null;
    priority: number;
    conditions: Record<string, unknown> | null;
    output_severity: string | null;
    output_escalation_level: number | null;
    output_tier: number | null;
    dedup_window_minutes: number | null;
    deduplicate: boolean;
    suppress_in_maintenance: boolean;
    notify_roles: string[];
    notify_users: number[];
    playbook_id: number | null;
    playbook_name: string | null;
    is_active: boolean;
}

interface TriageQueueData {
    id: number;
    name: string;
    code: string;
    tier: number;
    description: string | null;
    handle_severities: string[];
    handle_sources: string[];
    handle_alert_types: string[];
    assigned_roles: string[];
    assigned_users: number[];
    auto_escalate_after_minutes: number | null;
    escalate_to_queue_id: number | null;
    escalate_to_queue_name: string | null;
    is_active: boolean;
    open_alert_count: number;
}

interface MaintenanceWindowData {
    id: number;
    name: string;
    description: string | null;
    signal_source_id: number | null;
    signal_source_name: string | null;
    site_id: number | null;
    starts_at: string;
    ends_at: string;
    status: 'scheduled' | 'active' | 'completed' | 'cancelled';
    created_by_name: string | null;
}

interface SignalOutboxRow {
    id: number;
    status: 'failed' | 'dead_letter';
    attempts: number;
    last_attempt_at: string | null;
    last_error: string | null;
    created_at: string | null;
    updated_at: string | null;
    can_retry: boolean;
    signal: {
        id: number;
        asset_id: number;
        device_id: number | null;
        signal_type: string;
        severity_hint: string;
        occurred_at: string | null;
    } | null;
}

interface PlaybookOption {
    id: number;
    name: string;
    code: string;
}

interface SiteOption {
    id: number;
    name: string;
}

interface Props {
    activeTab: string;
    signalRules: SignalRuleData[];
    signalTypes: SignalTypeOption[];
    signalSources: SignalSourceOption[];
    triageQueues: TriageQueueData[];
    maintenanceWindows: MaintenanceWindowData[];
    signalOutbox: SignalOutboxRow[];
    playbooks: PlaybookOption[];
    sites: SiteOption[];
    configOptions: Record<
        string,
        Array<{
            id: number;
            group: string;
            value: string;
            label: string;
            color: string | null;
            description: string | null;
            sort_order: number;
            is_active: boolean;
        }>
    >;
}

// --- Helpers ---

const severityOptions = ['low', 'medium', 'high', 'critical'] as const;
const sourceOptions = [
    'fleet',
    'compliance',
    'medication',
    'incident',
    'device',
    'manual',
    'integration',
] as const;
const roleOptions = [
    'control_room_operator',
    'control_room_supervisor',
    'site_manager',
    'clinical_lead',
    'on_call_manager',
] as const;

const severityColors: Record<string, string> = {
    low: 'bg-muted text-foreground',
    medium: 'bg-status-warning-bg text-status-warning',
    high: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
};

const statusColors: Record<string, string> = {
    active: 'bg-status-success-bg text-status-success',
    inactive: 'bg-muted text-muted-foreground',
    maintenance: 'bg-status-warning-bg text-status-warning',
};

const windowStatusColors: Record<string, string> = {
    scheduled: 'bg-status-info-bg text-status-info',
    active: 'bg-status-success-bg text-status-success',
    completed: 'bg-muted text-muted-foreground',
    cancelled: 'bg-status-critical-bg text-status-critical',
};

const outboxStatusColors: Record<string, string> = {
    failed: 'bg-status-warning-bg text-status-warning',
    dead_letter: 'bg-status-critical-bg text-status-critical',
};

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return 'Never';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ${diffMins % 60}m ago`;
    return `${diffDays}d ago`;
}

function heartbeatColor(isoString: string | null): string {
    if (!isoString) return 'text-status-critical';
    const diffMins = (Date.now() - new Date(isoString).getTime()) / 60000;
    if (diffMins < 5) return 'text-status-success';
    if (diffMins < 10) return 'text-status-warning';
    return 'text-status-critical';
}

function formatDateTime(isoString: string | null): string {
    if (!isoString) return '-';
    return new Date(isoString).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function toLocalDatetimeValue(isoString: string | null): string {
    if (!isoString) return '';
    const d = new Date(isoString);
    const offset = d.getTimezoneOffset();
    const local = new Date(d.getTime() - offset * 60000);
    return local.toISOString().slice(0, 16);
}

// --- Signal Rule Dialog ---

function SignalRuleDialog({
    open,
    onClose,
    rule,
    signalTypes,
    signalSources,
    playbooks,
}: {
    open: boolean;
    onClose: () => void;
    rule: SignalRuleData | null;
    signalTypes: SignalTypeOption[];
    signalSources: SignalSourceOption[];
    playbooks: PlaybookOption[];
}) {
    const isEdit = !!rule;

    const [form, setForm] = useState({
        name: rule?.name ?? '',
        signal_type_id: rule?.signal_type_id?.toString() ?? '',
        signal_source_id: rule?.signal_source_id?.toString() ?? '',
        priority: rule?.priority?.toString() ?? '100',
        conditions: rule?.conditions
            ? JSON.stringify(rule.conditions, null, 2)
            : '',
        output_severity: rule?.output_severity ?? '',
        output_escalation_level:
            rule?.output_escalation_level?.toString() ?? '0',
        output_tier: rule?.output_tier?.toString() ?? '1',
        dedup_window_minutes: rule?.dedup_window_minutes?.toString() ?? '',
        deduplicate: rule?.deduplicate ?? false,
        suppress_in_maintenance: rule?.suppress_in_maintenance ?? true,
        playbook_id: rule?.playbook_id?.toString() ?? '',
        is_active: rule?.is_active ?? true,
    });
    const [processing, setProcessing] = useState(false);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);

        let parsedConditions = null;
        if (form.conditions.trim()) {
            try {
                parsedConditions = JSON.parse(form.conditions);
            } catch {
                setProcessing(false);
                return;
            }
        }

        const data = {
            name: form.name,
            signal_type_id: form.signal_type_id
                ? Number(form.signal_type_id)
                : null,
            signal_type_code: form.signal_type_id
                ? (signalTypes.find((t) => t.id === Number(form.signal_type_id))
                      ?.code ?? null)
                : null,
            signal_source_id: form.signal_source_id
                ? Number(form.signal_source_id)
                : null,
            priority: Number(form.priority),
            conditions: parsedConditions,
            output_severity: form.output_severity || null,
            output_escalation_level: form.output_escalation_level
                ? Number(form.output_escalation_level)
                : null,
            output_tier: form.output_tier ? Number(form.output_tier) : null,
            dedup_window_minutes: form.dedup_window_minutes
                ? Number(form.dedup_window_minutes)
                : null,
            deduplicate: form.deduplicate,
            suppress_in_maintenance: form.suppress_in_maintenance,
            playbook_id: form.playbook_id ? Number(form.playbook_id) : null,
            is_active: form.is_active,
        };

        const url = isEdit
            ? `/control-room/settings/rules/${rule!.id}`
            : '/control-room/settings/rules';

        const method = isEdit ? 'put' : 'post';

        router[method](url, data, {
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        });
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[85vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Edit Signal Rule' : 'Create Signal Rule'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEdit
                            ? 'Update the configuration for this signal rule.'
                            : 'Define how incoming signals are processed into alerts.'}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="rule-name">Name</Label>
                        <Input
                            id="rule-name"
                            value={form.name}
                            onChange={(e) =>
                                setForm({ ...form, name: e.target.value })
                            }
                            required
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label>Signal Type</Label>
                            <Select
                                value={form.signal_type_id}
                                onValueChange={(v) =>
                                    setForm({ ...form, signal_type_id: v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Any type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {signalTypes.map((t) => (
                                        <SelectItem
                                            key={t.id}
                                            value={t.id.toString()}
                                        >
                                            {t.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Signal Source</Label>
                            <Select
                                value={form.signal_source_id}
                                onValueChange={(v) =>
                                    setForm({ ...form, signal_source_id: v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Any source" />
                                </SelectTrigger>
                                <SelectContent>
                                    {signalSources.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={s.id.toString()}
                                        >
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="rule-priority">Priority</Label>
                            <Input
                                id="rule-priority"
                                type="number"
                                min={0}
                                max={1000}
                                value={form.priority}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        priority: e.target.value,
                                    })
                                }
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Output Severity</Label>
                            <Select
                                value={form.output_severity}
                                onValueChange={(v) =>
                                    setForm({ ...form, output_severity: v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Inherit" />
                                </SelectTrigger>
                                <SelectContent>
                                    {severityOptions.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {s.charAt(0).toUpperCase() +
                                                s.slice(1)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="rule-escalation">
                                Escalation Level
                            </Label>
                            <Input
                                id="rule-escalation"
                                type="number"
                                min={0}
                                max={10}
                                value={form.output_escalation_level}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        output_escalation_level: e.target.value,
                                    })
                                }
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="rule-dedup">
                                Dedup Window (min)
                            </Label>
                            <Input
                                id="rule-dedup"
                                type="number"
                                min={0}
                                value={form.dedup_window_minutes}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        dedup_window_minutes: e.target.value,
                                    })
                                }
                                placeholder="No dedup"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Playbook</Label>
                            <Select
                                value={form.playbook_id}
                                onValueChange={(v) =>
                                    setForm({ ...form, playbook_id: v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="None" />
                                </SelectTrigger>
                                <SelectContent>
                                    {playbooks.map((p) => (
                                        <SelectItem
                                            key={p.id}
                                            value={p.id.toString()}
                                        >
                                            {p.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="rule-conditions">
                            Conditions (JSON)
                        </Label>
                        <Textarea
                            id="rule-conditions"
                            rows={4}
                            className="font-mono text-sm"
                            value={form.conditions}
                            onChange={(e) =>
                                setForm({ ...form, conditions: e.target.value })
                            }
                            placeholder='{"site_id": 1, "severity_hint": "critical"}'
                        />
                    </div>

                    <div className="flex items-center gap-6">
                        <div className="flex items-center gap-2">
                            <Switch
                                id="rule-suppress"
                                checked={form.suppress_in_maintenance}
                                onCheckedChange={(v) =>
                                    setForm({
                                        ...form,
                                        suppress_in_maintenance: v,
                                    })
                                }
                            />
                            <Label htmlFor="rule-suppress">
                                Suppress during maintenance
                            </Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Switch
                                id="rule-dedup-toggle"
                                checked={form.deduplicate}
                                onCheckedChange={(v) =>
                                    setForm({ ...form, deduplicate: v })
                                }
                            />
                            <Label htmlFor="rule-dedup-toggle">
                                Deduplicate
                            </Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Switch
                                id="rule-active"
                                checked={form.is_active}
                                onCheckedChange={(v) =>
                                    setForm({ ...form, is_active: v })
                                }
                            />
                            <Label htmlFor="rule-active">Active</Label>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Saving...'
                                : isEdit
                                  ? 'Update Rule'
                                  : 'Create Rule'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// --- Triage Queue Dialog ---

function TriageQueueDialog({
    open,
    onClose,
    queue,
    allQueues,
}: {
    open: boolean;
    onClose: () => void;
    queue: TriageQueueData | null;
    allQueues: TriageQueueData[];
}) {
    const isEdit = !!queue;

    const [form, setForm] = useState({
        name: queue?.name ?? '',
        code: queue?.code ?? '',
        tier: queue?.tier?.toString() ?? '1',
        description: queue?.description ?? '',
        handle_severities: queue?.handle_severities ?? [],
        handle_sources: queue?.handle_sources ?? [],
        assigned_roles: queue?.assigned_roles ?? [],
        auto_escalate_after_minutes:
            queue?.auto_escalate_after_minutes?.toString() ?? '',
        escalate_to_queue_id: queue?.escalate_to_queue_id?.toString() ?? '',
        is_active: queue?.is_active ?? true,
    });
    const [processing, setProcessing] = useState(false);

    function toggleArrayItem(
        field: 'handle_severities' | 'handle_sources' | 'assigned_roles',
        value: string,
    ) {
        setForm((prev) => {
            const arr = prev[field];
            const next = arr.includes(value)
                ? arr.filter((v) => v !== value)
                : [...arr, value];
            return { ...prev, [field]: next };
        });
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);

        const data = {
            name: form.name,
            code: form.code,
            tier: Number(form.tier),
            description: form.description || null,
            handle_severities: form.handle_severities,
            handle_sources: form.handle_sources,
            assigned_roles: form.assigned_roles,
            auto_escalate_after_minutes: form.auto_escalate_after_minutes
                ? Number(form.auto_escalate_after_minutes)
                : null,
            escalate_to_queue_id: form.escalate_to_queue_id
                ? Number(form.escalate_to_queue_id)
                : null,
            is_active: form.is_active,
        };

        const url = isEdit
            ? `/control-room/settings/queues/${queue!.id}`
            : '/control-room/settings/queues';

        const method = isEdit ? 'put' : 'post';

        router[method](url, data, {
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        });
    }

    const otherQueues = allQueues.filter((q) => q.id !== queue?.id);

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[85vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Edit Triage Queue' : 'Create Triage Queue'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEdit
                            ? 'Update queue configuration and routing rules.'
                            : 'Define a new queue for alert triage and routing.'}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="queue-name">Name</Label>
                            <Input
                                id="queue-name"
                                value={form.name}
                                onChange={(e) =>
                                    setForm({ ...form, name: e.target.value })
                                }
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="queue-code">Code</Label>
                            <Input
                                id="queue-code"
                                value={form.code}
                                onChange={(e) =>
                                    setForm({ ...form, code: e.target.value })
                                }
                                required
                                placeholder="e.g. tier-1-general"
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="queue-tier">Tier</Label>
                            <Input
                                id="queue-tier"
                                type="number"
                                min={1}
                                max={5}
                                value={form.tier}
                                onChange={(e) =>
                                    setForm({ ...form, tier: e.target.value })
                                }
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="queue-escalate-mins">
                                Auto-Escalate After (min)
                            </Label>
                            <Input
                                id="queue-escalate-mins"
                                type="number"
                                min={1}
                                value={form.auto_escalate_after_minutes}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        auto_escalate_after_minutes:
                                            e.target.value,
                                    })
                                }
                                placeholder="Disabled"
                            />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="queue-desc">Description</Label>
                        <Textarea
                            id="queue-desc"
                            rows={2}
                            value={form.description}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    description: e.target.value,
                                })
                            }
                        />
                    </div>

                    <div className="space-y-2">
                        <Label>Handle Severities</Label>
                        <div className="flex flex-wrap gap-3">
                            {severityOptions.map((s) => (
                                <label
                                    key={s}
                                    className="flex items-center gap-1.5 text-sm"
                                >
                                    <Checkbox
                                        checked={form.handle_severities.includes(
                                            s,
                                        )}
                                        onCheckedChange={() =>
                                            toggleArrayItem(
                                                'handle_severities',
                                                s,
                                            )
                                        }
                                    />
                                    {s.charAt(0).toUpperCase() + s.slice(1)}
                                </label>
                            ))}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label>Handle Sources</Label>
                        <div className="flex flex-wrap gap-3">
                            {sourceOptions.map((s) => (
                                <label
                                    key={s}
                                    className="flex items-center gap-1.5 text-sm"
                                >
                                    <Checkbox
                                        checked={form.handle_sources.includes(
                                            s,
                                        )}
                                        onCheckedChange={() =>
                                            toggleArrayItem('handle_sources', s)
                                        }
                                    />
                                    {s.charAt(0).toUpperCase() + s.slice(1)}
                                </label>
                            ))}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label>Assigned Roles</Label>
                        <div className="flex flex-wrap gap-3">
                            {roleOptions.map((r) => (
                                <label
                                    key={r}
                                    className="flex items-center gap-1.5 text-sm"
                                >
                                    <Checkbox
                                        checked={form.assigned_roles.includes(
                                            r,
                                        )}
                                        onCheckedChange={() =>
                                            toggleArrayItem('assigned_roles', r)
                                        }
                                    />
                                    {r.replace(/_/g, ' ')}
                                </label>
                            ))}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label>Escalate To Queue</Label>
                        <Select
                            value={form.escalate_to_queue_id}
                            onValueChange={(v) =>
                                setForm({ ...form, escalate_to_queue_id: v })
                            }
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="None" />
                            </SelectTrigger>
                            <SelectContent>
                                {otherQueues.map((q) => (
                                    <SelectItem
                                        key={q.id}
                                        value={q.id.toString()}
                                    >
                                        {q.name} (Tier {q.tier})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex items-center gap-2">
                        <Switch
                            id="queue-active"
                            checked={form.is_active}
                            onCheckedChange={(v) =>
                                setForm({ ...form, is_active: v })
                            }
                        />
                        <Label htmlFor="queue-active">Active</Label>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Saving...'
                                : isEdit
                                  ? 'Update Queue'
                                  : 'Create Queue'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// --- Maintenance Window Dialog ---

function MaintenanceWindowDialog({
    open,
    onClose,
    window: mw,
    signalSources,
    sites,
}: {
    open: boolean;
    onClose: () => void;
    window: MaintenanceWindowData | null;
    signalSources: SignalSourceOption[];
    sites: SiteOption[];
}) {
    const isEdit = !!mw;

    const [form, setForm] = useState({
        name: mw?.name ?? '',
        description: mw?.description ?? '',
        signal_source_id: mw?.signal_source_id?.toString() ?? '',
        site_id: mw?.site_id?.toString() ?? '',
        starts_at: toLocalDatetimeValue(mw?.starts_at ?? null),
        ends_at: toLocalDatetimeValue(mw?.ends_at ?? null),
    });
    const [processing, setProcessing] = useState(false);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setProcessing(true);

        const data = {
            name: form.name,
            description: form.description || null,
            signal_source_id: form.signal_source_id
                ? Number(form.signal_source_id)
                : null,
            site_id: form.site_id ? Number(form.site_id) : null,
            starts_at: form.starts_at
                ? new Date(form.starts_at).toISOString()
                : null,
            ends_at: form.ends_at ? new Date(form.ends_at).toISOString() : null,
        };

        const url = isEdit
            ? `/control-room/settings/maintenance/${mw!.id}`
            : '/control-room/settings/maintenance';

        const method = isEdit ? 'put' : 'post';

        router[method](url, data, {
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        });
    }

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit
                            ? 'Edit Maintenance Window'
                            : 'Schedule Maintenance Window'}
                    </DialogTitle>
                    <DialogDescription>
                        Signals from the selected source/site will be suppressed
                        during this window.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="mw-name">Name</Label>
                        <Input
                            id="mw-name"
                            value={form.name}
                            onChange={(e) =>
                                setForm({ ...form, name: e.target.value })
                            }
                            required
                            placeholder="e.g. Server patching - March"
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="mw-desc">Description</Label>
                        <Textarea
                            id="mw-desc"
                            rows={2}
                            value={form.description}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    description: e.target.value,
                                })
                            }
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label>Signal Source</Label>
                            <Select
                                value={form.signal_source_id}
                                onValueChange={(v) =>
                                    setForm({ ...form, signal_source_id: v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All sources" />
                                </SelectTrigger>
                                <SelectContent>
                                    {signalSources.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={s.id.toString()}
                                        >
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Site</Label>
                            <Select
                                value={form.site_id}
                                onValueChange={(v) =>
                                    setForm({ ...form, site_id: v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All sites" />
                                </SelectTrigger>
                                <SelectContent>
                                    {sites.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={s.id.toString()}
                                        >
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="mw-starts">Starts At</Label>
                            <Input
                                id="mw-starts"
                                type="datetime-local"
                                value={form.starts_at}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        starts_at: e.target.value,
                                    })
                                }
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="mw-ends">Ends At</Label>
                            <Input
                                id="mw-ends"
                                type="datetime-local"
                                value={form.ends_at}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        ends_at: e.target.value,
                                    })
                                }
                                required
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Saving...'
                                : isEdit
                                  ? 'Update Window'
                                  : 'Schedule Window'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// --- Delete Confirmation Dialog ---

function DeleteConfirmDialog({
    open,
    onClose,
    onConfirm,
    title,
    description,
}: {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    title: string;
    description: string;
}) {
    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={onConfirm}>
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// --- Main Page Component ---

export default function ControlRoomSettings({
    activeTab,
    signalRules,
    signalTypes,
    signalSources,
    triageQueues,
    maintenanceWindows,
    signalOutbox,
    playbooks,
    sites,
    configOptions,
}: Props) {
    const [tab, setTab] = useState(activeTab);

    // Config options state
    const [optionDialogOpen, setOptionDialogOpen] = useState(false);
    const [optionGroup, setOptionGroup] = useState('category');
    const [optionValue, setOptionValue] = useState('');
    const [optionLabel, setOptionLabel] = useState('');
    const [optionColor, setOptionColor] = useState('');
    const [optionDesc, setOptionDesc] = useState('');
    const [deleteOptionId, setDeleteOptionId] = useState<number | null>(null);

    // Signal Rules state
    const [ruleDialogOpen, setRuleDialogOpen] = useState(false);
    const [editingRule, setEditingRule] = useState<SignalRuleData | null>(null);
    const [deleteRuleId, setDeleteRuleId] = useState<number | null>(null);

    // Queue state
    const [queueDialogOpen, setQueueDialogOpen] = useState(false);
    const [editingQueue, setEditingQueue] = useState<TriageQueueData | null>(
        null,
    );

    // Maintenance window state
    const [mwDialogOpen, setMwDialogOpen] = useState(false);
    const [editingMw, setEditingMw] = useState<MaintenanceWindowData | null>(
        null,
    );

    function handleTabChange(newTab: string) {
        setTab(newTab);
        router.get(
            '/control-room/settings',
            { tab: newTab },
            { preserveState: true, preserveScroll: true },
        );
    }

    function handleDeleteRule() {
        if (!deleteRuleId) return;
        router.delete(`/control-room/settings/rules/${deleteRuleId}`, {
            onSuccess: () => setDeleteRuleId(null),
        });
    }

    function handleToggleRuleActive(rule: SignalRuleData) {
        router.put(
            `/control-room/settings/rules/${rule.id}`,
            {
                ...rule,
                conditions: (rule.conditions ?? {}) as Record<
                    string,
                    string | number | boolean | null
                >,
                is_active: !rule.is_active,
            },
            { preserveScroll: true },
        );
    }

    function handleToggleQueueActive(queue: TriageQueueData) {
        router.put(
            `/control-room/settings/queues/${queue.id}`,
            { ...queue, is_active: !queue.is_active },
            { preserveScroll: true },
        );
    }

    function handleCancelWindow(windowId: number) {
        router.post(
            `/control-room/settings/maintenance/${windowId}/cancel`,
            {},
            { preserveScroll: true },
        );
    }

    function handleRetryOutbox(outboxId: number) {
        router.post(
            `/control-room/settings/signal-outbox/${outboxId}/retry`,
            {},
            { preserveScroll: true },
        );
    }

    const breadcrumbs = [
        { title: 'Control Room', href: '/control-room' },
        { title: 'Settings', href: '#' },
    ];

    return (
        <AppLayout>
            <Head title="Control Room Settings" />
            <PageShell>
                <PageHero variant="compact"
                    title="Settings"
                    description="Configure signal rules, triage queues, sources, and maintenance windows."
                />

                <Tabs
                    defaultValue={tab}
                    onValueChange={handleTabChange}
                    className="mt-6"
                >
                    <TabsList>
                        <TabsTrigger value="rules">Signal Rules</TabsTrigger>
                        <TabsTrigger value="queues">Triage Queues</TabsTrigger>
                        <TabsTrigger value="sources">
                            Signal Sources
                        </TabsTrigger>
                        <TabsTrigger value="maintenance">
                            Maintenance
                        </TabsTrigger>
                        <TabsTrigger value="signal-outbox">
                            Signal Outbox
                        </TabsTrigger>
                        <TabsTrigger value="ticket-options">
                            Ticket Options
                        </TabsTrigger>
                    </TabsList>

                    {/* --- Tab 1: Signal Rules --- */}
                    <TabsContent value="rules" className="mt-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle className="text-base">
                                    Signal Rules
                                </CardTitle>
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        setEditingRule(null);
                                        setRuleDialogOpen(true);
                                    }}
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Create Rule
                                </Button>
                            </CardHeader>
                            <CardContent>
                                {signalRules.length === 0 ? (
                                    <p className="py-8 text-center text-sm text-muted-foreground">
                                        No signal rules configured yet. Create
                                        one to get started.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-muted-foreground">
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Name
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Signal Type
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Source
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Priority
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Severity
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Dedup
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Active
                                                    </th>
                                                    <th className="pb-2 font-medium">
                                                        Actions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {signalRules.map((rule) => (
                                                    <tr
                                                        key={rule.id}
                                                        className="border-b last:border-0"
                                                    >
                                                        <td className="py-2.5 pr-4 font-medium">
                                                            {rule.name}
                                                        </td>
                                                        <td className="py-2.5 pr-4 text-muted-foreground">
                                                            {rule.signal_type_name ??
                                                                'Any'}
                                                        </td>
                                                        <td className="py-2.5 pr-4 text-muted-foreground">
                                                            {rule.signal_source_name ??
                                                                'Any'}
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            {rule.priority}
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            {rule.output_severity ? (
                                                                <Badge
                                                                    variant="outline"
                                                                    className={
                                                                        severityColors[
                                                                            rule
                                                                                .output_severity
                                                                        ] ?? ''
                                                                    }
                                                                >
                                                                    {
                                                                        rule.output_severity
                                                                    }
                                                                </Badge>
                                                            ) : (
                                                                <span className="text-muted-foreground">
                                                                    Inherit
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            {rule.dedup_window_minutes
                                                                ? `${rule.dedup_window_minutes}m`
                                                                : '-'}
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            <Switch
                                                                checked={
                                                                    rule.is_active
                                                                }
                                                                onCheckedChange={() =>
                                                                    handleToggleRuleActive(
                                                                        rule,
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                        <td className="py-2.5">
                                                            <div className="flex gap-1">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() => {
                                                                        setEditingRule(
                                                                            rule,
                                                                        );
                                                                        setRuleDialogOpen(
                                                                            true,
                                                                        );
                                                                    }}
                                                                >
                                                                    <Pencil className="h-3.5 w-3.5" />
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setDeleteRuleId(
                                                                            rule.id,
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 className="h-3.5 w-3.5 text-status-critical" />
                                                                </Button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* --- Tab 2: Triage Queues --- */}
                    <TabsContent value="queues" className="mt-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle className="text-base">
                                    Triage Queues
                                </CardTitle>
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        setEditingQueue(null);
                                        setQueueDialogOpen(true);
                                    }}
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Create Queue
                                </Button>
                            </CardHeader>
                            <CardContent>
                                {triageQueues.length === 0 ? (
                                    <p className="py-8 text-center text-sm text-muted-foreground">
                                        No triage queues configured yet.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-muted-foreground">
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Name
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Code
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Tier
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Severities
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Sources
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Auto-Escalate
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Next Queue
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Open
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Active
                                                    </th>
                                                    <th className="pb-2 font-medium">
                                                        Actions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {triageQueues.map((queue) => (
                                                    <tr
                                                        key={queue.id}
                                                        className="border-b last:border-0"
                                                    >
                                                        <td className="py-2.5 pr-4 font-medium">
                                                            {queue.name}
                                                        </td>
                                                        <td className="py-2.5 pr-4 font-mono text-xs text-muted-foreground">
                                                            {queue.code}
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            <Badge variant="outline">
                                                                T{queue.tier}
                                                            </Badge>
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            <div className="flex flex-wrap gap-1">
                                                                {queue.handle_severities.map(
                                                                    (s) => (
                                                                        <Badge
                                                                            key={
                                                                                s
                                                                            }
                                                                            variant="outline"
                                                                            className={`text-xs ${severityColors[s] ?? ''}`}
                                                                        >
                                                                            {s}
                                                                        </Badge>
                                                                    ),
                                                                )}
                                                                {queue
                                                                    .handle_severities
                                                                    .length ===
                                                                    0 && (
                                                                    <span className="text-muted-foreground">
                                                                        All
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            <div className="flex flex-wrap gap-1">
                                                                {queue.handle_sources.map(
                                                                    (s) => (
                                                                        <Badge
                                                                            key={
                                                                                s
                                                                            }
                                                                            variant="outline"
                                                                            className="text-xs"
                                                                        >
                                                                            {s}
                                                                        </Badge>
                                                                    ),
                                                                )}
                                                                {queue
                                                                    .handle_sources
                                                                    .length ===
                                                                    0 && (
                                                                    <span className="text-muted-foreground">
                                                                        All
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            {queue.auto_escalate_after_minutes
                                                                ? `${queue.auto_escalate_after_minutes}m`
                                                                : '-'}
                                                        </td>
                                                        <td className="py-2.5 pr-4 text-muted-foreground">
                                                            {queue.escalate_to_queue_name ??
                                                                '-'}
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            <Badge
                                                                variant="outline"
                                                                className={
                                                                    queue.open_alert_count >
                                                                    0
                                                                        ? 'bg-status-warning-bg text-status-warning'
                                                                        : ''
                                                                }
                                                            >
                                                                {
                                                                    queue.open_alert_count
                                                                }
                                                            </Badge>
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            <Switch
                                                                checked={
                                                                    queue.is_active
                                                                }
                                                                onCheckedChange={() =>
                                                                    handleToggleQueueActive(
                                                                        queue,
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                        <td className="py-2.5">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => {
                                                                    setEditingQueue(
                                                                        queue,
                                                                    );
                                                                    setQueueDialogOpen(
                                                                        true,
                                                                    );
                                                                }}
                                                            >
                                                                <Pencil className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* --- Tab 3: Signal Sources (read-only) --- */}
                    <TabsContent value="sources" className="mt-4">
                        {signalSources.length === 0 ? (
                            <Card>
                                <CardContent className="py-8 text-center text-sm text-muted-foreground">
                                    No signal sources configured.
                                </CardContent>
                            </Card>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {signalSources.map((source) => (
                                    <Card key={source.id}>
                                        <CardHeader className="pb-3">
                                            <div className="flex items-start justify-between">
                                                <div>
                                                    <CardTitle className="text-base">
                                                        {source.name}
                                                    </CardTitle>
                                                    {source.vendor && (
                                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                                            {source.vendor}
                                                        </p>
                                                    )}
                                                </div>
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        statusColors[
                                                            source.status
                                                        ] ?? ''
                                                    }
                                                >
                                                    {source.status}
                                                </Badge>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            <div className="grid grid-cols-2 gap-2 text-sm">
                                                <div>
                                                    <p className="text-xs text-muted-foreground">
                                                        Last Heartbeat
                                                    </p>
                                                    <p
                                                        className={`font-medium ${heartbeatColor(source.last_heartbeat_at)}`}
                                                    >
                                                        {formatRelativeTime(
                                                            source.last_heartbeat_at,
                                                        )}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-xs text-muted-foreground">
                                                        Last Signal
                                                    </p>
                                                    <p className="font-medium">
                                                        {formatRelativeTime(
                                                            source.last_signal_at,
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">
                                                    24h Signal Count
                                                </p>
                                                <p className="text-lg font-semibold">
                                                    {source.signal_count_24h.toLocaleString()}
                                                </p>
                                            </div>
                                            {source.capabilities.length > 0 && (
                                                <div>
                                                    <p className="mb-1 text-xs text-muted-foreground">
                                                        Capabilities
                                                    </p>
                                                    <div className="flex flex-wrap gap-1">
                                                        {source.capabilities.map(
                                                            (cap) => (
                                                                <Badge
                                                                    key={cap}
                                                                    variant="secondary"
                                                                    className="text-xs"
                                                                >
                                                                    {cap}
                                                                </Badge>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </TabsContent>

                    {/* --- Tab 4: Signal Outbox --- */}
                    <TabsContent value="signal-outbox" className="mt-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Signal Outbox
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {signalOutbox.length === 0 ? (
                                    <p className="py-8 text-center text-sm text-muted-foreground">
                                        No failed signal deliveries.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-muted-foreground">
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Signal
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Status
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Attempts
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Last Attempt
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Error
                                                    </th>
                                                    <th className="pb-2 font-medium">
                                                        Action
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {signalOutbox.map((row) => (
                                                    <tr
                                                        key={row.id}
                                                        className="border-b last:border-0"
                                                    >
                                                        <td className="py-2.5 pr-4">
                                                            <div className="font-medium">
                                                                {row.signal
                                                                    ?.signal_type ??
                                                                    `Outbox #${row.id}`}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {row.signal
                                                                    ? `Signal #${row.signal.id} - Asset #${row.signal.asset_id}`
                                                                    : 'Missing source signal'}
                                                            </div>
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            <Badge
                                                                variant="outline"
                                                                className={
                                                                    outboxStatusColors[
                                                                        row
                                                                            .status
                                                                    ] ?? ''
                                                                }
                                                            >
                                                                {row.status}
                                                            </Badge>
                                                        </td>
                                                        <td className="py-2.5 pr-4">
                                                            {row.attempts}
                                                        </td>
                                                        <td className="py-2.5 pr-4 text-muted-foreground">
                                                            {formatDateTime(
                                                                row.last_attempt_at,
                                                            )}
                                                        </td>
                                                        <td className="max-w-[280px] truncate py-2.5 pr-4 text-muted-foreground">
                                                            {row.last_error ??
                                                                '-'}
                                                        </td>
                                                        <td className="py-2.5">
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={
                                                                    !row.can_retry
                                                                }
                                                                onClick={() =>
                                                                    handleRetryOutbox(
                                                                        row.id,
                                                                    )
                                                                }
                                                            >
                                                                <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                                                Retry
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* --- Tab 5: Maintenance Windows --- */}
                    <TabsContent value="maintenance" className="mt-4">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle className="text-base">
                                    Maintenance Windows
                                </CardTitle>
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        setEditingMw(null);
                                        setMwDialogOpen(true);
                                    }}
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Schedule Window
                                </Button>
                            </CardHeader>
                            <CardContent>
                                {maintenanceWindows.length === 0 ? (
                                    <p className="py-8 text-center text-sm text-muted-foreground">
                                        No maintenance windows scheduled.
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-muted-foreground">
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Name
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Source / Site
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Starts At
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Ends At
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Status
                                                    </th>
                                                    <th className="pr-4 pb-2 font-medium">
                                                        Created By
                                                    </th>
                                                    <th className="pb-2 font-medium">
                                                        Actions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {maintenanceWindows.map(
                                                    (mw) => (
                                                        <tr
                                                            key={mw.id}
                                                            className="border-b last:border-0"
                                                        >
                                                            <td className="py-2.5 pr-4 font-medium">
                                                                {mw.name}
                                                            </td>
                                                            <td className="py-2.5 pr-4 text-muted-foreground">
                                                                {mw.signal_source_name ??
                                                                    'All sources'}
                                                            </td>
                                                            <td className="py-2.5 pr-4">
                                                                {formatDateTime(
                                                                    mw.starts_at,
                                                                )}
                                                            </td>
                                                            <td className="py-2.5 pr-4">
                                                                {formatDateTime(
                                                                    mw.ends_at,
                                                                )}
                                                            </td>
                                                            <td className="py-2.5 pr-4">
                                                                <Badge
                                                                    variant="outline"
                                                                    className={
                                                                        windowStatusColors[
                                                                            mw
                                                                                .status
                                                                        ] ?? ''
                                                                    }
                                                                >
                                                                    {mw.status}
                                                                </Badge>
                                                            </td>
                                                            <td className="py-2.5 pr-4 text-muted-foreground">
                                                                {mw.created_by_name ??
                                                                    '-'}
                                                            </td>
                                                            <td className="py-2.5">
                                                                <div className="flex gap-1">
                                                                    {(mw.status ===
                                                                        'scheduled' ||
                                                                        mw.status ===
                                                                            'active') && (
                                                                        <>
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                onClick={() => {
                                                                                    setEditingMw(
                                                                                        mw,
                                                                                    );
                                                                                    setMwDialogOpen(
                                                                                        true,
                                                                                    );
                                                                                }}
                                                                            >
                                                                                <Pencil className="h-3.5 w-3.5" />
                                                                            </Button>
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                onClick={() =>
                                                                                    handleCancelWindow(
                                                                                        mw.id,
                                                                                    )
                                                                                }
                                                                            >
                                                                                <X className="h-3.5 w-3.5 text-status-critical" />
                                                                            </Button>
                                                                        </>
                                                                    )}
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                    {/* --- Tab 5: Ticket Options --- */}
                    <TabsContent
                        value="ticket-options"
                        className="mt-4 space-y-6"
                    >
                        {(
                            [
                                'category',
                                'resolution_code',
                                'task_category',
                            ] as const
                        ).map((group) => {
                            const groupLabels: Record<string, string> = {
                                category: 'Alert Categories',
                                resolution_code: 'Resolution Codes',
                                task_category: 'Task Categories',
                            };
                            const items = configOptions?.[group] ?? [];
                            return (
                                <Card key={group}>
                                    <CardHeader className="flex flex-row items-center justify-between">
                                        <CardTitle className="text-base">
                                            {groupLabels[group] ?? group}
                                        </CardTitle>
                                        <Button
                                            size="sm"
                                            onClick={() => {
                                                setOptionGroup(group);
                                                setOptionValue('');
                                                setOptionLabel('');
                                                setOptionColor('');
                                                setOptionDesc('');
                                                setOptionDialogOpen(true);
                                            }}
                                        >
                                            <Plus className="mr-1 h-4 w-4" />{' '}
                                            Add
                                        </Button>
                                    </CardHeader>
                                    <CardContent>
                                        {items.length === 0 ? (
                                            <p className="py-4 text-center text-sm text-muted-foreground">
                                                No options configured. Click Add
                                                to create one.
                                            </p>
                                        ) : (
                                            <div className="space-y-2">
                                                {items.map((opt) => (
                                                    <div
                                                        key={opt.id}
                                                        className="flex items-center justify-between rounded-lg border px-4 py-2.5"
                                                    >
                                                        <div className="flex items-center gap-3">
                                                            {opt.color && (
                                                                <span
                                                                    className="inline-block h-3 w-3 rounded-full"
                                                                    style={{
                                                                        backgroundColor:
                                                                            opt.color,
                                                                    }}
                                                                />
                                                            )}
                                                            <div>
                                                                <span className="text-sm font-medium">
                                                                    {opt.label}
                                                                </span>
                                                                <span className="ml-2 text-xs text-muted-foreground">
                                                                    ({opt.value}
                                                                    )
                                                                </span>
                                                                {opt.description && (
                                                                    <p className="text-xs text-muted-foreground">
                                                                        {
                                                                            opt.description
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <Switch
                                                                checked={
                                                                    opt.is_active
                                                                }
                                                                onCheckedChange={() =>
                                                                    router.put(
                                                                        `/control-room/settings/options/${opt.id}`,
                                                                        {
                                                                            is_active:
                                                                                !opt.is_active,
                                                                        },
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                            <ConfirmChip
                                                                label="Delete"
                                                                icon={Trash2}
                                                                destructive
                                                                onConfirm={() =>
                                                                    router.delete(
                                                                        `/control-room/settings/options/${opt.id}`,
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </TabsContent>
                </Tabs>

                {/* Add Option Dialog */}
                {optionDialogOpen && (
                    <Dialog
                        open={optionDialogOpen}
                        onOpenChange={setOptionDialogOpen}
                    >
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Add Option</DialogTitle>
                            </DialogHeader>
                            <div className="space-y-3 py-2">
                                <div>
                                    <Label>Value (slug)</Label>
                                    <Input
                                        value={optionValue}
                                        onChange={(e) =>
                                            setOptionValue(
                                                e.target.value
                                                    .toLowerCase()
                                                    .replace(
                                                        /[^a-z0-9_]/g,
                                                        '_',
                                                    ),
                                            )
                                        }
                                        placeholder="e.g. incident"
                                    />
                                </div>
                                <div>
                                    <Label>Display Label</Label>
                                    <Input
                                        value={optionLabel}
                                        onChange={(e) =>
                                            setOptionLabel(e.target.value)
                                        }
                                        placeholder="e.g. Incident"
                                    />
                                </div>
                                <div>
                                    <Label>Color (hex)</Label>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            value={optionColor}
                                            onChange={(e) =>
                                                setOptionColor(e.target.value)
                                            }
                                            placeholder="#ef4444"
                                            className="flex-1"
                                        />
                                        {optionColor && (
                                            <span
                                                className="inline-block h-6 w-6 rounded-full border"
                                                style={{
                                                    backgroundColor:
                                                        optionColor,
                                                }}
                                            />
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <Label>Description (optional)</Label>
                                    <Input
                                        value={optionDesc}
                                        onChange={(e) =>
                                            setOptionDesc(e.target.value)
                                        }
                                        placeholder="Brief description..."
                                    />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button
                                    variant="ghost"
                                    onClick={() => setOptionDialogOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    disabled={
                                        !optionValue.trim() ||
                                        !optionLabel.trim()
                                    }
                                    onClick={() => {
                                        router.post(
                                            '/control-room/settings/options',
                                            {
                                                group: optionGroup,
                                                value: optionValue.trim(),
                                                label: optionLabel.trim(),
                                                color:
                                                    optionColor.trim() || null,
                                                description:
                                                    optionDesc.trim() || null,
                                            },
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    setOptionDialogOpen(false),
                                            },
                                        );
                                    }}
                                >
                                    Create
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                )}

                {/* Dialogs */}
                {ruleDialogOpen && (
                    <SignalRuleDialog
                        open={ruleDialogOpen}
                        onClose={() => {
                            setRuleDialogOpen(false);
                            setEditingRule(null);
                        }}
                        rule={editingRule}
                        signalTypes={signalTypes}
                        signalSources={signalSources}
                        playbooks={playbooks}
                    />
                )}

                {queueDialogOpen && (
                    <TriageQueueDialog
                        open={queueDialogOpen}
                        onClose={() => {
                            setQueueDialogOpen(false);
                            setEditingQueue(null);
                        }}
                        queue={editingQueue}
                        allQueues={triageQueues}
                    />
                )}

                {mwDialogOpen && (
                    <MaintenanceWindowDialog
                        open={mwDialogOpen}
                        onClose={() => {
                            setMwDialogOpen(false);
                            setEditingMw(null);
                        }}
                        window={editingMw}
                        signalSources={signalSources}
                        sites={sites}
                    />
                )}

                <DeleteConfirmDialog
                    open={deleteRuleId !== null}
                    onClose={() => setDeleteRuleId(null)}
                    onConfirm={handleDeleteRule}
                    title="Delete Signal Rule"
                    description="Are you sure you want to delete this signal rule? This action cannot be undone."
                />
            </PageShell>
        </AppLayout>
    );
}
