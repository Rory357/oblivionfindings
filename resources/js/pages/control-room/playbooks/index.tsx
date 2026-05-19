import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
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
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    CheckCircle,
    ChevronDown,
    ChevronUp,
    Clock,
    Layers,
    Play,
    Plus,
    Search as SearchIcon,
    Shield,
    Trash2,
    Wrench,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

// --- Types ---

interface PlaybookSummary {
    id: number;
    name: string;
    code: string | null;
    description: string | null;
    category: string;
    version: number;
    is_active: boolean;
    auto_attach: boolean;
    requires_approval: boolean;
    sla_acknowledge_minutes: number | null;
    sla_response_minutes: number | null;
    sla_resolution_minutes: number | null;
    steps_count: number;
    runs_count: number;
    last_run_at: string | null;
    created_at: string | null;
}

interface StepForm {
    title: string;
    type: string;
    instructions: string;
    is_required: boolean;
    is_blocking: boolean;
    time_limit_minutes: string;
}

interface Props {
    playbooks: PlaybookSummary[];
    filters: {
        category?: string;
        is_active?: string;
    };
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
    if (diffHours < 24) return `${diffHours}h ago`;
    return `${diffDays}d ago`;
}

function emptyStep(): StepForm {
    return {
        title: '',
        type: 'task',
        instructions: '',
        is_required: true,
        is_blocking: false,
        time_limit_minutes: '',
    };
}

// --- Component ---

export default function PlaybooksIndex({
    playbooks,
    filters,
    categories,
    stepTypes,
    can,
}: Props) {
    const [activeCategory, setActiveCategory] = useState<string>(
        filters.category || 'all',
    );
    const [showCreateDialog, setShowCreateDialog] = useState(false);

    // Create form state
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [category, setCategory] = useState('emergency');
    const [autoAttach, setAutoAttach] = useState(false);
    const [requiresApproval, setRequiresApproval] = useState(false);
    const [slaAck, setSlaAck] = useState('');
    const [slaResponse, setSlaResponse] = useState('');
    const [slaResolution, setSlaResolution] = useState('');
    const [requiredEvidence, setRequiredEvidence] = useState<string[]>([]);
    const [steps, setSteps] = useState<StepForm[]>([emptyStep()]);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const evidenceOptions = [
        'photo',
        'video',
        'document',
        'signature',
        'witness_statement',
        'incident_report',
    ];

    const applyFilter = (key: string, value: string) => {
        const newFilters = {
            ...filters,
            [key]: value === 'all' ? undefined : value,
        };
        router.get(
            '/control-room/playbooks',
            newFilters as Record<string, string>,
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    const handleCategoryTab = (cat: string) => {
        setActiveCategory(cat);
        applyFilter('category', cat);
    };

    const toggleActive = (playbook: PlaybookSummary) => {
        router.post(
            `/control-room/playbooks/${playbook.id}/toggle-active`,
            {},
            {
                preserveScroll: true,
            },
        );
    };

    const addStep = () => {
        setSteps([...steps, emptyStep()]);
    };

    const removeStep = (index: number) => {
        if (steps.length > 1) {
            setSteps(steps.filter((_, i) => i !== index));
        }
    };

    const updateStep = (
        index: number,
        field: keyof StepForm,
        value: string | boolean,
    ) => {
        const updated = [...steps];
        (updated[index] as any)[field] = value;
        setSteps(updated);
    };

    const moveStep = (index: number, direction: 'up' | 'down') => {
        const newIndex = direction === 'up' ? index - 1 : index + 1;
        if (newIndex < 0 || newIndex >= steps.length) return;
        const updated = [...steps];
        [updated[index], updated[newIndex]] = [
            updated[newIndex],
            updated[index],
        ];
        setSteps(updated);
    };

    const toggleEvidence = (type: string) => {
        setRequiredEvidence((prev) =>
            prev.includes(type)
                ? prev.filter((t) => t !== type)
                : [...prev, type],
        );
    };

    const resetForm = () => {
        setName('');
        setDescription('');
        setCategory('emergency');
        setAutoAttach(false);
        setRequiresApproval(false);
        setSlaAck('');
        setSlaResponse('');
        setSlaResolution('');
        setRequiredEvidence([]);
        setSteps([emptyStep()]);
    };

    const handleCreate = (e: FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);

        router.post(
            '/control-room/playbooks',
            {
                name,
                description: description || null,
                category,
                auto_attach: autoAttach,
                requires_approval: requiresApproval,
                sla_acknowledge_minutes: slaAck ? parseInt(slaAck) : null,
                sla_response_minutes: slaResponse
                    ? parseInt(slaResponse)
                    : null,
                sla_resolution_minutes: slaResolution
                    ? parseInt(slaResolution)
                    : null,
                required_evidence: requiredEvidence,
                steps: steps.map((s) => ({
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
                onSuccess: () => {
                    setShowCreateDialog(false);
                    resetForm();
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const categoryTabs = [
        { key: 'all', label: 'All' },
        ...Object.entries(categories).map(([key, label]) => ({ key, label })),
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Playbooks', href: '/control-room/playbooks' },
            ]}
        >
            <Head title="Playbooks - Control Room" />

            <div className="flex flex-col gap-6 p-6">
                <PageShell>
                    <PageHero variant="compact"
                        title="Playbooks"
                        description="Create and manage response procedure playbooks for consistent incident handling."
                        backHref="/control-room"
                        backLabel="Control Room"
                        actions={
                            can.manage ? (
                                <Dialog
                                    open={showCreateDialog}
                                    onOpenChange={setShowCreateDialog}
                                >
                                    <DialogTrigger asChild>
                                        <Button>
                                            <Plus className="mr-2 h-4 w-4" />
                                            Create Playbook
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
                                        <form onSubmit={handleCreate}>
                                            <DialogHeader>
                                                <DialogTitle>
                                                    Create Playbook
                                                </DialogTitle>
                                                <DialogDescription>
                                                    Define a response procedure
                                                    with ordered steps.
                                                </DialogDescription>
                                            </DialogHeader>

                                            <div className="mt-4 space-y-6">
                                                {/* Basic Info */}
                                                <div className="grid gap-4 sm:grid-cols-2">
                                                    <div className="space-y-2 sm:col-span-2">
                                                        <Label htmlFor="pb-name">
                                                            Name *
                                                        </Label>
                                                        <Input
                                                            id="pb-name"
                                                            value={name}
                                                            onChange={(e) =>
                                                                setName(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            placeholder="e.g. Emergency Response Protocol"
                                                            required
                                                        />
                                                    </div>
                                                    <div className="space-y-2 sm:col-span-2">
                                                        <Label htmlFor="pb-desc">
                                                            Description
                                                        </Label>
                                                        <Textarea
                                                            id="pb-desc"
                                                            value={description}
                                                            onChange={(e) =>
                                                                setDescription(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            placeholder="Describe the purpose and scope of this playbook..."
                                                            rows={2}
                                                        />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label>
                                                            Category *
                                                        </Label>
                                                        <Select
                                                            value={category}
                                                            onValueChange={
                                                                setCategory
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {Object.entries(
                                                                    categories,
                                                                ).map(
                                                                    ([
                                                                        key,
                                                                        label,
                                                                    ]) => (
                                                                        <SelectItem
                                                                            key={
                                                                                key
                                                                            }
                                                                            value={
                                                                                key
                                                                            }
                                                                        >
                                                                            {
                                                                                label
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                    <div className="flex items-end gap-6">
                                                        <div className="flex items-center gap-2">
                                                            <Switch
                                                                id="pb-auto"
                                                                checked={
                                                                    autoAttach
                                                                }
                                                                onCheckedChange={
                                                                    setAutoAttach
                                                                }
                                                            />
                                                            <Label htmlFor="pb-auto">
                                                                Auto-attach
                                                            </Label>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <Switch
                                                                id="pb-approval"
                                                                checked={
                                                                    requiresApproval
                                                                }
                                                                onCheckedChange={
                                                                    setRequiresApproval
                                                                }
                                                            />
                                                            <Label htmlFor="pb-approval">
                                                                Requires
                                                                Approval
                                                            </Label>
                                                        </div>
                                                    </div>
                                                </div>

                                                {/* SLA Targets */}
                                                <div>
                                                    <h4 className="mb-2 text-sm font-medium">
                                                        SLA Targets (minutes)
                                                    </h4>
                                                    <div className="grid gap-3 sm:grid-cols-3">
                                                        <div className="space-y-1">
                                                            <Label
                                                                htmlFor="sla-ack"
                                                                className="text-xs text-muted-foreground"
                                                            >
                                                                Acknowledge
                                                            </Label>
                                                            <Input
                                                                id="sla-ack"
                                                                type="number"
                                                                min={1}
                                                                value={slaAck}
                                                                onChange={(e) =>
                                                                    setSlaAck(
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="e.g. 5"
                                                            />
                                                        </div>
                                                        <div className="space-y-1">
                                                            <Label
                                                                htmlFor="sla-resp"
                                                                className="text-xs text-muted-foreground"
                                                            >
                                                                Response
                                                            </Label>
                                                            <Input
                                                                id="sla-resp"
                                                                type="number"
                                                                min={1}
                                                                value={
                                                                    slaResponse
                                                                }
                                                                onChange={(e) =>
                                                                    setSlaResponse(
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="e.g. 15"
                                                            />
                                                        </div>
                                                        <div className="space-y-1">
                                                            <Label
                                                                htmlFor="sla-res"
                                                                className="text-xs text-muted-foreground"
                                                            >
                                                                Resolution
                                                            </Label>
                                                            <Input
                                                                id="sla-res"
                                                                type="number"
                                                                min={1}
                                                                value={
                                                                    slaResolution
                                                                }
                                                                onChange={(e) =>
                                                                    setSlaResolution(
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="e.g. 60"
                                                            />
                                                        </div>
                                                    </div>
                                                </div>

                                                {/* Required Evidence */}
                                                <div>
                                                    <h4 className="mb-2 text-sm font-medium">
                                                        Required Evidence
                                                    </h4>
                                                    <div className="flex flex-wrap gap-2">
                                                        {evidenceOptions.map(
                                                            (type) => (
                                                                <Button
                                                                    key={type}
                                                                    type="button"
                                                                    variant="outline"
                                                                    onClick={() =>
                                                                        toggleEvidence(
                                                                            type,
                                                                        )
                                                                    }
                                                                    className={`h-auto rounded-full px-3 py-1 text-xs ${
                                                                        requiredEvidence.includes(
                                                                            type,
                                                                        )
                                                                            ? 'border-primary bg-primary/10 text-primary'
                                                                            : 'border-border text-muted-foreground hover:bg-muted'
                                                                    }`}
                                                                >
                                                                    {type.replace(
                                                                        /_/g,
                                                                        ' ',
                                                                    )}
                                                                </Button>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>

                                                {/* Steps Editor */}
                                                <div>
                                                    <div className="mb-2 flex items-center justify-between">
                                                        <h4 className="text-sm font-medium">
                                                            Steps *
                                                        </h4>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={addStep}
                                                        >
                                                            <Plus className="mr-1 h-3 w-3" />
                                                            Add Step
                                                        </Button>
                                                    </div>
                                                    <div className="space-y-3">
                                                        {steps.map(
                                                            (step, index) => (
                                                                <Card
                                                                    key={index}
                                                                    className="border-dashed"
                                                                >
                                                                    <CardContent className="pt-4">
                                                                        <div className="flex items-start gap-2">
                                                                            <div className="flex flex-col items-center gap-1 pt-1">
                                                                                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                                                                    {index +
                                                                                        1}
                                                                                </span>
                                                                                <Button
                                                                                    type="button"
                                                                                    variant="ghost"
                                                                                    size="icon"
                                                                                    onClick={() =>
                                                                                        moveStep(
                                                                                            index,
                                                                                            'up',
                                                                                        )
                                                                                    }
                                                                                    disabled={
                                                                                        index ===
                                                                                        0
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
                                                                                        moveStep(
                                                                                            index,
                                                                                            'down',
                                                                                        )
                                                                                    }
                                                                                    disabled={
                                                                                        index ===
                                                                                        steps.length -
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
                                                                                        onChange={(
                                                                                            e,
                                                                                        ) =>
                                                                                            updateStep(
                                                                                                index,
                                                                                                'title',
                                                                                                e
                                                                                                    .target
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
                                                                                            updateStep(
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
                                                                                                    key,
                                                                                                    label,
                                                                                                ]) => (
                                                                                                    <SelectItem
                                                                                                        key={
                                                                                                            key
                                                                                                        }
                                                                                                        value={
                                                                                                            key
                                                                                                        }
                                                                                                    >
                                                                                                        {
                                                                                                            label
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
                                                                                    onChange={(
                                                                                        e,
                                                                                    ) =>
                                                                                        updateStep(
                                                                                            index,
                                                                                            'instructions',
                                                                                            e
                                                                                                .target
                                                                                                .value,
                                                                                        )
                                                                                    }
                                                                                    placeholder="Instructions for this step..."
                                                                                    rows={
                                                                                        2
                                                                                    }
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
                                                                                                updateStep(
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
                                                                                                updateStep(
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
                                                                                    <div className="flex items-center gap-2">
                                                                                        <Input
                                                                                            type="number"
                                                                                            min={
                                                                                                1
                                                                                            }
                                                                                            value={
                                                                                                step.time_limit_minutes
                                                                                            }
                                                                                            onChange={(
                                                                                                e,
                                                                                            ) =>
                                                                                                updateStep(
                                                                                                    index,
                                                                                                    'time_limit_minutes',
                                                                                                    e
                                                                                                        .target
                                                                                                        .value,
                                                                                                )
                                                                                            }
                                                                                            placeholder="Time limit (min)"
                                                                                            className="w-36"
                                                                                        />
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                size="icon"
                                                                                onClick={() =>
                                                                                    removeStep(
                                                                                        index,
                                                                                    )
                                                                                }
                                                                                disabled={
                                                                                    steps.length ===
                                                                                    1
                                                                                }
                                                                                className="h-8 w-8 text-muted-foreground hover:text-destructive disabled:opacity-30"
                                                                            >
                                                                                <Trash2 className="h-4 w-4" />
                                                                            </Button>
                                                                        </div>
                                                                    </CardContent>
                                                                </Card>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            </div>

                                            <DialogFooter className="mt-6">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() => {
                                                        setShowCreateDialog(
                                                            false,
                                                        );
                                                        resetForm();
                                                    }}
                                                >
                                                    Cancel
                                                </Button>
                                                <Button
                                                    type="submit"
                                                    disabled={isSubmitting}
                                                >
                                                    {isSubmitting
                                                        ? 'Creating...'
                                                        : 'Create Playbook'}
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            ) : undefined
                        }
                    />

                    {/* Category Tabs */}
                    <div className="flex flex-wrap gap-1 rounded-lg border bg-muted/50 p-1">
                        {categoryTabs.map((tab) => (
                            <Button
                                type="button"
                                variant="ghost"
                                key={tab.key}
                                onClick={() => handleCategoryTab(tab.key)}
                                className={`h-auto rounded-md px-3 py-1.5 text-sm font-medium ${
                                    activeCategory === tab.key
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {tab.label}
                            </Button>
                        ))}
                    </div>

                    {/* Active/Inactive Filter */}
                    <div className="flex items-center gap-3">
                        <Select
                            value={filters.is_active ?? 'all'}
                            onValueChange={(v) => applyFilter('is_active', v)}
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All Playbooks
                                </SelectItem>
                                <SelectItem value="1">Active Only</SelectItem>
                                <SelectItem value="0">Inactive Only</SelectItem>
                            </SelectContent>
                        </Select>
                        <span className="text-sm text-muted-foreground">
                            {playbooks.length} playbook
                            {playbooks.length !== 1 ? 's' : ''}
                        </span>
                    </div>

                    {/* Playbook Grid */}
                    {playbooks.length === 0 ? (
                        <Card>
                            <CardContent className="pt-6">
                                <div className="py-12 text-center">
                                    <BookOpen className="mx-auto mb-3 h-12 w-12 text-muted-foreground/50" />
                                    <p className="text-sm text-muted-foreground">
                                        No playbooks found.
                                    </p>
                                    {can.manage && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="mt-3"
                                            onClick={() =>
                                                setShowCreateDialog(true)
                                            }
                                        >
                                            <Plus className="mr-1 h-3 w-3" />
                                            Create your first playbook
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {playbooks.map((pb) => {
                                const catConfig =
                                    categoryConfig[pb.category] ??
                                    categoryConfig.maintenance;
                                const CatIcon = catConfig.icon;
                                return (
                                    <Card
                                        key={pb.id}
                                        className={`transition-colors hover:shadow-md ${!pb.is_active ? 'opacity-60' : ''}`}
                                    >
                                        <CardHeader className="pb-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0 flex-1">
                                                    <Link
                                                        href={`/control-room/playbooks/${pb.id}`}
                                                        className="font-semibold hover:underline"
                                                    >
                                                        {pb.name}
                                                    </Link>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                        <Badge
                                                            variant="outline"
                                                            className={
                                                                catConfig.color
                                                            }
                                                        >
                                                            <CatIcon className="mr-1 h-3 w-3" />
                                                            {categories[
                                                                pb.category
                                                            ] ?? pb.category}
                                                        </Badge>
                                                        <Badge
                                                            variant="outline"
                                                            className="text-xs"
                                                        >
                                                            v{pb.version}
                                                        </Badge>
                                                    </div>
                                                </div>
                                                {can.manage && (
                                                    <Switch
                                                        checked={pb.is_active}
                                                        onCheckedChange={() =>
                                                            toggleActive(pb)
                                                        }
                                                        aria-label={`Toggle ${pb.name} active`}
                                                    />
                                                )}
                                            </div>
                                        </CardHeader>
                                        <CardContent>
                                            {pb.description && (
                                                <p className="mb-3 line-clamp-2 text-sm text-muted-foreground">
                                                    {pb.description}
                                                </p>
                                            )}
                                            <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                <span className="flex items-center gap-1">
                                                    <Layers className="h-3 w-3" />
                                                    {pb.steps_count} step
                                                    {pb.steps_count !== 1
                                                        ? 's'
                                                        : ''}
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Play className="h-3 w-3" />
                                                    {pb.runs_count} run
                                                    {pb.runs_count !== 1
                                                        ? 's'
                                                        : ''}
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Clock className="h-3 w-3" />
                                                    {formatRelativeTime(
                                                        pb.last_run_at,
                                                    )}
                                                </span>
                                            </div>
                                            {(pb.sla_acknowledge_minutes ||
                                                pb.sla_response_minutes ||
                                                pb.sla_resolution_minutes) && (
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {pb.sla_acknowledge_minutes && (
                                                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium">
                                                            ACK{' '}
                                                            {
                                                                pb.sla_acknowledge_minutes
                                                            }
                                                            m
                                                        </span>
                                                    )}
                                                    {pb.sla_response_minutes && (
                                                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium">
                                                            RESP{' '}
                                                            {
                                                                pb.sla_response_minutes
                                                            }
                                                            m
                                                        </span>
                                                    )}
                                                    {pb.sla_resolution_minutes && (
                                                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium">
                                                            RES{' '}
                                                            {
                                                                pb.sla_resolution_minutes
                                                            }
                                                            m
                                                        </span>
                                                    )}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    )}
                </PageShell>
            </div>
        </AppLayout>
    );
}
