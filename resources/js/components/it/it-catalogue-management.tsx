import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import {
    BookOpenCheck,
    CheckCircle2,
    CircleDashed,
    Pencil,
    Plus,
    Send,
    Trash2,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';

export interface CatalogManagementField {
    key: string;
    label: string;
    type: string;
    required: boolean;
    visibility: string;
    options?: string[];
    min?: number | '';
    max?: number | '';
    help?: string;
}

export interface CatalogManagementItem {
    id: number;
    it_service_id: number | null;
    service_name: string | null;
    name: string;
    slug: string;
    description: string | null;
    outcome_type: string;
    category: string;
    provisioning_type: string | null;
    default_priority: string;
    requires_approval: boolean;
    is_published: boolean;
    internal_only: boolean;
    form_schema_version: number;
    form_schema: { fields?: CatalogManagementField[] };
    search_terms: string[];
    sort_order: number;
    submission_count: number;
}

export interface CatalogServiceOption {
    id: number;
    name: string;
}

interface Props {
    items: CatalogManagementItem[];
    services: CatalogServiceOption[];
}

const FIELD_TYPES = [
    'text',
    'textarea',
    'email',
    'date',
    'integer',
    'number',
    'boolean',
    'select',
    'multiselect',
    'employee',
    'user',
    'asset',
];
const OUTCOMES = ['service_request', 'security_request', 'provisioning'];
const CATEGORIES = ['hardware', 'account', 'network', 'other'];
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const PROVISIONING_TYPES = ['account', 'access', 'equipment', 'other'];
const humanize = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());

const emptyField = (index: number): CatalogManagementField => ({
    key: `field_${index + 1}`,
    label: '',
    type: 'text',
    required: false,
    visibility: 'requester',
    options: [],
    help: '',
});

const normaliseFields = (
    fields: CatalogManagementField[] = [],
): CatalogManagementField[] =>
    fields.map((field) => ({
        ...field,
        options: (field.options ?? []).map((option) =>
            typeof option === 'string' ? option : String(option),
        ),
        help: field.help ?? '',
        min: field.min ?? '',
        max: field.max ?? '',
    }));

export function ItCatalogueManagement({ items, services }: Props) {
    const [editing, setEditing] = useState<CatalogManagementItem | null>(null);
    const [editorOpen, setEditorOpen] = useState(false);
    const [publishing, setPublishing] = useState<CatalogManagementItem | null>(
        null,
    );
    const [unpublishing, setUnpublishing] =
        useState<CatalogManagementItem | null>(null);
    const form = useForm({
        it_service_id: '',
        name: '',
        description: '',
        outcome_type: 'service_request',
        category: 'other',
        provisioning_type: '',
        default_priority: 'normal',
        requires_approval: false,
        internal_only: false,
        search_terms: [] as string[],
        sort_order: 0,
        form_schema: { fields: [] as CatalogManagementField[] },
    });
    const unpublishForm = useForm({ reason: '' });

    const openEditor = (item?: CatalogManagementItem) => {
        setEditing(item ?? null);
        form.clearErrors();
        form.setData({
            it_service_id: String(item?.it_service_id ?? ''),
            name: item?.name ?? '',
            description: item?.description ?? '',
            outcome_type: item?.outcome_type ?? 'service_request',
            category: item?.category ?? 'other',
            provisioning_type: item?.provisioning_type ?? '',
            default_priority: item?.default_priority ?? 'normal',
            requires_approval: item?.requires_approval ?? false,
            internal_only: item?.internal_only ?? false,
            search_terms: item?.search_terms ?? [],
            sort_order: item?.sort_order ?? 0,
            form_schema: {
                fields: normaliseFields(item?.form_schema.fields),
            },
        });
        setEditorOpen(true);
    };

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            search_terms: data.search_terms.filter(Boolean),
        }));
        const options = {
            preserveScroll: true,
            onSuccess: () => setEditorOpen(false),
        };
        if (editing) {
            form.patch(`/it/setup/catalogue-items/${editing.id}`, options);
        } else {
            form.post('/it/setup/catalogue-items', options);
        }
    };

    const setField = (
        index: number,
        patch: Partial<CatalogManagementField>,
    ) => {
        const fields = [...form.data.form_schema.fields];
        fields[index] = { ...fields[index], ...patch };
        form.setData('form_schema', { fields });
    };

    const removeField = (index: number) => {
        form.setData('form_schema', {
            fields: form.data.form_schema.fields.filter(
                (_, fieldIndex) => fieldIndex !== index,
            ),
        });
    };

    const publish = () => {
        if (!publishing) return;
        router.post(
            `/it/setup/catalogue-items/${publishing.id}/publish`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setPublishing(null),
            },
        );
    };

    const unpublish = (event: FormEvent) => {
        event.preventDefault();
        if (!unpublishing) return;
        unpublishForm.post(
            `/it/setup/catalogue-items/${unpublishing.id}/unpublish`,
            {
                preserveScroll: true,
                onSuccess: () => setUnpublishing(null),
            },
        );
    };

    return (
        <section className="space-y-4" aria-labelledby="catalogue-setup-title">
            <header className="flex flex-col justify-between gap-3 rounded-2xl border border-border bg-card p-5 shadow-sm md:flex-row md:items-center">
                <div className="flex items-start gap-3">
                    <span className="grid h-11 w-11 flex-none place-items-center rounded-xl bg-primary/10 text-primary">
                        <BookOpenCheck className="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                        <h2
                            id="catalogue-setup-title"
                            className="text-lg font-bold"
                        >
                            Service catalogue
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Build request forms as drafts, test their fields,
                            and publish them when they are ready for staff.
                        </p>
                    </div>
                </div>
                <Button className="min-h-11" onClick={() => openEditor()}>
                    <Plus className="h-4 w-4" aria-hidden="true" />
                    New request form
                </Button>
            </header>

            {items.length ? (
                <div className="grid gap-4 xl:grid-cols-2">
                    {items.map((item) => (
                        <article
                            key={item.id}
                            className="rounded-2xl border border-border bg-card p-5 shadow-sm"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex items-center gap-2">
                                        {item.is_published ? (
                                            <CheckCircle2
                                                className="text-status-good h-4 w-4"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <CircleDashed
                                                className="h-4 w-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        )}
                                        <StatusBadge
                                            variant={
                                                item.is_published
                                                    ? 'success'
                                                    : 'neutral'
                                            }
                                            size="sm"
                                        >
                                            {item.is_published
                                                ? 'Published'
                                                : 'Draft'}
                                        </StatusBadge>
                                        <StatusBadge variant="info" size="sm">
                                            {humanize(item.outcome_type)}
                                        </StatusBadge>
                                    </div>
                                    <h3 className="mt-3 font-semibold">
                                        {item.name}
                                    </h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {item.description ??
                                            'No description has been added.'}
                                    </p>
                                </div>
                            </div>
                            <dl className="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-muted/35 p-3 text-sm sm:grid-cols-4">
                                <Metric
                                    label="Form version"
                                    value={`v${item.form_schema_version}`}
                                />
                                <Metric
                                    label="Fields"
                                    value={String(
                                        item.form_schema.fields?.length ?? 0,
                                    )}
                                />
                                <Metric
                                    label="Submissions"
                                    value={String(item.submission_count)}
                                />
                                <Metric
                                    label="Service"
                                    value={item.service_name ?? 'Not linked'}
                                />
                            </dl>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => openEditor(item)}
                                >
                                    <Pencil
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Edit
                                </Button>
                                {item.is_published ? (
                                    <Button
                                        variant="outline"
                                        className="min-h-11"
                                        onClick={() => {
                                            unpublishForm.reset();
                                            unpublishForm.clearErrors();
                                            setUnpublishing(item);
                                        }}
                                    >
                                        Return to draft
                                    </Button>
                                ) : (
                                    <Button
                                        className="min-h-11"
                                        onClick={() => setPublishing(item)}
                                    >
                                        <Send
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                        Publish
                                    </Button>
                                )}
                            </div>
                        </article>
                    ))}
                </div>
            ) : (
                <div className="rounded-2xl border border-dashed border-border bg-card px-6 py-14 text-center">
                    <p className="font-semibold">No request forms yet</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Create a draft form, then publish it when it is ready.
                    </p>
                </div>
            )}

            <Dialog open={editorOpen} onOpenChange={setEditorOpen}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-4xl">
                    <form onSubmit={save}>
                        <DialogHeader>
                            <DialogTitle>
                                {editing
                                    ? `Edit ${editing.name}`
                                    : 'New request form'}
                            </DialogTitle>
                            <DialogDescription>
                                Saving creates a draft. Publishing is a
                                separate, deliberate action.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="mt-5 grid gap-4 md:grid-cols-2">
                            <Field
                                label="Request name"
                                error={form.errors.name}
                            >
                                <Input
                                    required
                                    className="min-h-11"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Linked service"
                                error={form.errors.it_service_id}
                            >
                                <select
                                    className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                                    value={form.data.it_service_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'it_service_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">No linked service</option>
                                    {services.map((service) => (
                                        <option
                                            key={service.id}
                                            value={service.id}
                                        >
                                            {service.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Outcome">
                                <Select
                                    value={form.data.outcome_type}
                                    options={OUTCOMES}
                                    onChange={(value) =>
                                        form.setData('outcome_type', value)
                                    }
                                />
                            </Field>
                            <Field label="Category">
                                <Select
                                    value={form.data.category}
                                    options={CATEGORIES}
                                    onChange={(value) =>
                                        form.setData('category', value)
                                    }
                                />
                            </Field>
                            {form.data.outcome_type === 'provisioning' ? (
                                <Field
                                    label="Provisioning type"
                                    error={form.errors.provisioning_type}
                                >
                                    <Select
                                        value={form.data.provisioning_type}
                                        options={PROVISIONING_TYPES}
                                        onChange={(value) =>
                                            form.setData(
                                                'provisioning_type',
                                                value,
                                            )
                                        }
                                    />
                                </Field>
                            ) : null}
                            <Field label="Default priority">
                                <Select
                                    value={form.data.default_priority}
                                    options={PRIORITIES}
                                    onChange={(value) =>
                                        form.setData('default_priority', value)
                                    }
                                />
                            </Field>
                            <Field label="Search terms (comma separated)">
                                <Input
                                    className="min-h-11"
                                    value={form.data.search_terms.join(', ')}
                                    onChange={(event) =>
                                        form.setData(
                                            'search_terms',
                                            event.target.value
                                                .split(',')
                                                .map((term) => term.trim()),
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Display order">
                                <Input
                                    type="number"
                                    min={0}
                                    className="min-h-11"
                                    value={form.data.sort_order}
                                    onChange={(event) =>
                                        form.setData(
                                            'sort_order',
                                            Number(event.target.value),
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Description"
                                className="md:col-span-2"
                                error={form.errors.description}
                            >
                                <Textarea
                                    rows={3}
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>

                        <div className="mt-5 flex flex-wrap gap-4 rounded-xl border border-border p-3">
                            <Checkbox
                                label="Approval required"
                                checked={form.data.requires_approval}
                                onChange={(checked) =>
                                    form.setData('requires_approval', checked)
                                }
                            />
                            <Checkbox
                                label="IT staff only"
                                checked={form.data.internal_only}
                                onChange={(checked) =>
                                    form.setData('internal_only', checked)
                                }
                            />
                        </div>

                        <section
                            className="mt-6 space-y-3"
                            aria-labelledby="form-fields-title"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h3
                                        id="form-fields-title"
                                        className="font-semibold"
                                    >
                                        Form fields
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        Employee, user, and asset fields use
                                        safe named choices rather than record
                                        numbers.
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() =>
                                        form.setData('form_schema', {
                                            fields: [
                                                ...form.data.form_schema.fields,
                                                emptyField(
                                                    form.data.form_schema.fields
                                                        .length,
                                                ),
                                            ],
                                        })
                                    }
                                >
                                    <Plus
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Add field
                                </Button>
                            </div>
                            {form.data.form_schema.fields.map(
                                (field, index) => (
                                    <div
                                        key={`${index}-${field.key}`}
                                        className="rounded-xl border border-border p-4"
                                    >
                                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                            <Field label="Field key">
                                                <Input
                                                    className="min-h-11"
                                                    value={field.key}
                                                    onChange={(event) =>
                                                        setField(index, {
                                                            key: event.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            </Field>
                                            <Field label="Question or label">
                                                <Input
                                                    className="min-h-11"
                                                    value={field.label}
                                                    onChange={(event) =>
                                                        setField(index, {
                                                            label: event.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            </Field>
                                            <Field label="Field type">
                                                <Select
                                                    value={field.type}
                                                    options={FIELD_TYPES}
                                                    onChange={(value) =>
                                                        setField(index, {
                                                            type: value,
                                                            options: [],
                                                        })
                                                    }
                                                />
                                            </Field>
                                            <Field label="Visible to">
                                                <Select
                                                    value={field.visibility}
                                                    options={[
                                                        'requester',
                                                        'internal',
                                                        'restricted',
                                                    ]}
                                                    onChange={(value) =>
                                                        setField(index, {
                                                            visibility: value,
                                                        })
                                                    }
                                                />
                                            </Field>
                                        </div>
                                        <div className="mt-3 grid gap-3 md:grid-cols-[1fr_auto_auto] md:items-end">
                                            {['select', 'multiselect'].includes(
                                                field.type,
                                            ) ? (
                                                <Field label="Choices (comma separated)">
                                                    <Input
                                                        className="min-h-11"
                                                        value={(
                                                            field.options ?? []
                                                        ).join(', ')}
                                                        onChange={(event) =>
                                                            setField(index, {
                                                                options:
                                                                    event.target.value
                                                                        .split(
                                                                            ',',
                                                                        )
                                                                        .map(
                                                                            (
                                                                                option,
                                                                            ) =>
                                                                                option.trim(),
                                                                        )
                                                                        .filter(
                                                                            Boolean,
                                                                        ),
                                                            })
                                                        }
                                                    />
                                                </Field>
                                            ) : null}
                                            <Field label="Help text">
                                                <Input
                                                    className="min-h-11"
                                                    value={field.help ?? ''}
                                                    onChange={(event) =>
                                                        setField(index, {
                                                            help: event.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            </Field>
                                            <Checkbox
                                                label="Required"
                                                checked={field.required}
                                                onChange={(checked) =>
                                                    setField(index, {
                                                        required: checked,
                                                    })
                                                }
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="min-h-11 text-destructive"
                                                onClick={() =>
                                                    removeField(index)
                                                }
                                            >
                                                <Trash2
                                                    className="h-4 w-4"
                                                    aria-hidden="true"
                                                />
                                                Remove
                                            </Button>
                                        </div>
                                        {[
                                            'text',
                                            'textarea',
                                            'email',
                                            'integer',
                                            'number',
                                        ].includes(field.type) ? (
                                            <div className="mt-3 grid max-w-md gap-3 sm:grid-cols-2">
                                                <Field label="Minimum">
                                                    <Input
                                                        type="number"
                                                        min={0}
                                                        className="min-h-11"
                                                        value={field.min ?? ''}
                                                        onChange={(event) =>
                                                            setField(index, {
                                                                min:
                                                                    event.target
                                                                        .value ===
                                                                    ''
                                                                        ? ''
                                                                        : Number(
                                                                              event
                                                                                  .target
                                                                                  .value,
                                                                          ),
                                                            })
                                                        }
                                                    />
                                                </Field>
                                                <Field label="Maximum">
                                                    <Input
                                                        type="number"
                                                        min={1}
                                                        className="min-h-11"
                                                        value={field.max ?? ''}
                                                        onChange={(event) =>
                                                            setField(index, {
                                                                max:
                                                                    event.target
                                                                        .value ===
                                                                    ''
                                                                        ? ''
                                                                        : Number(
                                                                              event
                                                                                  .target
                                                                                  .value,
                                                                          ),
                                                            })
                                                        }
                                                    />
                                                </Field>
                                            </div>
                                        ) : null}
                                    </div>
                                ),
                            )}
                        </section>

                        {Object.keys(form.errors).length ? (
                            <p
                                className="mt-4 text-sm text-destructive"
                                role="alert"
                            >
                                Check the highlighted request form details
                                before saving.
                            </p>
                        ) : null}
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                onClick={() => setEditorOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={form.processing}
                            >
                                Save draft
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={publishing !== null}
                onOpenChange={(open) => !open && setPublishing(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Publish request</DialogTitle>
                        <DialogDescription>
                            {publishing?.name} will become available in the
                            service catalogue using form version{' '}
                            {publishing?.form_schema_version}.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setPublishing(null)}
                        >
                            Keep as draft
                        </Button>
                        <Button className="min-h-11" onClick={publish}>
                            Publish request
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={unpublishing !== null}
                onOpenChange={(open) => !open && setUnpublishing(null)}
            >
                <DialogContent>
                    <form onSubmit={unpublish}>
                        <DialogHeader>
                            <DialogTitle>Return request to draft</DialogTitle>
                            <DialogDescription>
                                New submissions will stop. Existing requests and
                                their form snapshots remain available.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5">
                            <Field
                                label="Reason for unpublishing"
                                error={unpublishForm.errors.reason}
                            >
                                <Textarea
                                    required
                                    rows={4}
                                    value={unpublishForm.data.reason}
                                    onChange={(event) =>
                                        unpublishForm.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                onClick={() => setUnpublishing(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={unpublishForm.processing}
                            >
                                Return to draft
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function Metric({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 truncate font-medium" title={value}>
                {value}
            </dd>
        </div>
    );
}

function Field({
    label,
    error,
    className = '',
    children,
}: {
    label: string;
    error?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <label className={`space-y-1.5 text-sm font-medium ${className}`}>
            <span>{label}</span>
            {children}
            {error ? (
                <span className="block text-xs text-destructive">{error}</span>
            ) : null}
        </label>
    );
}

function Select({
    value,
    options,
    onChange,
}: {
    value: string;
    options: string[];
    onChange: (value: string) => void;
}) {
    return (
        <select
            className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            {options.map((option) => (
                <option key={option} value={option}>
                    {humanize(option)}
                </option>
            ))}
        </select>
    );
}

function Checkbox({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="flex min-h-11 items-center gap-2 text-sm font-medium">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
            />
            {label}
        </label>
    );
}
