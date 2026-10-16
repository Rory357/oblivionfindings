import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    Folder,
    FolderPlus,
    Loader2,
    Pencil,
    RefreshCw,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import { HubBrowseSelect } from './_browse-select';
import { useEditorLeave } from './_editor-leave';
import type {
    HubCollection,
    HubCollectionContext,
    HubContent,
    HubPageSummary,
} from './_types';

/** Counts belong to published parent resources, including when their files are listed separately. */
export function resourceCollectionChoices(
    pages: HubPageSummary[],
    context?: HubCollectionContext,
) {
    if (context)
        return [
            ...context.items.map((item) => ({
                ...item,
                key: `collection:${item.id}`,
            })),
            ...context.legacy_groups.map((item) => ({ ...item, id: null })),
        ].sort((a, b) => a.name.localeCompare(b.name));
    return [
        ...new Set(
            pages
                .map((page) => page.collection)
                .filter((name): name is string => !!name),
        ),
    ]
        .sort()
        .map((name) => ({
            key: name,
            id: null,
            name,
            count: new Set(
                pages
                    .filter((page) => page.collection === name)
                    .map((page) => page.id),
            ).size,
        }));
}

export function resourceInCollection(
    page: HubPageSummary,
    key: string,
    choices: ReturnType<typeof resourceCollectionChoices>,
) {
    if (key === 'all') return true;
    const choice = choices.find((item) => item.key === key);
    if (choice?.id) return page.collection_id === choice.id;
    if (choice) return !page.collection_id && page.collection === choice.name;
    // Older return links kept the name. New navigation retains identity across renames.
    return page.collection === key;
}

function useCollections(actorId: number | null, enabled = true) {
    const [context, setContext] = useState<HubCollectionContext | null>(null);
    const [loading, setLoading] = useState(enabled && !!actorId);
    const [error, setError] = useState('');
    const [refresh, setRefresh] = useState(0);
    useEffect(() => {
        if (!enabled || !actorId) return;
        let current = true;
        setLoading(true);
        setError('');
        hubRequest<HubCollectionContext>('/company-hub/collections', 'GET')
            .then((result) => {
                if (!current) return;
                if (result.actor_id !== actorId)
                    throw new Error(
                        'Your account has changed. Reopen collections to continue.',
                    );
                setContext(result);
            })
            .catch((e) => {
                if (current) {
                    setContext(null);
                    setError((e as Error).message);
                }
            })
            .finally(() => {
                if (current) setLoading(false);
            });
        return () => {
            current = false;
        };
    }, [actorId, enabled, refresh]);
    return {
        context,
        setContext,
        loading,
        error,
        refresh: () => setRefresh((value) => value + 1),
    };
}

export function ResourceCollectionPicker({
    actorId,
    content,
    onChange,
    preview = false,
}: {
    actorId: number | null;
    content: Pick<HubContent, 'collection' | 'collection_id'>;
    onChange: (
        changes: Pick<HubContent, 'collection' | 'collection_id'>,
    ) => void;
    preview?: boolean;
}) {
    return (
        <CollectionPickerBody
            key={`${actorId}:${preview}`}
            {...{ actorId, content, onChange, preview }}
        />
    );
}

function CollectionPickerBody({
    actorId,
    content,
    onChange,
    preview,
}: Parameters<typeof ResourceCollectionPicker>[0]) {
    const { context, loading, error, refresh } = useCollections(
        actorId,
        !preview,
    );
    if (preview || context?.ready === false)
        return (
            <div className="space-y-2">
                <Label htmlFor="resource-legacy-collection">
                    Resource collection
                </Label>
                <Input
                    id="resource-legacy-collection"
                    maxLength={60}
                    value={content.collection ?? ''}
                    onChange={(e) =>
                        onChange({
                            collection_id: null,
                            collection: e.target.value,
                        })
                    }
                    placeholder="For example, Getting started"
                />
            </div>
        );
    const selected = context?.options.find(
        (item) => item.id === content.collection_id,
    );
    const value = content.collection_id
        ? String(content.collection_id)
        : content.collection
          ? 'legacy'
          : 'none';
    return (
        <div className="space-y-2">
            <Label>Resource collection</Label>
            {loading ? (
                <p role="status" className="text-xs text-muted-foreground">
                    Loading collections…
                </p>
            ) : error || !context ? (
                <div role="alert" className="space-y-2 text-xs">
                    <p>
                        {error || 'Collections could not be loaded.'} Your saved
                        selection is kept.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={refresh}
                    >
                        <RefreshCw className="size-3.5" /> Try again
                    </Button>
                </div>
            ) : (
                <HubBrowseSelect
                    label="Resource collection"
                    value={value}
                    onChange={(next) => {
                        if (next === 'none')
                            onChange({ collection_id: null, collection: null });
                        else {
                            const choice = context.options.find(
                                (item) => String(item.id) === next,
                            );
                            if (choice)
                                onChange({
                                    collection_id: choice.id,
                                    collection: choice.name,
                                });
                        }
                    }}
                    options={[
                        { value: 'none', label: 'No collection' },
                        ...(!content.collection_id && content.collection
                            ? [
                                  {
                                      value: 'legacy',
                                      label: `Current group: ${content.collection}`,
                                  },
                              ]
                            : []),
                        ...(content.collection_id && !selected
                            ? [
                                  {
                                      value,
                                      label: 'Previously selected collection · unavailable',
                                  },
                              ]
                            : []),
                        ...context.options.map((item) => ({
                            value: String(item.id),
                            label: item.name,
                        })),
                    ]}
                />
            )}
            {context?.can_create && (
                <Link
                    href="/company-hub?view=resources&resource_workspace=collections"
                    className="inline-flex text-xs text-primary underline-offset-4 hover:underline"
                >
                    Manage collections
                </Link>
            )}
        </div>
    );
}

type Selection =
    | { kind: 'new'; name: string }
    | { kind: 'rename'; id: number; version: number; name: string }
    | {
          kind: 'adopt';
          key: string;
          adoption_key: string;
          name: string;
          count: number;
      };
type NextAction = Selection | { kind: 'back' };
const empty: Selection = { kind: 'new', name: '' };

export function ResourceCollectionWorkspace({
    onClose,
    onAdopt,
}: {
    onClose: () => void;
    onAdopt?: (legacyKey: string, collectionKey: string) => void;
}) {
    const actorId =
        (usePage().props.auth as { user?: { id: number } } | undefined)?.user
            ?.id ?? null;
    return (
        <CollectionWorkspaceBody
            key={actorId}
            actorId={actorId}
            onClose={onClose}
            onAdopt={onAdopt}
        />
    );
}

function CollectionWorkspaceBody({
    actorId,
    onClose,
    onAdopt,
}: {
    actorId: number | null;
    onClose: () => void;
    onAdopt?: (legacyKey: string, collectionKey: string) => void;
}) {
    const {
        context,
        setContext,
        loading,
        error: loadError,
        refresh,
    } = useCollections(actorId);
    const [selection, setSelection] = useState<Selection>(empty);
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [nextAction, setNextAction] = useState<NextAction | null>(null);
    const pending = useRef(false);
    const alive = useRef(true);
    const input = useRef<HTMLInputElement>(null);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const dirty = name !== selection.name;
    const current =
        selection.kind === 'rename'
            ? context?.items.find((item) => item.id === selection.id)
            : null;
    const currentGroup =
        selection.kind === 'adopt'
            ? context?.legacy_groups.find((item) => item.key === selection.key)
            : null;
    const canSave =
        !!context?.ready &&
        !loadError &&
        !loading &&
        (selection.kind === 'rename'
            ? current?.can_rename
            : selection.kind === 'adopt'
              ? currentGroup?.can_adopt &&
                currentGroup.adoption_key === selection.adoption_key
              : context.can_create);
    const choose = (next: NextAction) => {
        if (pending.current) return;
        if (dirty) {
            setNextAction(next);
            return;
        }
        apply(next);
    };
    const apply = (next: NextAction) => {
        setNextAction(null);
        if (next.kind === 'back') {
            onClose();
            return;
        }
        setSelection(next);
        setName(next.name);
        setError('');
        setMessage('');
        requestAnimationFrame(() => input.current?.focus());
    };
    const save = async () => {
        if (!canSave || pending.current || !name.trim() || !actorId)
            return false;
        pending.current = true;
        setBusy(true);
        setError('');
        setMessage('');
        try {
            const result = await hubRequest<{
                collection: HubCollection;
                resourceCollections: HubCollectionContext;
            }>(
                selection.kind === 'rename'
                    ? `/company-hub/collections/${selection.id}`
                    : selection.kind === 'adopt'
                      ? '/company-hub/collections/adopt'
                      : '/company-hub/collections',
                selection.kind === 'rename' ? 'PATCH' : 'POST',
                {
                    actor_id: actorId,
                    name: name.trim(),
                    ...(selection.kind === 'rename'
                        ? { version: selection.version }
                        : selection.kind === 'adopt'
                          ? {
                                key: selection.key,
                                adoption_key: selection.adoption_key,
                            }
                          : {}),
                },
            );
            if (!alive.current) return false;
            const confirmed = result.resourceCollections?.items.find(
                (item) => item.id === result.collection?.id,
            );
            if (
                result.resourceCollections?.actor_id !== actorId ||
                !confirmed ||
                confirmed.version !== result.collection.version ||
                confirmed.name !== result.collection.name ||
                confirmed.id <= 0 ||
                (selection.kind === 'rename' &&
                    (confirmed.id !== selection.id ||
                        confirmed.version <= selection.version))
            )
                throw new Error(
                    'The saved collection could not be confirmed. Refresh collections before making another change.',
                );
            setContext(result.resourceCollections);
            if (selection.kind === 'adopt')
                onAdopt?.(selection.key, `collection:${confirmed.id}`);
            setSelection({
                kind: 'rename',
                id: confirmed.id,
                version: confirmed.version,
                name: confirmed.name,
            });
            setName(confirmed.name);
            setMessage(
                selection.kind === 'rename'
                    ? 'Collection renamed.'
                    : 'Collection created. Choose it in a resource’s page settings to add more resources.',
            );
            setNextAction(null);
            return true;
        } catch (e) {
            if (alive.current) setError((e as Error).message);
            return false;
        } finally {
            pending.current = false;
            if (alive.current) setBusy(false);
        }
    };
    const leave = useEditorLeave({
        dirty,
        saving: busy,
        save,
        discardLocal: () => setName(selection.name),
        onBlocked: () => setError('Finish this change before continuing.'),
    });
    return (
        <section aria-label="Manage resource collections" className="space-y-6">
            <div className="flex items-center justify-between gap-4 border-b pb-4">
                <Button
                    variant="ghost"
                    disabled={busy}
                    onClick={() => choose({ kind: 'back' })}
                >
                    <ArrowLeft className="size-4" /> Browse resources
                </Button>
                <Button
                    variant="outline"
                    disabled={busy || loading}
                    onClick={refresh}
                >
                    <RefreshCw className="size-4" /> Refresh collections
                </Button>
            </div>
            {nextAction && (
                <div
                    role="alert"
                    className="flex flex-wrap items-center gap-3 rounded-lg border border-primary/20 bg-primary/5 p-4 text-sm"
                >
                    <p className="flex-1">
                        You have an unsaved collection name.
                    </p>
                    <Button
                        variant="outline"
                        onClick={() => setNextAction(null)}
                    >
                        Keep editing
                    </Button>
                    <Button variant="ghost" onClick={() => apply(nextAction)}>
                        Discard name and continue
                    </Button>
                </div>
            )}
            {(loadError || error) && (
                <p role="alert" className="text-sm text-destructive">
                    {error || loadError}
                </p>
            )}
            {message && (
                <p role="status" className="flex items-center gap-2 text-sm">
                    <Check className="size-4 text-primary" />
                    {message}
                </p>
            )}
            {loading && (
                <p
                    role="status"
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Loader2 className="size-4 animate-spin" />
                    Loading collections…
                </p>
            )}
            {context && !context.ready && (
                <p className="text-sm text-muted-foreground">
                    Shared collection editing is not available yet. Existing
                    resource groups are still available to browse.
                </p>
            )}
            {context && (
                <div className="grid grid-cols-[minmax(0,1fr)_minmax(280px,360px)] items-start gap-8">
                    <div className="min-w-0">
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-semibold">
                                    Collections
                                </h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Bring related guides and answers together.
                                </p>
                            </div>
                            {context.can_create && (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => choose(empty)}
                                >
                                    <FolderPlus className="size-4" />
                                    New collection
                                </Button>
                            )}
                        </div>
                        <div className="divide-y">
                            {context.items.map((item) => (
                                <div
                                    key={item.id}
                                    className={`flex min-w-0 items-center gap-4 py-4 ${selection.kind === 'rename' && selection.id === item.id ? 'bg-primary/5' : ''}`}
                                >
                                    <Folder className="ml-2 size-5 shrink-0 text-primary" />
                                    <div className="min-w-0 flex-1">
                                        <h4 className="font-medium break-words">
                                            {item.name}
                                        </h4>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {item.count}{' '}
                                            {item.count === 1
                                                ? 'resource'
                                                : 'resources'}{' '}
                                            available to you
                                        </p>
                                    </div>
                                    {item.can_rename && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={busy}
                                            aria-label={`Rename ${item.name}`}
                                            onClick={() =>
                                                choose({
                                                    kind: 'rename',
                                                    id: item.id,
                                                    version: item.version,
                                                    name: item.name,
                                                })
                                            }
                                        >
                                            <Pencil className="size-3.5" />
                                            Rename
                                        </Button>
                                    )}
                                </div>
                            ))}
                            {!context.items.length && (
                                <p className="py-6 text-sm text-muted-foreground">
                                    Create your first shared collection, then
                                    choose it in a resource’s page settings.
                                </p>
                            )}
                        </div>
                        {!!context.legacy_groups.length && (
                            <div className="mt-8">
                                <h4 className="text-sm font-semibold">
                                    Existing resource groups
                                </h4>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    These groups use names saved on individual
                                    resources.
                                </p>
                                <div className="mt-3 divide-y">
                                    {context.legacy_groups.map((group) => (
                                        <div
                                            key={group.key}
                                            className="flex items-center gap-3 py-4"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium break-words">
                                                    {group.name}
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {group.count}{' '}
                                                    {group.count === 1
                                                        ? 'resource'
                                                        : 'resources'}{' '}
                                                    available to you
                                                </p>
                                            </div>
                                            {group.can_adopt &&
                                                group.adoption_key && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={busy}
                                                        aria-label={`Manage ${group.name} as a collection`}
                                                        onClick={() =>
                                                            choose({
                                                                kind: 'adopt',
                                                                key: group.key,
                                                                adoption_key:
                                                                    group.adoption_key!,
                                                                name: group.name,
                                                                count: group.count,
                                                            })
                                                        }
                                                    >
                                                        Make a collection
                                                    </Button>
                                                )}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                    <form
                        className="min-w-0 space-y-5 border-l pl-6"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void save();
                        }}
                    >
                        <div>
                            <p className="text-xs font-semibold text-primary">
                                Shared resource library
                            </p>
                            <h3 className="mt-2 text-lg font-semibold">
                                {selection.kind === 'rename'
                                    ? 'Rename collection'
                                    : 'Create a collection'}
                            </h3>
                            <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                {selection.kind === 'rename'
                                    ? 'Choose a short, useful name colleagues will recognise.'
                                    : 'Give related resources a clear home.'}
                            </p>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="hub-collection-name">
                                Collection name
                            </Label>
                            <Input
                                ref={input}
                                id="hub-collection-name"
                                required
                                maxLength={60}
                                disabled={busy || !canSave}
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="For example, Getting started"
                            />
                        </div>
                        {selection.kind === 'adopt' && (
                            <p className="text-sm leading-6 text-muted-foreground">
                                This will connect the {selection.count}{' '}
                                {selection.count === 1
                                    ? 'resource'
                                    : 'resources'}{' '}
                                you can manage in “{selection.name}” to this
                                collection. Existing drafts keep their current
                                settings.
                            </p>
                        )}
                        {current &&
                            selection.kind === 'rename' &&
                            current.version !== selection.version && (
                                <div role="alert" className="space-y-2 text-sm">
                                    <p>
                                        This collection has changed. Your
                                        unfinished name has been kept.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            choose({
                                                kind: 'rename',
                                                id: current.id,
                                                version: current.version,
                                                name: current.name,
                                            })
                                        }
                                    >
                                        Use latest saved name
                                    </Button>
                                </div>
                            )}
                        <Button
                            disabled={
                                busy ||
                                !canSave ||
                                !name.trim() ||
                                (selection.kind === 'rename' && !dirty)
                            }
                        >
                            {busy && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            {selection.kind === 'rename'
                                ? 'Save collection'
                                : 'Create collection'}
                        </Button>
                        <p className="text-xs leading-5 text-muted-foreground">
                            Resources keep their existing reading permissions.
                            Counts show resources available to you.
                        </p>
                    </form>
                </div>
            )}
            {leave.dialog}
        </section>
    );
}
