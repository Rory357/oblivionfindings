import PageShell from '@/components/page-shell';
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
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    ChevronDown,
    ChevronUp,
    Clock,
    ExternalLink,
    Layers,
    Lock,
    Pencil,
    Plus,
    Save,
    Search as SearchIcon,
    Shield,
    Star,
    Trash2,
    Wrench,
    X,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

// --- Types ---

interface PlaybookStep {
    id: number;
    order: number;
    title: string;
    type: string;
    instructions: string | null;
    is_required: boolean;
    is_blocking: boolean;
    time_limit_minutes: number | null;
    decision_options: string[] | null;
    notify_config: Record<string, any> | null;
    evidence_config: Record<string, any> | null;
}

interface PlaybookDetail {
    id: number;
    name: string;
    code: string | null;
    description: string | null;
    category: string;
    version: number;
    is_active: boolean;
    auto_attach: boolean;
    trigger_alert_types: string[];
    trigger_severities: string[];
    sla_acknowledge_minutes: number | null;
    sla_response_minutes: number | null;
    sla_resolution_minutes: number | null;
    required_evidence: string[];
    requires_approval: boolean;
    approval_roles: string[];
    escalation_after_minutes: number | null;
    escalation_targets: string[];
    created_by: { id: number; name: string } | null;
    updated_by: { id: number; name: string } | null;
    created_at: string | null;
    updated_at: string | null;
    steps: PlaybookStep[];
}

interface RunAlert {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
}

interface RunEntry {
    id: number;
    alert_id: number;
    alert: RunAlert | null;
    status: string;
    current_step: number;
    completed_steps: number;
    total_steps: number;
    progress: number;
    started_at: string | null;
    completed_at: string | null;
    started_by: { id: number; name: string } | null;
    completed_by: { id: number; name: string } | null;
}

interface StepEditForm {
    id: number | null;
    title: string;
    type: string;
    instructions: string;
    is_required: boolean;
    is_blocking: boolean;
    time_limit_minutes: string;
}

interface Props {
    playbook: PlaybookDetail;
    recentRuns: RunEntry[];
    categories: Record<string, string>;
    stepTypes: Record<string, string>;
    can: {
        manage: boolean;
    };
}

// --- Helpers ---

const categoryConfig: Record<
    string,
    { color: string; icon: typeof AlertTriangle }
> = {
    emergency: {
        color: 'bg-status-critical-bg text-status-critical border-status-critical/30',
        icon: AlertTriangle,
    },
    safety: {
        color: 'bg-status-warning-bg text-status-warning border-status-warning/30',
        icon: Shield,
    },
    compliance: {
        color: 'bg-status-info-bg text-status-info border-status-info/30',
        icon: CheckCircle,
    },
    maintenance: {
        color: 'bg-muted text-foreground border-border',
        icon: Wrench,
    },
    investigation: {
        color: 'bg-primary/10 text-primary border-primary',
        icon: SearchIcon,
    },
};

const stepTypeColors: Record<string, string> = {
    task: 'bg-status-info-bg text-status-info',
    decision: 'bg-primary/10 text-primary',
    notification: 'bg-status-warning-bg text-status-warning',
    escalation: 'bg-status-critical-bg text-status-critical',
    evidence: 'bg-status-success-bg text-status-success',
    approval: 'bg-status-warning-bg text-status-warning',
};

const runStatusColors: Record<string, string> = {
    pending: 'bg-muted text-foreground',
    in_progress: 'bg-status-info-bg text-status-info',
    completed: 'bg-status-success-bg text-status-success',
    failed: 'bg-status-critical-bg text-status-critical',
    cancelled: 'bg-muted text-muted-foreground',
};

const severityColors: Record<string, string> = {
    critical: 'bg-status-critical text-white',
    high: 'bg-status-warning text-white',
    medium: 'bg-status-warning text-black',
    low: 'bg-status-success text-white',
};

function formatDate(isoString: string | null): string {
    if (!isoString) return '-';
    return new Date(isoString).toLocaleString('en-NZ', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function formatDuration(
    startIso: string | null,
    endIso: string | null,
): string {
    if (!startIso || !endIso) return '-';
    const start = new Date(startIso).getTime();
    const end = new Date(endIso).getTime();
    const diffMs = end - start;
    const mins = Math.floor(diffMs / 60000);
    const hrs = Math.floor(mins / 60);
    if (hrs > 0) return `${hrs}h ${mins % 60}m`;
    return `${mins}m`;
}

// --- Component ---

export default function PlaybookShow({
    playbook,
    recentRuns,
    categories,
    stepTypes,
    can,
}: Props) {
    const [editing, setEditing] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);

    // Edit form state
    const [editName, setEditName] = useState(playbook.name);
    const [editDescription, setEditDescription] = useState(
        playbook.description ?? '',
    );
    const [editCategory, setEditCategory] = useState(playbook.category);
    const [editAutoAttach, setEditAutoAttach] = useState(playbook.auto_attach);
    const [editRequiresApproval, setEditRequiresApproval] = useState(
        playbook.requires_approval,
    );
    const [editSlaAck, setEditSlaAck] = useState(
        playbook.sla_acknowledge_minutes?.toString() ?? '',
    );
    const [editSlaResponse, setEditSlaResponse] = useState(
        playbook.sla_response_minutes?.toString() ?? '',
    );
    const [editSlaResolution, setEditSlaResolution] = useState(
        playbook.sla_resolution_minutes?.toString() ?? '',
    );
    const [editRequiredEvidence, setEditRequiredEvidence] = useState<string[]>(
        playbook.required_evidence ?? [],
    );
    const [editSteps, setEditSteps] = useState<StepEditForm[]>(
        playbook.steps.map((s) => ({
            id: s.id,
            title: s.title,
            type: s.type,
            instructions: s.instructions ?? '',
            is_required: s.is_required,
            is_blocking: s.is_blocking,
            time_limit_minutes: s.time_limit_minutes?.toString() ?? '',
        })),
    );

    const evidenceOptions = [
        'photo',
        'video',
        'document',
        'signature',
        'witness_statement',
        'incident_report',
    ];

    const catConfig =
        categoryConfig[playbook.category] ?? categoryConfig.maintenance;
    const CatIcon = catConfig.icon;

    const toggleActive = () => {
        router.post(
            `/control-room/playbooks/${playbook.id}/toggle-active`,
            {},
            { preserveScroll: true },
        );
    };

    const startEditing = () => {
        setEditName(playbook.name);
        setEditDescription(playbook.description ?? '');
        setEditCategory(playbook.category);
        setEditAutoAttach(playbook.auto_attach);
        setEditRequiresApproval(playbook.requires_approval);
        setEditSlaAck(playbook.sla_acknowledge_minutes?.toString() ?? '');
        setEditSlaResponse(playbook.sla_response_minutes?.toString() ?? '');
        setEditSlaResolution(playbook.sla_resolution_minutes?.toString() ?? '');
        setEditRequiredEvidence(playbook.required_evidence ?? []);
        setEditSteps(
            playbook.steps.map((s) => ({
                id: s.id,
                title: s.title,
                type: s.type,
                instructions: s.instructions ?? '',
                is_required: s.is_required,
                is_blocking: s.is_blocking,
                time_limit_minutes: s.time_limit_minutes?.toString() ?? '',
            })),
        );
        setEditing(true);
    };

    const cancelEditing = () => {
        setEditing(false);
    };

    const addEditStep = () => {
        setEditSteps([
            ...editSteps,
            {
                id: null,
                title: '',
                type: 'task',
                instructions: '',
                is_required: true,
                is_blocking: false,
                time_limit_minutes: '',
            },
        ]);
    };

    const removeEditStep = (index: number) => {
        if (editSteps.length > 1) {
            setEditSteps(editSteps.filter((_, i) => i !== index));
        }
    };

    const updateEditStep = (
        index: number,
        field: keyof StepEditForm,
        value: string | boolean | number | null,
    ) => {
        const updated = [...editSteps];
        (updated[index] as any)[field] = value;
        setEditSteps(updated);
    };

    const moveEditStep = (index: number, direction: 'up' | 'down') => {
        const newIndex = direction === 'up' ? index - 1 : index + 1;
        if (newIndex < 0 || newIndex >= editSteps.length) return;
        const updated = [...editSteps];
        [updated[index], updated[newIndex]] = [
            updated[newIndex],
            updated[index],
        ];
        setEditSteps(updated);
    };

    const toggleEditEvidence = (type: string) => {
        setEditRequiredEvidence((prev) =>
            prev.includes(type)
                ? prev.filter((t) => t !== type)
                : [...prev, type],
        );
    };

    const handleSave = (e: FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);

        router.put(
            `/control-room/playbooks/${playbook.id}`,
            {
                name: editName,
                description: editDescription || null,
                category: editCategory,
                auto_attach: editAutoAttach,
                requires_approval: editRequiresApproval,
                sla_acknowledge_minutes: editSlaAck
                    ? parseInt(editSlaAck)
                    : null,
                sla_response_minutes: editSlaResponse
                    ? parseInt(editSlaResponse)
                    : null,
                sla_resolution_minutes: editSlaResolution
                    ? parseInt(editSlaResolution)
                    : null,
                required_evidence: editRequiredEvidence,
                steps: editSteps.map((s) => ({
                    id: s.id,
                    title: s.title,
                    type: s.type,
                    instructions: s.instructions || null,
                    is_required: s.is_required,
                    is_blocking: s.is_blocking,
                    time_limit_minutes: s.time_limit_minutes
                        ? parseInt(s.time_limit_minutes)
                        : null,
                })),
            },
            {
                onSuccess: () => setEditing(false),
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Playbooks', href: '/control-room/playbooks' },
                {
                    title: playbook.name,
                    href: `/control-room/playbooks/${playbook.id}`,
                },
            ]}
        >
            <Head title={`${playbook.name} - Playbooks`} />

            <div className="flex flex-col gap-6 p-6">
                <PageShell>
                    {/* Header */}
                    <PageHero variant="compact"
                        title={
                            <div className="flex items-center gap-3">
                                <span>{playbook.name}</span>
                                <Badge
                                    variant="outline"
                                    className={catConfig.color}
                                >
                                    <CatIcon className="mr-1 h-3 w-3" />
                                    {categories[playbook.category] ??
                                        playbook.category}
                                </Badge>
                                <Badge variant="outline" className="text-xs">
                                    v{playbook.version}
                                </Badge>
                                {!playbook.is_active && (
                                    <Badge
                                        variant="outline"
                                        className="bg-muted text-muted-foreground"
                                    >
                                        Inactive
                                    </Badge>
                                )}
                            </div>
                        }
                        description={playbook.description ?? undefined}
                        backHref="/control-room/playbooks"
                        backLabel="All Playbooks"
                        actions={
                            can.manage ? (
                                <div className="flex items-center gap-2">
                                    <Switch
                                        checked={playbook.is_active}
                                        onCheckedChange={toggleActive}
                                        aria-label="Toggle active"
                                    />
                                    <span className="text-sm text-muted-foreground">
                                        {playbook.is_active
                                            ? 'Active'
                                            : 'Inactive'}
                                    </span>
                                    {!editing ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={startEditing}
                                        >
                                            <Pencil className="mr-1 h-3 w-3" />
                                            Edit
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={cancelEditing}
                                        >
                                            <X className="mr-1 h-3 w-3" />
                                            Cancel
                                        </Button>
                                    )}
                                </div>
                            ) : undefined
                        }
                    />

                    {editing ? (
                        /* ==================== EDIT MODE ==================== */
                        <form onSubmit={handleSave} className="space-y-6">
                            {/* Basic Info */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Playbook Details
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2 sm:col-span-2">
                                            <Label>Name *</Label>
                                            <Input
                                                value={editName}
                                                onChange={(e) =>
                                                    setEditName(e.target.value)
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2 sm:col-span-2">
                                            <Label>Description</Label>
                                            <Textarea
                                                value={editDescription}
                                                onChange={(e) =>
                                                    setEditDescription(
                                                        e.target.value,
                                                    )
                                                }
                                                rows={2}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Category *</Label>
                                            <Select
                                                value={editCategory}
                                                onValueChange={setEditCategory}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {Object.entries(
                                                        categories,
                                                    ).map(([k, v]) => (
                                                        <SelectItem
                                                            key={k}
                                                            value={k}
                                                        >
                                                            {v}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="flex items-end gap-6">
                                            <div className="flex items-center gap-2">
                                                <Switch
                                                    checked={editAutoAttach}
                                                    onCheckedChange={
                                                        setEditAutoAttach
                                                    }
                                                />
                                                <Label>Auto-attach</Label>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Switch
                                                    checked={
                                                        editRequiresApproval
                                                    }
                                                    onCheckedChange={
                                                        setEditRequiresApproval
                                                    }
                                                />
                                                <Label>Requires Approval</Label>
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* SLA Targets */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        SLA Targets (minutes)
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <div className="space-y-1">
                                            <Label className="text-xs text-muted-foreground">
                                                Acknowledge
                                            </Label>
                                            <Input
                                                type="number"
                                                min={1}
                                                value={editSlaAck}
                                                onChange={(e) =>
                                                    setEditSlaAck(
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="text-xs text-muted-foreground">
                                                Response
                                            </Label>
                                            <Input
                                                type="number"
                                                min={1}
                                                value={editSlaResponse}
                                                onChange={(e) =>
                                                    setEditSlaResponse(
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="text-xs text-muted-foreground">
                                                Resolution
                                            </Label>
                                            <Input
                                                type="number"
                                                min={1}
                                                value={editSlaResolution}
                                                onChange={(e) =>
                                                    setEditSlaResolution(
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Required Evidence */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Required Evidence
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex flex-wrap gap-2">
                                        {evidenceOptions.map((type) => (
                                            <Button
                                                key={type}
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    toggleEditEvidence(type)
                                                }
                                                className={`rounded-full px-3 text-xs ${
                                                    editRequiredEvidence.includes(
                                                        type,
                                                    )
                                                        ? 'border-primary bg-primary/10 text-primary'
                                                        : 'border-border text-muted-foreground hover:bg-muted'
                                                }`}
                                            >
                                                {type.replace(/_/g, ' ')}
                                            </Button>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Steps Editor */}
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-base">
                                            Steps
                                        </CardTitle>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={addEditStep}
                                        >
                                            <Plus className="mr-1 h-3 w-3" />
                                            Add Step
                                        </Button>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {editSteps.map((step, index) => (
                                        <Card
                                            key={index}
                                            className="border-dashed"
                                        >
                                            <CardContent className="pt-4">
                                                <div className="flex items-start gap-2">
                                                    <div className="flex flex-col items-center gap-1 pt-1">
                                                        <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                                            {index + 1}
                                                        </span>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                moveEditStep(
                                                                    index,
                                                                    'up',
                                                                )
                                                            }
                                                            disabled={
                                                                index === 0
                                                            }
                                                            className="h-5 w-5 text-muted-foreground hover:text-foreground disabled:opacity-30"
                                                        >
                                                            <ChevronUp className="h-3 w-3" />
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                moveEditStep(
                                                                    index,
                                                                    'down',
                                                                )
                                                            }
                                                            disabled={
                                                                index ===
                                                                editSteps.length -
                                                                    1
                                                            }
                                                            className="h-5 w-5 text-muted-foreground hover:text-foreground disabled:opacity-30"
                                                        >
                                                            <ChevronDown className="h-3 w-3" />
                                                        </Button>
                                                    </div>
                                                    <div className="flex-1 space-y-3">
                                                        <div className="grid gap-3 sm:grid-cols-2">
                                                            <Input
                                                                value={
                                                                    step.title
                                                                }
                                                                onChange={(e) =>
                                                                    updateEditStep(
                                                                        index,
                                                                        'title',
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="Step title *"
                                                                required
                                                            />
                                                            <Select
                                                                value={
                                                                    step.type
                                                                }
                                                                onValueChange={(
                                                                    v,
                                                                ) =>
                                                                    updateEditStep(
                                                                        index,
                                                                        'type',
                                                                        v,
                                                                    )
                                                                }
                                                            >
                                                                <SelectTrigger>
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {Object.entries(
                                                                        stepTypes,
                                                                    ).map(
                                                                        ([
                                                                            k,
                                                                            v,
                                                                        ]) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    k
                                                                                }
                                                                                value={
                                                                                    k
                                                                                }
                                                                            >
                                                                                {
                                                                                    v
                                                                                }
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                        <Textarea
                                                            value={
                                                                step.instructions
                                                            }
                                                            onChange={(e) =>
                                                                updateEditStep(
                                                                    index,
                                                                    'instructions',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            placeholder="Instructions..."
                                                            rows={2}
                                                        />
                                                        <div className="flex flex-wrap items-center gap-4">
                                                            <div className="flex items-center gap-2">
                                                                <Switch
                                                                    checked={
                                                                        step.is_required
                                                                    }
                                                                    onCheckedChange={(
                                                                        v,
                                                                    ) =>
                                                                        updateEditStep(
                                                                            index,
                                                                            'is_required',
                                                                            v,
                                                                        )
                                                                    }
                                                                />
                                                                <span className="text-xs">
                                                                    Required
                                                                </span>
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                <Switch
                                                                    checked={
                                                                        step.is_blocking
                                                                    }
                                                                    onCheckedChange={(
                                                                        v,
                                                                    ) =>
                                                                        updateEditStep(
                                                                            index,
                                                                            'is_blocking',
                                                                            v,
                                                                        )
                                                                    }
                                                                />
                                                                <span className="text-xs">
                                                                    Blocking
                                                                </span>
                                                            </div>
                                                            <Input
                                                                type="number"
                                                                min={1}
                                                                value={
                                                                    step.time_limit_minutes
                                                                }
                                                                onChange={(e) =>
                                                                    updateEditStep(
                                                                        index,
                                                                        'time_limit_minutes',
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="Time limit (min)"
                                                                className="w-36"
                                                            />
                                                        </div>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() =>
                                                            removeEditStep(
                                                                index,
                                                            )
                                                        }
                                                        disabled={
                                                            editSteps.length ===
                                                            1
                                                        }
                                                        className="h-7 w-7 text-muted-foreground hover:text-destructive disabled:opacity-30"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    ))}
                                </CardContent>
                            </Card>

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={cancelEditing}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={isSubmitting}>
                                    <Save className="mr-1 h-4 w-4" />
                                    {isSubmitting
                                        ? 'Saving...'
                                        : 'Save Changes'}
                                </Button>
                            </div>
                        </form>
                    ) : (
                        /* ==================== VIEW MODE ==================== */
                        <div className="space-y-6">
                            {/* Info Cards Row */}
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {/* Trigger Conditions */}
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            Trigger Conditions
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-2">
                                            <div>
                                                <span className="text-xs font-medium text-muted-foreground">
                                                    Alert Types
                                                </span>
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {playbook
                                                        .trigger_alert_types
                                                        .length > 0 ? (
                                                        playbook.trigger_alert_types.map(
                                                            (t) => (
                                                                <Badge
                                                                    key={t}
                                                                    variant="outline"
                                                                    className="text-xs"
                                                                >
                                                                    {t}
                                                                </Badge>
                                                            ),
                                                        )
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">
                                                            Any
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <div>
                                                <span className="text-xs font-medium text-muted-foreground">
                                                    Severities
                                                </span>
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {playbook.trigger_severities
                                                        .length > 0 ? (
                                                        playbook.trigger_severities.map(
                                                            (s) => (
                                                                <Badge
                                                                    key={s}
                                                                    className={`text-xs ${severityColors[s] ?? ''}`}
                                                                >
                                                                    {s}
                                                                </Badge>
                                                            ),
                                                        )
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">
                                                            Any
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2 pt-1">
                                                {playbook.auto_attach && (
                                                    <Badge
                                                        variant="outline"
                                                        className="bg-status-success-bg text-xs text-status-success"
                                                    >
                                                        Auto-attach
                                                    </Badge>
                                                )}
                                                {playbook.requires_approval && (
                                                    <Badge
                                                        variant="outline"
                                                        className="bg-status-warning-bg text-xs text-status-warning"
                                                    >
                                                        Requires Approval
                                                    </Badge>
                                                )}
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* SLA Targets */}
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            SLA Targets
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-3">
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm">
                                                    Acknowledge
                                                </span>
                                                <span className="font-mono text-sm font-medium">
                                                    {playbook.sla_acknowledge_minutes
                                                        ? `${playbook.sla_acknowledge_minutes} min`
                                                        : '-'}
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm">
                                                    Response
                                                </span>
                                                <span className="font-mono text-sm font-medium">
                                                    {playbook.sla_response_minutes
                                                        ? `${playbook.sla_response_minutes} min`
                                                        : '-'}
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm">
                                                    Resolution
                                                </span>
                                                <span className="font-mono text-sm font-medium">
                                                    {playbook.sla_resolution_minutes
                                                        ? `${playbook.sla_resolution_minutes} min`
                                                        : '-'}
                                                </span>
                                            </div>
                                            {playbook.escalation_after_minutes && (
                                                <div className="flex items-center justify-between border-t pt-2">
                                                    <span className="text-sm text-muted-foreground">
                                                        Escalate after
                                                    </span>
                                                    <span className="font-mono text-sm font-medium text-status-critical">
                                                        {
                                                            playbook.escalation_after_minutes
                                                        }{' '}
                                                        min
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Required Evidence */}
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            Required Evidence
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {playbook.required_evidence.length >
                                        0 ? (
                                            <div className="flex flex-wrap gap-1.5">
                                                {playbook.required_evidence.map(
                                                    (type) => (
                                                        <Badge
                                                            key={type}
                                                            variant="outline"
                                                            className="text-xs"
                                                        >
                                                            {type.replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                                        </Badge>
                                                    ),
                                                )}
                                            </div>
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                No evidence requirements
                                                specified.
                                            </p>
                                        )}
                                        {playbook.created_by && (
                                            <p className="mt-4 text-xs text-muted-foreground">
                                                Created by{' '}
                                                {playbook.created_by.name}
                                                {playbook.created_at &&
                                                    ` on ${formatDate(playbook.created_at)}`}
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>

                            {/* Steps */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Layers className="h-4 w-4" />
                                        Steps ({playbook.steps.length})
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {playbook.steps.length === 0 ? (
                                        <p className="py-4 text-center text-sm text-muted-foreground">
                                            No steps defined.
                                        </p>
                                    ) : (
                                        <div className="space-y-3">
                                            {playbook.steps.map((step) => (
                                                <div
                                                    key={step.id}
                                                    className="flex items-start gap-3 rounded-lg border p-3"
                                                >
                                                    <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">
                                                        {step.order}
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-medium">
                                                                {step.title}
                                                            </span>
                                                            <Badge
                                                                className={`text-xs ${stepTypeColors[step.type] ?? 'bg-muted text-foreground'}`}
                                                            >
                                                                {stepTypes[
                                                                    step.type
                                                                ] ?? step.type}
                                                            </Badge>
                                                            {step.is_required && (
                                                                <span
                                                                    className="flex items-center gap-0.5 text-xs text-status-warning"
                                                                    title="Required"
                                                                >
                                                                    <Star className="h-3 w-3" />
                                                                    Required
                                                                </span>
                                                            )}
                                                            {step.is_blocking && (
                                                                <span
                                                                    className="flex items-center gap-0.5 text-xs text-status-critical"
                                                                    title="Blocking"
                                                                >
                                                                    <Lock className="h-3 w-3" />
                                                                    Blocking
                                                                </span>
                                                            )}
                                                            {step.time_limit_minutes && (
                                                                <span className="flex items-center gap-0.5 text-xs text-muted-foreground">
                                                                    <Clock className="h-3 w-3" />
                                                                    {
                                                                        step.time_limit_minutes
                                                                    }
                                                                    m
                                                                </span>
                                                            )}
                                                        </div>
                                                        {step.instructions && (
                                                            <p className="mt-1 text-sm text-muted-foreground">
                                                                {
                                                                    step.instructions
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Run History */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Run History
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {recentRuns.length === 0 ? (
                                        <p className="py-4 text-center text-sm text-muted-foreground">
                                            No runs yet. This playbook has not
                                            been executed.
                                        </p>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>
                                                            Alert
                                                        </TableHead>
                                                        <TableHead>
                                                            Status
                                                        </TableHead>
                                                        <TableHead>
                                                            Progress
                                                        </TableHead>
                                                        <TableHead>
                                                            Started
                                                        </TableHead>
                                                        <TableHead>
                                                            Completed
                                                        </TableHead>
                                                        <TableHead>
                                                            Duration
                                                        </TableHead>
                                                        <TableHead>
                                                            Started By
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {recentRuns.map((run) => (
                                                        <TableRow key={run.id}>
                                                            <TableCell>
                                                                {run.alert ? (
                                                                    <Link
                                                                        href={`/control-room/alerts/${run.alert_id}`}
                                                                        className="flex items-center gap-1 text-sm hover:underline"
                                                                    >
                                                                        <span className="font-medium">
                                                                            #
                                                                            {
                                                                                run.alert_id
                                                                            }
                                                                        </span>
                                                                        <Badge
                                                                            className={`text-[10px] ${severityColors[run.alert.severity] ?? ''}`}
                                                                        >
                                                                            {
                                                                                run
                                                                                    .alert
                                                                                    .severity
                                                                            }
                                                                        </Badge>
                                                                        <ExternalLink className="h-3 w-3 text-muted-foreground" />
                                                                    </Link>
                                                                ) : (
                                                                    <span className="text-sm text-muted-foreground">
                                                                        #
                                                                        {
                                                                            run.alert_id
                                                                        }
                                                                    </span>
                                                                )}
                                                            </TableCell>
                                                            <TableCell>
                                                                <Badge
                                                                    variant="outline"
                                                                    className={`text-xs ${runStatusColors[run.status] ?? ''}`}
                                                                >
                                                                    {run.status.replace(
                                                                        /_/g,
                                                                        ' ',
                                                                    )}
                                                                </Badge>
                                                            </TableCell>
                                                            <TableCell>
                                                                <div className="flex items-center gap-2">
                                                                    <div className="h-2 w-20 overflow-hidden rounded-full bg-muted">
                                                                        <div
                                                                            className="h-full rounded-full bg-primary transition-all"
                                                                            style={{
                                                                                width: `${run.progress}%`,
                                                                            }}
                                                                        />
                                                                    </div>
                                                                    <span className="text-xs text-muted-foreground">
                                                                        {
                                                                            run.completed_steps
                                                                        }
                                                                        /
                                                                        {
                                                                            run.total_steps
                                                                        }
                                                                    </span>
                                                                </div>
                                                            </TableCell>
                                                            <TableCell className="text-sm">
                                                                {formatDate(
                                                                    run.started_at,
                                                                )}
                                                            </TableCell>
                                                            <TableCell className="text-sm">
                                                                {formatDate(
                                                                    run.completed_at,
                                                                )}
                                                            </TableCell>
                                                            <TableCell className="font-mono text-sm">
                                                                {formatDuration(
                                                                    run.started_at,
                                                                    run.completed_at,
                                                                )}
                                                            </TableCell>
                                                            <TableCell className="text-sm">
                                                                {run.started_by
                                                                    ?.name ??
                                                                    '-'}
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    )}
                </PageShell>
            </div>
        </AppLayout>
    );
}
