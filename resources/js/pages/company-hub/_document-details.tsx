import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { CheckCircle2, Loader2, Save, Send } from 'lucide-react';
import { useEffect, useState } from 'react';
import { hubRequest } from './_api';
import { HubDateField } from './_date-fields';
import { HubSelect } from './_select';

type Choice = { id: number; name: string };
export type DocumentMetadata = {
    title: string;
    description: string;
    category: string;
    resource_type: string | null;
    collection_id: number | null;
    collection: string | null;
    owner_id: number;
    review_on: string | null;
    audience: { type: 'all' | 'sites' | 'departments'; ids: number[] };
    team_ids: number[];
    requires_approval: boolean;
};
export type GovernanceWarning = {
    field: string;
    code: string;
    message: string;
};
type Options = {
    actor_id: number;
    resource_types: string[];
    categories: string[];
    collections: Choice[];
    sites: Choice[];
    departments: Choice[];
};
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const stableValue = (value: unknown): unknown =>
    Array.isArray(value)
        ? value.map(stableValue)
        : value && typeof value === 'object'
          ? Object.fromEntries(
                Object.entries(value)
                    .sort(([left], [right]) => left.localeCompare(right))
                    .map(([key, item]) => [key, stableValue(item)]),
            )
          : value;
export const sameDocumentValue = (left: unknown, right: unknown) =>
    JSON.stringify(stableValue(left)) === JSON.stringify(stableValue(right));
export function validDocumentMetadata(
    value: unknown,
): value is DocumentMetadata {
    if (!value || typeof value !== 'object') return false;
    const item = value as DocumentMetadata;
    return (
        typeof item.title === 'string' &&
        typeof item.description === 'string' &&
        typeof item.category === 'string' &&
        positive(item.owner_id) &&
        (item.resource_type === null ||
            typeof item.resource_type === 'string') &&
        (item.collection_id === null || positive(item.collection_id)) &&
        (item.collection === null || typeof item.collection === 'string') &&
        (item.review_on === null ||
            /^\d{4}-\d{2}-\d{2}$/.test(item.review_on)) &&
        ['all', 'sites', 'departments'].includes(item.audience?.type) &&
        Array.isArray(item.audience?.ids) &&
        item.audience.ids.every(positive) &&
        Array.isArray(item.team_ids) &&
        item.team_ids.every(positive) &&
        typeof item.requires_approval === 'boolean'
    );
}

/** Send changed fields so an untouched legacy collection or page setting survives. */
export function documentMetadataChanges(
    original: DocumentMetadata,
    next: DocumentMetadata,
) {
    const result: Record<string, unknown> = {};
    for (const key of [
        'title',
        'description',
        'category',
        'resource_type',
        'collection_id',
        'owner_id',
        'review_on',
        'audience',
        'team_ids',
        'requires_approval',
    ] as const) {
        const value =
            typeof next[key] === 'string' ? next[key].trim() : next[key];
        if (!sameDocumentValue(original[key], value)) result[key] = value;
    }
    return result;
}

export function DocumentDetails({
    value,
    onChange,
    actor,
    owners,
    canChangeOwner,
    disabled,
    dirty,
    saving,
    canSubmit,
    canPublish,
    warnings,
    onSave,
    onSubmit,
    onPublish,
    issues = [],
}: {
    value: DocumentMetadata;
    onChange: (value: DocumentMetadata) => void;
    actor: number;
    owners: Choice[];
    canChangeOwner: boolean;
    disabled: boolean;
    dirty: boolean;
    saving: boolean;
    canSubmit: boolean;
    canPublish: boolean;
    warnings: GovernanceWarning[];
    issues?: { message: string }[];
    onSave: () => void;
    onSubmit: () => void;
    onPublish: () => void;
}) {
    const [options, setOptions] = useState<Options | null>(null);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        let active = true;
        void hubRequest<Options>('/company-hub/documents/options', 'GET')
            .then((result) => {
                const choices = (items: unknown): items is Choice[] =>
                    Array.isArray(items) &&
                    items.every(
                        (item) =>
                            item &&
                            positive(item.id) &&
                            typeof item.name === 'string',
                    );
                if (
                    !result ||
                    result.actor_id !== actor ||
                    !choices(result.collections) ||
                    !choices(result.sites) ||
                    !choices(result.departments) ||
                    !Array.isArray(result.resource_types) ||
                    !result.resource_types.every(
                        (item) => typeof item === 'string',
                    ) ||
                    !Array.isArray(result.categories) ||
                    !result.categories.every((item) => typeof item === 'string')
                )
                    throw new Error(
                        'The available document choices could not be confirmed for this account.',
                    );
                if (active) {
                    setOptions(result);
                    setError('');
                }
            })
            .catch((failure) => {
                if (active)
                    setError(
                        failure instanceof Error
                            ? failure.message
                            : 'Could not load document choices.',
                    );
            });
        return () => {
            active = false;
        };
    }, [actor, attempt]);
    const change = <K extends keyof DocumentMetadata>(
        key: K,
        next: DocumentMetadata[K],
    ) => onChange({ ...value, [key]: next });
    const audienceChoices =
        value.audience.type === 'sites'
            ? (options?.sites ?? [])
            : (options?.departments ?? []);
    const categories = Array.from(
        new Set([value.category, ...(options?.categories ?? [])]),
    );
    const collectionOptions = [
        { value: '', label: 'No collection' },
        ...(options?.collections ?? []).map((item) => ({
            value: String(item.id),
            label: item.name,
        })),
    ];
    if (
        value.collection_id &&
        !options?.collections.some((item) => item.id === value.collection_id)
    )
        collectionOptions.push({
            value: String(value.collection_id),
            label: value.collection ?? 'Current collection',
        });
    return (
        <section
            aria-label="Document details"
            className="hub-upload-file space-y-5"
        >
            <div>
                <h2 className="text-section-title">Details & sharing</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Make this document easy to find, then share its saved draft.
                </p>
            </div>
            <fieldset
                disabled={disabled}
                className="grid min-w-0 gap-4 sm:grid-cols-2"
            >
                <label className="space-y-1 text-sm font-medium sm:col-span-2">
                    Document title
                    <Input
                        value={value.title}
                        maxLength={160}
                        onChange={(event) =>
                            change('title', event.target.value)
                        }
                    />
                </label>
                <label className="space-y-1 text-sm font-medium sm:col-span-2">
                    Description
                    <Textarea
                        value={value.description}
                        rows={3}
                        maxLength={2000}
                        onChange={(event) =>
                            change('description', event.target.value)
                        }
                        placeholder="What will this help people do?"
                    />
                </label>
                <div className="space-y-1">
                    <p className="text-sm font-medium">Document type</p>
                    <HubSelect
                        label="Document type"
                        value={value.resource_type ?? ''}
                        disabled={disabled || !options}
                        onChange={(next) =>
                            change('resource_type', next || null)
                        }
                        options={[
                            { value: '', label: 'Choose a type' },
                            ...Array.from(
                                new Set([
                                    ...(value.resource_type
                                        ? [value.resource_type]
                                        : []),
                                    ...(options?.resource_types ?? []),
                                ]),
                            ).map((item) => ({ value: item, label: item })),
                        ]}
                    />
                </div>
                <div className="space-y-1">
                    <p className="text-sm font-medium">Category</p>
                    <HubSelect
                        label="Category"
                        value={value.category}
                        disabled={disabled || !options}
                        onChange={(next) => change('category', next)}
                        options={categories.map((item) => ({
                            value: item,
                            label: item,
                        }))}
                    />
                </div>
                <div className="space-y-1">
                    <p className="text-sm font-medium">Collection</p>
                    <HubSelect
                        label="Collection"
                        value={String(value.collection_id ?? '')}
                        disabled={disabled || !options}
                        onChange={(next) =>
                            change('collection_id', next ? Number(next) : null)
                        }
                        options={collectionOptions}
                    />
                    {value.collection && !value.collection_id && (
                        <p className="text-xs text-muted-foreground">
                            Current collection: {value.collection}. Choose a
                            collection to move it.
                        </p>
                    )}
                </div>
                <HubDateField
                    label="Next review date"
                    value={value.review_on ?? ''}
                    disabled={disabled}
                    onChange={(next) => change('review_on', next || null)}
                />
                <div className="space-y-1">
                    <p className="text-sm font-medium">Owner</p>
                    {canChangeOwner ? (
                        <HubSelect
                            label="Document owner"
                            value={String(value.owner_id)}
                            disabled={disabled}
                            onChange={(next) =>
                                change('owner_id', Number(next))
                            }
                            options={
                                owners.some(
                                    (item) => item.id === value.owner_id,
                                )
                                    ? owners.map((item) => ({
                                          value: String(item.id),
                                          label: item.name,
                                      }))
                                    : [
                                          {
                                              value: String(value.owner_id),
                                              label: 'Current owner',
                                          },
                                          ...owners.map((item) => ({
                                              value: String(item.id),
                                              label: item.name,
                                          })),
                                      ]
                            }
                        />
                    ) : (
                        <p className="py-2 text-sm text-muted-foreground">
                            {value.owner_id === actor
                                ? 'You maintain this document.'
                                : 'Contact a Hub editor to change the owner.'}
                        </p>
                    )}
                </div>
                <div className="space-y-1">
                    <p className="text-sm font-medium">Who can read it?</p>
                    <HubSelect
                        label="Document audience"
                        value={value.audience.type}
                        disabled={disabled || !options}
                        onChange={(next) =>
                            change('audience', {
                                type: next as DocumentMetadata['audience']['type'],
                                ids: [],
                            })
                        }
                        options={[
                            { value: 'all', label: 'All staff' },
                            { value: 'sites', label: 'Selected sites' },
                            {
                                value: 'departments',
                                label: 'Selected departments',
                            },
                        ]}
                    />
                </div>
                {value.audience.type !== 'all' && (
                    <div className="space-y-2 sm:col-span-2">
                        <p className="text-sm font-medium">
                            {value.audience.type === 'sites'
                                ? 'Sites'
                                : 'Departments'}
                        </p>
                        <div className="grid max-h-48 gap-1 overflow-y-auto sm:grid-cols-2">
                            {audienceChoices.map((item) => (
                                <label
                                    key={item.id}
                                    className="flex min-h-10 items-center gap-2 rounded-md px-2 text-sm hover:bg-muted"
                                >
                                    <input
                                        type="checkbox"
                                        checked={value.audience.ids.includes(
                                            item.id,
                                        )}
                                        onChange={(event) =>
                                            change('audience', {
                                                ...value.audience,
                                                ids: event.target.checked
                                                    ? [
                                                          ...value.audience.ids,
                                                          item.id,
                                                      ]
                                                    : value.audience.ids.filter(
                                                          (id) =>
                                                              id !== item.id,
                                                      ),
                                            })
                                        }
                                    />
                                    {item.name}
                                </label>
                            ))}
                        </div>
                        {!audienceChoices.length && (
                            <p className="text-sm text-muted-foreground">
                                No available choices. Choose another audience or
                                refresh the document.
                            </p>
                        )}
                    </div>
                )}
                {!!options?.departments.length && (
                    <details className="sm:col-span-2">
                        <summary className="cursor-pointer py-2 text-sm font-medium">
                            Related teams
                            {value.team_ids.length
                                ? ` (${value.team_ids.length})`
                                : ''}
                        </summary>
                        <div className="grid max-h-48 gap-1 overflow-y-auto sm:grid-cols-2">
                            {options.departments.map((item) => (
                                <label
                                    key={item.id}
                                    className="flex min-h-10 items-center gap-2 rounded-md px-2 text-sm hover:bg-muted"
                                >
                                    <input
                                        type="checkbox"
                                        checked={value.team_ids.includes(
                                            item.id,
                                        )}
                                        onChange={(event) =>
                                            change(
                                                'team_ids',
                                                event.target.checked
                                                    ? [
                                                          ...value.team_ids,
                                                          item.id,
                                                      ]
                                                    : value.team_ids.filter(
                                                          (id) =>
                                                              id !== item.id,
                                                      ),
                                            )
                                        }
                                    />
                                    {item.name}
                                </label>
                            ))}
                        </div>
                    </details>
                )}
                <label className="flex min-h-10 items-center gap-2 text-sm sm:col-span-2">
                    <input
                        type="checkbox"
                        checked={value.requires_approval}
                        onChange={(event) =>
                            change('requires_approval', event.target.checked)
                        }
                    />
                    Ask another publisher to approve this document
                </label>
            </fieldset>
            {error && (
                <div className="space-y-2 text-sm">
                    <p role="alert" className="text-destructive">
                        {error}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={saving}
                        onClick={() => setAttempt((current) => current + 1)}
                    >
                        Retry document choices
                    </Button>
                </div>
            )}
            {!options && !error && (
                <p className="text-sm text-muted-foreground">
                    Loading organisation choices…
                </p>
            )}
            {!!warnings.length && (
                <aside
                    aria-label="Ownership and review guidance"
                    className="space-y-2 rounded-lg bg-muted/60 p-3 text-sm text-muted-foreground"
                >
                    {warnings.map((warning) => (
                        <p key={warning.code}>{warning.message}</p>
                    ))}
                </aside>
            )}
            {!!issues.length && (
                <div className="space-y-1 text-sm text-muted-foreground">
                    {issues.map((issue, index) => (
                        <p key={index}>{issue.message}</p>
                    ))}
                </div>
            )}
            {dirty && (
                <p className="text-sm text-muted-foreground">
                    Save these details before submitting or publishing.
                </p>
            )}
            <div className="flex flex-wrap items-center gap-2 border-t pt-4">
                <Button
                    variant="outline"
                    disabled={disabled || !dirty || !value.title.trim()}
                    onClick={onSave}
                >
                    {saving ? (
                        <Loader2 className="size-4 animate-spin" />
                    ) : (
                        <Save className="size-4" />
                    )}
                    Save details
                </Button>
                <Button
                    variant={canPublish ? 'outline' : 'default'}
                    disabled={disabled || dirty || !canSubmit}
                    onClick={onSubmit}
                >
                    <Send className="size-4" />
                    Submit for review
                </Button>
                {canPublish && (
                    <Button disabled={disabled || dirty} onClick={onPublish}>
                        <CheckCircle2 className="size-4" />
                        Publish document
                    </Button>
                )}
            </div>
        </section>
    );
}
