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
import { registerDraftHistoryGuard } from '@/lib/draft-history';
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
    Eye,
    EyeOff,
    FolderPlus,
    GripVertical,
    LockKeyhole,
    Pencil,
    Plus,
    RotateCcw,
    Save,
    Send,
    Trash2,
    Undo2,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { hubRequest } from './_api';
import { navigationDiscovery } from './_navigation-discovery';
import { HubSelect } from './_select';
import type { HubUpdate } from './_types';

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
    editing = false,
    selectedId,
    onNavigate,
    updates = [],
    relatedPageIds = [],
}: {
    items: NavigationItem[];
    current: string;
    onPreview?: (item: NavigationItem) => void;
    editing?: boolean;
    selectedId?: string;
    onNavigate?: (href: string) => void;
    updates?: HubUpdate[];
    relatedPageIds?: number[];
}) {
    const container = useRef<HTMLElement>(null);
    const trigger = useRef<HTMLElement | null>(null);
    const anchor = useRef({
        getBoundingClientRect: () =>
            trigger.current?.getBoundingClientRect() ??
            container.current!.getBoundingClientRect(),
    });
    const [group, setGroup] = useState<string | null>(null);
    const visible = items.filter((item) => editing || !item.hidden);
    const roots = visible.filter(
        (item) =>
            !item.parent &&
            (editing ||
                item.type !== 'group' ||
                visible.some((child) => child.parent === item.id)),
    );
    const selected =
        visible.find((item) => `${item.type}:${item.target}` === current) ??
        (current === 'view:guides'
            ? (visible.find(
                  (item) =>
                      item.type === 'group' &&
                      visible.some(
                          (child) =>
                              child.parent === item.id &&
                              child.type === 'page' &&
                              relatedPageIds.includes(Number(child.target)),
                      ),
              ) ??
              visible.find(
                  (item) => item.type === 'view' && item.target === 'resources',
              ))
            : undefined);
    const openGroup = !editing
        ? roots.find((item) => item.id === group && item.type === 'group')
        : undefined;
    const visit = (item: NavigationItem) => {
        if (onPreview) {
            onPreview(item);
            return;
        }
        if (!item.url) return;
        if (item.url.startsWith('https://'))
            window.open(item.url, '_blank', 'noopener,noreferrer');
        else if (onNavigate) onNavigate(item.url);
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
                maxVisible={8}
                items={roots.map((item) => ({
                    key: item.id,
                    label: item.label,
                    ...(!editing
                        ? { overflow: navigationDiscovery(item, updates) }
                        : {}),
                    ...(item.type === 'group' ? { icon: ChevronDown } : {}),
                    ...(editing && item.hidden ? { icon: EyeOff } : {}),
                }))}
                value={
                    selectedId
                        ? items.find((item) => item.id === selectedId)
                              ?.parent || selectedId
                        : selected?.parent || selected?.id || ''
                }
                onSelect={(id) => {
                    const item = roots.find((entry) => entry.id === id);
                    if (!item) return;
                    if (editing) {
                        onPreview?.(item);
                        return;
                    }
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
                open={!!openGroup}
                onOpenChange={(open) => !open && setGroup(null)}
            >
                <PopoverAnchor virtualRef={anchor} />
                <PopoverContent
                    align="start"
                    className="w-80 p-2"
                    aria-label={openGroup?.label}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        trigger.current?.focus();
                    }}
                >
                    <p className="px-3 py-2 text-xs font-semibold text-muted-foreground">
                        {openGroup?.label}
                    </p>
                    {visible
                        .filter((item) => item.parent === group)
                        .map((item) => {
                            const discovery = navigationDiscovery(
                                item,
                                updates,
                            );
                            const Icon = discovery.icon;
                            return (
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
                                    <Icon className="size-4 shrink-0 text-muted-foreground" />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span>{item.label}</span>
                                            {discovery.unread && (
                                                <span
                                                    className="size-1.5 shrink-0 rounded-full bg-primary"
                                                    aria-label="New updates"
                                                />
                                            )}
                                        </span>
                                        <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                                            {discovery.description}
                                        </span>
                                    </span>
                                    <ArrowUpRight className="size-3.5 shrink-0" />
                                </Button>
                            );
                        })}
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

export type NavigationEditorSlots = {
    rail: HTMLElement | null;
    inspector: HTMLElement | null;
};

function NavigationPlacement({
    embedded,
    target,
    children,
}: {
    embedded: boolean;
    target?: HTMLElement | null;
    children: ReactNode;
}) {
    return embedded
        ? target
            ? createPortal(children, target)
            : null
        : children;
}

export function SharedNavigationDesigner({
    onDirtyChange,
    onBusyChange,
    onPublished,
    editorSlots,
}: {
    onDirtyChange: (dirty: boolean) => void;
    onBusyChange?: (busy: boolean) => void;
    onPublished?: (workspace: NavigationWorkspace) => void;
    editorSlots?: NavigationEditorSlots;
}) {
    const [workspace, setWorkspace] = useState<NavigationWorkspace | null>(
        null,
    );
    const [items, setItems] = useState<NavigationItem[]>([]);
    const [selectedId, setSelectedId] = useState('home');
    const [previewingMenu, setPreviewingMenu] = useState(false);
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
    const mutationLock = useRef(false);
    const inspectorTarget = editorSlots?.inspector;
    useEffect(() => {
        inspectorTarget?.closest('aside')?.scrollTo?.({ top: 0 });
    }, [selectedId, inspectorTarget]);
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
            if (dirtyRef.current || mutationLock.current) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        const unsubscribe = router.on('before', (event) => {
            if (dirtyRef.current || mutationLock.current) {
                event.preventDefault();
                setError(
                    mutationLock.current
                        ? 'Wait for the menu change to finish before leaving.'
                        : 'Save your menu draft or discard local changes before leaving.',
                );
            }
        });
        window.addEventListener('beforeunload', warn);
        return () => {
            unsubscribe();
            window.removeEventListener('beforeunload', warn);
        };
    }, []);
    useEffect(() => {
        if (!dirty && !busy) return;
        return registerDraftHistoryGuard((_continueHistory, cancelHistory) => {
            cancelHistory();
            setError(
                mutationLock.current
                    ? 'Wait for the menu change to finish before leaving.'
                    : 'Save your menu draft or discard local changes before leaving.',
            );
        });
    }, [dirty, busy]);
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );
    const edit = (next: NavigationItem[]) => {
        if (mutationLock.current) return;
        setHistory((past) => [...past.slice(-39), items]);
        setItems(next);
        setError('');
        setNotice('');
    };
    const change = async (action: 'save' | 'publish' | 'discard') => {
        if (!workspace || mutationLock.current) return;
        mutationLock.current = true;
        setBusy(true);
        onBusyChange?.(true);
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
            // Embedded editors keep their unsaved page state and update the
            // preview directly instead of triggering an Inertia page refresh.
            if (action === 'publish') {
                if (onPublished) onPublished(data);
                else {
                    mutationLock.current = false;
                    router.reload({
                        only: ['sharedNavigation'],
                        preserveScroll: true,
                    });
                }
            }
        } catch (e) {
            setError((e as Error).message);
            setConfirmation(null);
        } finally {
            mutationLock.current = false;
            setBusy(false);
            onBusyChange?.(false);
        }
    };
    const add = (type: NavigationItem['type']) => {
        const item: NavigationItem = {
            id: `nav-${crypto.randomUUID()}`,
            type,
            label: type === 'group' ? 'New menu' : 'New tab',
            target: '',
            parent: '',
            hidden: false,
        };
        edit([...items, item]);
        setSelectedId(item.id);
    };
    const selected = items.find((item) => item.id === selectedId) ?? items[0];
    const removeSelected = () => {
        if (!selected || selected.id === 'home' || mutationLock.current) return;
        edit(
            items
                .filter((item) => item.id !== selected.id)
                .map((item) =>
                    item.parent === selected.id
                        ? { ...item, parent: '' }
                        : item,
                ),
        );
        setSelectedId('home');
    };
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
                        {editorSlots
                            ? 'Editing shared tabs'
                            : 'A clear path through your hub'}
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
                {!editorSlots && (
                    <Button
                        variant="outline"
                        disabled={busy || items.length >= 48}
                        onClick={() => add('link')}
                    >
                        <Plus className="size-4" />
                        Add tab
                    </Button>
                )}
                {!editorSlots && (
                    <Button
                        variant="outline"
                        disabled={busy || items.length >= 48}
                        onClick={() => add('group')}
                    >
                        <FolderPlus className="size-4" />
                        Add group
                    </Button>
                )}
                <Button
                    variant="outline"
                    disabled={busy || !dirty}
                    onClick={() => void change('save')}
                >
                    <Save className="size-4" />
                    {editorSlots ? 'Save tabs draft' : 'Save draft'}
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
            {editorSlots && previewingMenu && !notice && (
                <p role="status" className="text-sm text-muted-foreground">
                    Previewing your unpublished menu. Open a group to check its
                    links; nothing navigates or publishes.
                </p>
            )}
            {!editorSlots && (
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
            )}
            {editorSlots && (
                <NavigationPlacement embedded target={editorSlots.rail}>
                    <div className="flex w-full min-w-0 items-center gap-2">
                        <div className="min-w-0 flex-1">
                            <HubNavigationRail
                                key={previewingMenu ? 'preview' : 'edit'}
                                items={items}
                                current={previewingMenu ? 'view:home' : ''}
                                editing={!previewingMenu}
                                selectedId={
                                    previewingMenu ? undefined : selected?.id
                                }
                                onPreview={(item) =>
                                    previewingMenu
                                        ? setNotice(
                                              `Preview: ${item.label} opens ${summary(item)}. Your unpublished menu has not been applied.`,
                                          )
                                        : setSelectedId(item.id)
                                }
                            />
                        </div>
                        <Button
                            variant="ghost"
                            className="hub-glass-button mb-1 min-w-[132px] shrink-0 justify-center"
                            disabled={busy}
                            aria-pressed={previewingMenu}
                            onClick={() => {
                                setPreviewingMenu(!previewingMenu);
                                setNotice('');
                            }}
                        >
                            {previewingMenu ? (
                                <Pencil className="size-4" />
                            ) : (
                                <Eye className="size-4" />
                            )}
                            {previewingMenu ? 'Edit tabs' : 'Preview menu'}
                        </Button>
                        <Button
                            variant="ghost"
                            className="hub-glass-button mb-1 shrink-0"
                            disabled={busy || items.length >= 48}
                            onClick={() => add('link')}
                        >
                            <Plus className="size-4" />
                            Add tab
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="hub-glass-button mb-1 shrink-0"
                            aria-label="Add group"
                            disabled={busy || items.length >= 48}
                            onClick={() => add('group')}
                        >
                            <FolderPlus className="size-4" />
                        </Button>
                    </div>
                </NavigationPlacement>
            )}
            <NavigationPlacement
                embedded={!!editorSlots}
                target={editorSlots?.inspector}
            >
                <div
                    className={`hub-nav-designer ${editorSlots ? 'hub-nav-inline-inspector' : ''}`}
                >
                    <div>
                        {editorSlots && (
                            <h3 className="mb-3 text-sm font-semibold">
                                Tab order and groups
                            </h3>
                        )}
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
                                        return from?.parent ===
                                            target?.parent &&
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
                                                    peer.parent ===
                                                        item.parent &&
                                                    peer.id !== 'home',
                                            ),
                                            index = peers.indexOf(item);
                                        return (
                                            <NavigationRow
                                                key={item.id}
                                                item={item}
                                                selected={
                                                    selected?.id === item.id
                                                }
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
                            Drag within a menu, or use the arrows. Choose a
                            parent menu in settings to move a link into a group.
                            Home always stays first. Destinations keep their
                            existing access permissions.
                        </p>
                    </div>
                    {selected && (
                        <aside
                            className="hub-nav-inspector"
                            aria-label="Navigation item settings"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <h3 className="font-semibold">
                                    {selected.type === 'group'
                                        ? 'Menu group'
                                        : 'Navigation tab'}
                                </h3>
                                {selected.id !== 'home' && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="shrink-0 px-2 text-xs"
                                        disabled={busy}
                                        onClick={removeSelected}
                                    >
                                        <Trash2 className="size-3.5" />
                                        Remove{' '}
                                        {selected.type === 'group'
                                            ? 'group'
                                            : 'tab'}
                                    </Button>
                                )}
                            </div>
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
                                            <HubSelect
                                                label="Destination type"
                                                value={selected.type}
                                                disabled={busy}
                                                onChange={(value) =>
                                                    update({
                                                        type: value as NavigationItem['type'],
                                                        target: '',
                                                    })
                                                }
                                                options={[
                                                    {
                                                        value: 'view',
                                                        label: 'Hub view',
                                                    },
                                                    {
                                                        value: 'page',
                                                        label: 'Published page',
                                                    },
                                                    {
                                                        value: 'link',
                                                        label: 'Web or application link',
                                                    },
                                                ]}
                                            />
                                        </Label>
                                    )}
                                {selected.type === 'view' && (
                                    <Label className="grid gap-2">
                                        Hub view
                                        <HubSelect
                                            label="Hub view"
                                            value={selected.target}
                                            disabled={
                                                busy || selected.id === 'home'
                                            }
                                            onChange={(value) =>
                                                update({
                                                    target: value,
                                                })
                                            }
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'Choose a view',
                                                },
                                                ...Object.entries(
                                                    workspace.views,
                                                ).map(([value, label]) => ({
                                                    value,
                                                    label,
                                                })),
                                            ]}
                                        />
                                    </Label>
                                )}
                                {selected.type === 'page' && (
                                    <Label className="grid gap-2">
                                        Published page
                                        <HubSelect
                                            label="Published page"
                                            value={selected.target}
                                            disabled={busy}
                                            onChange={(value) =>
                                                update({
                                                    target: value,
                                                })
                                            }
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'Choose a published page',
                                                },
                                                ...workspace.pages.map(
                                                    (page) => ({
                                                        value: String(page.id),
                                                        label: page.title,
                                                    }),
                                                ),
                                            ]}
                                        />
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
                                                update({
                                                    target: e.target.value,
                                                })
                                            }
                                        />
                                    </Label>
                                )}
                                {selected.type !== 'group' &&
                                    selected.id !== 'home' && (
                                        <Label className="grid gap-2">
                                            Parent menu
                                            <HubSelect
                                                label="Parent menu"
                                                value={selected.parent}
                                                disabled={busy}
                                                onChange={(value) =>
                                                    update({
                                                        parent: value,
                                                    })
                                                }
                                                options={[
                                                    {
                                                        value: '',
                                                        label: 'Top level',
                                                    },
                                                    ...groups.map((group) => ({
                                                        value: group.id,
                                                        label: group.label,
                                                    })),
                                                ]}
                                            />
                                        </Label>
                                    )}
                                {selected.id === 'home' ? (
                                    <p className="text-xs text-muted-foreground">
                                        Home stays visible and first so everyone
                                        can find their way back.
                                    </p>
                                ) : (
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={selected.hidden}
                                            onChange={(e) =>
                                                update({
                                                    hidden: e.target.checked,
                                                })
                                            }
                                        />
                                        Hide from staff
                                    </label>
                                )}
                            </fieldset>
                            <Button
                                variant="ghost"
                                disabled={
                                    busy || (!dirty && !workspace.has_draft)
                                }
                                onClick={() => setConfirmation('discard')}
                            >
                                <RotateCcw className="size-4" />
                                Discard navigation draft
                            </Button>
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                One shared menu for staff. Page links only
                                appear while that person can read the published
                                page. Removing a tab keeps its page and content.{' '}
                                {workspace.can_publish
                                    ? 'Save the draft, then publish when ready.'
                                    : 'A person with publishing permission must publish your saved draft.'}
                            </p>
                        </aside>
                    )}
                </div>
            </NavigationPlacement>
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
