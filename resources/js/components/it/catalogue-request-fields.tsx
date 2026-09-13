import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    CatalogueAttachmentField,
    catalogueFiles,
} from './catalogue-attachments';
import { CatalogueEntityPicker } from './catalogue-entity-picker';
export type CatalogValue = string | number | boolean | string[] | File[] | null;
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
    site_options?: { id: number; name: string }[];
    can_request_for_others?: boolean;
}

export interface CatalogFieldOption {
    id: number;
    name: string;
    detail: string | null;
}

export type CatalogEntityFieldType = 'employee' | 'user' | 'asset';
export type CatalogFieldOptions = Record<
    CatalogEntityFieldType,
    CatalogFieldOption[]
>;

function optionValue(option: CatalogOption): string {
    return String(typeof option === 'string' ? option : option.value);
}

function optionLabel(option: CatalogOption): string {
    return typeof option === 'string' ? option : option.label;
}

export function CatalogFieldControl({
    actorId,
    itemId,
    schemaVersion,
    disabled,
    field,
    options,
    value,
    error,
    onChange,
    onSelectedOption,
    onAccessLost,
    onSessionLost,
}: {
    actorId: number;
    itemId: number;
    schemaVersion: number;
    disabled: boolean;
    field: CatalogField;
    options: CatalogFieldOption[];
    value: CatalogValue | undefined;
    error?: string;
    onChange: (value: CatalogValue) => void;
    onSelectedOption?: (value: CatalogFieldOption | null) => void;
    onAccessLost?: () => void;
    onSessionLost?: () => void;
}) {
    const id = `catalog-field-${field.key}`;
    const isInternal = ['internal', 'restricted'].includes(
        field.visibility ?? 'requester',
    );
    const describedBy = [
        isInternal ? `${id}-visibility` : null,
        field.help ? `${id}-help` : null,
        error ? `${id}-error` : null,
    ]
        .filter(Boolean)
        .join(' ');
    const shared = {
        id,
        disabled,
        required: field.required,
        'aria-invalid': Boolean(error),
        'aria-describedby': describedBy || undefined,
    };
    const visibilityHelp = isInternal ? (
        <p id={`${id}-visibility`} className="text-xs text-muted-foreground">
            Internal IT detail. Only authorised IT staff can see this answer.
        </p>
    ) : null;

    let control;
    if (field.type === 'attachment') {
        control = (
            <CatalogueAttachmentField
                id={id}
                label={field.label}
                files={catalogueFiles(value)}
                max={field.max}
                disabled={disabled}
                required={field.required}
                describedBy={describedBy || undefined}
                error={error}
                onChange={onChange}
            />
        );
    } else if (field.type === 'textarea') {
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
                                disabled={disabled}
                                aria-invalid={Boolean(error)}
                                aria-describedby={describedBy || undefined}
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
    } else if (['employee', 'user', 'asset'].includes(field.type ?? '')) {
        control = (
            <CatalogueEntityPicker
                actorId={actorId}
                itemId={itemId}
                schemaVersion={schemaVersion}
                fieldKey={field.key}
                label={field.label}
                id={id}
                required={field.required}
                disabled={disabled}
                invalid={Boolean(error)}
                describedBy={describedBy || undefined}
                value={
                    value === '' || value === null || value === undefined
                        ? null
                        : Number(value)
                }
                initialSelected={options.find(
                    (option) => option.id === Number(value),
                )}
                onChange={(next) => onChange(next ?? '')}
                onSelectedOption={onSelectedOption}
                onAccessLost={onAccessLost}
                onSessionLost={onSessionLost}
            />
        );
    } else if (field.type === 'boolean') {
        control = (
            <label htmlFor={id} className="flex min-h-11 items-center gap-2">
                <input
                    id={id}
                    type="checkbox"
                    disabled={disabled}
                    aria-invalid={Boolean(error)}
                    aria-describedby={describedBy || undefined}
                    checked={Boolean(value)}
                    onChange={(event) => onChange(event.target.checked)}
                />
                <span className="text-sm">Yes</span>
            </label>
        );
    } else {
        const numeric = ['integer', 'number'].includes(field.type ?? '');
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
                {visibilityHelp}
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
            {visibilityHelp}
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
