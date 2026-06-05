import { router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Camera,
    ClipboardCheck,
    GripVertical,
    Loader2,
    Lock,
    PenLine,
    Plus,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
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
import { cn } from '@/lib/utils';

import { catColorVar } from './category';
import { useChecklistConfig } from './context';
import { categoryIcon } from './icons';
import type { ResponseType, TemplateDetail } from './types';

interface ItemDraft {
    id?: number;
    question: string;
    response_type: ResponseType;
    response_config: { min: string; max: string; unit: string };
    is_required: boolean;
    guidance: string;
    failure_creates_hazard: boolean;
    has_responses?: boolean;
}

interface BuilderForm {
    key: string;
    name: string;
    description: string;
    category: string;
    applicable_to_type: string;
    frequency: string;
    is_active: boolean;
    requires_photo: boolean;
    requires_signature: boolean;
    items: ItemDraft[];
}

const RESPONSE_TYPES: { value: ResponseType; label: string }[] = [
    { value: 'yes_no', label: 'Yes / No (Pass·Fail)' },
    { value: 'yes_no_na', label: 'Yes / No / N/A' },
    { value: 'pass_fail', label: 'Pass / Fail' },
    { value: 'numeric', label: 'Numeric reading' },
    { value: 'text', label: 'Text note' },
    { value: 'photo', label: 'Photo evidence' },
];

const FREQUENCIES = ['once', 'daily', 'weekly', 'fortnightly', 'monthly', 'quarterly', 'annual'];
const APPLIES_TO = ['all', 'house', 'head_office', 'facility'];

const DEFAULTS: BuilderForm = {
    key: '',
    name: '',
    description: '',
    category: '',
    applicable_to_type: 'all',
    frequency: 'weekly',
    is_active: true,
    requires_photo: false,
    requires_signature: false,
    items: [],
};

function slugify(s: string): string {
    return s
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 50);
}

function blankItem(): ItemDraft {
    return {
        question: '',
        response_type: 'yes_no',
        response_config: { min: '', max: '', unit: '' },
        is_required: true,
        guidance: '',
        failure_creates_hazard: false,
    };
}

function detailToForm(detail: TemplateDetail): BuilderForm {
    return {
        key: detail.key,
        name: detail.name,
        description: detail.description ?? '',
        category: detail.category ?? '',
        applicable_to_type: detail.applicable_to_type,
        frequency: detail.frequency,
        is_active: detail.is_active,
        requires_photo: detail.requires_photo,
        requires_signature: detail.requires_signature,
        items: detail.items.map((it) => ({
            id: it.id,
            question: it.question,
            response_type: it.response_type,
            response_config: {
                min: it.response_config?.min != null ? String(it.response_config.min) : '',
                max: it.response_config?.max != null ? String(it.response_config.max) : '',
                unit: it.response_config?.unit ?? '',
            },
            is_required: it.is_required,
            guidance: it.guidance ?? '',
            failure_creates_hazard: it.failure_creates_hazard,
            has_responses: it.has_responses,
        })),
    };
}

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

/**
 * Shell: handles loading the template detail for edit (partial reload), then
 * mounts the body with the resolved initial values so useForm is seeded once —
 * controlled <Select>s reflect the value from first render.
 */
export function TemplateBuilderModal({ target, onClose }: { target: 'new' | number; onClose: () => void }) {
    const page = usePage();
    const detail = (page.props as { templateDetail?: TemplateDetail | null }).templateDetail ?? null;
    const isEdit = target !== 'new';
    const ready = !isEdit || (detail !== null && detail.id === target);

    useEffect(() => {
        if (isEdit) {
            router.reload({ only: ['templateDetail'], data: { template: target }, preserveState: true, preserveScroll: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target]);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 1100px)', width: 'min(92vw, 1100px)' }}
            >
                {!ready ? (
                    <div className="flex h-64 flex-col items-center justify-center gap-3 text-muted-foreground">
                        <Loader2 className="h-6 w-6 animate-spin" />
                        <span className="text-sm">Loading template…</span>
                    </div>
                ) : (
                    <TemplateBuilderBody
                        key={isEdit ? `edit-${target}` : 'new'}
                        templateId={isEdit ? (target as number) : null}
                        initial={isEdit && detail ? detailToForm(detail) : DEFAULTS}
                        assignmentsCount={isEdit && detail ? detail.assignments_count : 0}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function TemplateBuilderBody({
    templateId,
    initial,
    assignmentsCount,
    onClose,
}: {
    templateId: number | null;
    initial: BuilderForm;
    assignmentsCount: number;
    onClose: () => void;
}) {
    const cfg = useChecklistConfig();
    const isEdit = templateId !== null;
    const form = useForm<BuilderForm>(initial);
    const keyEdited = useRef(isEdit); // key is locked in edit; don't auto-slug over it
    const [confirmDelete, setConfirmDelete] = useState(false);

    const items = form.data.items;
    const setItems = (next: ItemDraft[]) => form.setData('items', next);
    const updateItem = (i: number, patch: Partial<ItemDraft>) =>
        setItems(items.map((it, idx) => (idx === i ? { ...it, ...patch } : it)));
    const moveItem = (i: number, dir: -1 | 1) => {
        const j = i + dir;
        if (j < 0 || j >= items.length) return;
        const next = [...items];
        [next[i], next[j]] = [next[j], next[i]];
        setItems(next);
    };

    const onName = (value: string) => {
        form.setData('name', value);
        if (!isEdit && !keyEdited.current) {
            form.setData('key', slugify(value));
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            category: (data.category as string) || null,
            items: (data.items as ItemDraft[]).map((it) => ({
                id: it.id,
                question: it.question,
                response_type: it.response_type,
                response_config:
                    it.response_type === 'numeric'
                        ? {
                              min: it.response_config.min === '' ? null : it.response_config.min,
                              max: it.response_config.max === '' ? null : it.response_config.max,
                              unit: it.response_config.unit || null,
                          }
                        : null,
                is_required: it.is_required,
                guidance: it.guidance || null,
                failure_creates_hazard: it.failure_creates_hazard,
            })),
        }));
        const opts = { preserveScroll: true, preserveState: true, onSuccess: onClose };
        if (isEdit) {
            form.put(`/sites/checklists/templates/${templateId}`, opts);
        } else {
            form.post('/sites/checklists/templates', opts);
        }
    };

    const remove = () => {
        router.delete(`/sites/checklists/templates/${templateId}`, { preserveScroll: true, onSuccess: onClose });
    };

    const itemError = Object.keys(form.errors).find((k) => k.startsWith('items.'));
    const canDelete = isEdit && assignmentsCount === 0;

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <ClipboardCheck className="h-4 w-4 text-primary" />
                    {isEdit ? 'Edit checklist template' : 'New checklist template'}
                </DialogTitle>
                <DialogDescription>
                    Define the checklist and the items support workers complete on each run.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-5">
                {/* Details */}
                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <Label>
                            Name <span className="text-status-critical">*</span>
                        </Label>
                        <Input
                            value={form.data.name}
                            onChange={(e) => onName(e.target.value)}
                            placeholder="e.g. Weekly House Inspection"
                        />
                        <FieldError message={form.errors.name} />
                    </div>
                    <div>
                        <Label>
                            Key <span className="text-status-critical">*</span>
                        </Label>
                        <Input
                            value={form.data.key}
                            onChange={(e) => {
                                keyEdited.current = true;
                                form.setData('key', e.target.value);
                            }}
                            placeholder="e.g. weekly_house_inspection"
                            disabled={isEdit}
                        />
                        <p className="mt-1 text-[11px] text-muted-foreground">
                            {isEdit ? 'The key is fixed once created.' : 'Unique identifier — lowercase, no spaces.'}
                        </p>
                        <FieldError message={form.errors.key} />
                    </div>
                    <div className="sm:col-span-2">
                        <Label>Description</Label>
                        <Textarea
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            rows={2}
                            placeholder="What this checklist is for and when it should be used"
                        />
                    </div>
                </div>

                {/* Category tile picker */}
                <div>
                    <Label className="mb-2 block">Category</Label>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {cfg.categories.map((c) => {
                            const Icon = categoryIcon(c.icon);
                            const active = form.data.category === c.key;
                            return (
                                <button
                                    key={c.key}
                                    type="button"
                                    onClick={() => form.setData('category', active ? '' : c.key)}
                                    aria-pressed={active}
                                    className={cn(
                                        'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                                        active ? 'border-primary bg-primary/10 ring-1 ring-primary/40' : 'border-border',
                                    )}
                                >
                                    <span
                                        className="mt-0.5 shrink-0 rounded-lg p-1.5"
                                        style={{ background: `color-mix(in oklch, ${catColorVar(c.tone)} 15%, transparent)`, color: catColorVar(c.tone) }}
                                    >
                                        <Icon className="h-4 w-4" />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-medium">{c.label}</span>
                                        <span className="block text-xs text-muted-foreground">{c.blurb}</span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <p className="mt-1.5 text-[11px] text-muted-foreground">
                        Templates with no category appear under “Uncategorised” in the Library.
                    </p>
                </div>

                {/* Applies to + frequency + flags */}
                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <Label>Applies to</Label>
                        <Select value={form.data.applicable_to_type} onValueChange={(v) => form.setData('applicable_to_type', v)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {APPLIES_TO.map((t) => (
                                    <SelectItem key={t} value={t}>
                                        {cfg.typeLabels[t] ?? t}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label>Default frequency</Label>
                        <Select value={form.data.frequency} onValueChange={(v) => form.setData('frequency', v)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {FREQUENCIES.map((f) => (
                                    <SelectItem key={f} value={f}>
                                        {cfg.freqLabels[f] ?? f}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-lg border border-border bg-muted/30 px-4 py-3">
                    <ToggleField
                        label="Active"
                        hint="Available for assignment"
                        checked={form.data.is_active}
                        onChange={(v) => form.setData('is_active', v)}
                    />
                    <ToggleField
                        label="Requires photo"
                        Icon={Camera}
                        checked={form.data.requires_photo}
                        onChange={(v) => form.setData('requires_photo', v)}
                    />
                    <ToggleField
                        label="Requires sign-off"
                        Icon={PenLine}
                        checked={form.data.requires_signature}
                        onChange={(v) => form.setData('requires_signature', v)}
                    />
                </div>

                {/* Items editor */}
                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <div>
                            <h3 className="text-sm font-semibold">Items</h3>
                            <p className="text-xs text-muted-foreground">
                                {items.length} {items.length === 1 ? 'item' : 'items'} on this checklist
                            </p>
                        </div>
                        <Button type="button" variant="outline" size="sm" onClick={() => setItems([...items, blankItem()])}>
                            <Plus className="h-3.5 w-3.5" />
                            Add item
                        </Button>
                    </div>
                    <FieldError message={itemError ? 'Each item needs a question and a response type.' : undefined} />

                    {items.length === 0 ? (
                        <div className="rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                            No items yet. Add the checks support workers will complete.
                        </div>
                    ) : (
                        <div className="space-y-2.5">
                            {items.map((it, i) => (
                                <ItemRow
                                    key={i}
                                    item={it}
                                    index={i}
                                    total={items.length}
                                    onChange={(patch) => updateItem(i, patch)}
                                    onMove={(dir) => moveItem(i, dir)}
                                    onRemove={() => setItems(items.filter((_, idx) => idx !== i))}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <DialogFooter className="mt-5 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    {isEdit ? (
                        confirmDelete ? (
                            <div className="flex items-center gap-2">
                                <span className="text-xs text-muted-foreground">Delete this template?</span>
                                <Button type="button" variant="destructive" size="sm" onClick={remove}>
                                    Confirm delete
                                </Button>
                                <Button type="button" variant="ghost" size="sm" onClick={() => setConfirmDelete(false)}>
                                    Cancel
                                </Button>
                            </div>
                        ) : (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-status-critical hover:text-status-critical"
                                disabled={!canDelete}
                                title={canDelete ? undefined : 'Remove active site assignments before deleting.'}
                                onClick={() => setConfirmDelete(true)}
                            >
                                <Trash2 className="h-3.5 w-3.5" />
                                Delete
                            </Button>
                        )
                    ) : null}
                </div>
                <div className="flex items-center gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        {isEdit ? 'Save template' : 'Create template'}
                    </Button>
                </div>
            </DialogFooter>
        </form>
    );
}

function ToggleField({
    label,
    hint,
    Icon,
    checked,
    onChange,
}: {
    label: string;
    hint?: string;
    Icon?: typeof Camera;
    checked: boolean;
    onChange: (v: boolean) => void;
}) {
    return (
        <label className="flex cursor-pointer items-center gap-2">
            <Switch checked={checked} onCheckedChange={onChange} />
            <span className="flex items-center gap-1.5 text-sm">
                {Icon ? <Icon className="h-3.5 w-3.5 text-muted-foreground" /> : null}
                <span className="font-medium">{label}</span>
                {hint ? <span className="text-xs text-muted-foreground">· {hint}</span> : null}
            </span>
        </label>
    );
}

function ItemRow({
    item,
    index,
    total,
    onChange,
    onMove,
    onRemove,
}: {
    item: ItemDraft;
    index: number;
    total: number;
    onChange: (patch: Partial<ItemDraft>) => void;
    onMove: (dir: -1 | 1) => void;
    onRemove: () => void;
}) {
    const locked = item.has_responses === true;
    return (
        <div className="rounded-lg border border-border bg-card p-3">
            <div className="flex items-start gap-2">
                <div className="flex flex-col items-center gap-0.5 pt-1">
                    <GripVertical className="h-4 w-4 text-muted-foreground/40" />
                    <span className="text-[10px] font-semibold text-muted-foreground">{index + 1}</span>
                </div>
                <div className="min-w-0 flex-1 space-y-2">
                    <Input
                        value={item.question}
                        onChange={(e) => onChange({ question: e.target.value })}
                        placeholder="e.g. Smoke alarms tested and sounding"
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={item.response_type} onValueChange={(v) => onChange({ response_type: v as ResponseType })}>
                            <SelectTrigger className="h-9 w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {RESPONSE_TYPES.map((r) => (
                                    <SelectItem key={r.value} value={r.value}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {item.response_type === 'numeric' ? (
                            <div className="flex items-center gap-1.5">
                                <Input
                                    value={item.response_config.min}
                                    onChange={(e) => onChange({ response_config: { ...item.response_config, min: e.target.value } })}
                                    placeholder="min"
                                    type="number"
                                    className="h-9 w-20"
                                />
                                <span className="text-xs text-muted-foreground">–</span>
                                <Input
                                    value={item.response_config.max}
                                    onChange={(e) => onChange({ response_config: { ...item.response_config, max: e.target.value } })}
                                    placeholder="max"
                                    type="number"
                                    className="h-9 w-20"
                                />
                                <Input
                                    value={item.response_config.unit}
                                    onChange={(e) => onChange({ response_config: { ...item.response_config, unit: e.target.value } })}
                                    placeholder="unit"
                                    className="h-9 w-20"
                                />
                            </div>
                        ) : null}

                        <label className="flex cursor-pointer items-center gap-1.5 text-xs">
                            <Switch checked={item.is_required} onCheckedChange={(v) => onChange({ is_required: v })} />
                            Required
                        </label>
                        <label className="flex cursor-pointer items-center gap-1.5 text-xs">
                            <Switch
                                checked={item.failure_creates_hazard}
                                onCheckedChange={(v) => onChange({ failure_creates_hazard: v })}
                            />
                            <span className="flex items-center gap-1">
                                <TriangleAlert className="h-3 w-3 text-status-critical" />
                                Raises hazard
                            </span>
                        </label>
                    </div>
                    <Input
                        value={item.guidance}
                        onChange={(e) => onChange({ guidance: e.target.value })}
                        placeholder="Guidance for staff (optional)"
                        className="h-8 text-xs"
                    />
                </div>
                <div className="flex flex-col items-center gap-0.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7"
                        disabled={index === 0}
                        aria-label="Move up"
                        onClick={() => onMove(-1)}
                    >
                        <ArrowUp className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7"
                        disabled={index === total - 1}
                        aria-label="Move down"
                        onClick={() => onMove(1)}
                    >
                        <ArrowDown className="h-3.5 w-3.5" />
                    </Button>
                    {locked ? (
                        <span title="Has run responses — can't be removed" className="flex h-7 w-7 items-center justify-center text-muted-foreground/50">
                            <Lock className="h-3.5 w-3.5" />
                        </span>
                    ) : (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7 text-status-critical hover:text-status-critical"
                            aria-label="Remove item"
                            onClick={onRemove}
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}
