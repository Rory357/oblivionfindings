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
import { useForm } from '@inertiajs/react';
import { BookOpen, Search, Send } from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';

type CatalogValue = string | number | boolean | string[] | null;
type CatalogOption = string | { label: string; value: string | number };

export interface CatalogField {
    key: string;
    label: string;
    type?: string;
    required?: boolean;
    visibility?: string;
    options?: CatalogOption[];
    default?: CatalogValue;
    max?: number;
    min?: number;
    help?: string;
}

export interface CatalogItem {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    outcome_type: string;
    category: string;
    default_priority: string;
    requires_approval: boolean;
    form_schema_version: number;
    form_schema: { fields?: CatalogField[] };
}

interface Props {
    items: CatalogItem[];
}

const humanize = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());

const submissionKey = () =>
    `catalog-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;

function initialValues(item: CatalogItem): Record<string, CatalogValue> {
    return Object.fromEntries(
        (item.form_schema.fields ?? []).map((field) => [
            field.key,
            field.default ??
                (field.type === 'boolean'
                    ? false
                    : field.type === 'multiselect'
                      ? []
                      : ''),
        ]),
    );
}

export function ItServiceCatalogue({ items }: Props) {
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<CatalogItem | null>(null);
    const form = useForm<{
        schema_version: number;
        idempotency_key: string;
        values: Record<string, CatalogValue>;
    }>({
        schema_version: 1,
        idempotency_key: '',
        values: {},
    });

    const filtered = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase();
        if (!needle) return items;

        return items.filter((item) =>
            [item.name, item.description, item.category, item.outcome_type]
                .filter(Boolean)
                .some((value) =>
                    String(value).toLocaleLowerCase().includes(needle),
                ),
        );
    }, [items, query]);

    const open = (item: CatalogItem) => {
        setSelected(item);
        form.clearErrors();
        form.setData({
            schema_version: item.form_schema_version,
            idempotency_key: submissionKey(),
            values: initialValues(item),
        });
    };

    const close = () => {
        if (!form.processing) setSelected(null);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!selected) return;

        form.post(`/it/catalog/${selected.id}/submissions`, {
            preserveScroll: true,
            onSuccess: () => setSelected(null),
        });
    };

    const setValue = (key: string, value: CatalogValue) => {
        form.setData('values', { ...form.data.values, [key]: value });
    };

    return (
        <section
            className="space-y-4"
            aria-labelledby="service-catalogue-title"
        >
            <header className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                <div className="flex items-start gap-3">
                    <span className="grid h-11 w-11 flex-none place-items-center rounded-xl bg-primary/10 text-primary">
                        <BookOpen className="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                        <h2
                            id="service-catalogue-title"
                            className="text-xl font-bold tracking-tight"
                        >
                            Service catalogue
                        </h2>
                        <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                            Choose a supported request. The right form,
                            approval, priority, and fulfilment workflow are
                            applied automatically.
                        </p>
                    </div>
                </div>
                <label className="relative mt-4 block max-w-xl">
                    <span className="sr-only">Search service catalogue</span>
                    <Search
                        className="pointer-events-none absolute top-3.5 left-3 h-4 w-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search requests, systems, or support needs"
                        className="min-h-11 pl-10"
                    />
                </label>
            </header>

            {filtered.length ? (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {filtered.map((item) => (
                        <article
                            key={item.id}
                            className="flex min-h-56 flex-col rounded-2xl border border-border bg-card p-5 shadow-sm"
                        >
                            <div className="flex flex-wrap gap-2">
                                <StatusBadge variant="info" size="sm">
                                    {humanize(item.outcome_type)}
                                </StatusBadge>
                                <StatusBadge variant="neutral" size="sm">
                                    {humanize(item.category)}
                                </StatusBadge>
                                {item.requires_approval ? (
                                    <StatusBadge variant="warning" size="sm">
                                        Approval required
                                    </StatusBadge>
                                ) : null}
                            </div>
                            <h3 className="mt-4 font-semibold">{item.name}</h3>
                            <p className="mt-1 flex-1 text-sm leading-relaxed text-muted-foreground">
                                {item.description ??
                                    'Use this form to start the supported workflow.'}
                            </p>
                            <Button
                                className="mt-5 min-h-11 w-full"
                                onClick={() => open(item)}
                            >
                                {item.name}
                            </Button>
                        </article>
                    ))}
                </div>
            ) : (
                <div className="rounded-2xl border border-dashed border-border bg-card px-6 py-14 text-center">
                    <p className="font-semibold">No matching requests</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Try a system name or a broader description of what you
                        need.
                    </p>
                </div>
            )}

            <Dialog
                open={selected !== null}
                onOpenChange={(openState) => !openState && close()}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    {selected ? (
                        <form onSubmit={submit}>
                            <DialogHeader>
                                <DialogTitle>{selected.name}</DialogTitle>
                                <DialogDescription>
                                    Complete the published request form.
                                    Required fields are marked.
                                </DialogDescription>
                            </DialogHeader>

                            {Object.keys(form.errors).length ? (
                                <div
                                    role="alert"
                                    className="mt-4 rounded-xl border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
                                >
                                    <p className="font-semibold">
                                        Check the request details below.
                                    </p>
                                    <ul className="mt-1 list-disc pl-5">
                                        {Object.values(form.errors).map(
                                            (error) => (
                                                <li key={error}>{error}</li>
                                            ),
                                        )}
                                    </ul>
                                </div>
                            ) : null}

                            <div className="mt-5 space-y-4">
                                {(selected.form_schema.fields ?? []).map(
                                    (field) => (
                                        <CatalogFieldControl
                                            key={field.key}
                                            field={field}
                                            value={form.data.values[field.key]}
                                            error={
                                                form.errors[
                                                    `values.${field.key}`
                                                ]
                                            }
                                            onChange={(value) =>
                                                setValue(field.key, value)
                                            }
                                        />
                                    ),
                                )}
                            </div>

                            <DialogFooter className="mt-6">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={close}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    <Send
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Submit request
                                </Button>
                            </DialogFooter>
                        </form>
                    ) : null}
                </DialogContent>
            </Dialog>
        </section>
    );
}

function optionValue(option: CatalogOption): string {
    return String(typeof option === 'string' ? option : option.value);
}

function optionLabel(option: CatalogOption): string {
    return typeof option === 'string' ? option : option.label;
}

function CatalogFieldControl({
    field,
    value,
    error,
    onChange,
}: {
    field: CatalogField;
    value: CatalogValue | undefined;
    error?: string;
    onChange: (value: CatalogValue) => void;
}) {
    const id = `catalog-field-${field.key}`;
    const describedBy = [
        field.help ? `${id}-help` : null,
        error ? `${id}-error` : null,
    ]
        .filter(Boolean)
        .join(' ');
    const shared = {
        id,
        required: field.required,
        'aria-invalid': Boolean(error),
        'aria-describedby': describedBy || undefined,
    };

    let control;
    if (field.type === 'textarea') {
        control = (
            <Textarea
                {...shared}
                value={String(value ?? '')}
                maxLength={field.max}
                rows={4}
                onChange={(event) => onChange(event.target.value)}
            />
        );
    } else if (field.type === 'select') {
        control = (
            <select
                {...shared}
                className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={String(value ?? '')}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">Select an option</option>
                {(field.options ?? []).map((option) => (
                    <option
                        key={optionValue(option)}
                        value={optionValue(option)}
                    >
                        {optionLabel(option)}
                    </option>
                ))}
            </select>
        );
    } else if (field.type === 'multiselect') {
        const selected = Array.isArray(value) ? value.map(String) : [];
        control = (
            <div
                id={id}
                className="space-y-1 rounded-xl border border-border p-2"
            >
                {(field.options ?? []).map((option) => {
                    const optionId = `${id}-${optionValue(option).replace(/\W+/g, '-')}`;
                    const checked = selected.includes(optionValue(option));
                    return (
                        <label
                            key={optionId}
                            htmlFor={optionId}
                            className="flex min-h-11 items-center gap-2 rounded-lg px-2 text-sm hover:bg-muted/50"
                        >
                            <input
                                id={optionId}
                                type="checkbox"
                                checked={checked}
                                onChange={(event) =>
                                    onChange(
                                        event.target.checked
                                            ? [...selected, optionValue(option)]
                                            : selected.filter(
                                                  (item) =>
                                                      item !==
                                                      optionValue(option),
                                              ),
                                    )
                                }
                            />
                            {optionLabel(option)}
                        </label>
                    );
                })}
            </div>
        );
    } else if (field.type === 'boolean') {
        control = (
            <label htmlFor={id} className="flex min-h-11 items-center gap-2">
                <input
                    id={id}
                    type="checkbox"
                    checked={Boolean(value)}
                    onChange={(event) => onChange(event.target.checked)}
                />
                <span className="text-sm">Yes</span>
            </label>
        );
    } else {
        const numeric = [
            'integer',
            'number',
            'user',
            'asset',
            'employee',
        ].includes(field.type ?? '');
        control = (
            <Input
                {...shared}
                type={
                    field.type === 'email' || field.type === 'date'
                        ? field.type
                        : numeric
                          ? 'number'
                          : 'text'
                }
                value={String(value ?? '')}
                min={field.min}
                max={numeric ? field.max : undefined}
                maxLength={!numeric ? field.max : undefined}
                onChange={(event) =>
                    onChange(
                        numeric && event.target.value !== ''
                            ? Number(event.target.value)
                            : event.target.value,
                    )
                }
            />
        );
    }

    if (field.type === 'multiselect') {
        return (
            <fieldset
                className="space-y-1.5"
                aria-invalid={Boolean(error)}
                aria-describedby={describedBy || undefined}
            >
                <legend className="text-sm font-medium">
                    {field.label}
                    {field.required ? <span aria-hidden="true"> *</span> : null}
                </legend>
                {control}
                {field.help ? (
                    <p
                        id={`${id}-help`}
                        className="text-xs text-muted-foreground"
                    >
                        {field.help}
                    </p>
                ) : null}
                {error ? (
                    <p id={`${id}-error`} className="text-xs text-destructive">
                        {error}
                    </p>
                ) : null}
            </fieldset>
        );
    }

    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="block text-sm font-medium">
                {field.label}
                {field.required ? <span aria-hidden="true"> *</span> : null}
            </label>
            {control}
            {field.help ? (
                <p id={`${id}-help`} className="text-xs text-muted-foreground">
                    {field.help}
                </p>
            ) : null}
            {error ? (
                <p id={`${id}-error`} className="text-xs text-destructive">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
