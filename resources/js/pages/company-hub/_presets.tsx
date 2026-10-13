import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    BookmarkPlus,
    Plus,
    RefreshCw,
    Search,
    Trash2,
    Undo2,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import {
    blockLibrary,
    blockSpan,
    createBlock,
    duplicateSection,
} from './_layout';
import type { HubSection } from './_types';

export type SectionPreset = {
    id: number;
    name: string;
    section: HubSection;
    scope: 'personal' | 'shared';
    version: number;
    can_remove: boolean;
};
type RemovedPreset = Pick<SectionPreset, 'id' | 'name' | 'scope' | 'version'>;
type Library = {
    presets: SectionPreset[];
    can_share: boolean;
    removed_presets?: RemovedPreset[];
};
const starters = [
    { title: 'Welcome & essentials', types: ['text', 'links'] },
    { title: 'Meet the team', types: ['people', 'resources'] },
    { title: 'Recognition & connection', types: ['shoutouts', 'events'] },
];

function SectionPreview({ section }: { section: HubSection }) {
    return (
        <div className="grid grid-cols-12 gap-1.5" aria-hidden="true">
            {section.blocks.map((block, index) => (
                <span
                    key={block.id}
                    style={{
                        gridColumn: `span ${blockSpan(section, block, index)}`,
                    }}
                    className="truncate rounded-md bg-primary/10 px-2 py-3 text-[10px] font-medium text-primary"
                >
                    {blockLibrary.find(([type]) => type === block.type)?.[1] ??
                        block.type}
                </span>
            ))}
        </div>
    );
}

export function SectionPresets({
    pageId,
    selected,
    onInsert,
    full,
}: {
    pageId: number;
    selected?: HubSection;
    onInsert: (section: HubSection) => void;
    full: boolean;
}) {
    const [library, setLibrary] = useState<Library>({
        presets: [],
        can_share: false,
    });
    const [error, setError] = useState(''),
        [notice, setNotice] = useState('');
    const [name, setName] = useState(''),
        [query, setQuery] = useState('');
    const [scope, setScope] = useState<'personal' | 'shared'>('personal');
    const [filter, setFilter] = useState('all');
    const [busy, setBusy] = useState(false),
        [loading, setLoading] = useState(true);
    const [reload, setReload] = useState(0);
    const [removed, setRemoved] = useState<RemovedPreset | null>(null);
    const insertion = useRef({ onInsert, full, pageId });
    insertion.current = { onInsert, full, pageId };
    const base = `/company-hub/manage/${pageId}/presets`;
    useEffect(() => {
        let active = true;
        if (pageId) {
            setLoading(true);
            void hubRequest<Library>(base, 'GET')
                .then((data) => {
                    if (active) {
                        setLibrary(data);
                        setError('');
                    }
                })
                .catch((e: Error) => {
                    if (active) setError(e.message);
                })
                .finally(() => {
                    if (active) setLoading(false);
                });
        } else setLoading(false);
        return () => {
            active = false;
        };
    }, [pageId, base, reload]);
    const act = async (action: () => Promise<void>) => {
        setBusy(true);
        setError('');
        setNotice('');
        try {
            await action();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const save = () =>
        act(async () => {
            if (!selected) return;
            await hubRequest(base, 'POST', {
                name: name.trim(),
                section: selected,
                scope,
            });
            setName('');
            setNotice(
                scope === 'shared'
                    ? 'Shared section saved for authorised editors.'
                    : 'Section saved to your library.',
            );
            setReload((value) => value + 1);
        });
    const insert = (preset: SectionPreset) =>
        act(async () => {
            const { section } = await hubRequest<{ section: HubSection }>(
                `${base}/${preset.id}/use`,
                'POST',
                { version: preset.version },
            );
            if (insertion.current.pageId !== pageId) return;
            if (insertion.current.full)
                throw new Error(
                    'This page now has 12 sections. Remove one before adding another.',
                );
            insertion.current.onInsert(duplicateSection(section));
            setNotice(
                'Inserted an independent copy. Other pages will stay as they are.',
            );
        });
    const remove = (preset: SectionPreset) =>
        act(async () => {
            const result = await hubRequest<{ version: number }>(
                `${base}/${preset.id}`,
                'DELETE',
                { version: preset.version },
            );
            setLibrary((current) => ({
                ...current,
                presets: current.presets.filter(
                    (item) => item.id !== preset.id,
                ),
                removed_presets: [
                    { ...preset, version: result.version },
                    ...(current.removed_presets ?? []).filter(
                        (item) => item.id !== preset.id,
                    ),
                ],
            }));
            setRemoved({ ...preset, version: result.version });
            setNotice(
                `${preset.name} removed from the library. Sections already on pages are kept.`,
            );
        });
    const restore = (preset: RemovedPreset) =>
        act(async () => {
            await hubRequest(`${base}/${preset.id}/restore`, 'POST', {
                version: preset.version,
            });
            if (removed?.id === preset.id) setRemoved(null);
            setNotice('Section restored to the library.');
            setReload((value) => value + 1);
        });
    const presets = library.presets.filter(
        (preset) =>
            (filter === 'all' || preset.scope === filter) &&
            preset.name.toLowerCase().includes(query.toLowerCase()),
    );
    return (
        <details className="mt-5 rounded-xl border bg-card p-4">
            <summary className="cursor-pointer text-sm font-medium">
                Section library
            </summary>
            <div className="mt-4 space-y-4">
                <p className="text-xs leading-relaxed text-muted-foreground">
                    Start with a layout, or reuse a section saved by your
                    editors. Each insertion is an independent copy. Images and
                    files are chosen on the new page.
                </p>
                <div className="grid grid-cols-3 gap-2">
                    {starters.map((preset) => (
                        <Button
                            key={preset.title}
                            variant="outline"
                            className="h-auto py-3 text-xs whitespace-normal"
                            disabled={full || busy}
                            onClick={() =>
                                onInsert({
                                    id: crypto.randomUUID(),
                                    title: preset.title,
                                    columns: 2,
                                    layout: 'wide-left',
                                    blocks: preset.types.map((type) =>
                                        createBlock(type),
                                    ),
                                })
                            }
                        >
                            <Plus className="size-3 shrink-0" />
                            {preset.title}
                        </Button>
                    ))}
                </div>
                {selected && (
                    <div className="space-y-3 rounded-xl bg-muted/40 p-3">
                        <Label className="grid gap-2">
                            Save selected section as
                            <Input
                                placeholder={selected.title || 'Section name'}
                                maxLength={100}
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                            />
                        </Label>
                        <div className="flex items-end gap-2">
                            <Label className="grid flex-1 gap-2">
                                Save to
                                <select
                                    aria-label="Section library destination"
                                    className="hub-native-select"
                                    value={scope}
                                    onChange={(e) =>
                                        setScope(
                                            e.target.value as
                                                | 'personal'
                                                | 'shared',
                                        )
                                    }
                                    disabled={busy || loading}
                                >
                                    <option value="personal">
                                        My sections
                                    </option>
                                    {library.can_share && (
                                        <option value="shared">
                                            Shared with editors
                                        </option>
                                    )}
                                </select>
                            </Label>
                            <Button
                                disabled={
                                    busy || loading || !name.trim() || !pageId
                                }
                                variant="outline"
                                onClick={save}
                            >
                                <BookmarkPlus className="size-4" />
                                Save section
                            </Button>
                        </div>
                        {scope === 'shared' && (
                            <p className="text-xs text-muted-foreground">
                                Text and settings are included. Only editors
                                with access to the original page and its
                                audience can use this section.
                            </p>
                        )}
                    </div>
                )}
                <div className="flex items-center gap-2">
                    <Search className="size-4 shrink-0 text-muted-foreground" />
                    <Input
                        aria-label="Search section library"
                        placeholder="Find a saved section…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                    <select
                        aria-label="Section library filter"
                        className="hub-native-select min-w-32 shrink-0"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                    >
                        <option value="all">All sections</option>
                        <option value="shared">Shared</option>
                        <option value="personal">Mine</option>
                    </select>
                    <Button
                        size="icon"
                        variant="ghost"
                        aria-label="Refresh section library"
                        disabled={busy || loading}
                        onClick={() => setReload((value) => value + 1)}
                    >
                        <RefreshCw className="size-4" />
                    </Button>
                </div>
                {loading && (
                    <p role="status" className="text-xs text-muted-foreground">
                        Loading your section library…
                    </p>
                )}
                {presets.map((preset) => (
                    <article
                        key={preset.id}
                        className="space-y-3 rounded-xl border p-3"
                    >
                        <SectionPreview section={preset.section} />
                        <div className="flex items-center gap-2">
                            <div className="min-w-0 flex-1">
                                <h4 className="truncate text-sm font-medium">
                                    {preset.name}
                                </h4>
                                <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                    {preset.scope === 'shared' && (
                                        <Users className="size-3" />
                                    )}
                                    {preset.scope === 'shared'
                                        ? 'Shared with editors'
                                        : 'My section'}{' '}
                                    · {preset.section.blocks.length} blocks
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={full || busy || loading}
                                aria-label={`Insert ${preset.name}`}
                                onClick={() => insert(preset)}
                            >
                                <Plus className="size-3" />
                                Insert
                            </Button>
                            {preset.can_remove && (
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label={`Remove ${preset.name} from library`}
                                    disabled={busy || loading}
                                    onClick={() => remove(preset)}
                                >
                                    <Trash2 className="size-3" />
                                </Button>
                            )}
                        </div>
                    </article>
                ))}
                {!loading && !presets.length && (
                    <p className="text-xs text-muted-foreground">
                        {library.presets.length
                            ? 'No sections match your search.'
                            : 'Select a section on the canvas to save a reusable layout.'}
                    </p>
                )}
                {full && (
                    <p className="text-xs text-muted-foreground">
                        This page has 12 sections. Remove one before adding
                        another.
                    </p>
                )}
                {notice && (
                    <p role="status" className="text-xs text-muted-foreground">
                        {notice}
                    </p>
                )}
                {removed && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy || loading}
                        onClick={() => restore(removed)}
                    >
                        <Undo2 className="size-3" />
                        Undo removal
                    </Button>
                )}
                {!!library.removed_presets?.length && (
                    <details className="rounded-lg border p-3">
                        <summary className="cursor-pointer text-xs font-medium">
                            Removed sections · {library.removed_presets.length}
                        </summary>
                        <p className="mt-2 text-xs text-muted-foreground">
                            Restore a section to use it again. Existing pages
                            are unaffected.
                        </p>
                        <div className="mt-3 space-y-2">
                            {library.removed_presets.map((preset) => (
                                <div
                                    key={preset.id}
                                    className="flex items-center gap-3"
                                >
                                    <span className="flex-1 text-sm">
                                        {preset.name}
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={busy || loading}
                                        aria-label={`Restore ${preset.name}`}
                                        onClick={() => restore(preset)}
                                    >
                                        <Undo2 className="size-3" />
                                        Restore
                                    </Button>
                                </div>
                            ))}
                        </div>
                    </details>
                )}
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
            </div>
        </details>
    );
}
