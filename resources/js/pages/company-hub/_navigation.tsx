import { PageHeaderRail } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpRight,
    ChevronDown,
    EyeOff,
    FolderPlus,
    GripVertical,
    LockKeyhole,
    Plus,
    RotateCcw,
    Save,
    Send,
    Trash2,
    Undo2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';

export type NavigationItem = {
    id: string;
    type: 'view' | 'page' | 'link' | 'group';
    label: string;
    target: string;
    parent: string;
    hidden: boolean;
    url?: string | null;
};
export type NavigationWorkspace = {
    version: number;
    has_draft: boolean;
    published_at: string | null;
    draft: NavigationItem[];
    published: NavigationItem[];
    pages: Array<{ id: string; title: string; url: string }>;
    views: Record<string, string>;
    can_publish: boolean;
};

/** Shared rail styling, including its measured overflow and keyboard access. */
export function HubNavigationRail({
    items,
    current,
    onPreview,
}: {
    items: NavigationItem[];
    current: string;
    onPreview?: (item: NavigationItem) => void;
}) {
    const container = useRef<HTMLElement>(null);
    const trigger = useRef<HTMLElement | null>(null);
    const anchor = useRef({
        getBoundingClientRect: () =>
            trigger.current?.getBoundingClientRect() ??
            container.current!.getBoundingClientRect(),
    });
    const [group, setGroup] = useState<string | null>(null);
    const visible = items.filter((item) => !item.hidden);
    const roots = visible.filter(
        (item) =>
            !item.parent &&
            (item.type !== 'group' ||
                visible.some((child) => child.parent === item.id)),
    );
    const selected = visible.find(
        (item) => `${item.type}:${item.target}` === current,
    );
    const visit = (item: NavigationItem) => {
        if (onPreview) {
            onPreview(item);
            return;
        }
        if (!item.url) return;
        if (item.url.startsWith('https://'))
            window.open(item.url, '_blank', 'noopener,noreferrer');
        else router.visit(item.url);
    };
    return (
        <nav
            ref={container}
            aria-label={onPreview ? 'Navigation preview' : 'Company Hub'}
            className="relative w-full min-w-0 overflow-x-clip"
        >
            <PageHeaderRail
                ariaLabel={
                    onPreview ? 'Navigation preview views' : 'Company Hub views'
                }
                showFind={false}
                items={roots.map((item) => ({
                    key: item.id,
                    label: item.label,
                    ...(item.type === 'group' ? { icon: ChevronDown } : {}),
                }))}
                value={selected?.parent || selected?.id || ''}
                onSelect={(id) => {
                    const item = roots.find((entry) => entry.id === id);
                    if (!item) return;
                    if (item.type !== 'group') {
                        visit(item);
                        return;
                    }
                    trigger.current =
                        Array.from(
                            container.current?.querySelectorAll<HTMLElement>(
                                '[role="tab"]',
                            ) ?? [],
                        ).find((el) => el.textContent?.trim() === item.label) ??
                        container.current?.querySelector<HTMLElement>(
                            '[aria-label^="More views"]',
                        ) ??
                        null;
                    setGroup(id);
                }}
            />
            <Popover
                open={!!group}
                onOpenChange={(open) => !open && setGroup(null)}
            >
                <PopoverAnchor virtualRef={anchor} />
                <PopoverContent
                    align="start"
                    className="w-64 p-2"
                    aria-label={roots.find((item) => item.id === group)?.label}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        trigger.current?.focus();
                    }}
                >
                    <p className="px-3 py-2 text-xs font-semibold text-muted-foreground">
                        {roots.find((item) => item.id === group)?.label}
                    </p>
                    {visible
                        .filter((item) => item.parent === group)
                        .map((item) => (
                            <Button
                                variant="ghost"
                                key={item.id}
                                type="button"
                                className="h-auto w-full justify-between gap-3 px-3 py-2.5 text-left text-sm font-normal"
                                onClick={() => {
                                    setGroup(null);
                                    visit(item);
                                }}
                            >
                                {item.label}
                                <ArrowUpRight className="size-3.5" />
                            </Button>
                        ))}
                </PopoverContent>
            </Popover>
        </nav>
    );
}

export function moveNavigation(
    items: NavigationItem[],
    id: string,
    target: string,
): NavigationItem[] {
    const from = items.find((item) => item.id === id),
        to = items.find((item) => item.id === target);
    if (
        !from ||
        !to ||
        id === 'home' ||
        target === 'home' ||
        from.parent !== to.parent
    )
        return items;
    const peers = items.filter((item) => item.parent === from.parent);
    const moved = arrayMove(peers, peers.indexOf(from), peers.indexOf(to));
    let index = 0;
    return items.map((item) =>
        item.parent === from.parent ? moved[index++] : item,
    );
}

export function SharedNavigationDesigner({
    onDirtyChange,
}: {
    onDirtyChange: (dirty: boolean) => void;
}) {
    const [workspace, setWorkspace] = useState<NavigationWorkspace | null>(
        null,
    );
    const [items, setItems] = useState<NavigationItem[]>([]);
    const [selectedId, setSelectedId] = useState('home');
    const [history, setHistory] = useState<NavigationItem[][]>([]);
    const [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [notice, setNotice] = useState('');
    const [confirmation, setConfirmation] = useState<
        'publish' | 'discard' | 'reload' | null
    >(null);
    const [loadKey, setLoadKey] = useState(0);
    const dirty =
        !!workspace &&
        JSON.stringify(items) !== JSON.stringify(workspace.draft);
    const dirtyRef = useRef(dirty);
    dirtyRef.current = dirty;
    useEffect(() => {
        onDirtyChange(dirty);
        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);
    useEffect(() => {
        let alive = true;
        hubRequest<NavigationWorkspace>(
            '/company-hub/editor/shared-navigation',
            'GET',
        )
            .then((data) => {
                if (alive) {
                    setWorkspace(data);
                    setItems(data.draft);
                    setHistory([]);
                    setError('');
                }
            })
            .catch((e: Error) => alive && setError(e.message));
        return () => {
            alive = false;
        };
    }, [loadKey]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (dirtyRef.current) event.preventDefault();
        };
        const unsubscribe = router.on('before', (event) => {
            if (dirtyRef.current) {
                event.preventDefault();
                setError(
                    'Save your menu draft or discard local changes before leaving.',
                );
            }
        });
        window.addEventListener('beforeunload', warn);
        return () => {
            unsubscribe();
            window.removeEventListener('beforeunload', warn);
        };
    }, []);
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );
    const edit = (next: NavigationItem[]) => {
        if (busy) return;
        setHistory((past) => [...past.slice(-39), items]);
        setItems(next);
        setError('');
        setNotice('');
    };
    const change = async (action: 'save' | 'publish' | 'discard') => {
        if (!workspace) return;
        setBusy(true);
        setError('');
        try {
            const data = await hubRequest<NavigationWorkspace>(
                '/company-hub/editor/shared-navigation',
                'PUT',
                {
                    action,
                    version: workspace.version,
                    ...(action === 'save' ? { items } : {}),
                },
            );
            setWorkspace(data);
            setItems(data.draft);
            setHistory([]);
            dirtyRef.current = false;
            setConfirmation(null);
            setNotice(
                action === 'publish'
                    ? 'Shared navigation published. Staff will see this menu.'
                    : action === 'save'
                      ? 'Draft saved. Staff still see the published menu.'
                      : 'Navigation draft discarded. The published menu is unchanged.',
            );
            if (action === 'publish')
                router.reload({
                    only: ['sharedNavigation'],
                    preserveScroll: true,
                });
        } catch (e) {
            setError((e as Error).message);
            setConfirmation(null);
        } finally {
            setBusy(false);
        }
    };
    const add = (type: NavigationItem['type']) => {
        const item: NavigationItem = {
            id: `nav-${crypto.randomUUID()}`,
            type,
            label: type === 'group' ? 'New menu' : 'New link',
            target: '',
            parent: '',
            hidden: false,
        };
        edit([...items, item]);
        setSelectedId(item.id);
    };
    const selected = items.find((item) => item.id === selectedId) ?? items[0];
    const groups = items.filter((item) => item.type === 'group');
    const ordered = items
        .filter((item) => !item.parent)
        .flatMap((item) => [
            item,
            ...items.filter((child) => child.parent === item.id),
        ]);
    const update = (patch: Partial<NavigationItem>) =>
        edit(
            items.map((item) =>
                item.id === selected?.id ? { ...item, ...patch } : item,
            ),
        );
    const summary = (item: NavigationItem) =>
        item.type === 'group'
            ? 'Menu group'
            : item.type === 'view'
              ? workspace?.views[item.target]
              : item.type === 'page'
                ? (workspace?.pages.find((page) => page.id === item.target)
                      ?.title ?? 'Choose a published page')
                : item.target || 'Choose a destination';
    const reload = () => {
        if (dirty) setConfirmation('reload');
        else {
            setWorkspace(null);
            setLoadKey((key) => key + 1);
        }
    };
    if (!workspace)
        return (
            <Card className="p-6">
                <p role={error ? 'alert' : 'status'}>
                    {error || 'Loading the shared menu…'}
                </p>
                {error && (
                    <Button className="mt-4" onClick={reload}>
                        Try again
                    </Button>
                )}
            </Card>
        );
    return (
        <section className="space-y-5" aria-label="Navigation designer">
            <div className="flex flex-wrap items-center gap-2">
                <div className="mr-auto">
                    <h2 className="text-xl font-semibold">
                        A clear path through your hub
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {dirty
                            ? 'Unsaved menu changes'
                            : workspace.has_draft
                              ? 'Saved draft · not yet published'
                              : 'Your current shared navigation'}
                    </p>
                </div>
                <Button
                    variant="outline"
                    disabled={busy || !history.length}
                    onClick={() => {
                        setItems(history[history.length - 1]);
                        setHistory(history.slice(0, -1));
                    }}
                >
                    <Undo2 className="size-4" />
                    Undo
                </Button>
                <Button
                    variant="outline"
                    disabled={busy || items.length >= 48}
                    onClick={() => add('link')}
                >
                    <Plus className="size-4" />
                    Add link
                </Button>
                <Button
                    variant="outline"
                    disabled={busy || items.length >= 48}
                    onClick={() => add('group')}
                >
                    <FolderPlus className="size-4" />
                    Add group
                </Button>
                <Button
                    variant="outline"
                    disabled={busy || !dirty}
                    onClick={() => void change('save')}
                >
                    <Save className="size-4" />
                    Save draft
                </Button>
                {workspace.can_publish && (
                    <Button
                        disabled={busy || dirty || !workspace.has_draft}
                        onClick={() => setConfirmation('publish')}
                    >
                        <Send className="size-4" />
                        Publish navigation
                    </Button>
                )}
            </div>
            {error && (
                <div
                    role="alert"
                    className="flex items-center justify-between gap-4 rounded-xl border border-destructive/30 p-4 text-sm"
                >
                    <span>{error}</span>
                    <Button variant="outline" onClick={reload} disabled={busy}>
                        Reload latest menu
                    </Button>
                </div>
            )}
            {notice && (
                <p role="status" className="text-sm text-primary">
                    {notice}
                </p>
            )}
            <div className="hub-nav-preview">
                <p>Live navigation preview</p>
                <HubNavigationRail
                    items={items}
                    current="view:home"
                    onPreview={(item) =>
                        setNotice(
                            `Preview: ${item.label} opens ${summary(item)}. Save and publish to apply this menu.`,
                        )
                    }
                />
            </div>
            <div className="hub-nav-designer">
                <div>
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        accessibility={{
                            announcements: {
                                onDragStart: ({ active }) =>
                                    `Picked up ${items.find((item) => item.id === active.id)?.label ?? 'menu item'}. Use the arrow keys to move it, then Space to drop.`,
                                onDragOver: ({ active, over }) => {
                                    if (!over) return;
                                    const from = items.find(
                                            (item) => item.id === active.id,
                                        ),
                                        target = items.find(
                                            (item) => item.id === over.id,
                                        );
                                    return from?.parent === target?.parent &&
                                        target?.id !== 'home'
                                        ? `Move ${from?.label} to ${target?.label}.`
                                        : 'Keep this item in its current menu. Choose a different parent in settings.';
                                },
                                onDragEnd: ({ active, over }) => {
                                    const from = items.find(
                                            (item) => item.id === active.id,
                                        ),
                                        target = items.find(
                                            (item) => item.id === over?.id,
                                        );
                                    return over &&
                                        from?.parent === target?.parent &&
                                        target?.id !== 'home'
                                        ? `Placed ${from?.label} at ${target?.label}.`
                                        : 'Menu order unchanged.';
                                },
                                onDragCancel: () =>
                                    'Move cancelled. Menu order unchanged.',
                            },
                        }}
                        onDragEnd={({ active, over }) => {
                            if (over && active.id !== over.id)
                                edit(
                                    moveNavigation(
                                        items,
                                        String(active.id),
                                        String(over.id),
                                    ),
                                );
                        }}
                    >
                        <SortableContext
                            items={ordered.map((item) => item.id)}
                            strategy={verticalListSortingStrategy}
                        >
                            <div className="space-y-2">
                                {ordered.map((item) => {
                                    const peers = items.filter(
                                            (peer) =>
                                                peer.parent === item.parent &&
                                                peer.id !== 'home',
                                        ),
                                        index = peers.indexOf(item);
                                    return (
                                        <NavigationRow
                                            key={item.id}
                                            item={item}
                                            selected={selected?.id === item.id}
                                            summary={summary(item) ?? ''}
                                            busy={busy}
                                            onSelect={() =>
                                                setSelectedId(item.id)
                                            }
                                            up={index > 0}
                                            down={
                                                index >= 0 &&
                                                index < peers.length - 1
                                            }
                                            onMove={(step) => {
                                                const target =
                                                    peers[index + step];
                                                if (target)
                                                    edit(
                                                        moveNavigation(
                                                            items,
                                                            item.id,
                                                            target.id,
                                                        ),
                                                    );
                                            }}
                                        />
                                    );
                                })}
                            </div>
                        </SortableContext>
                    </DndContext>
                    <p className="mt-4 text-xs leading-relaxed text-muted-foreground">
                        Drag within a menu, or use the arrows. Choose a parent
                        menu in settings to move a link into a group. Home
                        always stays first. Destinations keep their existing
                        access permissions.
                    </p>
                </div>
                {selected && (
                    <aside
                        className="hub-nav-inspector"
                        aria-label="Navigation item settings"
                    >
                        <h3 className="font-semibold">
                            {selected.type === 'group'
                                ? 'Menu group'
                                : 'Navigation link'}
                        </h3>
                        <fieldset disabled={busy} className="space-y-5">
                            <Label className="grid gap-2">
                                Navigation label
                                <Input
                                    value={selected.label}
                                    maxLength={60}
                                    onChange={(e) =>
                                        update({ label: e.target.value })
                                    }
                                />
                            </Label>
                            {selected.type !== 'group' &&
                                selected.id !== 'home' && (
                                    <Label className="grid gap-2">
                                        Destination type
                                        <select
                                            aria-label="Destination type"
                                            className="hub-native-select"
                                            value={selected.type}
                                            onChange={(e) =>
                                                update({
                                                    type: e.target
                                                        .value as NavigationItem['type'],
                                                    target: '',
                                                })
                                            }
                                        >
                                            <option value="view">
                                                Hub view
                                            </option>
                                            <option value="page">
                                                Published page
                                            </option>
                                            <option value="link">
                                                Web or application link
                                            </option>
                                        </select>
                                    </Label>
                                )}
                            {selected.type === 'view' && (
                                <Label className="grid gap-2">
                                    Hub view
                                    <select
                                        aria-label="Hub view"
                                        className="hub-native-select"
                                        value={selected.target}
                                        disabled={selected.id === 'home'}
                                        onChange={(e) =>
                                            update({ target: e.target.value })
                                        }
                                    >
                                        <option value="">Choose a view</option>
                                        {Object.entries(workspace.views).map(
                                            ([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </Label>
                            )}
                            {selected.type === 'page' && (
                                <Label className="grid gap-2">
                                    Published page
                                    <select
                                        aria-label="Published page"
                                        className="hub-native-select"
                                        value={selected.target}
                                        onChange={(e) =>
                                            update({ target: e.target.value })
                                        }
                                    >
                                        <option value="">
                                            Choose a published page
                                        </option>
                                        {workspace.pages.map((page) => (
                                            <option
                                                key={page.id}
                                                value={page.id}
                                            >
                                                {page.title}
                                            </option>
                                        ))}
                                    </select>
                                </Label>
                            )}
                            {selected.type === 'link' && (
                                <Label className="grid gap-2">
                                    Destination
                                    <Input
                                        placeholder="https://… or /application-page"
                                        value={selected.target}
                                        maxLength={2000}
                                        onChange={(e) =>
                                            update({ target: e.target.value })
                                        }
                                    />
                                </Label>
                            )}
                            {selected.type !== 'group' &&
                                selected.id !== 'home' && (
                                    <Label className="grid gap-2">
                                        Parent menu
                                        <select
                                            aria-label="Parent menu"
                                            className="hub-native-select"
                                            value={selected.parent}
                                            onChange={(e) =>
                                                update({
                                                    parent: e.target.value,
                                                })
                                            }
                                        >
                                            <option value="">Top level</option>
                                            {groups.map((group) => (
                                                <option
                                                    key={group.id}
                                                    value={group.id}
                                                >
                                                    {group.label}
                                                </option>
                                            ))}
                                        </select>
                                    </Label>
                                )}
                            {selected.id === 'home' ? (
                                <p className="text-xs text-muted-foreground">
                                    Home stays visible and first so everyone can
                                    find their way back.
                                </p>
                            ) : (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={selected.hidden}
                                        onChange={(e) =>
                                            update({ hidden: e.target.checked })
                                        }
                                    />
                                    Hide from staff
                                </label>
                            )}
                            {selected.id !== 'home' && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        edit(
                                            items
                                                .filter(
                                                    (item) =>
                                                        item.id !== selected.id,
                                                )
                                                .map((item) =>
                                                    item.parent === selected.id
                                                        ? {
                                                              ...item,
                                                              parent: '',
                                                          }
                                                        : item,
                                                ),
                                        );
                                        setSelectedId('home');
                                    }}
                                >
                                    <Trash2 className="size-4" />
                                    Remove{' '}
                                    {selected.type === 'group'
                                        ? 'group'
                                        : 'link'}
                                </Button>
                            )}
                        </fieldset>
                        <Button
                            variant="ghost"
                            disabled={busy || (!dirty && !workspace.has_draft)}
                            onClick={() => setConfirmation('discard')}
                        >
                            <RotateCcw className="size-4" />
                            Discard navigation draft
                        </Button>
                        <p className="text-xs leading-relaxed text-muted-foreground">
                            One shared menu for staff. Page links only appear
                            while that person can read the published page.{' '}
                            {workspace.can_publish
                                ? 'Save the draft, then publish when ready.'
                                : 'A person with publishing permission must publish your saved draft.'}
                        </p>
                    </aside>
                )}
            </div>
            <Dialog
                open={!!confirmation}
                onOpenChange={(open) => !busy && !open && setConfirmation(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {confirmation === 'publish'
                                ? 'Publish the shared navigation?'
                                : confirmation === 'reload'
                                  ? 'Reload the latest menu?'
                                  : 'Discard navigation changes?'}
                        </DialogTitle>
                        <DialogDescription>
                            {confirmation === 'publish'
                                ? 'The saved menu will appear for staff. This publishes navigation only; page drafts stay unchanged.'
                                : confirmation === 'reload'
                                  ? 'Your unsaved menu changes will be replaced by the latest saved draft.'
                                  : 'The saved navigation draft and your local edits will be removed. Staff keep the published menu.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setConfirmation(null)}
                        >
                            Keep editing
                        </Button>
                        <Button
                            disabled={busy}
                            onClick={() => {
                                if (confirmation === 'reload') {
                                    dirtyRef.current = false;
                                    setConfirmation(null);
                                    setWorkspace(null);
                                    setLoadKey((key) => key + 1);
                                } else
                                    void change(
                                        confirmation === 'publish'
                                            ? 'publish'
                                            : 'discard',
                                    );
                            }}
                        >
                            {confirmation === 'publish'
                                ? 'Publish shared menu'
                                : confirmation === 'reload'
                                  ? 'Reload menu'
                                  : 'Discard draft'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function NavigationRow({
    item,
    selected,
    summary,
    busy,
    up,
    down,
    onSelect,
    onMove,
}: {
    item: NavigationItem;
    selected: boolean;
    summary: string;
    busy: boolean;
    up: boolean;
    down: boolean;
    onSelect: () => void;
    onMove: (step: number) => void;
}) {
    const { setNodeRef, transform, transition, attributes, listeners } =
        useSortable({ id: item.id, disabled: busy || item.id === 'home' });
    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={`hub-nav-row ${selected ? 'is-selected' : ''} ${item.parent ? 'is-child' : ''}`}
        >
            <Button
                variant="ghost"
                size="icon"
                disabled={busy || item.id === 'home'}
                aria-label={`Drag ${item.label}`}
                {...attributes}
                {...listeners}
            >
                {item.id === 'home' ? (
                    <LockKeyhole className="size-4" />
                ) : (
                    <GripVertical className="size-4" />
                )}
            </Button>
            <Button
                variant="ghost"
                className="h-auto min-w-0 flex-1 flex-col items-start gap-1 py-2 text-left"
                onClick={onSelect}
                aria-label={`Edit ${item.label}`}
                aria-pressed={selected}
            >
                <strong className="flex items-center gap-2 text-sm">
                    {item.label}
                    {item.hidden && (
                        <EyeOff className="size-3.5" aria-label="Hidden" />
                    )}
                </strong>
                <span className="block truncate text-xs text-muted-foreground">
                    {summary}
                    {item.hidden ? ' · Hidden' : ''}
                </span>
            </Button>
            <Button
                variant="ghost"
                size="icon"
                disabled={busy || !up}
                aria-label={`Move ${item.label} up`}
                onClick={() => onMove(-1)}
            >
                <ArrowUp className="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                disabled={busy || !down}
                aria-label={`Move ${item.label} down`}
                onClick={() => onMove(1)}
            >
                <ArrowDown className="size-4" />
            </Button>
        </div>
    );
}
