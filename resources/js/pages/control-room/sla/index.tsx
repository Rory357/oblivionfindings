import PageHeader from '@/components/page-header';
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
    DialogTrigger,
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Edit2,
    ExternalLink,
    Plus,
    Shield,
    ShieldAlert,
    Target,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

// --- Types ---

interface ComplianceData {
    acknowledge_pct: number | null;
    response_pct: number | null;
    resolution_pct: number | null;
}

interface SlaDefinitionData {
    id: number;
    name: string;
    code: string;
    description: string | null;
    alert_types: string[];
    severities: string[];
    sources: string[];
    acknowledge_target_minutes: number | null;
    response_target_minutes: number | null;
    resolution_target_minutes: number | null;
    business_hours_only: boolean;
    business_hours: { start?: string; end?: string; days?: number[] } | null;
    escalate_on_acknowledge_breach: boolean;
    escalate_on_response_breach: boolean;
    escalate_on_resolution_breach: boolean;
    breach_notify_roles: string[];
    is_active: boolean;
    total_alerts: number;
    compliance: ComplianceData;
}

interface FormData {
    name: string;
    code: string;
    description: string;
    alert_types: string[];
    severities: string[];
    sources: string[];
    acknowledge_target_minutes: string;
    response_target_minutes: string;
    resolution_target_minutes: string;
    business_hours_only: boolean;
    business_hours_start: string;
    business_hours_end: string;
    business_hours_days: number[];
    escalate_on_acknowledge_breach: boolean;
    escalate_on_response_breach: boolean;
    escalate_on_resolution_breach: boolean;
    breach_notify_roles: string;
    is_active: boolean;
}

interface Props {
    slaDefinitions: SlaDefinitionData[];
    can: {
        manage: boolean;
    };
}

// --- Constants ---

const SEVERITY_OPTIONS = ['critical', 'high', 'medium', 'low'] as const;
const SOURCE_OPTIONS = ['fleet', 'personal_tracker', 'manual', 'external', 'compliance', 'other'] as const;
const ALERT_TYPE_OPTIONS = ['geofence_breach', 'sos_alert', 'fall_detected', 'speed_violation', 'wandering', 'device_offline', 'battery_low', 'medication_due', 'check_in_missed', 'other'] as const;
const DAY_LABELS: Record<number, string> = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat', 7: 'Sun' };

const severityColors: Record<string, string> = {
    critical: 'bg-red-600 text-white',
    high: 'bg-orange-500 text-white',
    medium: 'bg-yellow-500 text-white',
    low: 'bg-blue-500 text-white',
};

// --- Helpers ---

function formatMinutes(minutes: number | null): string {
    if (minutes === null || minutes === undefined) return '-';
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    if (mins === 0) return `${hours}h`;
    return `${hours}h ${mins}m`;
}

function emptyForm(): FormData {
    return {
        name: '',
        code: '',
        description: '',
        alert_types: [],
        severities: [],
        sources: [],
        acknowledge_target_minutes: '',
        response_target_minutes: '',
        resolution_target_minutes: '',
        business_hours_only: false,
        business_hours_start: '08:00',
        business_hours_end: '18:00',
        business_hours_days: [1, 2, 3, 4, 5],
        escalate_on_acknowledge_breach: true,
        escalate_on_response_breach: true,
        escalate_on_resolution_breach: true,
        breach_notify_roles: '',
        is_active: true,
    };
}

function slaToForm(sla: SlaDefinitionData): FormData {
    return {
        name: sla.name,
        code: sla.code,
        description: sla.description ?? '',
        alert_types: sla.alert_types,
        severities: sla.severities,
        sources: sla.sources,
        acknowledge_target_minutes: sla.acknowledge_target_minutes?.toString() ?? '',
        response_target_minutes: sla.response_target_minutes?.toString() ?? '',
        resolution_target_minutes: sla.resolution_target_minutes?.toString() ?? '',
        business_hours_only: sla.business_hours_only,
        business_hours_start: sla.business_hours?.start ?? '08:00',
        business_hours_end: sla.business_hours?.end ?? '18:00',
        business_hours_days: sla.business_hours?.days ?? [1, 2, 3, 4, 5],
        escalate_on_acknowledge_breach: sla.escalate_on_acknowledge_breach,
        escalate_on_response_breach: sla.escalate_on_response_breach,
        escalate_on_resolution_breach: sla.escalate_on_resolution_breach,
        breach_notify_roles: (sla.breach_notify_roles ?? []).join(', '),
        is_active: sla.is_active,
    };
}

// --- Circular Progress Component ---

function CircularProgress({ value, label, size = 64 }: { value: number | null; label: string; size?: number }) {
    const radius = (size - 8) / 2;
    const circumference = 2 * Math.PI * radius;
    const pct = value ?? 0;
    const offset = circumference - (pct / 100) * circumference;

    let strokeColor = 'stroke-green-500';
    if (pct < 80) strokeColor = 'stroke-yellow-500';
    if (pct < 60) strokeColor = 'stroke-red-500';

    return (
        <div className="flex flex-col items-center gap-1">
            <div className="relative" style={{ width: size, height: size }}>
                <svg width={size} height={size} className="-rotate-90">
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={4}
                        className="text-muted/30"
                    />
                    {value !== null && (
                        <circle
                            cx={size / 2}
                            cy={size / 2}
                            r={radius}
                            fill="none"
                            strokeWidth={4}
                            strokeLinecap="round"
                            strokeDasharray={circumference}
                            strokeDashoffset={offset}
                            className={strokeColor}
                        />
                    )}
                </svg>
                <span className="absolute inset-0 flex items-center justify-center text-xs font-semibold">
                    {value !== null ? `${Math.round(value)}%` : 'N/A'}
                </span>
            </div>
            <span className="text-[10px] text-muted-foreground">{label}</span>
        </div>
    );
}

// --- Multi-select badge component ---

function MultiSelectBadges({
    options,
    selected,
    onChange,
    colorMap,
}: {
    options: readonly string[];
    selected: string[];
    onChange: (newSelected: string[]) => void;
    colorMap?: Record<string, string>;
}) {
    const toggle = (value: string) => {
        if (selected.includes(value)) {
            onChange(selected.filter((s) => s !== value));
        } else {
            onChange([...selected, value]);
        }
    };

    return (
        <div className="flex flex-wrap gap-1.5">
            {options.map((opt) => {
                const isSelected = selected.includes(opt);
                const customColor = colorMap?.[opt];
                return (
                    <button
                        key={opt}
                        type="button"
                        onClick={() => toggle(opt)}
                        className={`rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors ${
                            isSelected
                                ? customColor ?? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-background text-muted-foreground hover:bg-muted'
                        }`}
                    >
                        {opt.replace(/_/g, ' ')}
                    </button>
                );
            })}
        </div>
    );
}

// --- SLA Form Dialog ---

function SlaFormDialog({
    open,
    onOpenChange,
    editingSla,
    formData,
    setFormData,
    submitting,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editingSla: SlaDefinitionData | null;
    formData: FormData;
    setFormData: (data: FormData) => void;
    submitting: boolean;
    onSubmit: (e: FormEvent) => void;
}) {
    const updateField = <K extends keyof FormData>(key: K, value: FormData[K]) => {
        setFormData({ ...formData, [key]: value });
    };

    const toggleDay = (day: number) => {
        const days = formData.business_hours_days;
        if (days.includes(day)) {
            updateField('business_hours_days', days.filter((d) => d !== day));
        } else {
            updateField('business_hours_days', [...days, day].sort());
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{editingSla ? 'Edit SLA Definition' : 'Create SLA Definition'}</DialogTitle>
                    <DialogDescription>
                        {editingSla
                            ? 'Update the SLA targets and matching criteria.'
                            : 'Define targets for acknowledge, response, and resolution times.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={onSubmit} className="space-y-6">
                    {/* Basic Info */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="sla-name">Name</Label>
                            <Input
                                id="sla-name"
                                value={formData.name}
                                onChange={(e) => updateField('name', e.target.value)}
                                placeholder="e.g. Critical Alert SLA"
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="sla-code">Code</Label>
                            <Input
                                id="sla-code"
                                value={formData.code}
                                onChange={(e) => updateField('code', e.target.value)}
                                placeholder="e.g. SLA-CRIT"
                                required
                            />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="sla-description">Description</Label>
                        <Textarea
                            id="sla-description"
                            value={formData.description}
                            onChange={(e) => updateField('description', e.target.value)}
                            placeholder="Optional description of this SLA policy..."
                            rows={2}
                        />
                    </div>

                    {/* Matching Criteria */}
                    <div className="space-y-4 rounded-lg border p-4">
                        <h4 className="text-sm font-semibold">Matching Criteria</h4>
                        <p className="text-xs text-muted-foreground">
                            Leave a section empty to match all values for that criteria.
                        </p>

                        <div className="space-y-2">
                            <Label>Alert Types</Label>
                            <MultiSelectBadges
                                options={ALERT_TYPE_OPTIONS}
                                selected={formData.alert_types}
                                onChange={(v) => updateField('alert_types', v)}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label>Severities</Label>
                            <MultiSelectBadges
                                options={SEVERITY_OPTIONS}
                                selected={formData.severities}
                                onChange={(v) => updateField('severities', v)}
                                colorMap={severityColors}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label>Sources</Label>
                            <MultiSelectBadges
                                options={SOURCE_OPTIONS}
                                selected={formData.sources}
                                onChange={(v) => updateField('sources', v)}
                            />
                        </div>
                    </div>

                    {/* Target Times */}
                    <div className="space-y-4 rounded-lg border p-4">
                        <h4 className="text-sm font-semibold">Target Times (minutes)</h4>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="space-y-2">
                                <Label htmlFor="ack-target">Acknowledge</Label>
                                <Input
                                    id="ack-target"
                                    type="number"
                                    min={1}
                                    value={formData.acknowledge_target_minutes}
                                    onChange={(e) => updateField('acknowledge_target_minutes', e.target.value)}
                                    placeholder="e.g. 5"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="resp-target">Response</Label>
                                <Input
                                    id="resp-target"
                                    type="number"
                                    min={1}
                                    value={formData.response_target_minutes}
                                    onChange={(e) => updateField('response_target_minutes', e.target.value)}
                                    placeholder="e.g. 15"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="res-target">Resolution</Label>
                                <Input
                                    id="res-target"
                                    type="number"
                                    min={1}
                                    value={formData.resolution_target_minutes}
                                    onChange={(e) => updateField('resolution_target_minutes', e.target.value)}
                                    placeholder="e.g. 60"
                                />
                            </div>
                        </div>
                    </div>

                    {/* Business Hours */}
                    <div className="space-y-4 rounded-lg border p-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <h4 className="text-sm font-semibold">Business Hours Only</h4>
                                <p className="text-xs text-muted-foreground">
                                    SLA timers only count during business hours.
                                </p>
                            </div>
                            <Switch
                                checked={formData.business_hours_only}
                                onCheckedChange={(v) => updateField('business_hours_only', v)}
                            />
                        </div>

                        {formData.business_hours_only && (
                            <div className="space-y-3">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Start Time</Label>
                                        <Input
                                            type="time"
                                            value={formData.business_hours_start}
                                            onChange={(e) => updateField('business_hours_start', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>End Time</Label>
                                        <Input
                                            type="time"
                                            value={formData.business_hours_end}
                                            onChange={(e) => updateField('business_hours_end', e.target.value)}
                                        />
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <Label>Days</Label>
                                    <div className="flex flex-wrap gap-1.5">
                                        {([1, 2, 3, 4, 5, 6, 7] as const).map((day) => (
                                            <button
                                                key={day}
                                                type="button"
                                                onClick={() => toggleDay(day)}
                                                className={`rounded-md border px-3 py-1 text-xs font-medium transition-colors ${
                                                    formData.business_hours_days.includes(day)
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-border bg-background text-muted-foreground hover:bg-muted'
                                                }`}
                                            >
                                                {DAY_LABELS[day]}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Escalation */}
                    <div className="space-y-4 rounded-lg border p-4">
                        <h4 className="text-sm font-semibold">Escalation on Breach</h4>
                        <div className="space-y-3">
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="esc-ack"
                                    checked={formData.escalate_on_acknowledge_breach}
                                    onCheckedChange={(v) => updateField('escalate_on_acknowledge_breach', !!v)}
                                />
                                <Label htmlFor="esc-ack" className="text-sm font-normal">
                                    Escalate on acknowledge breach
                                </Label>
                            </div>
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="esc-resp"
                                    checked={formData.escalate_on_response_breach}
                                    onCheckedChange={(v) => updateField('escalate_on_response_breach', !!v)}
                                />
                                <Label htmlFor="esc-resp" className="text-sm font-normal">
                                    Escalate on response breach
                                </Label>
                            </div>
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="esc-res"
                                    checked={formData.escalate_on_resolution_breach}
                                    onCheckedChange={(v) => updateField('escalate_on_resolution_breach', !!v)}
                                />
                                <Label htmlFor="esc-res" className="text-sm font-normal">
                                    Escalate on resolution breach
                                </Label>
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="notify-roles">Breach Notification Roles</Label>
                            <Input
                                id="notify-roles"
                                value={formData.breach_notify_roles}
                                onChange={(e) => updateField('breach_notify_roles', e.target.value)}
                                placeholder="e.g. admin, coordinator, manager"
                            />
                            <p className="text-xs text-muted-foreground">Comma-separated list of roles to notify on breach.</p>
                        </div>
                    </div>

                    {/* Active */}
                    <div className="flex items-center justify-between rounded-lg border p-4">
                        <div>
                            <h4 className="text-sm font-semibold">Active</h4>
                            <p className="text-xs text-muted-foreground">
                                Only active SLAs are applied to new alerts.
                            </p>
                        </div>
                        <Switch
                            checked={formData.is_active}
                            onCheckedChange={(v) => updateField('is_active', v)}
                        />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Saving...' : editingSla ? 'Update SLA' : 'Create SLA'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// --- SLA Card ---

function SlaCard({
    sla,
    canManage,
    onEdit,
    onToggleActive,
}: {
    sla: SlaDefinitionData;
    canManage: boolean;
    onEdit: (sla: SlaDefinitionData) => void;
    onToggleActive: (sla: SlaDefinitionData) => void;
}) {
    return (
        <Card className={!sla.is_active ? 'opacity-60' : ''}>
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <CardTitle className="text-base">{sla.name}</CardTitle>
                            <Badge variant="outline" className="shrink-0 font-mono text-xs">
                                {sla.code}
                            </Badge>
                        </div>
                        {sla.description && (
                            <p className="mt-1 text-xs text-muted-foreground">{sla.description}</p>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        {canManage && (
                            <Button variant="ghost" size="sm" onClick={() => onEdit(sla)}>
                                <Edit2 className="h-3.5 w-3.5" />
                            </Button>
                        )}
                        {canManage && (
                            <Switch
                                checked={sla.is_active}
                                onCheckedChange={() => onToggleActive(sla)}
                            />
                        )}
                        {!canManage && (
                            <Badge variant={sla.is_active ? 'default' : 'secondary'}>
                                {sla.is_active ? 'Active' : 'Inactive'}
                            </Badge>
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Target Times */}
                <div className="grid grid-cols-3 gap-3">
                    <div className="rounded-md bg-muted/50 p-2 text-center">
                        <p className="text-[10px] font-medium uppercase text-muted-foreground">Acknowledge</p>
                        <p className="text-sm font-semibold">{formatMinutes(sla.acknowledge_target_minutes)}</p>
                    </div>
                    <div className="rounded-md bg-muted/50 p-2 text-center">
                        <p className="text-[10px] font-medium uppercase text-muted-foreground">Response</p>
                        <p className="text-sm font-semibold">{formatMinutes(sla.response_target_minutes)}</p>
                    </div>
                    <div className="rounded-md bg-muted/50 p-2 text-center">
                        <p className="text-[10px] font-medium uppercase text-muted-foreground">Resolution</p>
                        <p className="text-sm font-semibold">{formatMinutes(sla.resolution_target_minutes)}</p>
                    </div>
                </div>

                {/* Compliance Gauges */}
                <div className="flex items-center justify-center gap-6 py-2">
                    <CircularProgress value={sla.compliance.acknowledge_pct} label="Ack" />
                    <CircularProgress value={sla.compliance.response_pct} label="Response" />
                    <CircularProgress value={sla.compliance.resolution_pct} label="Resolution" />
                </div>

                <div className="text-center text-xs text-muted-foreground">
                    {sla.total_alerts} alert{sla.total_alerts !== 1 ? 's' : ''} matched
                </div>

                {/* Matching Criteria */}
                <div className="space-y-2">
                    {sla.severities.length > 0 && (
                        <div className="flex flex-wrap items-center gap-1">
                            <span className="text-xs text-muted-foreground">Severities:</span>
                            {sla.severities.map((s) => (
                                <Badge key={s} className={`text-[10px] ${severityColors[s] ?? ''}`}>
                                    {s}
                                </Badge>
                            ))}
                        </div>
                    )}
                    {sla.sources.length > 0 && (
                        <div className="flex flex-wrap items-center gap-1">
                            <span className="text-xs text-muted-foreground">Sources:</span>
                            {sla.sources.map((s) => (
                                <Badge key={s} variant="outline" className="text-[10px]">
                                    {s}
                                </Badge>
                            ))}
                        </div>
                    )}
                    {sla.alert_types.length > 0 && (
                        <div className="flex flex-wrap items-center gap-1">
                            <span className="text-xs text-muted-foreground">Types:</span>
                            {sla.alert_types.map((t) => (
                                <Badge key={t} variant="secondary" className="text-[10px]">
                                    {t.replace(/_/g, ' ')}
                                </Badge>
                            ))}
                        </div>
                    )}
                    {sla.severities.length === 0 && sla.sources.length === 0 && sla.alert_types.length === 0 && (
                        <p className="text-xs text-muted-foreground italic">Matches all alerts</p>
                    )}
                </div>

                {/* Flags */}
                <div className="flex flex-wrap gap-2 text-xs">
                    {sla.business_hours_only && (
                        <Badge variant="outline" className="gap-1">
                            <Clock className="h-3 w-3" />
                            Business hours only
                        </Badge>
                    )}
                    {(sla.escalate_on_acknowledge_breach || sla.escalate_on_response_breach || sla.escalate_on_resolution_breach) && (
                        <Badge variant="outline" className="gap-1">
                            <ShieldAlert className="h-3 w-3" />
                            Auto-escalate
                        </Badge>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

// --- Main Page ---

export default function SlaIndex({ slaDefinitions, can }: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingSla, setEditingSla] = useState<SlaDefinitionData | null>(null);
    const [formData, setFormData] = useState<FormData>(emptyForm());
    const [submitting, setSubmitting] = useState(false);

    const handleCreate = () => {
        setEditingSla(null);
        setFormData(emptyForm());
        setDialogOpen(true);
    };

    const handleEdit = (sla: SlaDefinitionData) => {
        setEditingSla(sla);
        setFormData(slaToForm(sla));
        setDialogOpen(true);
    };

    const handleToggleActive = (sla: SlaDefinitionData) => {
        router.post(`/control-room/sla/${sla.id}/toggle-active`, {}, {
            preserveScroll: true,
        });
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setSubmitting(true);

        const payload: Record<string, unknown> = {
            name: formData.name,
            code: formData.code,
            description: formData.description || null,
            alert_types: formData.alert_types.length > 0 ? formData.alert_types : null,
            severities: formData.severities.length > 0 ? formData.severities : null,
            sources: formData.sources.length > 0 ? formData.sources : null,
            acknowledge_target_minutes: formData.acknowledge_target_minutes ? parseInt(formData.acknowledge_target_minutes) : null,
            response_target_minutes: formData.response_target_minutes ? parseInt(formData.response_target_minutes) : null,
            resolution_target_minutes: formData.resolution_target_minutes ? parseInt(formData.resolution_target_minutes) : null,
            business_hours_only: formData.business_hours_only,
            business_hours: formData.business_hours_only
                ? {
                      start: formData.business_hours_start,
                      end: formData.business_hours_end,
                      days: formData.business_hours_days,
                  }
                : null,
            escalate_on_acknowledge_breach: formData.escalate_on_acknowledge_breach,
            escalate_on_response_breach: formData.escalate_on_response_breach,
            escalate_on_resolution_breach: formData.escalate_on_resolution_breach,
            breach_notify_roles: formData.breach_notify_roles
                ? formData.breach_notify_roles.split(',').map((r) => r.trim()).filter(Boolean)
                : null,
            is_active: formData.is_active,
        };

        const url = editingSla ? `/control-room/sla/${editingSla.id}` : '/control-room/sla';
        const method = editingSla ? 'put' : 'post';

        router[method](url, payload, {
            preserveScroll: true,
            onSuccess: () => {
                setDialogOpen(false);
                setEditingSla(null);
                setFormData(emptyForm());
            },
            onFinish: () => setSubmitting(false),
        });
    };

    const activeCount = slaDefinitions.filter((s) => s.is_active).length;
    const totalAlerts = slaDefinitions.reduce((sum, s) => sum + s.total_alerts, 0);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'SLA Management', href: '#' },
            ]}
        >
            <Head title="SLA Management - Control Room" />
            <PageShell>
                <PageHeader
                    title="SLA Management"
                    description="Configure service level agreements for alert acknowledgement, response, and resolution times."
                    actions={
                        <div className="flex items-center gap-2">
                            <Link href="/control-room/sla/breaches">
                                <Button variant="outline" size="sm">
                                    <AlertTriangle className="mr-2 h-4 w-4" />
                                    Breach Report
                                </Button>
                            </Link>
                            {can.manage && (
                                <Button size="sm" onClick={handleCreate}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create SLA
                                </Button>
                            )}
                        </div>
                    }
                />

                {/* Summary Cards */}
                <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-medium text-muted-foreground">
                                Total SLAs
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{slaDefinitions.length}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-medium text-muted-foreground">
                                Active
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-green-600">{activeCount}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-medium text-muted-foreground">
                                Total Alerts Matched
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{totalAlerts}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-medium text-muted-foreground">
                                Inactive
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-muted-foreground">
                                {slaDefinitions.length - activeCount}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* SLA Cards Grid */}
                {slaDefinitions.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <Shield className="mb-4 h-12 w-12 text-muted-foreground/50" />
                            <p className="text-lg font-medium text-muted-foreground">No SLA definitions configured</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Create your first SLA definition to begin tracking alert response times.
                            </p>
                            {can.manage && (
                                <Button className="mt-4" onClick={handleCreate}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create SLA
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {slaDefinitions.map((sla) => (
                            <SlaCard
                                key={sla.id}
                                sla={sla}
                                canManage={can.manage}
                                onEdit={handleEdit}
                                onToggleActive={handleToggleActive}
                            />
                        ))}
                    </div>
                )}
            </PageShell>

            {/* Create/Edit Dialog */}
            <SlaFormDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                editingSla={editingSla}
                formData={formData}
                setFormData={setFormData}
                submitting={submitting}
                onSubmit={handleSubmit}
            />
        </AppLayout>
    );
}
