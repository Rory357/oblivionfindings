import { Button } from '@/components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { GitMerge, ListChecks, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

interface Option {
    id: number;
    name: string;
}

export interface ProvisioningTemplateTask {
    id?: number;
    task_key: string;
    title: string;
    description: string | null;
    category: string;
    action: string;
    request_type: string;
    responsible_team_id: number | null;
    responsible_team: Option | null;
    stage: number;
    sort_order: number;
    dependency_task_keys: string[];
    trigger_fields: string[];
    approval_required: boolean;
    evidence_required: boolean;
    due_offset_days: number;
    fulfiller_fields: string[];
}

export interface ProvisioningTemplate {
    id: number;
    name: string;
    description: string | null;
    lifecycle_type: string;
    position_role: string | null;
    site_id: number | null;
    site: Option | null;
    employment_type: string | null;
    selection_priority: number;
    is_active: boolean;
    tasks: ProvisioningTemplateTask[];
}

type TaskDraft = Omit<ProvisioningTemplateTask, 'id' | 'responsible_team' | 'responsible_team_id'> & {
    responsible_team_id: number | '';
};

const LIFECYCLES = ['joiner', 'mover', 'leaver'];
const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'];
const CATEGORIES = [
    'account',
    'group',
    'licence',
    'email',
    'device',
    'peripheral',
    'network',
    'access_control',
    'telephony',
    'vehicle_technology',
    'healthcare_access',
    'equipment',
    'other',
];
const ACTIONS = ['grant', 'change', 'revoke', 'recover', 'configure', 'verify'];
const REQUEST_TYPES = ['account', 'access', 'equipment', 'other'];
const TRIGGER_FIELDS = ['position_role', 'primary_site_id', 'employment_type'];
const FULFILLER_FIELDS = [
    'employee_number',
    'work_email',
    'position_title',
    'position_role',
    'employment_type',
    'primary_site',
    'manager',
];
const ANY = 'any';

const label = (value: string) => value.replaceAll('_', ' ').replace(/^\w/, (letter) => letter.toUpperCase());

const blankTask = (index = 0): TaskDraft => ({
    task_key: `step-${index + 1}`,
    title: '',
    description: null,
    category: 'account',
    action: 'grant',
    request_type: 'account',
    responsible_team_id: '',
    stage: index + 1,
    sort_order: index,
    dependency_task_keys: [],
    trigger_fields: [],
    approval_required: false,
    evidence_required: false,
    due_offset_days: 0,
    fulfiller_fields: ['employee_number', 'work_email', 'position_role', 'primary_site'],
});

export function ItProvisioningTemplates({
    templates,
    teams,
    sites,
    positionRoles,
}: {
    templates: ProvisioningTemplate[];
    teams: Option[];
    sites: Option[];
    positionRoles: string[];
}) {
    const [open, setOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const form = useForm({
        name: '',
        description: '',
        lifecycle_type: 'joiner',
        position_role: '',
        site_id: '' as number | '',
        employment_type: '',
        selection_priority: 0,
        is_active: true,
        tasks: [blankTask()],
    });
    const roleOptions = Array.from(new Set([
        ...positionRoles,
        ...(form.data.position_role ? [form.data.position_role] : []),
    ]));

    const create = () => {
        setEditingId(null);
        form.reset();
        form.setData('tasks', [blankTask()]);
        setOpen(true);
    };

    const edit = (template: ProvisioningTemplate) => {
        setEditingId(template.id);
        form.setData({
            name: template.name,
            description: template.description ?? '',
            lifecycle_type: template.lifecycle_type,
            position_role: template.position_role ?? '',
            site_id: template.site_id ?? '',
            employment_type: template.employment_type ?? '',
            selection_priority: template.selection_priority,
            is_active: template.is_active,
            tasks: template.tasks.map((task) => ({
                task_key: task.task_key,
                title: task.title,
                description: task.description,
                category: task.category,
                action: task.action,
                request_type: task.request_type,
                responsible_team_id: task.responsible_team_id ?? '',
                stage: task.stage,
                sort_order: task.sort_order,
                dependency_task_keys: task.dependency_task_keys,
                trigger_fields: task.trigger_fields,
                approval_required: task.approval_required,
                evidence_required: task.evidence_required,
                due_offset_days: task.due_offset_days,
                fulfiller_fields: task.fulfiller_fields,
            })),
        });
        setOpen(true);
    };

    const updateTask = <K extends keyof TaskDraft>(index: number, field: K, value: TaskDraft[K]) => {
        form.setData('tasks', form.data.tasks.map((task, taskIndex) => taskIndex === index ? { ...task, [field]: value } : task));
    };

    const toggleList = (
        index: number,
        field: 'dependency_task_keys' | 'trigger_fields' | 'fulfiller_fields',
        value: string,
        checked: boolean,
    ) => {
        const current = form.data.tasks[index][field];
        updateTask(index, field, checked ? [...current, value] : current.filter((item) => item !== value));
    };

    const save = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };
        if (editingId) form.patch(`/it/setup/provisioning-templates/${editingId}`, options);
        else form.post('/it/setup/provisioning-templates', options);
    };

    return (
        <>
            <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="flex items-center gap-2 text-base font-bold text-foreground">
                            <GitMerge className="h-4 w-4 text-primary" /> Lifecycle workflow templates
                        </h2>
                        <p className="mt-1 max-w-3xl text-xs text-muted-foreground">
                            Define ordered or parallel IT steps for HR joiners, role/site movers, and leavers. The most specific active role, site, and employment match wins.
                        </p>
                    </div>
                    <Button onClick={create}><Plus className="h-4 w-4" /> New template</Button>
                </div>

                {templates.length > 0 ? (
                    <div className="mt-4 grid gap-3 lg:grid-cols-2">
                        {templates.map((template) => (
                            <article key={template.id} className="rounded-xl border border-border bg-background p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="truncate text-sm font-bold">{template.name}</h3>
                                            <StatusBadge variant={template.is_active ? 'success' : 'neutral'} size="sm">
                                                {label(template.lifecycle_type)}
                                            </StatusBadge>
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">{template.description || 'No description'}</p>
                                    </div>
                                    <Button size="icon" variant="ghost" aria-label={`Edit ${template.name}`} onClick={() => edit(template)}>
                                        <Pencil className="h-4 w-4" />
                                    </Button>
                                </div>
                                <div className="mt-3 flex flex-wrap gap-1.5 text-[10.5px]">
                                    <span className="rounded-full bg-muted px-2 py-1">Role: {template.position_role || 'Any'}</span>
                                    <span className="rounded-full bg-muted px-2 py-1">Site: {template.site?.name || 'Any'}</span>
                                    <span className="rounded-full bg-muted px-2 py-1">Employment: {template.employment_type ? label(template.employment_type) : 'Any'}</span>
                                    <span className="rounded-full bg-muted px-2 py-1">Priority {template.selection_priority}</span>
                                </div>
                                <ol className="mt-3 grid gap-2">
                                    {template.tasks.map((task) => (
                                        <li key={task.task_key} className="flex items-start gap-2 rounded-lg border border-border/70 p-2.5">
                                            <span className="grid h-6 w-6 flex-none place-items-center rounded-md bg-primary/10 text-[10px] font-bold text-primary">{task.stage}</span>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-xs font-semibold">{task.title}</p>
                                                <p className="truncate text-[10.5px] text-muted-foreground">
                                                    {label(task.action)} {label(task.category)}{task.responsible_team ? ` · ${task.responsible_team.name}` : ''}
                                                    {task.dependency_task_keys.length ? ` · after ${task.dependency_task_keys.join(', ')}` : ''}
                                                </p>
                                            </div>
                                            {task.approval_required || task.evidence_required ? <ShieldCheck className="h-3.5 w-3.5 text-primary" /> : null}
                                        </li>
                                    ))}
                                </ol>
                            </article>
                        ))}
                    </div>
                ) : (
                    <div className="mt-4 rounded-xl border border-dashed border-border px-5 py-10 text-center">
                        <ListChecks className="mx-auto h-6 w-6 text-muted-foreground" />
                        <p className="mt-2 text-sm font-semibold">No lifecycle templates</p>
                        <p className="mt-1 text-xs text-muted-foreground">Create one before HR lifecycle events can generate coordinated IT work.</p>
                    </div>
                )}
            </section>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl">
                    <form onSubmit={save}>
                        <DialogHeader>
                            <DialogTitle>{editingId ? 'Edit lifecycle template' : 'New lifecycle template'}</DialogTitle>
                            <DialogDescription>
                                Keep HR as the identity owner. Select only the minimum fields each fulfiller needs, and link asset/device recovery to canonical assignments.
                            </DialogDescription>
                        </DialogHeader>
                        {Object.keys(form.errors).length > 0 ? (
                            <div className="mt-4 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-xs text-destructive">
                                Please correct the highlighted or invalid template fields. {form.errors.tasks}
                            </div>
                        ) : null}
                        <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="Template name" className="sm:col-span-2">
                                <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required maxLength={255} />
                            </Field>
                            <Field label="Lifecycle">
                                <ValueSelect value={form.data.lifecycle_type} values={LIFECYCLES} onChange={(value) => form.setData('lifecycle_type', value)} />
                            </Field>
                            <Field label="Selection priority">
                                <Input type="number" value={form.data.selection_priority} onChange={(event) => form.setData('selection_priority', Number(event.target.value))} />
                            </Field>
                            <Field label="Description" className="sm:col-span-2 lg:col-span-4">
                                <Textarea value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} rows={2} />
                            </Field>
                            <Field label="Role match" hint="optional">
                                <Select value={form.data.position_role || ANY} onValueChange={(value) => form.setData('position_role', value === ANY ? '' : value)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value={ANY}>Any role</SelectItem>{roleOptions.map((value) => <SelectItem key={value} value={value}>{label(value)}</SelectItem>)}</SelectContent>
                                </Select>
                            </Field>
                            <Field label="Site match">
                                <Select value={form.data.site_id === '' ? ANY : String(form.data.site_id)} onValueChange={(value) => form.setData('site_id', value === ANY ? '' : Number(value))}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value={ANY}>Any site</SelectItem>{sites.map((site) => <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </Field>
                            <Field label="Employment match">
                                <Select value={form.data.employment_type || ANY} onValueChange={(value) => form.setData('employment_type', value === ANY ? '' : value)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value={ANY}>Any type</SelectItem>{EMPLOYMENT_TYPES.map((value) => <SelectItem key={value} value={value}>{label(value)}</SelectItem>)}</SelectContent>
                                </Select>
                            </Field>
                            <label className="flex min-h-9 items-center gap-2 self-end rounded-lg border border-border px-3 text-xs font-medium">
                                <Checkbox checked={form.data.is_active} onCheckedChange={(checked) => form.setData('is_active', checked === true)} /> Active
                            </label>
                        </div>

                        <div className="mt-6 flex items-center justify-between gap-3">
                            <div><h3 className="text-sm font-bold">Workflow steps</h3><p className="text-[11px] text-muted-foreground">Same stage runs in parallel; dependencies must point to an earlier stage.</p></div>
                            <Button type="button" size="sm" variant="outline" onClick={() => form.setData('tasks', [...form.data.tasks, blankTask(form.data.tasks.length)])}>
                                <Plus className="h-3.5 w-3.5" /> Add step
                            </Button>
                        </div>
                        <div className="mt-3 grid gap-3">
                            {form.data.tasks.map((task, index) => (
                                <div key={index} className="rounded-xl border border-border p-3.5">
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-xs font-bold">Step {index + 1}</p>
                                        <Button type="button" size="icon" variant="ghost" disabled={form.data.tasks.length === 1} onClick={() => form.setData('tasks', form.data.tasks.filter((_, taskIndex) => taskIndex !== index))} aria-label={`Remove step ${index + 1}`}>
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                    <div className="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <Field label="Task key"><Input value={task.task_key} onChange={(event) => updateTask(index, 'task_key', event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'))} required /></Field>
                                        <Field label="Title" className="sm:col-span-1 lg:col-span-3"><Input value={task.title} onChange={(event) => updateTask(index, 'title', event.target.value)} required /></Field>
                                        <Field label="Instructions" hint="optional" className="sm:col-span-2 lg:col-span-4"><Textarea value={task.description ?? ''} onChange={(event) => updateTask(index, 'description', event.target.value || null)} rows={2} /></Field>
                                        <Field label="Category"><ValueSelect value={task.category} values={CATEGORIES} onChange={(value) => updateTask(index, 'category', value)} /></Field>
                                        <Field label="Action"><ValueSelect value={task.action} values={ACTIONS} onChange={(value) => updateTask(index, 'action', value)} /></Field>
                                        <Field label="Request type"><ValueSelect value={task.request_type} values={REQUEST_TYPES} onChange={(value) => updateTask(index, 'request_type', value)} /></Field>
                                        <Field label="Responsible team">
                                            <Select value={task.responsible_team_id === '' ? ANY : String(task.responsible_team_id)} onValueChange={(value) => updateTask(index, 'responsible_team_id', value === ANY ? '' : Number(value))}>
                                                <SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value={ANY}>Unassigned</SelectItem>{teams.map((team) => <SelectItem key={team.id} value={String(team.id)}>{team.name}</SelectItem>)}</SelectContent>
                                            </Select>
                                        </Field>
                                        <Field label="Stage"><Input type="number" min={1} max={50} value={task.stage} onChange={(event) => updateTask(index, 'stage', Number(event.target.value))} /></Field>
                                        <Field label="Order within stage"><Input type="number" min={0} max={1000} value={task.sort_order} onChange={(event) => updateTask(index, 'sort_order', Number(event.target.value))} /></Field>
                                        <Field label="Due offset (days)"><Input type="number" min={-365} max={365} value={task.due_offset_days} onChange={(event) => updateTask(index, 'due_offset_days', Number(event.target.value))} /></Field>
                                        <div className="flex items-end gap-4 pb-2 lg:col-span-2">
                                            <Check label="Approval required" checked={task.approval_required} onChange={(checked) => updateTask(index, 'approval_required', checked)} />
                                            <Check label="Evidence required" checked={task.evidence_required} onChange={(checked) => updateTask(index, 'evidence_required', checked)} />
                                        </div>
                                        <ChoiceGroup label="Depends on" values={form.data.tasks.slice(0, index).map((candidate) => candidate.task_key)} selected={task.dependency_task_keys} onToggle={(value, checked) => toggleList(index, 'dependency_task_keys', value, checked)} empty="No earlier steps" />
                                        {form.data.lifecycle_type === 'mover' ? <ChoiceGroup label="Run when changed" values={TRIGGER_FIELDS} selected={task.trigger_fields} onToggle={(value, checked) => toggleList(index, 'trigger_fields', value, checked)} /> : null}
                                        <ChoiceGroup label="Minimum employee details shown" values={FULFILLER_FIELDS} selected={task.fulfiller_fields} onToggle={(value, checked) => toggleList(index, 'fulfiller_fields', value, checked)} className={form.data.lifecycle_type === 'mover' ? 'lg:col-span-2' : 'lg:col-span-3'} />
                                    </div>
                                </div>
                            ))}
                        </div>
                        <DialogFooter className="mt-6">
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save template'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Field({ label: fieldLabel, hint, className = '', children }: { label: string; hint?: string; className?: string; children: ReactNode }) {
    return <label className={`grid gap-1.5 text-xs font-semibold text-foreground ${className}`}><span>{fieldLabel}{hint ? <span className="ml-1 font-normal text-muted-foreground">({hint})</span> : null}</span>{children}</label>;
}

function ValueSelect({ value, values, onChange }: { value: string; values: string[]; onChange: (value: string) => void }) {
    return <Select value={value} onValueChange={onChange}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{values.map((item) => <SelectItem key={item} value={item}>{label(item)}</SelectItem>)}</SelectContent></Select>;
}

function Check({ label: checkLabel, checked, onChange }: { label: string; checked: boolean; onChange: (checked: boolean) => void }) {
    return <label className="flex items-center gap-2 text-xs font-medium"><Checkbox checked={checked} onCheckedChange={(value) => onChange(value === true)} />{checkLabel}</label>;
}

function ChoiceGroup({ label: groupLabel, values, selected, onToggle, empty, className = '' }: { label: string; values: string[]; selected: string[]; onToggle: (value: string, checked: boolean) => void; empty?: string; className?: string }) {
    return <fieldset className={`rounded-lg border border-border p-2.5 ${className}`}><legend className="px-1 text-[11px] font-bold">{groupLabel}</legend><div className="flex flex-wrap gap-x-3 gap-y-2">{values.length ? values.map((value) => <Check key={value} label={label(value)} checked={selected.includes(value)} onChange={(checked) => onToggle(value, checked)} />) : <span className="text-[11px] text-muted-foreground">{empty}</span>}</div></fieldset>;
}
