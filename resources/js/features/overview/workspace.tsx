import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterAvatars,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderMeterSpark,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Toggle } from '@/components/ui/toggle';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime, formatTime } from '@/lib/datetime';
import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    rectSortingStrategy,
    sortableKeyboardCoordinates,
    useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowRight,
    ArrowUp,
    ArrowUpRight,
    BookOpen,
    Building2,
    CalendarDays,
    Check,
    CloudOff,
    ContactRound,
    Grip,
    HeartHandshake,
    Landmark,
    LayoutDashboard,
    LayoutTemplate,
    Maximize2,
    MonitorCog,
    MoreHorizontal,
    Plus,
    Redo2,
    RefreshCw,
    RotateCcw,
    Search,
    ShieldCheck,
    Sparkles,
    Star,
    Trash2,
    TriangleAlert,
    Undo2,
    UsersRound,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    type CSSProperties,
    type PointerEvent,
    type ReactNode,
} from 'react';
import { toast } from 'sonner';
import {
    BlockContent,
    BlockSketch,
    ConnectionHistoryStrip,
    OverviewDetailContext,
} from './block-content';
import {
    catalog,
    defaultBlocks,
    fillRows,
    moveBlock,
    tidyBlocks,
    writeOverview,
    type BlockData,
    type BlockLayout,
    type BlockMeta,
    type Layout,
    type OverviewData,
    type OverviewProps,
    type Range,
    type Role,
    type Row,
} from './types';
import './workspace.css';

const rangeLabels = {
    today: 'Today',
    week: 'This week',
    month: 'This month',
    quarter: 'This quarter',
};
type Tab = 'overview' | 'attention' | 'insights';
/** The view-mode card footer (status line + View details). */
const FOOTER_HEIGHT = 43;
type DialogView = 'library' | 'starters' | 'saved' | 'find' | null;

function ScopeSelect({
    value,
    sites,
    onChange,
    label,
}: {
    value: string;
    sites: OverviewProps['sites'];
    onChange: (site: string) => void;
    label: string;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger aria-label={label} className="ov-scope-select">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="page">Follow page filter</SelectItem>
                <SelectItem value="all">All your sites</SelectItem>
                {sites.map((s) => (
                    <SelectItem key={s.id} value={String(s.id)}>
                        {s.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function PuzzleBlock({
    layout,
    meta,
    data,
    editing,
    sites,
    onChange,
    onRemove,
    onMove,
    expanded,
    onExpand,
    onEdit,
    children,
}: {
    layout: BlockLayout;
    meta: BlockMeta;
    data?: BlockData;
    editing: boolean;
    sites: OverviewProps['sites'];
    onChange: (value: Partial<BlockLayout>) => void;
    onRemove: () => void;
    onMove: (delta: number) => void;
    expanded: boolean;
    onExpand: () => void;
    onEdit: () => void;
    children?: ReactNode;
}) {
    const {
        setNodeRef,
        setActivatorNodeRef,
        attributes,
        listeners,
        isDragging,
        transform,
        transition,
    } = useSortable({ id: layout.id, disabled: !editing });
    const content = useRef<HTMLDivElement>(null);
    const tools = useRef<HTMLDivElement>(null);
    const bottom = useRef<HTMLDivElement>(null);
    const [naturalHeight, setNaturalHeight] = useState(300);
    // Saved heights are view-mode heights. The editor adds a tools bar and a
    // scope bar and drops the footer, so it converts with this difference.
    const [chrome, setChrome] = useState(0);
    useLayoutEffect(() => {
        setChrome(
            editing
                ? (tools.current?.offsetHeight ?? 0) +
                      (bottom.current?.offsetHeight ?? 0) -
                      (meta.kind === 'focus' ? 0 : FOOTER_HEIGHT)
                : 0,
        );
    }, [editing, meta.kind]);
    const [resize, setResize] = useState<{
        span: number;
        height: number;
    } | null>(null);
    const start = useRef<{
        x: number;
        y: number;
        width: number;
        span: number;
        height: number;
    } | null>(null);
    useLayoutEffect(() => {
        const element = content.current;
        if (!element) return;
        const observer = new ResizeObserver((entries) =>
            setNaturalHeight(Math.ceil(entries[0].contentRect.height)),
        );
        observer.observe(element);
        return () => observer.disconnect();
    }, []);
    const span = resize?.span ?? layout.span;
    const footerHeight = meta.kind === 'focus' || editing ? 0 : FOOTER_HEIGHT;
    const savedHeight = resize?.height ?? layout.height;
    const height = Math.max(
        naturalHeight + footerHeight,
        savedHeight ? savedHeight + chrome : 0,
    );
    const title = data?.title ?? meta.title;
    const style = {
        '--ov-span': span,
        '--ov-chart-height':
            layout.height || resize?.height
                ? `${Math.max(190, Math.min(320, (resize?.height ?? layout.height) - 180))}px`
                : undefined,
        gridRow: `span ${Math.max(1, Math.ceil((height + 20) / 36))}`,
        transform: CSS.Transform.toString(transform),
        transition: transition,
        zIndex: isDragging ? 5 : undefined,
    } as CSSProperties;
    function startResize(e: PointerEvent<HTMLButtonElement>) {
        const block = content.current?.parentElement;
        if (!block) return;
        e.preventDefault();
        e.currentTarget.setPointerCapture(e.pointerId);
        start.current = {
            x: e.clientX,
            y: e.clientY,
            span: layout.span,
            height: block.getBoundingClientRect().height - chrome,
            width: block.getBoundingClientRect().width,
        };
    }
    function resizeMove(e: PointerEvent<HTMLButtonElement>) {
        const initial = start.current;
        if (!initial) return;
        const estimated =
            initial.span +
            ((e.clientX - initial.x) / initial.width) * initial.span;
        const width = [4, 6, 8, 12].reduce((best, n) =>
            Math.abs(n - estimated) < Math.abs(best - estimated) ? n : best,
        );
        setResize({
            span: width,
            height: Math.max(
                0,
                Math.min(
                    720,
                    Math.round((initial.height + e.clientY - initial.y) / 36) *
                        36,
                ),
            ),
        });
    }
    function finishResize() {
        if (resize) onChange(resize);
        start.current = null;
        setResize(null);
    }
    return (
        <section
            ref={setNodeRef}
            style={style}
            className={`ov-card ${editing ? 'ov-card-edit' : ''} ${isDragging ? 'ov-dragging' : ''}`}
            aria-label={title}
            tabIndex={-1}
            data-block={layout.id}
            data-span={layout.span}
            data-kind={meta.kind}
        >
            <div ref={content} className="ov-card-inner">
                {editing && (
                    <div className="ov-block-tools" ref={tools}>
                        <button
                            type="button"
                            className="ov-icon-button ov-drag-handle"
                            ref={setActivatorNodeRef}
                            {...attributes}
                            {...listeners}
                            aria-label={`Move ${title}`}
                        >
                            <Grip size={17} />
                        </button>
                        <span>
                            {span === 4
                                ? 'Compact'
                                : span === 12
                                  ? 'Expanded'
                                  : 'Standard'}
                        </span>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="ov-tool-button"
                                    aria-label={`Size and position of ${title}`}
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>
                                    Block size
                                </DropdownMenuLabel>
                                {[4, 6, 8, 12].map((n) => (
                                    <DropdownMenuItem
                                        key={n}
                                        onSelect={() => onChange({ span: n })}
                                    >
                                        {n === 4
                                            ? 'Compact'
                                            : n === 6
                                              ? 'Half width'
                                              : n === 8
                                                ? 'Two thirds'
                                                : 'Full width'}
                                        {layout.span === n && (
                                            <Check className="ml-auto size-4" />
                                        )}
                                    </DropdownMenuItem>
                                ))}
                                <DropdownMenuItem
                                    onSelect={() => onChange({ height: 0 })}
                                >
                                    Fit content height
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem onSelect={() => onMove(-1)}>
                                    <ArrowUp /> Move earlier
                                </DropdownMenuItem>
                                <DropdownMenuItem onSelect={() => onMove(1)}>
                                    <ArrowDown /> Move later
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="ov-tool-button"
                            aria-label={`Remove ${title}`}
                            onClick={onRemove}
                        >
                            <X />
                        </Button>
                    </div>
                )}
                <header className="ov-card-head">
                    <div>
                        <h2>{title}</h2>
                        <p>{data?.subtitle ?? meta.description}</p>
                    </div>
                    {data?.state === 'proposed' ? (
                        <StatusBadge variant="neutral">Proposed</StatusBadge>
                    ) : (
                        !editing && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="ov-tool-button"
                                        aria-label={`Options for ${title}`}
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {data?.href && (
                                        <DropdownMenuItem asChild>
                                            <Link href={data.href}>
                                                <ArrowUpRight />
                                                Open workspace
                                            </Link>
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuItem onSelect={onEdit}>
                                        <LayoutDashboard />
                                        Edit layout
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )
                    )}
                </header>
                {layout.site !== 'page' &&
                    (!data?.scopeMode || data.scopeMode === 'site') && (
                        <div className="ov-fixed-scope">
                            <ShieldCheck size={12} />
                            Fixed to{' '}
                            {layout.site === 'all'
                                ? 'all your sites'
                                : (sites.find(
                                      (s) => String(s.id) === layout.site,
                                  )?.name ?? 'a site you can no longer see')}
                        </div>
                    )}
                <div
                    className="ov-block-content"
                    inert={editing ? true : undefined}
                >
                    <BlockContent
                        meta={meta}
                        data={data}
                        expanded={expanded}
                        onAction={onExpand}
                    />
                </div>
                {editing && (
                    <div className="ov-block-bottom" ref={bottom}>
                        {data?.scopeMode && data.scopeMode !== 'site' ? (
                            <span>
                                {data.scopeMode === 'personal'
                                    ? 'Personal · across sites'
                                    : 'Organisation-wide'}
                            </span>
                        ) : (
                            <ScopeSelect
                                value={layout.site}
                                sites={sites}
                                onChange={(site) => onChange({ site })}
                                label={`Site for ${title}`}
                            />
                        )}
                        <span>Drag corner to resize</span>
                    </div>
                )}
                {children}
            </div>
            {!editing && meta.kind !== 'focus' && (
                <footer className="ov-card-foot">
                    <span>
                        {data?.state === 'proposed'
                            ? 'Proposed summary · not connected'
                            : data?.state === 'restricted'
                              ? 'Available with the required permission'
                              : data?.state === 'unavailable' || data?.partial
                                ? 'Some information is unavailable'
                                : meta.id === 'attention'
                                  ? data && data.rows.length > 3 && !expanded
                                      ? `${data.rows.length - 3} more categories waiting`
                                      : data?.total === 0
                                        ? 'No outstanding priorities in this view'
                                        : 'Current work · ordered by urgency'
                                  : (data?.footnote ??
                                    'Live records · what you can see')}
                    </span>
                    {meta.id === 'attention' &&
                    !expanded &&
                    data &&
                    (data.partial ||
                        (data.total ?? 0) > 0 ||
                        data.rows.length > 0 ||
                        (data.reviewTotal ?? 0) > 0) ? (
                        // eslint-disable-next-line no-restricted-syntax -- styled as the footer's text link, like its sibling <Link>s
                        <button type="button" onClick={onExpand}>
                            View all {data?.total ?? 0}{' '}
                            {data?.total === 1 ? 'item' : 'items'}{' '}
                            <ArrowRight size={14} />
                        </button>
                    ) : (
                        data?.href && (
                            <Link href={data.href}>
                                {data.actionLabel ?? 'View details'}{' '}
                                <ArrowUpRight size={13} />
                            </Link>
                        )
                    )}
                </footer>
            )}
            {editing && (
                <button
                    className="ov-resize"
                    aria-label={`Resize ${title}`}
                    onPointerDown={startResize}
                    onPointerMove={resizeMove}
                    onPointerUp={finishResize}
                    onPointerCancel={() => {
                        start.current = null;
                        setResize(null);
                    }}
                    onKeyDown={(e) => {
                        if (
                            ![
                                'ArrowLeft',
                                'ArrowRight',
                                'ArrowUp',
                                'ArrowDown',
                            ].includes(e.key)
                        )
                            return;
                        e.preventDefault();
                        if (e.key === 'ArrowUp' || e.key === 'ArrowDown')
                            onChange({
                                height: Math.max(
                                    0,
                                    Math.min(
                                        720,
                                        (layout.height ||
                                            naturalHeight - chrome) +
                                            (e.key === 'ArrowDown' ? 36 : -36),
                                    ),
                                ),
                            });
                        else {
                            const widths = [4, 6, 8, 12];
                            onChange({
                                span: widths[
                                    Math.max(
                                        0,
                                        Math.min(
                                            3,
                                            widths.indexOf(layout.span) +
                                                (e.key === 'ArrowRight'
                                                    ? 1
                                                    : -1),
                                        ),
                                    )
                                ],
                            });
                        }
                    }}
                >
                    <Maximize2 size={15} />
                </button>
            )}
        </section>
    );
}

export default function OverviewWorkspace(props: OverviewProps) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Overview', href: '/dashboard' },
            ]}
        >
            <Head title="Overview" />
            <Workspace
                key={`${props.role}-${props.filters.site}-${props.filters.range}`}
                {...props}
            />
        </AppLayout>
    );
}

function Workspace({
    role,
    roles,
    defaultRole,
    sites,
    filters,
    overview,
    preference,
}: OverviewProps) {
    const roleMeta = useMemo(
        () => ({
            ...catalog[role],
            blocks: catalog[role].blocks.map((meta) => ({
                ...meta,
                title: overview.blocks[meta.id]?.title ?? meta.title,
                description:
                    overview.blocks[meta.id]?.state === 'ready' &&
                    [
                        'focus',
                        'trend',
                        'distribution',
                        'sites',
                        'agenda',
                    ].includes(meta.id)
                        ? overview.blocks[meta.id].subtitle
                        : meta.description,
            })),
        }),
        [role, overview.blocks],
    );
    const initial = {
        ...preference.value,
        range: filters.range,
        blocks: preference.value.blocks ?? defaultBlocks(role),
    };
    const [saved, setSaved] = useState<Layout>(initial);
    const [draft, setDraft] = useState<Layout | null>(null);
    const current = draft ?? saved;
    const [history, setHistory] = useState<Layout[]>([]);
    const [future, setFuture] = useState<Layout[]>([]);
    const [revision, setRevision] = useState(preference.revision);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [dialog, setDialog] = useState<DialogView>(null);
    const [previewId, setPreviewId] = useState<string | null>(null);
    // The tab lives in the URL too, so changing site or period keeps it.
    const [tab, setTab] = useState<Tab>(() => {
        const value =
            typeof window === 'undefined'
                ? null
                : new URLSearchParams(window.location.search).get('tab');
        return value === 'attention' || value === 'insights'
            ? value
            : 'overview';
    });
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('All');
    const [favoritesOnly, setFavoritesOnly] = useState(false);
    const [availableOnly, setAvailableOnly] = useState(false);
    const [copyName, setCopyName] = useState('');
    const [copyError, setCopyError] = useState('');
    const [announcement, setAnnouncement] = useState('');
    const [scopes, setScopes] = useState<Record<string, OverviewData>>({});
    const [refreshing, setRefreshing] = useState(false);
    const [isDefault, setIsDefault] = useState(defaultRole === role);
    const [detailRow, setDetailRow] = useState<Row | null>(null);
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [conflict, setConflict] = useState<{
        revision: string | null;
        value: OverviewProps['preference']['value'];
    } | null>(null);
    const [savingDefault, setSavingDefault] = useState(false);
    const board = useRef<HTMLDivElement>(null);
    /** Where focus goes when the dialog closes (a Find result's block). */
    const returnFocus = useRef<string | null>(null);
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 7 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );
    const editing = draft !== null;
    // Warnings stay in Needs attention; only critical work is called urgent.
    const urgentNow = overview.urgent.filter((row) => row.tone === 'critical');
    // Blocks pinned to another site, grouped by site: "3:trend+sites,7:agenda".
    // Each site fetch works out only its own pinned blocks.
    const scopeKey = Object.entries(
        current.blocks.reduce<Record<string, string[]>>((sites, b) => {
            const site = b.site === 'page' ? filters.site : b.site;
            if (site !== filters.site) (sites[site] ??= []).push(b.id);
            return sites;
        }, {}),
    )
        .map(([site, ids]) => `${site}:${[...ids].sort().join('+')}`)
        .sort()
        .join(',');
    useEffect(() => {
        const controller = new AbortController();
        for (const entry of scopeKey.split(',').filter(Boolean)) {
            const [site, ids] = entry.split(':');
            fetch(
                `/dashboard/data?${new URLSearchParams({ role, range: filters.range, site, blocks: ids.split('+').join(',') })}`,
                {
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                },
            )
                .then(async (response) => {
                    if (!response.ok) throw new Error('Scope unavailable');
                    return (await response.json()) as OverviewData;
                })
                .then((data) =>
                    setScopes((previous) => ({ ...previous, [site]: data })),
                )
                .catch((e) => {
                    if (e.name !== 'AbortError')
                        setScopes((previous) => ({
                            ...previous,
                            [site]: {
                                ...overview,
                                metrics: [],
                                urgent: [],
                                blocks: Object.fromEntries(
                                    roleMeta.blocks.map((b) => [
                                        b.id,
                                        {
                                            state: 'unavailable',
                                            subtitle: 'Information unavailable',
                                            message:
                                                'This site summary could not be loaded. Check your access or refresh.',
                                            rows: [],
                                            series: [],
                                            total: null,
                                        },
                                    ]),
                                ),
                            },
                        }));
                });
        }
        return () => controller.abort();
    }, [scopeKey, role, filters.range, overview, roleMeta]);
    // The page load works out only the layout's blocks. The editor needs the
    // whole library (previews, newly added blocks), so it loads it once here.
    // Tied to the page data it was loaded with, so a refresh or new filters
    // never show last time's previews.
    const [libraryLoad, setLibraryLoad] = useState<{
        source: OverviewData;
        blocks: Record<string, BlockData>;
    } | null>(null);
    const libraryData =
        libraryLoad?.source === overview ? libraryLoad.blocks : null;
    useEffect(() => {
        if (!editing || libraryData) return;
        const setLibraryData = (blocks: Record<string, BlockData>) =>
            setLibraryLoad({ source: overview, blocks });
        const controller = new AbortController();
        fetch(
            `/dashboard/data?${new URLSearchParams({ role, range: filters.range, site: filters.site, blocks: 'all' })}`,
            {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            },
        )
            .then(async (response) => {
                if (!response.ok) throw new Error('Library unavailable');
                return (await response.json()) as OverviewData;
            })
            .then((data) => setLibraryData(data.blocks))
            .catch((e) => {
                if (e.name === 'AbortError') return;
                setLibraryData(
                    Object.fromEntries(
                        roleMeta.blocks.map((b) => [
                            b.id,
                            {
                                state: 'unavailable',
                                subtitle: 'Information unavailable',
                                message:
                                    'This summary could not be loaded. Refresh to try again.',
                                rows: [],
                                series: [],
                                total: null,
                            } satisfies BlockData,
                        ]),
                    ),
                );
            });
        return () => controller.abort();
    }, [
        editing,
        libraryData,
        overview,
        role,
        filters.range,
        filters.site,
        roleMeta,
    ]);
    const pageBlocks = useMemo(
        () =>
            libraryData
                ? Object.fromEntries(
                      Object.entries(overview.blocks).map(([id, block]) => [
                          id,
                          block.state === 'deferred'
                              ? (libraryData[id] ?? block)
                              : block,
                      ]),
                  )
                : overview.blocks,
        [overview.blocks, libraryData],
    );
    useEffect(() => {
        if (!editing) return;
        const beforeUnload = (e: BeforeUnloadEvent) => {
            e.preventDefault();
        };
        window.addEventListener('beforeunload', beforeUnload);
        const cancelVisit = router.on('before', () => {
            toast.info('Save or cancel your layout before leaving this page.');
            return false;
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            cancelVisit();
        };
    }, [editing]);
    // Editor shortcuts listen on the window: removing a block unmounts the
    // focused control, and Ctrl+Z must still work from the page body.
    const shortcuts = useRef<(e: KeyboardEvent) => void>(() => {});
    shortcuts.current = (e: KeyboardEvent) => {
        const target = e.target as HTMLElement;
        if (
            dialog ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) ||
            target.isContentEditable
        )
            return;
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            void save();
        }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
            e.preventDefault();
            if (e.shiftKey) redo();
            else undo();
        }
    };
    useEffect(() => {
        if (!editing) return;
        const onKey = (e: KeyboardEvent) => shortcuts.current(e);
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [editing]);
    const change = useCallback(
        (next: Layout, message = 'Layout updated') => {
            if (!draft || saving) return;
            setHistory((h) => [...h.slice(-29), draft]);
            setFuture([]);
            setDraft(next);
            setAnnouncement(message);
        },
        [draft, saving],
    );
    function undo() {
        if (!draft || saving || !history.length) return;
        setFuture((f) => [draft, ...f]);
        setDraft(history[history.length - 1]);
        setHistory((h) => h.slice(0, -1));
        setAnnouncement('Layout change undone');
    }
    function redo() {
        if (!draft || saving || !future.length) return;
        setHistory((h) => [...h, draft]);
        setDraft(future[0]);
        setFuture((f) => f.slice(1));
        setAnnouncement('Layout change restored');
    }
    function edit() {
        setDraft(structuredClone(saved));
        setHistory([]);
        setFuture([]);
        setTab('overview');
        setError('');
    }
    async function save() {
        if (!draft || saving) return;
        setSaving(true);
        setError('');
        try {
            const result = await writeOverview(`/dashboard/layout/${role}`, {
                value: draft,
                revision,
            });
            setSaved(draft);
            setRevision(result.revision);
            setDraft(null);
            setAnnouncement('Your overview layout has been saved.');
            toast.success('Overview layout saved');
        } catch (e) {
            const failure = e as Error & {
                status?: number;
                data?: { revision?: string | null; value?: unknown };
            };
            if (failure.status === 409 && failure.data)
                setConflict({
                    revision: failure.data.revision ?? null,
                    value: failure.data
                        .value as OverviewProps['preference']['value'],
                });
            setError(
                e instanceof Error ? e.message : 'Could not save this layout.',
            );
        } finally {
            setSaving(false);
        }
    }
    /** After a clash with another tab: keep the draft and save over it. */
    function keepDraft() {
        if (!conflict) return;
        setRevision(conflict.revision);
        setConflict(null);
        setError('');
        setAnnouncement(
            'Your changes will replace the other tab’s layout when you save.',
        );
    }
    /** After a clash with another tab: adopt the newer saved layout. */
    function adoptLatest() {
        if (!conflict) return;
        const latest = {
            ...conflict.value,
            range: filters.range,
            blocks: conflict.value.blocks ?? defaultBlocks(role),
        };
        setSaved(latest);
        setRevision(conflict.revision);
        setConflict(null);
        setDraft(null);
        setError('');
        setAnnouncement('The layout from the other tab is now showing.');
    }
    function updateBlock(id: string, value: Partial<BlockLayout>) {
        change({
            ...current,
            blocks: current.blocks.map((b) =>
                b.id === id ? { ...b, ...value } : b,
            ),
        });
    }
    function addBlock(id: string) {
        const meta = roleMeta.blocks.find((b) => b.id === id);
        if (!meta || current.blocks.some((b) => b.id === id)) return;
        change(
            {
                ...current,
                blocks: [
                    ...current.blocks,
                    { id, span: meta.span, height: 0, site: 'page' },
                ],
            },
            `${meta.title} added`,
        );
    }
    function favorite(id: string) {
        change(
            {
                ...current,
                favorites: current.favorites.includes(id)
                    ? current.favorites.filter((x) => x !== id)
                    : [...current.favorites, id],
            },
            'Favourites updated',
        );
    }
    function dataFor(block: BlockLayout): BlockData | undefined {
        const site = block.site === 'page' ? filters.site : block.site;
        return site === filters.site
            ? pageBlocks[block.id]
            : scopes[site]?.blocks[block.id];
    }
    function navigate(next: { role?: Role; site?: string; range?: Range }) {
        if (editing) return;
        router.get(
            '/dashboard',
            {
                role,
                ...filters,
                ...next,
                ...(next.role ? { site: 'all', range: undefined } : {}),
                tab: tab === 'overview' ? undefined : tab,
            },
            { preserveScroll: true, replace: true },
        );
    }
    function tidy() {
        const blocks = tidyBlocks(current.blocks);
        let row: BlockLayout[] = [],
            width = 0;
        // Measure each card as it will look outside the editor: its content
        // without the tools and scope bars, plus the footer.
        const viewHeight = (b: BlockLayout) => {
            const card = board.current?.querySelector(`[data-block="${b.id}"]`);
            const measure = (selector: string) =>
                card?.querySelector(selector)?.getBoundingClientRect().height ??
                0;
            const kind = roleMeta.blocks.find((m) => m.id === b.id)?.kind;
            return (
                measure('.ov-card-inner') -
                measure('.ov-block-tools') -
                measure('.ov-block-bottom') +
                (kind === 'focus' ? 0 : FOOTER_HEIGHT)
            );
        };
        const align = () => {
            const height = Math.min(
                720,
                Math.ceil(Math.max(...row.map(viewHeight))),
            );
            row.forEach((b) => {
                b.height = height;
            });
            row = [];
            width = 0;
        };
        for (const block of blocks) {
            if (width + block.span > 12) align();
            row.push(block);
            width += block.span;
            if (width === 12) align();
        }
        if (row.length) align();
        change({ ...current, blocks }, 'Layout tidied');
    }
    function refresh() {
        if (editing) return;
        setRefreshing(true);
        router.reload({
            only: ['overview', 'sites', 'preference'],
            onFinish: () => setRefreshing(false),
        });
    }
    function startDialog(view: DialogView) {
        if (saving) return;
        setQuery('');
        setPreviewId(null);
        setDialog(view);
        setCopyError('');
    }
    function applyCopy() {
        const name = copyName.trim();
        if (!name) {
            setCopyError('Give your arrangement a name.');
            return;
        }
        if (
            current.copies.some(
                (c) => c.name.toLowerCase() === name.toLowerCase(),
            )
        ) {
            setCopyError('You already have an arrangement with that name.');
            return;
        }
        if (current.copies.length >= 3) {
            setCopyError(
                'You can keep three arrangements per role. Remove one to add another.',
            );
            return;
        }
        change(
            {
                ...current,
                copies: [
                    ...current.copies,
                    {
                        name,
                        blocks: structuredClone(current.blocks),
                        density: current.density,
                    },
                ],
            },
            'Arrangement added to your draft',
        );
        setCopyName('');
        setCopyError('');
    }
    function dragEnd(event: DragEndEvent) {
        const { active, over } = event;
        if (!over || active.id === over.id) return;
        const old = current.blocks.findIndex((b) => b.id === active.id),
            next = current.blocks.findIndex((b) => b.id === over.id);
        change(
            { ...current, blocks: arrayMove(current.blocks, old, next) },
            'Block moved',
        );
    }
    const library = useMemo(
        () =>
            roleMeta.blocks
                .filter(
                    (b) =>
                        (!query ||
                            `${b.title} ${b.description}`
                                .toLowerCase()
                                .includes(query.toLowerCase())) &&
                        (category === 'All' || b.category === category) &&
                        (!favoritesOnly || current.favorites.includes(b.id)) &&
                        (!availableOnly ||
                            !current.blocks.some((c) => c.id === b.id)),
                )
                .sort(
                    (a, b) =>
                        Number(current.favorites.includes(b.id)) -
                        Number(current.favorites.includes(a.id)),
                ),
        [roleMeta, query, category, favoritesOnly, availableOnly, current],
    );
    // Needs attention follows the approved mockup: the full priority list,
    // the next step beside it, then the upcoming dates.
    const attentionView: BlockLayout[] = [
        { id: 'attention', span: 8, height: 0, site: 'page' },
        { id: 'focus', span: 4, height: 0, site: 'page' },
        { id: 'agenda', span: 12, height: 0, site: 'page' },
    ];
    const shown =
        tab === 'attention'
            ? attentionView.filter((b) =>
                  roleMeta.blocks.some((m) => m.id === b.id),
              )
            : tab === 'insights'
              ? fillRows(
                    current.blocks.filter((b) =>
                        ['trend', 'donut', 'bars', 'progress'].includes(
                            roleMeta.blocks.find((m) => m.id === b.id)?.kind ??
                                '',
                        ),
                    ),
                )
              : current.blocks;
    const primaryHref = {
        worker: '/my-day',
        lead: '/my-day',
        manager: '/operations',
        hr: '/hr/analytics',
        it: '/it',
        governance: '/governance/dashboard',
    }[role];
    const primaryLabel = {
        worker: 'Open My Day',
        lead: 'Open team work',
        manager: 'Open operations',
        hr: 'Review HR work',
        it: 'Open service desk',
        governance: 'Board workspace',
    }[role];
    const previewMeta = roleMeta.blocks.find((b) => b.id === previewId);
    const titleOf = (id: string | number) =>
        roleMeta.blocks.find((b) => b.id === id)?.title ?? 'block';
    const needle = query.trim().toLowerCase();
    const foundSteps =
        dialog === 'find'
            ? overview.urgent.filter((r) =>
                  r.label.toLowerCase().includes(needle),
              )
            : [];
    const foundBlocks =
        dialog === 'find'
            ? roleMeta.blocks.filter(
                  (b) =>
                      current.blocks.some((c) => c.id === b.id) &&
                      b.title.toLowerCase().includes(needle),
              )
            : [];
    return (
        <div
            className="ov-workspace"
            data-editing={editing}
            data-density={current.density}
            data-role={role}
        >
            <PageHeader
                icon={
                    {
                        worker: HeartHandshake,
                        lead: UsersRound,
                        manager: Building2,
                        hr: ContactRound,
                        it: MonitorCog,
                        governance: Landmark,
                    }[role]
                }
                title="Overview"
                titleChip={
                    <PageHeaderStatusChip variant="neutral">
                        {roleMeta.name}
                    </PageHeaderStatusChip>
                }
                subline={roleMeta.description}
                actions={
                    !editing && (
                        <>
                            <PageHeaderSearchTrigger
                                className="ov-hero-find"
                                placeholder="Find in overview…"
                                onOpen={() => startDialog('find')}
                            />
                            <PageHeaderGlassButton onClick={edit}>
                                <LayoutDashboard size={16} />
                                Edit layout
                            </PageHeaderGlassButton>
                            {overview.primary?.target === 'attention' ? (
                                <PageHeaderPrimaryButton
                                    onClick={() => setTab('attention')}
                                >
                                    {overview.primary.label}{' '}
                                    <ArrowUpRight size={16} />
                                </PageHeaderPrimaryButton>
                            ) : (
                                <PageHeaderPrimaryButton asChild>
                                    <Link
                                        href={
                                            overview.primary?.href ??
                                            primaryHref
                                        }
                                    >
                                        {overview.primary?.label ??
                                            primaryLabel}
                                        <ArrowUpRight size={16} />
                                    </Link>
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    )
                }
                meters={
                    !editing &&
                    overview.metrics.map((m, i) => (
                        <PageHeaderMeterBlock
                            key={m.label}
                            label={m.label}
                            value={
                                m.instrument === 'bar'
                                    ? m.display
                                    : m.total !== undefined
                                      ? (m.display ??
                                        `${m.value ?? '—'} / ${m.total}`)
                                      : undefined
                            }
                            {...(m.href
                                ? { href: m.href }
                                : { onClick: () => setTab('attention') })}
                            // OverviewData always places Needs attention second.
                            tone={
                                i === 1
                                    ? urgentNow.length
                                        ? 'critical'
                                        : overview.urgent.length
                                          ? 'warning'
                                          : 'brand'
                                    : 'brand'
                            }
                        >
                            {m.instrument === 'bar' ? (
                                <PageHeaderMeterBar percent={m.percent ?? 0} />
                            ) : m.total !== undefined && m.total > 0 ? (
                                <PageHeaderMeterDonut
                                    percent={Math.round(
                                        ((m.value ?? 0) / m.total) * 100,
                                    )}
                                    caption={m.instrumentCaption}
                                />
                            ) : m.total === 0 ? (
                                <span className="ov-meter-no-data">
                                    <span>—</span>
                                    {m.instrumentCaption ?? 'No records yet'}
                                </span>
                            ) : m.people?.length ? (
                                <div className="ov-duty-meter">
                                    <PageHeaderMeterBig>
                                        {m.value ?? '—'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterAvatars
                                        people={m.people}
                                        overflow={Math.max(
                                            0,
                                            (m.value ?? 0) - m.people.length,
                                        )}
                                    />
                                </div>
                            ) : (
                                <PageHeaderMeterBig>
                                    {m.display ?? m.value ?? '—'}
                                </PageHeaderMeterBig>
                            )}
                            {m.series && m.series.some(Boolean) && (
                                <PageHeaderMeterSpark
                                    values={m.series.slice(-7)}
                                />
                            )}
                            <PageHeaderMeterCaption>
                                {m.caption}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))
                }
                filters={
                    !editing && (
                        <>
                            <span className="ov-header-scope">
                                <ShieldCheck size={13} />
                                {overview.scope}
                            </span>
                            {roles.length > 1 && (
                                <PageHeaderFilterSelect
                                    size="comfortable"
                                    icon={UsersRound}
                                    label={roleMeta.name}
                                    value={role}
                                    allValue={role}
                                    options={roles.map((r) => ({
                                        value: r,
                                        label: catalog[r].name,
                                    }))}
                                    onChange={(r) => {
                                        if (roles.includes(r as Role))
                                            navigate({ role: r as Role });
                                    }}
                                />
                            )}
                            {role !== 'governance' && (
                                <PageHeaderFilterSelect
                                    size="comfortable"
                                    icon={Building2}
                                    label="All your sites"
                                    value={filters.site}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All your sites',
                                        },
                                        ...sites.map((s) => ({
                                            value: String(s.id),
                                            label: s.name,
                                        })),
                                    ]}
                                    onChange={(site) => navigate({ site })}
                                />
                            )}
                            <PageHeaderFilterSelect
                                size="comfortable"
                                icon={CalendarDays}
                                label={rangeLabels[filters.range]}
                                value={filters.range}
                                allValue={filters.range}
                                options={Object.entries(rangeLabels).map(
                                    ([value, label]) => ({ value, label }),
                                )}
                                onChange={(range) => {
                                    if (range in rangeLabels)
                                        navigate({ range: range as Range });
                                }}
                            />
                        </>
                    )
                }
                rail={
                    !editing && (
                        <PageHeaderRail
                            items={[
                                {
                                    key: 'overview',
                                    label: 'At a glance',
                                    icon: LayoutDashboard,
                                },
                                {
                                    key: 'attention',
                                    label: 'Needs attention',
                                    count: overview.blocks.attention.total ?? 0,
                                    alert: urgentNow.length > 0,
                                },
                                {
                                    key: 'insights',
                                    label:
                                        role === 'worker'
                                            ? 'My week'
                                            : 'Insights',
                                },
                            ]}
                            value={tab}
                            onSelect={setTab}
                            onFind={() => startDialog('find')}
                            ariaLabel="Overview sections"
                        />
                    )
                }
            />
            {urgentNow.length > 0 && (
                <div className="ov-urgent" role="status">
                    <TriangleAlert size={18} aria-hidden="true" />
                    <strong>
                        {urgentNow.length === 1
                            ? '1 urgent item'
                            : `${urgentNow.length} urgent items`}
                    </strong>
                    <span>{urgentNow[0].label}</span>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            if (editing) startDialog('find');
                            else setTab('attention');
                        }}
                    >
                        Review now
                        <ArrowUpRight />
                    </Button>
                </div>
            )}
            {overview.partial && (
                <div className="ov-unavailable">
                    <CloudOff size={18} />
                    <span>
                        Some information could not be loaded. Available
                        summaries remain visible.
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={refresh}
                        disabled={editing || refreshing}
                    >
                        Retry
                    </Button>
                </div>
            )}
            {!editing && (
                <div className="ov-context">
                    {overview.partial || overview.blocks.attention.total ? (
                        // eslint-disable-next-line no-restricted-syntax -- a quiet text link in the status line, not a button
                        <button
                            type="button"
                            className="ov-context-priorities"
                            onClick={() => setTab('attention')}
                        >
                            {overview.partial
                                ? 'Some summaries need a refresh'
                                : `${overview.blocks.attention.total} ${overview.blocks.attention.total === 1 ? 'item needs' : 'items need'} your review`}
                            <ArrowRight size={13} />
                        </button>
                    ) : (
                        <span className="ov-context-priorities">
                            <Check size={14} />
                            You’re up to date
                        </span>
                    )}
                    <span>
                        {formatDate(overview.period.start)} –{' '}
                        {formatDate(overview.period.end)}
                    </span>
                    {/* eslint-disable-next-line no-restricted-syntax -- the "Updated" timestamp doubles as a quiet refresh link */}
                    <button
                        type="button"
                        className="ov-context-refresh"
                        onClick={refresh}
                        disabled={refreshing}
                        aria-label={`Refresh · updated ${formatTime(overview.updatedAt)}`}
                    >
                        <RefreshCw
                            size={13}
                            className={refreshing ? 'animate-spin' : undefined}
                        />
                        Updated {formatTime(overview.updatedAt)}
                    </button>
                </div>
            )}
            {editing && (
                <div className="ov-editor-toolbar">
                    <div className="ov-editor-top">
                        <div>
                            <span className="ov-eyebrow">Your layout</span>
                            <h2>Edit your overview</h2>
                            <p>
                                {roleMeta.name} · {current.blocks.length} blocks
                                · Changes save when you choose Save layout.
                            </p>
                        </div>
                        <div className="ov-toolbar-actions">
                            <Button
                                variant="outline"
                                disabled={saving}
                                onClick={() => startDialog('library')}
                            >
                                <Plus />
                                Add blocks
                            </Button>
                            <Button
                                variant="ghost"
                                disabled={saving}
                                onClick={() => {
                                    if (history.length) {
                                        setConfirmCancel(true);
                                        return;
                                    }
                                    setDraft(null);
                                    setError('');
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                disabled={saving}
                                onClick={() => void save()}
                            >
                                {saving ? 'Saving…' : 'Save layout'}
                            </Button>
                        </div>
                    </div>
                    <div className="ov-editor-secondary" inert={saving}>
                        <span>Drag to arrange · Resize a corner</span>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            className="ov-density"
                            aria-label="Information density"
                            value={current.density}
                            onValueChange={(d) => {
                                if (d === 'balanced' || d === 'compact')
                                    change({ ...current, density: d });
                            }}
                        >
                            <ToggleGroupItem value="balanced">
                                Balanced
                            </ToggleGroupItem>
                            <ToggleGroupItem value="compact">
                                Compact
                            </ToggleGroupItem>
                        </ToggleGroup>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="ov-tool-button"
                            aria-label="Undo layout change"
                            disabled={!history.length}
                            onClick={undo}
                        >
                            <Undo2 />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="ov-tool-button"
                            aria-label="Redo layout change"
                            disabled={!future.length}
                            onClick={redo}
                        >
                            <Redo2 />
                        </Button>
                        <Button variant="ghost" size="sm" onClick={tidy}>
                            <Sparkles />
                            Tidy layout
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => startDialog('saved')}
                        >
                            <BookOpen />
                            Saved layouts
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                change(
                                    {
                                        ...current,
                                        blocks: defaultBlocks(role),
                                        density: 'balanced',
                                    },
                                    'Role defaults restored to your draft',
                                )
                            }
                        >
                            <RotateCcw />
                            Restore defaults
                        </Button>
                    </div>
                    {error && (
                        <div role="alert" className="ov-save-error">
                            <p>{error}</p>
                            {conflict && (
                                <div className="ov-save-error-actions">
                                    <Button size="sm" onClick={keepDraft}>
                                        Keep my changes
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={adoptLatest}
                                    >
                                        Use the other tab’s layout
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
            <div
                className={editing ? 'ov-editor-stage' : ''}
                inert={saving}
                aria-busy={saving}
                role={editing ? 'region' : undefined}
                aria-label={editing ? 'Scrollable dashboard layout' : undefined}
            >
                <OverviewDetailContext.Provider
                    value={editing ? null : setDetailRow}
                >
                    <DndContext
                        accessibility={{
                            announcements: {
                                onDragStart: ({ active }) =>
                                    `Picked up ${titleOf(active.id)}.`,
                                onDragOver: ({ active, over }) =>
                                    over
                                        ? `${titleOf(active.id)} is over ${titleOf(over.id)}.`
                                        : `${titleOf(active.id)} is not over a block.`,
                                onDragEnd: ({ active, over }) =>
                                    over
                                        ? `${titleOf(active.id)} moved to where ${titleOf(over.id)} was.`
                                        : `${titleOf(active.id)} dropped.`,
                                onDragCancel: ({ active }) =>
                                    `Moving ${titleOf(active.id)} was cancelled.`,
                            },
                            screenReaderInstructions: {
                                draggable:
                                    'To move a block, press Space or Enter, use the arrow keys, then press Space or Enter to drop it. Press Escape to cancel.',
                            },
                        }}
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        onDragEnd={dragEnd}
                    >
                        <SortableContext
                            items={shown.map((b) => b.id)}
                            strategy={rectSortingStrategy}
                        >
                            <div
                                className="ov-board"
                                ref={board}
                                data-natural={
                                    !editing &&
                                    shown.every((block) => !block.height)
                                }
                            >
                                {shown.map((block) => {
                                    const meta = roleMeta.blocks.find(
                                        (b) => b.id === block.id,
                                    );
                                    if (!meta) return null;
                                    return (
                                        <PuzzleBlock
                                            key={block.id}
                                            layout={block}
                                            meta={meta}
                                            data={dataFor(block)}
                                            editing={editing}
                                            expanded={tab === 'attention'}
                                            onExpand={() => setTab('attention')}
                                            onEdit={edit}
                                            sites={sites}
                                            onChange={(value) =>
                                                updateBlock(block.id, value)
                                            }
                                            onRemove={() => {
                                                const index =
                                                    current.blocks.findIndex(
                                                        (b) =>
                                                            b.id === block.id,
                                                    );
                                                const next =
                                                    current.blocks[index + 1] ??
                                                    current.blocks[index - 1];
                                                change(
                                                    {
                                                        ...current,
                                                        blocks: current.blocks.filter(
                                                            (b) =>
                                                                b.id !==
                                                                block.id,
                                                        ),
                                                    },
                                                    `${meta.title} removed`,
                                                );
                                                // The focused ✕ unmounts; keep the
                                                // keyboard user on the board.
                                                requestAnimationFrame(() =>
                                                    (
                                                        board.current?.querySelector(
                                                            next
                                                                ? `[data-block="${next.id}"] .ov-drag-handle`
                                                                : '.ov-add-tile',
                                                        ) as HTMLElement | null
                                                    )?.focus(),
                                                );
                                            }}
                                            onMove={(delta) =>
                                                change({
                                                    ...current,
                                                    blocks: moveBlock(
                                                        current.blocks,
                                                        block.id,
                                                        delta,
                                                    ),
                                                })
                                            }
                                        />
                                    );
                                })}
                                {editing && (
                                    // eslint-disable-next-line no-restricted-syntax -- an empty grid slot that opens the block library
                                    <button
                                        type="button"
                                        className="ov-add-tile"
                                        onClick={() => startDialog('library')}
                                    >
                                        <Plus size={22} />
                                        <strong>Add another block</strong>
                                        <span>
                                            {roleMeta.blocks.length -
                                                current.blocks.length}{' '}
                                            more to choose from
                                        </span>
                                    </button>
                                )}
                            </div>
                        </SortableContext>
                    </DndContext>
                </OverviewDetailContext.Provider>
                {!shown.length && !editing && (
                    <div className="ov-board-empty">
                        <LayoutDashboard />
                        <h2>
                            {tab === 'insights'
                                ? 'No insight blocks selected'
                                : 'Your overview, your way'}
                        </h2>
                        <p>Add blocks or start with a suggested arrangement.</p>
                        <Button onClick={edit}>Choose blocks</Button>
                    </div>
                )}
            </div>
            <footer className="ov-footer">
                {editing ? (
                    <span>
                        Move: Space, arrow keys, Enter · Resize: arrow keys ·
                        Save: Ctrl Enter · Undo: Ctrl Z
                    </span>
                ) : (
                    <span>Live records · {overview.scope}</span>
                )}
                {!editing &&
                    roles.length > 1 &&
                    (isDefault ? (
                        <span className="ov-default-note">
                            <Check size={13} />
                            {roleMeta.name} opens first when you sign in
                        </span>
                    ) : (
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={savingDefault}
                            onClick={async () => {
                                if (savingDefault) return;
                                setSavingDefault(true);
                                try {
                                    await writeOverview(
                                        '/dashboard/default-role',
                                        { role },
                                    );
                                    setIsDefault(true);
                                    toast.success(
                                        `${roleMeta.name} is now your default overview`,
                                    );
                                } catch (e) {
                                    toast.error(
                                        e instanceof Error
                                            ? e.message
                                            : 'Could not save default',
                                    );
                                } finally {
                                    setSavingDefault(false);
                                }
                            }}
                        >
                            <Star />
                            Open {roleMeta.name} first
                        </Button>
                    ))}
            </footer>
            <div className="sr-only" role="status" aria-live="polite">
                {announcement}
            </div>
            <Dialog
                open={dialog !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDialog(null);
                        setPreviewId(null);
                    }
                }}
            >
                <DialogContent
                    className={`ov-dialog ${dialog === 'library' && !previewId ? 'ov-library-dialog' : ''}`}
                    onCloseAutoFocus={(e) => {
                        const target = returnFocus.current
                            ? (board.current?.querySelector(
                                  returnFocus.current,
                              ) as HTMLElement | null)
                            : null;
                        returnFocus.current = null;
                        if (target) {
                            e.preventDefault();
                            target.focus({ preventScroll: true });
                        }
                    }}
                    // POPUP_STYLE_GUIDE width tokens: long form for the
                    // library grid, standard form for everything else.
                    style={
                        dialog === 'library' && !previewId
                            ? {
                                  maxWidth: 'min(92vw, 900px)',
                                  width: 'min(92vw, 900px)',
                              }
                            : {
                                  maxWidth: 'min(92vw, 720px)',
                                  width: 'min(92vw, 720px)',
                              }
                    }
                >
                    <DialogHeader>
                        <DialogTitle>
                            {previewMeta
                                ? previewMeta.title
                                : dialog === 'library'
                                  ? 'Choose your blocks'
                                  : dialog === 'starters'
                                    ? 'Starter layouts'
                                    : dialog === 'saved'
                                      ? 'Your saved arrangements'
                                      : 'Find in your overview'}
                        </DialogTitle>
                        <DialogDescription>
                            {previewMeta
                                ? 'Block preview'
                                : `${roleMeta.name} · ${dialog === 'library' ? `${roleMeta.blocks.length} choices · ${roleMeta.blocks.length - current.blocks.length} available to add` : dialog === 'starters' ? `${roleMeta.starters.length} focused arrangements` : dialog === 'saved' ? 'Keep up to three arrangements for this role' : 'Jump to a summary or review urgent work'}`}
                        </DialogDescription>
                    </DialogHeader>
                    {dialog === 'library' && !previewMeta && (
                        <>
                            <div className="ov-library-toolbar">
                                <div className="ov-search">
                                    <Search size={16} />
                                    <Input
                                        aria-label="Search blocks"
                                        placeholder="Search blocks…"
                                        value={query}
                                        onChange={(e) =>
                                            setQuery(e.target.value)
                                        }
                                    />
                                </div>
                                <Button
                                    variant="outline"
                                    onClick={() => startDialog('starters')}
                                >
                                    <LayoutTemplate />
                                    Starter layouts
                                </Button>
                            </div>
                            <div className="ov-library-filters">
                                <ToggleGroup
                                    type="single"
                                    size="sm"
                                    aria-label="Block category"
                                    value={category}
                                    onValueChange={(c) => c && setCategory(c)}
                                >
                                    {[
                                        'All',
                                        ...new Set(
                                            roleMeta.blocks.map(
                                                (b) => b.category,
                                            ),
                                        ),
                                    ].map((c) => (
                                        <ToggleGroupItem key={c} value={c}>
                                            {c}
                                            <span className="ov-filter-count">
                                                {c === 'All'
                                                    ? roleMeta.blocks.length
                                                    : roleMeta.blocks.filter(
                                                          (b) =>
                                                              b.category === c,
                                                      ).length}
                                            </span>
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                                <Toggle
                                    size="sm"
                                    pressed={favoritesOnly}
                                    onPressedChange={setFavoritesOnly}
                                >
                                    <Star />
                                    Favourites
                                </Toggle>
                                <div className="ov-available-only">
                                    <Checkbox
                                        id="overview-available-only"
                                        checked={availableOnly}
                                        onCheckedChange={(checked) =>
                                            setAvailableOnly(checked === true)
                                        }
                                    />
                                    <Label htmlFor="overview-available-only">
                                        Not on my overview
                                    </Label>
                                </div>
                            </div>
                            <div className="ov-library-grid">
                                {library.map((meta) => {
                                    const on = current.blocks.some(
                                        (b) => b.id === meta.id,
                                    );
                                    const data = pageBlocks[meta.id];
                                    return (
                                        <article
                                            key={meta.id}
                                            className="ov-library-card"
                                        >
                                            <div className="ov-library-card-top">
                                                <span className="ov-eyebrow">
                                                    {meta.category}
                                                </span>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="ov-tool-button"
                                                    aria-label={`${current.favorites.includes(meta.id) ? 'Unfavourite' : 'Favourite'} ${meta.title}`}
                                                    aria-pressed={current.favorites.includes(
                                                        meta.id,
                                                    )}
                                                    onClick={() =>
                                                        favorite(meta.id)
                                                    }
                                                >
                                                    <Star
                                                        fill={
                                                            current.favorites.includes(
                                                                meta.id,
                                                            )
                                                                ? 'currentColor'
                                                                : 'none'
                                                        }
                                                    />
                                                </Button>
                                            </div>
                                            {/* eslint-disable-next-line no-restricted-syntax -- a thumbnail tile that opens the full preview */}
                                            <button
                                                type="button"
                                                className="ov-preview-thumb"
                                                data-preview-id={meta.id}
                                                aria-label={`Preview ${meta.title}`}
                                                onClick={() =>
                                                    setPreviewId(meta.id)
                                                }
                                            >
                                                {data?.state === 'ready' &&
                                                data.total ? (
                                                    <>
                                                        <strong>
                                                            {data.total}
                                                        </strong>
                                                        <span>
                                                            {data.rows
                                                                .slice(0, 3)
                                                                .map((r) => (
                                                                    <i
                                                                        key={
                                                                            r.label
                                                                        }
                                                                    >
                                                                        {
                                                                            r.label
                                                                        }{' '}
                                                                        <b>
                                                                            {r.value ??
                                                                                '→'}
                                                                        </b>
                                                                    </i>
                                                                ))}
                                                        </span>
                                                    </>
                                                ) : (
                                                    <>
                                                        <BlockSketch
                                                            kind={meta.kind}
                                                        />
                                                        <span>
                                                            {data?.state ===
                                                            'restricted'
                                                                ? 'Permission required'
                                                                : data?.state ===
                                                                    'unavailable'
                                                                  ? 'Temporarily unavailable'
                                                                  : data?.state ===
                                                                      'ready'
                                                                    ? 'Nothing recorded yet'
                                                                    : data?.state ===
                                                                        'deferred'
                                                                      ? 'Loading preview…'
                                                                      : 'Proposed summary'}
                                                        </span>
                                                    </>
                                                )}
                                            </button>
                                            <h3>{meta.title}</h3>
                                            <p>{meta.description}</p>
                                            <div className="ov-library-card-footer">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setPreviewId(meta.id)
                                                    }
                                                >
                                                    Preview
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant={
                                                        on
                                                            ? 'outline'
                                                            : 'default'
                                                    }
                                                    disabled={on}
                                                    onClick={() =>
                                                        addBlock(meta.id)
                                                    }
                                                >
                                                    {on ? (
                                                        <>
                                                            <Check />
                                                            Added
                                                        </>
                                                    ) : (
                                                        <>
                                                            <Plus />
                                                            Add block
                                                        </>
                                                    )}
                                                </Button>
                                            </div>
                                        </article>
                                    );
                                })}
                                {!library.length && (
                                    <div className="ov-empty">
                                        <Search />
                                        <strong>
                                            No blocks match these filters
                                        </strong>
                                        <p>
                                            Try a different search or category.
                                        </p>
                                    </div>
                                )}
                            </div>
                            <p className="text-caption text-muted-foreground">
                                {library.length}{' '}
                                {library.length === 1 ? 'block' : 'blocks'}{' '}
                                shown · Your choices become permanent when you
                                save the layout.
                            </p>
                        </>
                    )}
                    {dialog === 'library' && previewMeta && (
                        <>
                            <p className="text-subtle">
                                {previewMeta.description}
                            </p>
                            <div className="ov-full-preview">
                                <BlockContent
                                    meta={previewMeta}
                                    data={pageBlocks[previewMeta.id]}
                                    preview
                                />
                            </div>
                            {previewMeta.next && (
                                <div className="ov-next-note">
                                    <strong>What you can do next</strong>
                                    <p>{previewMeta.next}</p>
                                </div>
                            )}
                            <div className="ov-between">
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        const id = previewId;
                                        setPreviewId(null);
                                        requestAnimationFrame(() =>
                                            (
                                                document.querySelector(
                                                    `[data-preview-id="${id}"]`,
                                                ) as HTMLElement | null
                                            )?.focus(),
                                        );
                                    }}
                                >
                                    Back to blocks
                                </Button>
                                <Button
                                    disabled={current.blocks.some(
                                        (b) => b.id === previewMeta.id,
                                    )}
                                    onClick={() => addBlock(previewMeta.id)}
                                >
                                    {current.blocks.some(
                                        (b) => b.id === previewMeta.id,
                                    )
                                        ? 'Already added'
                                        : 'Add this block'}
                                </Button>
                            </div>
                        </>
                    )}
                    {dialog === 'starters' && (
                        <>
                            <div className="ov-starters">
                                {roleMeta.starters.map((starter) => (
                                    <article key={starter.name}>
                                        <LayoutTemplate className="ov-starter-icon" />
                                        <div>
                                            <span className="ov-eyebrow">
                                                {starter.ids.length} blocks ·
                                                Suggested
                                            </span>
                                            <h3>{starter.name}</h3>
                                            <p>{starter.description}</p>
                                            <div className="ov-starter-tags">
                                                {starter.ids.map((id) => (
                                                    <span key={id}>
                                                        {
                                                            roleMeta.blocks.find(
                                                                (b) =>
                                                                    b.id === id,
                                                            )?.title
                                                        }
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                        <Button
                                            onClick={() => {
                                                change(
                                                    {
                                                        ...current,
                                                        blocks: starter.ids.map(
                                                            (id) => ({
                                                                id,
                                                                span: 6,
                                                                height: 0,
                                                                site: 'page',
                                                            }),
                                                        ),
                                                        density: 'balanced',
                                                    },
                                                    `${starter.name} applied to draft`,
                                                );
                                                setDialog(null);
                                            }}
                                        >
                                            Use this starter
                                        </Button>
                                    </article>
                                ))}
                            </div>
                            <p className="text-caption text-muted-foreground">
                                A starter replaces the current draft. Undo or
                                Cancel restores your previous arrangement.
                                Urgent work stays visible.
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => startDialog('library')}
                            >
                                Back to blocks
                            </Button>
                        </>
                    )}
                    {dialog === 'saved' && (
                        <>
                            <div className="ov-saved-list">
                                {current.copies.map((copy, i) => (
                                    <article key={copy.name}>
                                        <LayoutTemplate />
                                        <div>
                                            <strong>{copy.name}</strong>
                                            <p>
                                                {copy.blocks.length} blocks ·{' '}
                                                {copy.density}
                                            </p>
                                        </div>
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                change({
                                                    ...current,
                                                    blocks: structuredClone(
                                                        copy.blocks,
                                                    ),
                                                    density: copy.density,
                                                });
                                                setDialog(null);
                                            }}
                                        >
                                            Use layout
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="ov-tool-button"
                                            aria-label={`Remove saved layout ${copy.name}`}
                                            onClick={() =>
                                                change(
                                                    {
                                                        ...current,
                                                        copies: current.copies.filter(
                                                            (_, index) =>
                                                                i !== index,
                                                        ),
                                                    },
                                                    'Saved arrangement removed from draft',
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </article>
                                ))}
                            </div>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    applyCopy();
                                }}
                                className="ov-save-copy"
                            >
                                <label htmlFor="overview-copy">
                                    Name this arrangement
                                </label>
                                <div>
                                    <Input
                                        id="overview-copy"
                                        value={copyName}
                                        maxLength={32}
                                        onChange={(e) =>
                                            setCopyName(e.target.value)
                                        }
                                        placeholder="For example, Monday planning"
                                    />
                                    <Button
                                        type="submit"
                                        disabled={current.copies.length >= 3}
                                    >
                                        Save a copy
                                    </Button>
                                </div>
                                {copyError && <p role="alert">{copyError}</p>}
                            </form>
                            <p className="text-caption text-muted-foreground">
                                Copies are saved with your layout. Removing a
                                copy can be undone before saving.
                            </p>
                        </>
                    )}
                    {dialog === 'find' && (
                        <>
                            <Input
                                aria-label="Find overview blocks"
                                placeholder="Find a block or next step…"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                            <div className="ov-find-results">
                                {foundSteps.map((row) => (
                                    <div
                                        key={row.label}
                                        className="ov-find-urgent"
                                        data-tone={row.tone}
                                    >
                                        <TriangleAlert size={18} />
                                        <strong>{row.label}</strong>
                                        <StatusBadge
                                            variant={
                                                row.tone === 'critical'
                                                    ? 'critical'
                                                    : 'warning'
                                            }
                                        >
                                            {row.tone === 'critical'
                                                ? 'Urgent'
                                                : 'Due soon'}
                                        </StatusBadge>
                                        {row.href && (
                                            <Link
                                                href={row.href}
                                                onClick={() => setDialog(null)}
                                            >
                                                {row.actionLabel ??
                                                    'Open workspace'}
                                                <ArrowUpRight size={14} />
                                            </Link>
                                        )}
                                    </div>
                                ))}
                                {!foundSteps.length && !foundBlocks.length && (
                                    <EmptyState
                                        variant="compact"
                                        icon={Search}
                                        heading="Nothing matches"
                                        description="Try another word, or open Edit layout to add a block."
                                    />
                                )}
                                {foundBlocks.map((meta) => (
                                    // eslint-disable-next-line no-restricted-syntax -- a result row that scrolls to its block
                                    <button
                                        type="button"
                                        key={meta.id}
                                        onClick={() => {
                                            setTab('overview');
                                            setDialog(null);
                                            requestAnimationFrame(() =>
                                                board.current
                                                    ?.querySelector(
                                                        `[data-block="${meta.id}"]`,
                                                    )
                                                    ?.scrollIntoView({
                                                        block: 'center',
                                                        behavior: 'auto',
                                                    }),
                                            );
                                            // Land keyboard focus on the
                                            // block, not back on Find.
                                            returnFocus.current = `[data-block="${meta.id}"]`;
                                        }}
                                    >
                                        <LayoutDashboard size={16} />
                                        <span>{meta.title}</span>
                                        <ArrowDown size={15} />
                                    </button>
                                ))}
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={confirmCancel}
                onClose={() => setConfirmCancel(false)}
                onConfirm={() => {
                    setConfirmCancel(false);
                    setDraft(null);
                    setConflict(null);
                    setError('');
                    setAnnouncement('Layout changes discarded');
                }}
                title="Discard your layout changes?"
                description="Your saved overview stays as it was. The changes you made in this editing session will be lost."
                confirmText="Discard changes"
                cancelText="Keep editing"
                variant="destructive"
            />
            <Dialog
                open={detailRow !== null}
                onOpenChange={(open) => {
                    if (!open) setDetailRow(null);
                }}
            >
                {detailRow && (
                    <DialogContent
                        className="ov-dialog ov-detail"
                        // POPUP_STYLE_GUIDE width token: read-only detail.
                        style={{
                            maxWidth: 'min(92vw, 480px)',
                            width: 'min(92vw, 480px)',
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>{detailRow.label}</DialogTitle>
                            {detailRow.detail && (
                                <DialogDescription>
                                    {detailRow.detail}
                                </DialogDescription>
                            )}
                        </DialogHeader>
                        {detailRow.status && (
                            <StatusBadge
                                className="w-fit"
                                variant={
                                    detailRow.tone === 'critical'
                                        ? 'critical'
                                        : detailRow.tone === 'warning'
                                          ? 'warning'
                                          : 'neutral'
                                }
                            >
                                {detailRow.status}
                            </StatusBadge>
                        )}
                        {!!detailRow.facts?.length && (
                            <dl className="ov-detail-facts">
                                {detailRow.facts.map((fact) => (
                                    <div key={fact.label}>
                                        <dt>{fact.label}</dt>
                                        <dd>
                                            {fact.date
                                                ? formatDate(fact.date)
                                                : fact.dateTime
                                                  ? formatDateTime(
                                                        fact.dateTime,
                                                    )
                                                  : (fact.value ??
                                                    'Not recorded')}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        {!!detailRow.history?.length && (
                            <div className="ov-detail-history">
                                <strong>
                                    Connection checks · last 24 hours
                                </strong>
                                <ConnectionHistoryStrip row={detailRow} />
                            </div>
                        )}
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setDetailRow(null)}
                            >
                                Close
                            </Button>
                            {detailRow.href && (
                                <Button asChild>
                                    <Link href={detailRow.href}>
                                        {detailRow.actionLabel ?? 'Open record'}
                                        <ArrowUpRight />
                                    </Link>
                                </Button>
                            )}
                        </DialogFooter>
                    </DialogContent>
                )}
            </Dialog>
        </div>
    );
}
