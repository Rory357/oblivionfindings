/**
 * Add / edit an incident template (approved H&S drawing pg-hsn-4, Stephan
 * 23 Oct 2026): a record header with Save template, then one Template card —
 * name, active, the incident type the report uses, a default severity, the
 * starting description and two lists of rows.
 */
import InputError from '@/components/input-error';
import {
    INCIDENT_TYPE_LABELS,
    sentenceCase,
} from '@/components/incidents/incident-stages';
import { EntityKebab, compactMenu } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderPrimaryButton,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, FileText, Plus, Trash2 } from 'lucide-react';
import { useId, type FormEvent } from 'react';

type Template = {
    id: number;
    name: string;
    type: string | null;
    severity: string | null;
    default_description: string | null;
    prompts: string[] | null;
    checklist: string[] | null;
    is_active: boolean;
};

type Props = {
    template: Template | null;
};

const NO_TYPE = 'none';
const NO_SEVERITY = 'none';

export default function IncidentTemplateEdit({ template }: Props) {
    const isNew = !template;
    const ids = useId();
    const form = useForm({
        name: template?.name ?? '',
        type: template?.type || NO_TYPE,
        severity: template?.severity || NO_SEVERITY,
        default_description: template?.default_description ?? '',
        prompts: template?.prompts ?? [],
        checklist: template?.checklist ?? [],
        is_active: template?.is_active ?? true,
    });
    // A saved free-text type stays selectable rather than being silently replaced.
    const savedType =
        template?.type && !INCIDENT_TYPE_LABELS[template.type]
            ? template.type
            : null;
    const typeOptions = [
        { value: NO_TYPE, label: 'Any type' },
        ...(savedType
            ? [{ value: savedType, label: `${sentenceCase(savedType)} (saved type)` }]
            : []),
        ...Object.entries(INCIDENT_TYPE_LABELS).map(([value, label]) => ({
            value,
            label,
        })),
    ];

    const save = (event?: FormEvent) => {
        event?.preventDefault();
        if (form.processing) return;
        form.transform((data) => ({
            ...data,
            type: data.type === NO_TYPE ? null : data.type,
            severity: data.severity === NO_SEVERITY ? null : data.severity,
            prompts: data.prompts.map((item) => item.trim()).filter(Boolean),
            checklist: data.checklist
                .map((item) => item.trim())
                .filter(Boolean),
        }));
        if (isNew) form.post('/incidents/templates');
        else if (template) form.put(`/incidents/templates/${template.id}`);
    };
    const title = isNew ? 'New template' : 'Edit template';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health and safety', href: '/health-safety' },
                { title: 'Incidents', href: '/incidents' },
                { title: 'Templates', href: '/incidents/templates' },
                {
                    title: template?.name ?? 'New template',
                    href: template
                        ? `/incidents/templates/${template.id}`
                        : '/incidents/templates/create',
                },
            ]}
        >
            <Head
                title={
                    isNew
                        ? 'New incident template'
                        : `Template · ${form.data.name || (template?.name ?? '')}`
                }
            />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/incidents/templates"
                        icon={FileText}
                        title={title}
                        subline="Pre-fills new incident reports"
                        actions={
                            <PageHeaderPrimaryButton
                                onClick={() => save()}
                                disabled={form.processing}
                            >
                                {form.processing ? 'Saving…' : 'Save template'}
                            </PageHeaderPrimaryButton>
                        }
                    />
                }
            >
                <form
                    onSubmit={save}
                    aria-label={title}
                    className="min-w-0 overflow-hidden rounded-[14px] border border-border bg-card text-card-foreground shadow-xs"
                >
                    <div className="border-b border-border px-4 py-3.5">
                        <h2 className="text-section-title">Template</h2>
                    </div>
                    <div className="grid gap-5 p-4 sm:px-9 sm:py-5 md:grid-cols-2">
                        <div className="grid gap-1.5 md:col-span-2 md:max-w-[calc(50%-0.625rem)]">
                            <Label htmlFor={`${ids}-name`}>
                                Name{' '}
                                <span aria-hidden="true" className="text-status-critical">
                                    *
                                </span>
                            </Label>
                            <Input
                                id={`${ids}-name`}
                                required
                                aria-required
                                aria-invalid={Boolean(form.errors.name)}
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <label className="flex items-center gap-2 text-sm font-semibold text-foreground md:col-span-2">
                            <Checkbox
                                checked={!!form.data.is_active}
                                onCheckedChange={(value) =>
                                    form.setData('is_active', value === true)
                                }
                            />
                            Active
                        </label>
                        <div className="grid gap-1.5">
                            <Label>Type (optional)</Label>
                            <SelectInput
                                ariaLabel="Type (optional)"
                                placeholder="Any type"
                                value={form.data.type}
                                onChange={(value) => form.setData('type', value)}
                                options={typeOptions}
                            />
                            <InputError message={form.errors.type} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Severity (optional)</Label>
                            <SelectInput
                                ariaLabel="Severity (optional)"
                                placeholder="No default"
                                value={form.data.severity}
                                onChange={(value) =>
                                    form.setData('severity', value)
                                }
                                options={[
                                    { value: NO_SEVERITY, label: 'No default' },
                                    { value: 'low', label: 'Low' },
                                    { value: 'medium', label: 'Medium' },
                                    { value: 'high', label: 'High' },
                                ]}
                            />
                            <InputError message={form.errors.severity} />
                        </div>
                        <div className="grid gap-1.5 md:col-span-2">
                            <Label htmlFor={`${ids}-description`}>
                                Starting description
                            </Label>
                            <Textarea
                                id={`${ids}-description`}
                                rows={3}
                                value={form.data.default_description}
                                onChange={(e) =>
                                    form.setData(
                                        'default_description',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.default_description} />
                        </div>
                        <RowsField
                            label="Questions to ask (optional)"
                            itemLabel="Question"
                            rows={form.data.prompts}
                            onChange={(rows) => form.setData('prompts', rows)}
                            error={form.errors.prompts}
                        />
                        <RowsField
                            label="Checklist items (optional)"
                            itemLabel="Checklist item"
                            rows={form.data.checklist}
                            onChange={(rows) => form.setData('checklist', rows)}
                            error={form.errors.checklist}
                        />
                    </div>
                    <button type="submit" hidden aria-hidden="true" tabIndex={-1} />
                </form>
            </PageLayout>
        </AppLayout>
    );
}

/** A repeating list of short rows with ⋯ (move, remove) and Add. */
function RowsField({
    label,
    itemLabel,
    rows,
    onChange,
    error,
}: {
    label: string;
    itemLabel: string;
    rows: string[];
    onChange: (rows: string[]) => void;
    error?: string;
}) {
    const labelId = useId();
    const move = (from: number, to: number) => {
        const next = [...rows];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item);
        onChange(next);
    };
    return (
        <div role="group" aria-labelledby={labelId} className="grid gap-2 md:col-span-2">
            <span id={labelId} className="text-sm font-semibold text-foreground">
                {label}
            </span>
            {rows.map((row, index) => (
                <div key={index} className="flex items-center gap-2">
                    <Input
                        aria-label={`${itemLabel} ${index + 1}`}
                        value={row}
                        onChange={(e) => {
                            const next = [...rows];
                            next[index] = e.target.value;
                            onChange(next);
                        }}
                    />
                    <EntityKebab
                        label={`Options for ${itemLabel.toLowerCase()} ${index + 1}`}
                        actions={compactMenu([
                            index > 0 && {
                                label: 'Move up',
                                icon: ArrowUp,
                                onClick: () => move(index, index - 1),
                            },
                            index < rows.length - 1 && {
                                label: 'Move down',
                                icon: ArrowDown,
                                onClick: () => move(index, index + 1),
                            },
                            { separator: true },
                            {
                                label: 'Remove',
                                icon: Trash2,
                                danger: true,
                                onClick: () =>
                                    onChange(rows.filter((_, i) => i !== index)),
                            },
                        ])}
                    />
                </div>
            ))}
            <div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => onChange([...rows, ''])}
                >
                    <Plus className="size-4" />
                    Add
                </Button>
            </div>
            <InputError message={error} />
        </div>
    );
}
