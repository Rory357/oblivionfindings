import AppLayout from '@/layouts/app-layout';
import { Head, useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import {
    ClipboardCheck,
    Plus,
    PlayCircle,
    CheckCircle2,
    LayoutTemplate,
    History,
    Trash2,
    ListChecks,
    Eye,
    AlertTriangle,
    ChevronDown,
    ChevronUp,
} from 'lucide-react';
import { useState } from 'react';

type Site = {
    id: number;
    name: string;
    type: string;
    display_type: string;
};

type TemplateItem = {
    id: number;
    question: string;
    response_type: string;
    is_required: boolean;
    sort_order: number;
};

type Template = {
    id: number;
    name: string;
    description?: string;
    frequency: string;
    site_id: number | null;
    items: TemplateItem[];
};

type RunResponse = {
    id: number;
    template_item_id: number;
    response_value: string;
    notes?: string;
    template_item?: {
        id: number;
        question: string;
        response_type: string;
        sort_order: number;
    };
};

type Run = {
    id: number;
    template: { id: number; name: string };
    completed_by: { id: number; name: string } | null;
    status: 'in_progress' | 'completed' | 'overdue';
    completed_at: string | null;
    created_at: string;
    completion_percentage: number;
    responses?: RunResponse[];
    damages_count?: number;
};

type Room = {
    id: number;
    name: string;
};

type Props = {
    site: Site;
    templates: Template[];
    runs: Run[];
    rooms: Room[];
    canManage: boolean;
    canRun: boolean;
};

type NewItem = {
    question: string;
    response_type: string;
    is_required: boolean;
};

const frequencyLabels: Record<string, string> = {
    once: 'One-time',
    daily: 'Daily',
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
};

const runStatusColors: Record<string, string> = {
    in_progress: 'bg-status-info-bg text-status-info border-status-info/30',
    completed: 'bg-status-success-bg text-status-success border-status-success/30',
    overdue: 'bg-status-critical-bg text-status-critical border-status-critical/30',
};

const runStatusLabels: Record<string, string> = {
    in_progress: 'In Progress',
    completed: 'Completed',
    overdue: 'Overdue',
};

const itemTypeLabels: Record<string, string> = {
    yes_no: 'Yes / No',
    yes_no_na: 'Yes / No / N/A',
    pass_fail: 'Pass / Fail',
    numeric: 'Numeric',
    text: 'Text',
    photo: 'Photo',
};

export default function HouseChecklists({ site, templates, runs, rooms, canManage, canRun }: Props) {
    const [createTemplateOpen, setCreateTemplateOpen] = useState(false);
    const [completeRunOpen, setCompleteRunOpen] = useState(false);
    const [viewRunOpen, setViewRunOpen] = useState(false);
    const [viewingRun, setViewingRun] = useState<Run | null>(null);
    const [activeRun, setActiveRun] = useState<Run | null>(null);
    const [activeRunTemplate, setActiveRunTemplate] = useState<Template | null>(null);

    // Template creation form
    const templateForm = useForm({
        name: '',
        description: '',
        frequency: 'daily',
        items: [] as NewItem[],
    });

    // Run completion form - dynamic based on template items
    const [runValues, setRunValues] = useState<Record<number, string>>({});
    const [runNotes, setRunNotes] = useState<Record<number, string>>({});

    // Damage reporting from checklist items
    type DamageReport = {
        title: string;
        description: string;
        severity: string;
        location_in_site: string;
    };
    const [itemDamages, setItemDamages] = useState<Record<number, DamageReport>>({});
    const [damageExpanded, setDamageExpanded] = useState<Record<number, boolean>>({});

    const addItem = () => {
        const items = [...templateForm.data.items, { question: '', response_type: 'yes_no', is_required: true }];
        templateForm.setData('items', items);
    };

    const removeItem = (index: number) => {
        const items = templateForm.data.items.filter((_, i) => i !== index);
        templateForm.setData('items', items);
    };

    const updateItem = (index: number, field: keyof NewItem, value: string | boolean) => {
        const items = [...templateForm.data.items];
        items[index] = { ...items[index], [field]: value };
        templateForm.setData('items', items);
    };

    const handleCreateTemplate = (e: React.FormEvent) => {
        e.preventDefault();
        templateForm.post(`/sites/${site.id}/house-checklists/templates`, {
            onSuccess: () => {
                setCreateTemplateOpen(false);
                templateForm.reset();
            },
        });
    };

    const startRun = (template: Template) => {
        router.post(`/sites/${site.id}/house-checklists/${template.id}/start`, {}, { preserveScroll: true });
    };

    const openCompleteRun = (run: Run) => {
        setActiveRun(run);
        const template = templates.find((t) => t.id === run.template.id) || null;
        setActiveRunTemplate(template);
        setRunValues({});
        setRunNotes({});
        setItemDamages({});
        setDamageExpanded({});
        setCompleteRunOpen(true);
    };

    const isNegativeResponse = (value: string) => value === 'no' || value === 'fail';

    const toggleDamageForItem = (itemId: number, question: string) => {
        setDamageExpanded((prev) => ({ ...prev, [itemId]: !prev[itemId] }));
        if (!itemDamages[itemId]) {
            setItemDamages((prev) => ({
                ...prev,
                [itemId]: {
                    title: `Checklist issue: ${question}`,
                    description: '',
                    severity: 'minor',
                    location_in_site: '',
                },
            }));
        }
    };

    const updateDamage = (itemId: number, field: keyof DamageReport, value: string) => {
        setItemDamages((prev) => ({
            ...prev,
            [itemId]: { ...prev[itemId], [field]: value },
        }));
    };

    const removeDamageForItem = (itemId: number) => {
        setDamageExpanded((prev) => ({ ...prev, [itemId]: false }));
        setItemDamages((prev) => {
            const next = { ...prev };
            delete next[itemId];
            return next;
        });
    };

    const handleCompleteRun = () => {
        if (!activeRun) return;
        const responses = activeRunTemplate?.items.map((item) => ({
            template_item_id: item.id,
            response_value: runValues[item.id] || '',
            notes: runNotes[item.id] || '',
        })) || [];

        // Collect damage reports from expanded items
        const damages = Object.values(itemDamages).filter(
            (d) => d.title.trim() && d.description.trim(),
        );

        router.post(
            `/sites/${site.id}/house-checklists/runs/${activeRun.id}/complete`,
            { responses, damages },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCompleteRunOpen(false);
                    setActiveRun(null);
                    setActiveRunTemplate(null);
                    setItemDamages({});
                    setDamageExpanded({});
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Sites', href: '/sites' },
            { title: site.name, href: `/sites/${site.id}` },
            { title: 'Daily Checklists', href: `/sites/${site.id}/house-checklists` },
        ]}>
            <Head title={`${site.name} - Daily Checklists`} />

            <div className="m-4 space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <ClipboardCheck className="w-5 h-5" />
                            Daily Checklists
                        </h1>
                        <p className="text-sm text-muted-foreground">{site.name}</p>
                    </div>
                    {canManage && (
                        <Button onClick={() => setCreateTemplateOpen(true)}>
                            <Plus className="w-4 h-4 mr-1" />
                            Create Template
                        </Button>
                    )}
                </div>

                {/* Templates Section */}
                <div>
                    <h2 className="text-base font-semibold flex items-center gap-2 mb-3">
                        <LayoutTemplate className="w-4 h-4" />
                        Templates
                    </h2>
                    {templates.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center text-muted-foreground">
                                <LayoutTemplate className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No checklist templates available</p>
                                {canManage && (
                                    <p className="text-sm mt-1">Click "Create Template" to set up your first checklist</p>
                                )}
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {templates.map((template) => (
                                <Card key={template.id} className="hover:bg-muted/50 transition-colors">
                                    <CardContent className="p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex-1 min-w-0">
                                                <h3 className="font-medium truncate">{template.name}</h3>
                                                {template.description && (
                                                    <p className="text-sm text-muted-foreground mt-1 line-clamp-2">
                                                        {template.description}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2 mt-3">
                                            <Badge variant="outline">
                                                {frequencyLabels[template.frequency] || template.frequency}
                                            </Badge>
                                            {template.site_id ? (
                                                <Badge className="bg-primary/20 text-primary/70 border-primary/30">
                                                    Site-specific
                                                </Badge>
                                            ) : (
                                                <Badge className="bg-muted-foreground/80/20 text-muted-foreground border-border/30">
                                                    Global
                                                </Badge>
                                            )}
                                            <span className="text-xs text-muted-foreground flex items-center gap-1">
                                                <ListChecks className="w-3 h-3" />
                                                {template.items.length} items
                                            </span>
                                        </div>
                                        {canRun && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="mt-3 w-full"
                                                onClick={() => startRun(template)}
                                            >
                                                <PlayCircle className="w-4 h-4 mr-1" />
                                                Start Run
                                            </Button>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </div>

                {/* Recent Runs Section */}
                <div>
                    <h2 className="text-base font-semibold flex items-center gap-2 mb-3">
                        <History className="w-4 h-4" />
                        Recent Runs
                    </h2>
                    <Card>
                        <CardContent className="p-0">
                            {runs.length === 0 ? (
                                <div className="text-center py-8 text-muted-foreground">
                                    <CheckCircle2 className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                    <p>No checklist runs yet</p>
                                    <p className="text-sm mt-1">Start a run from one of the templates above</p>
                                </div>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Template</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Completed By</TableHead>
                                            <TableHead>Completion</TableHead>
                                            <TableHead>Damages</TableHead>
                                            <TableHead>Date</TableHead>
                                            <TableHead className="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {runs.map((run) => (
                                            <TableRow key={run.id}>
                                                <TableCell className="font-medium">
                                                    {run.template.name}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge className={runStatusColors[run.status]}>
                                                        {runStatusLabels[run.status]}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {run.completed_by?.name || '-'}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        <div className="w-16 h-2 bg-muted rounded-full overflow-hidden">
                                                            <div
                                                                className={`h-full rounded-full ${
                                                                    run.completion_percentage === 100
                                                                        ? 'bg-status-success'
                                                                        : run.completion_percentage > 50
                                                                            ? 'bg-status-info'
                                                                            : 'bg-status-warning'
                                                                }`}
                                                                style={{ width: `${run.completion_percentage}%` }}
                                                            />
                                                        </div>
                                                        <span className="text-sm text-muted-foreground">
                                                            {run.completion_percentage}%
                                                        </span>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {(run.damages_count ?? 0) > 0 ? (
                                                        <Badge className="bg-status-warning-bg text-status-warning border-status-warning/30">
                                                            <AlertTriangle className="w-3 h-3 mr-1" />
                                                            {run.damages_count}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-sm text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {run.completed_at
                                                        ? new Date(run.completed_at).toLocaleDateString()
                                                        : new Date(run.created_at).toLocaleDateString()}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {run.status === 'completed' && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => {
                                                                    setViewingRun(run);
                                                                    setViewRunOpen(true);
                                                                }}
                                                            >
                                                                <Eye className="w-4 h-4 mr-1" />
                                                                View
                                                            </Button>
                                                        )}
                                                        {run.status === 'in_progress' && canRun && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() => openCompleteRun(run)}
                                                            >
                                                                <CheckCircle2 className="w-4 h-4 mr-1" />
                                                                Complete
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Create Template Dialog */}
                <Dialog open={createTemplateOpen} onOpenChange={setCreateTemplateOpen}>
                    <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Create Checklist Template</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleCreateTemplate} className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Name *</Label>
                                    <Input
                                        value={templateForm.data.name}
                                        onChange={(e) => templateForm.setData('name', e.target.value)}
                                        placeholder="e.g., Morning House Check"
                                        required
                                    />
                                    {templateForm.errors.name && (
                                        <p className="text-sm text-status-critical mt-1">{templateForm.errors.name}</p>
                                    )}
                                </div>
                                <div>
                                    <Label>Frequency *</Label>
                                    <Select
                                        value={templateForm.data.frequency}
                                        onValueChange={(v) => templateForm.setData('frequency', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="once">One-time</SelectItem>
                                            <SelectItem value="daily">Daily</SelectItem>
                                            <SelectItem value="weekly">Weekly</SelectItem>
                                            <SelectItem value="fortnightly">Fortnightly</SelectItem>
                                            <SelectItem value="monthly">Monthly</SelectItem>
                                            <SelectItem value="quarterly">Quarterly</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div>
                                <Label>Description</Label>
                                <Textarea
                                    value={templateForm.data.description}
                                    onChange={(e) => templateForm.setData('description', e.target.value)}
                                    rows={2}
                                    placeholder="Brief description of this checklist..."
                                />
                            </div>

                            {/* Dynamic Items */}
                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <Label className="text-base">Checklist Items</Label>
                                    <Button type="button" variant="outline" size="sm" onClick={addItem}>
                                        <Plus className="w-4 h-4 mr-1" />
                                        Add Item
                                    </Button>
                                </div>
                                {templateForm.data.items.length === 0 ? (
                                    <p className="text-sm text-muted-foreground text-center py-4 border rounded-lg border-dashed">
                                        No items added yet. Click "Add Item" to start building your checklist.
                                    </p>
                                ) : (
                                    <div className="space-y-2">
                                        {templateForm.data.items.map((item, index) => (
                                            <div key={index} className="flex items-start gap-2 p-3 rounded-lg border">
                                                <div className="flex-1 grid gap-2 sm:grid-cols-3">
                                                    <div className="sm:col-span-1">
                                                        <Input
                                                            value={item.question}
                                                            onChange={(e) => updateItem(index, 'question', e.target.value)}
                                                            placeholder="Question text"
                                                            required
                                                        />
                                                    </div>
                                                    <div>
                                                        <Select
                                                            value={item.response_type}
                                                            onValueChange={(v) => updateItem(index, 'response_type', v)}
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="yes_no">Yes / No</SelectItem>
                                                                <SelectItem value="yes_no_na">Yes / No / N/A</SelectItem>
                                                                <SelectItem value="pass_fail">Pass / Fail</SelectItem>
                                                                <SelectItem value="numeric">Numeric</SelectItem>
                                                                <SelectItem value="text">Text</SelectItem>
                                                                <SelectItem value="photo">Photo</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Checkbox
                                                            checked={item.is_required}
                                                            onCheckedChange={(checked) =>
                                                                updateItem(index, 'is_required', !!checked)
                                                            }
                                                        />
                                                        <Label className="font-normal text-sm">Required</Label>
                                                    </div>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-status-critical hover:text-status-critical"
                                                    onClick={() => removeItem(index)}
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setCreateTemplateOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={templateForm.processing}>
                                    Create Template
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* Complete Run Dialog */}
                <Dialog open={completeRunOpen} onOpenChange={(open) => {
                    setCompleteRunOpen(open);
                    if (!open) {
                        setActiveRun(null);
                        setActiveRunTemplate(null);
                    }
                }}>
                    <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>
                                Complete: {activeRun?.template.name}
                            </DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4">
                            {!activeRunTemplate ? (
                                <div className="text-center py-6 text-muted-foreground">
                                    <ClipboardCheck className="w-10 h-10 mx-auto mb-2 opacity-50" />
                                    <p>Template details not available.</p>
                                </div>
                            ) : activeRunTemplate.items.length === 0 ? (
                                <div className="text-center py-6 text-muted-foreground">
                                    <ClipboardCheck className="w-10 h-10 mx-auto mb-2 opacity-50" />
                                    <p>This checklist has no items to complete.</p>
                                    <p className="text-sm mt-1">You can still mark this run as complete.</p>
                                </div>
                            ) : (
                                activeRunTemplate.items
                                    .sort((a, b) => a.sort_order - b.sort_order)
                                    .map((item, idx) => (
                                        <div key={item.id} className="p-3 rounded-lg border space-y-2">
                                            <div className="flex items-start gap-2">
                                                <span className="text-xs text-muted-foreground font-mono mt-0.5">{idx + 1}.</span>
                                                <div className="flex-1">
                                                    <Label className="font-medium">
                                                        {item.question}
                                                        {item.is_required && (
                                                            <span className="text-status-critical ml-1">*</span>
                                                        )}
                                                    </Label>
                                                </div>
                                                <Badge variant="outline" className="text-xs shrink-0">
                                                    {itemTypeLabels[item.response_type] || item.response_type}
                                                </Badge>
                                            </div>
                                            {item.response_type === 'yes_no' || item.response_type === 'yes_no_na' ? (
                                                <div className="flex items-center gap-2">
                                                    <Button
                                                        type="button"
                                                        variant={runValues[item.id] === 'yes' ? 'default' : 'outline'}
                                                        size="sm"
                                                        onClick={() => setRunValues((prev) => ({ ...prev, [item.id]: 'yes' }))}
                                                    >
                                                        Yes
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant={runValues[item.id] === 'no' ? 'destructive' : 'outline'}
                                                        size="sm"
                                                        onClick={() => setRunValues((prev) => ({ ...prev, [item.id]: 'no' }))}
                                                    >
                                                        No
                                                    </Button>
                                                    {item.response_type === 'yes_no_na' && (
                                                        <Button
                                                            type="button"
                                                            variant={runValues[item.id] === 'na' ? 'secondary' : 'outline'}
                                                            size="sm"
                                                            onClick={() => setRunValues((prev) => ({ ...prev, [item.id]: 'na' }))}
                                                        >
                                                            N/A
                                                        </Button>
                                                    )}
                                                </div>
                                            ) : item.response_type === 'pass_fail' ? (
                                                <div className="flex items-center gap-2">
                                                    <Button
                                                        type="button"
                                                        variant={runValues[item.id] === 'pass' ? 'default' : 'outline'}
                                                        size="sm"
                                                        onClick={() => setRunValues((prev) => ({ ...prev, [item.id]: 'pass' }))}
                                                    >
                                                        Pass
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant={runValues[item.id] === 'fail' ? 'destructive' : 'outline'}
                                                        size="sm"
                                                        onClick={() => setRunValues((prev) => ({ ...prev, [item.id]: 'fail' }))}
                                                    >
                                                        Fail
                                                    </Button>
                                                </div>
                                            ) : item.response_type === 'numeric' ? (
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    value={runValues[item.id] || ''}
                                                    onChange={(e) =>
                                                        setRunValues((prev) => ({
                                                            ...prev,
                                                            [item.id]: e.target.value,
                                                        }))
                                                    }
                                                    placeholder="Enter value"
                                                />
                                            ) : (
                                                <Input
                                                    value={runValues[item.id] || ''}
                                                    onChange={(e) =>
                                                        setRunValues((prev) => ({
                                                            ...prev,
                                                            [item.id]: e.target.value,
                                                        }))
                                                    }
                                                    placeholder="Enter response"
                                                />
                                            )}
                                            <div>
                                                <Input
                                                    value={runNotes[item.id] || ''}
                                                    onChange={(e) =>
                                                        setRunNotes((prev) => ({
                                                            ...prev,
                                                            [item.id]: e.target.value,
                                                        }))
                                                    }
                                                    placeholder="Notes (optional)"
                                                    className="text-sm"
                                                />
                                            </div>

                                            {/* Report Damage - always available */}
                                            <div className="mt-2">
                                                    {!damageExpanded[item.id] ? (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            className="text-status-warning border-status-warning/30 hover:bg-status-warning"
                                                            onClick={() => toggleDamageForItem(item.id, item.question)}
                                                        >
                                                            <AlertTriangle className="w-3.5 h-3.5 mr-1" />
                                                            Report Damage
                                                        </Button>
                                                    ) : (
                                                        <div className="rounded-lg border border-status-warning/30 bg-status-warning p-3 space-y-2">
                                                            <div className="flex items-center justify-between">
                                                                <span className="text-sm font-medium text-status-warning flex items-center gap-1">
                                                                    <AlertTriangle className="w-3.5 h-3.5" />
                                                                    Damage Report
                                                                </span>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="text-status-critical hover:text-status-critical h-6 px-2"
                                                                    onClick={() => removeDamageForItem(item.id)}
                                                                >
                                                                    <Trash2 className="w-3.5 h-3.5" />
                                                                </Button>
                                                            </div>
                                                            <div>
                                                                <Label className="text-xs">Title *</Label>
                                                                <Input
                                                                    value={itemDamages[item.id]?.title || ''}
                                                                    onChange={(e) => updateDamage(item.id, 'title', e.target.value)}
                                                                    placeholder="Damage title"
                                                                    className="text-sm"
                                                                />
                                                            </div>
                                                            <div>
                                                                <Label className="text-xs">Description *</Label>
                                                                <Textarea
                                                                    value={itemDamages[item.id]?.description || ''}
                                                                    onChange={(e) => updateDamage(item.id, 'description', e.target.value)}
                                                                    placeholder="Describe the damage..."
                                                                    rows={2}
                                                                    className="text-sm"
                                                                />
                                                            </div>
                                                            <div className="grid gap-2 sm:grid-cols-2">
                                                                <div>
                                                                    <Label className="text-xs">Severity *</Label>
                                                                    <Select
                                                                        value={itemDamages[item.id]?.severity || 'minor'}
                                                                        onValueChange={(v) => updateDamage(item.id, 'severity', v)}
                                                                    >
                                                                        <SelectTrigger className="text-sm">
                                                                            <SelectValue />
                                                                        </SelectTrigger>
                                                                        <SelectContent>
                                                                            <SelectItem value="minor">Minor</SelectItem>
                                                                            <SelectItem value="moderate">Moderate</SelectItem>
                                                                            <SelectItem value="major">Major</SelectItem>
                                                                            <SelectItem value="critical">Critical</SelectItem>
                                                                        </SelectContent>
                                                                    </Select>
                                                                </div>
                                                                <div>
                                                                    <Label className="text-xs">Room</Label>
                                                                    <Select
                                                                        value={itemDamages[item.id]?.location_in_site || undefined}
                                                                        onValueChange={(v) => updateDamage(item.id, 'location_in_site', v)}
                                                                    >
                                                                        <SelectTrigger className="text-sm">
                                                                            <SelectValue placeholder="Select room" />
                                                                        </SelectTrigger>
                                                                        <SelectContent>
                                                                            {rooms.map((room) => (
                                                                                <SelectItem key={room.id} value={room.name}>
                                                                                    {room.name}
                                                                                </SelectItem>
                                                                            ))}
                                                                        </SelectContent>
                                                                    </Select>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                        </div>
                                    ))
                            )}
                        </div>
                        <DialogFooter className="flex-col sm:flex-row gap-2">
                            {Object.keys(itemDamages).length > 0 && (
                                <div className="flex items-center gap-1 text-sm text-status-warning mr-auto">
                                    <AlertTriangle className="w-4 h-4" />
                                    {Object.keys(itemDamages).length} damage{' '}
                                    {Object.keys(itemDamages).length === 1 ? 'report' : 'reports'} will be created
                                </div>
                            )}
                            <Button variant="outline" onClick={() => setCompleteRunOpen(false)}>
                                Cancel
                            </Button>
                            <Button onClick={handleCompleteRun}>
                                <CheckCircle2 className="w-4 h-4 mr-1" />
                                Mark Complete
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* View Completed Run Dialog */}
                <Dialog open={viewRunOpen} onOpenChange={(open) => {
                    setViewRunOpen(open);
                    if (!open) setViewingRun(null);
                }}>
                    <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>
                                {viewingRun?.template.name}
                            </DialogTitle>
                            {viewingRun && (
                                <p className="text-sm text-muted-foreground">
                                    Completed by {viewingRun.completed_by?.name || 'Unknown'} on{' '}
                                    {viewingRun.completed_at
                                        ? new Date(viewingRun.completed_at).toLocaleDateString()
                                        : '-'}
                                </p>
                            )}
                        </DialogHeader>
                        <div className="space-y-3">
                            {viewingRun?.responses && viewingRun.responses.length > 0 ? (
                                [...viewingRun.responses]
                                    .sort((a, b) => (a.template_item?.sort_order ?? 0) - (b.template_item?.sort_order ?? 0))
                                    .map((resp, idx) => (
                                        <div key={resp.id} className="p-3 rounded-lg border space-y-1">
                                            <div className="flex items-start gap-2">
                                                <span className="text-xs text-muted-foreground font-mono mt-0.5">{idx + 1}.</span>
                                                <div className="flex-1">
                                                    <p className="text-sm font-medium">
                                                        {resp.template_item?.question || `Item #${resp.template_item_id}`}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="ml-5">
                                                {resp.response_value ? (
                                                    <Badge className={
                                                        resp.response_value === 'yes' || resp.response_value === 'pass'
                                                            ? 'bg-status-success-bg text-status-success border-status-success/30'
                                                            : resp.response_value === 'no' || resp.response_value === 'fail'
                                                                ? 'bg-status-critical-bg text-status-critical border-status-critical/30'
                                                                : resp.response_value === 'na'
                                                                    ? 'bg-muted-foreground/80/20 text-muted-foreground border-border/30'
                                                                    : 'bg-status-info-bg text-status-info border-status-info/30'
                                                    }>
                                                        {resp.response_value === 'yes' ? 'Yes'
                                                            : resp.response_value === 'no' ? 'No'
                                                            : resp.response_value === 'na' ? 'N/A'
                                                            : resp.response_value === 'pass' ? 'Pass'
                                                            : resp.response_value === 'fail' ? 'Fail'
                                                            : resp.response_value}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">No response</span>
                                                )}
                                                {resp.notes && (
                                                    <p className="text-xs text-muted-foreground mt-1">Note: {resp.notes}</p>
                                                )}
                                            </div>
                                        </div>
                                    ))
                            ) : (
                                <div className="text-center py-6 text-muted-foreground">
                                    <ClipboardCheck className="w-10 h-10 mx-auto mb-2 opacity-50" />
                                    <p>No responses recorded for this run.</p>
                                </div>
                            )}
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setViewRunOpen(false)}>
                                Close
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
