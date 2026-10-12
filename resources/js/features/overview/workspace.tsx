/* eslint-disable no-restricted-syntax -- The approved puzzle controls and library cards use custom button geometry; form actions use the shared Button. */
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderMeterSpark,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatTime } from '@/lib/datetime';
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
import { BlockContent } from './block-content';
import {
    catalog,
    defaultBlocks,
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
} from './types';
import './workspace.css';

const rangeLabels = {
    today: 'Today',
    week: 'This week',
    month: 'This month',
    quarter: 'This quarter',
};
type Tab = 'overview' | 'attention' | 'insights';
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
                <SelectItem value="all">All permitted sites</SelectItem>
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
    const [naturalHeight, setNaturalHeight] = useState(300);
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
    const footerHeight = meta.kind === 'focus' || editing ? 0 : 43;
    const height = Math.max(
        naturalHeight + footerHeight,
        resize?.height ?? layout.height,
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
            height: block.getBoundingClientRect().height,
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
            data-block={layout.id}
            data-span={layout.span}
            data-kind={meta.kind}
        >
            <div ref={content} className="ov-card-inner">
                {editing && (
                    <div className="ov-block-tools">
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
                                <button
                                    className="ov-icon-button"
                                    aria-label={`Size and position of ${title}`}
                                >
                                    <MoreHorizontal size={18} />
                                </button>
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
                        <button
                            className="ov-icon-button"
                            aria-label={`Remove ${title}`}
                            onClick={onRemove}
                        >
                            <X size={16} />
                        </button>
                    </div>
                )}
                <header className="ov-card-head">
                    <div>
                        <h2>{title}</h2>
                        <p>{data?.subtitle ?? meta.description}</p>
                    </div>
                    {data?.state === 'proposed' ? (
                        <span className="ov-proposal">Proposed</span>
                    ) : (
                        !editing && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        className="ov-icon-button"
                                        aria-label={`Options for ${title}`}
                                    >
                                        <MoreHorizontal size={17} />
                                    </button>
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
                                ? 'all permitted sites'
                                : (sites.find(
                                      (s) => String(s.id) === layout.site,
                                  )?.name ?? 'unavailable site')}
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
                    <div className="ov-block-bottom">
                        {data?.scopeMode && data.scopeMode !== 'site' ? (
                            <span>
                                {data.scopeMode === 'personal'
                                    ? 'Personal · across sites'
                                    : 'Organisation-wide · permitted audience'}
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
                                    'Live records · your permitted view')}
                    </span>
                    {meta.id === 'attention' &&
                    !expanded &&
                    data &&
                    (data.partial ||
                        (data.total ?? 0) > 0 ||
                        data.rows.length > 0 ||
                        (data.reviewTotal ?? 0) > 0) ? (
                        <button onClick={onExpand}>
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
                                        (layout.height || naturalHeight) +
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
    const [tab, setTab] = useState<Tab>('overview');
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('All');
    const [favoritesOnly, setFavoritesOnly] = useState(false);
    const [availableOnly, setAvailableOnly] = useState(false);
    const [copyName, setCopyName] = useState('');
    const [copyError, setCopyError] = useState('');
    const [announcement, setAnnouncement] = useState('');
    const [scopes, setScopes] = useState<Record<string, OverviewData>>({});
    const [refreshing, setRefreshing] = useState(false);
    const board = useRef<HTMLDivElement>(null);
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 7 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );
    const editing = draft !== null;
    const scopeKey = [
        ...new Set(
            current.blocks
                .map((b) => (b.site === 'page' ? filters.site : b.site))
                .filter((s) => s !== filters.site),
        ),
    ]
        .sort()
        .join(',');
    useEffect(() => {
        const controller = new AbortController();
        for (const site of scopeKey.split(',').filter(Boolean)) {
            fetch(
                `/dashboard/data?${new URLSearchParams({ role, range: filters.range, site })}`,
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
            setError(
                e instanceof Error ? e.message : 'Could not save this layout.',
            );
        } finally {
            setSaving(false);
        }
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
        return (site === filters.site ? overview : scopes[site])?.blocks[
            block.id
        ];
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
            },
            { preserveScroll: true, replace: true },
        );
    }
    function tidy() {
        const blocks = tidyBlocks(current.blocks);
        let row: BlockLayout[] = [],
            width = 0;
        const align = () => {
            const height = Math.min(
                720,
                Math.ceil(
                    Math.max(
                        ...row.map(
                            (b) =>
                                board.current
                                    ?.querySelector(
                                        `[data-block="${b.id}"] .ov-card-inner`,
                                    )
                                    ?.getBoundingClientRect().height ?? 0,
                        ),
                    ),
                ),
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
    const shown =
        tab === 'attention'
            ? [{ id: 'attention', span: 12, height: 0, site: 'page' }]
            : tab === 'insights'
              ? current.blocks.filter((b) =>
                    ['trend', 'donut', 'bars', 'progress'].includes(
                        roleMeta.blocks.find((m) => m.id === b.id)?.kind ?? '',
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
    return (
        <div
            className="ov-workspace"
            data-editing={editing}
            data-density={current.density}
            data-role={role}
            onKeyDown={(e) => {
                if (
                    !editing ||
                    dialog ||
                    ['INPUT', 'TEXTAREA'].includes(
                        (e.target as HTMLElement).tagName,
                    )
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
            }}
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
                    <PageHeaderStatusChip
                        variant="neutral"
                        className="ov-role-chip"
                    >
                        {roleMeta.name}
                    </PageHeaderStatusChip>
                }
                subline={roleMeta.description}
                actions={
                    !editing && (
                        <>
                            <PageHeaderGlassButton
                                onClick={() => startDialog('find')}
                                aria-label="Find in overview"
                            >
                                <Search size={16} />
                            </PageHeaderGlassButton>
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
                            tone={
                                i === 1 && overview.urgent.length
                                    ? 'warning'
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
                            ) : m.avatars?.length ? (
                                <div className="ov-duty-meter">
                                    <PageHeaderMeterBig>
                                        {m.value ?? '—'}
                                    </PageHeaderMeterBig>
                                    <span
                                        className="ov-duty-avatars"
                                        aria-label="On-duty team initials"
                                    >
                                        {m.avatars.map((initials, index) => (
                                            <span key={index}>{initials}</span>
                                        ))}
                                    </span>
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
                                    icon={Building2}
                                    label="All permitted sites"
                                    value={filters.site}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All permitted sites',
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
                                    alert: overview.urgent.length > 0,
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
            {overview.urgent.length > 0 && (
                <div className="ov-urgent">
                    <ShieldCheck size={18} />
                    <strong>
                        {overview.urgent.length === 1
                            ? '1 priority needs attention'
                            : `${overview.urgent.length} priorities need attention`}
                    </strong>
                    <span>{overview.urgent[0].label}</span>
                    <button
                        onClick={() => {
                            if (editing) startDialog('find');
                            else setTab('attention');
                        }}
                    >
                        Review now <ArrowUpRight size={15} />
                    </button>
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
                    <button
                        className="ov-context-priorities"
                        onClick={() => setTab('attention')}
                    >
                        <Check size={14} />
                        {overview.partial
                            ? 'Some summaries need a refresh'
                            : overview.blocks.attention.total
                              ? `${overview.blocks.attention.total} ${overview.blocks.attention.total === 1 ? 'item needs' : 'items need'} your review`
                              : 'No outstanding priorities in this view'}
                        <ArrowRight size={13} />
                    </button>
                    <span>
                        {formatDate(overview.period.start)} –{' '}
                        {formatDate(overview.period.end)}
                    </span>
                    <button onClick={refresh} disabled={refreshing}>
                        <RefreshCw
                            size={13}
                            className={refreshing ? 'ov-loading' : ''}
                        />
                        Updated {formatTime(overview.updatedAt)}
                    </button>
                </div>
            )}
            {editing && (
                <div className="ov-editor-toolbar">
                    <div className="ov-editor-top">
                        <div>
                            <span className="ov-eyebrow">MAKE IT YOURS</span>
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
                        <div
                            className="ov-density"
                            aria-label="Information density"
                        >
                            {(['balanced', 'compact'] as const).map((d) => (
                                <button
                                    key={d}
                                    aria-pressed={current.density === d}
                                    onClick={() =>
                                        change({ ...current, density: d })
                                    }
                                >
                                    {d === 'balanced' ? 'Balanced' : 'Compact'}
                                </button>
                            ))}
                        </div>
                        <button
                            className="ov-icon-button"
                            aria-label="Undo layout change"
                            disabled={!history.length}
                            onClick={undo}
                        >
                            <Undo2 size={17} />
                        </button>
                        <button
                            className="ov-icon-button"
                            aria-label="Redo layout change"
                            disabled={!future.length}
                            onClick={redo}
                        >
                            <Redo2 size={17} />
                        </button>
                        <button className="ov-text-button" onClick={tidy}>
                            <Sparkles size={15} />
                            Tidy layout
                        </button>
                        <button
                            className="ov-text-button"
                            onClick={() => startDialog('saved')}
                        >
                            <BookOpen size={15} />
                            Saved layouts
                        </button>
                        <button
                            className="ov-text-button"
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
                            <RotateCcw size={15} />
                            Restore defaults
                        </button>
                    </div>
                    {error && (
                        <p role="alert" className="ov-save-error">
                            {error}
                        </p>
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
                <DndContext
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
                                        onRemove={() =>
                                            change(
                                                {
                                                    ...current,
                                                    blocks: current.blocks.filter(
                                                        (b) =>
                                                            b.id !== block.id,
                                                    ),
                                                },
                                                `${meta.title} removed`,
                                            )
                                        }
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
                                <button
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
                {!editing && (
                    <button
                        onClick={async () => {
                            try {
                                await writeOverview('/dashboard/default-role', {
                                    role,
                                });
                                toast.success(
                                    `${roleMeta.name} is your default overview`,
                                );
                            } catch (e) {
                                toast.error(
                                    e instanceof Error
                                        ? e.message
                                        : 'Could not save default',
                                );
                            }
                        }}
                    >
                        Make this my default view
                    </button>
                )}
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
                >
                    <DialogHeader>
                        <DialogTitle>
                            {previewMeta
                                ? previewMeta.title
                                : dialog === 'library'
                                  ? 'Make room for what matters'
                                  : dialog === 'starters'
                                    ? 'A useful place to start'
                                    : dialog === 'saved'
                                      ? 'Your saved arrangements'
                                      : 'Find in your overview'}
                        </DialogTitle>
                        <DialogDescription>
                            {previewMeta
                                ? 'Block preview'
                                : `${roleMeta.name} · ${dialog === 'library' ? '20 useful blocks. Choose what belongs in your day.' : dialog === 'starters' ? 'Three focused arrangements' : dialog === 'saved' ? 'Keep up to three arrangements for this role' : 'Jump to a summary or review urgent work'}`}
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
                                {[
                                    'All',
                                    ...new Set(
                                        roleMeta.blocks.map((b) => b.category),
                                    ),
                                ].map((c) => (
                                    <button
                                        key={c}
                                        aria-pressed={category === c}
                                        onClick={() => setCategory(c)}
                                    >
                                        {c}{' '}
                                        <span>
                                            {c === 'All'
                                                ? roleMeta.blocks.length
                                                : roleMeta.blocks.filter(
                                                      (b) => b.category === c,
                                                  ).length}
                                        </span>
                                    </button>
                                ))}
                                <button
                                    aria-pressed={favoritesOnly}
                                    onClick={() =>
                                        setFavoritesOnly(!favoritesOnly)
                                    }
                                >
                                    <Star size={14} />
                                    Favourites
                                </button>
                                <label>
                                    <input
                                        type="checkbox"
                                        checked={availableOnly}
                                        onChange={(e) =>
                                            setAvailableOnly(e.target.checked)
                                        }
                                    />
                                    Not on my overview
                                </label>
                            </div>
                            <div className="ov-library-grid">
                                {library.map((meta) => {
                                    const on = current.blocks.some(
                                        (b) => b.id === meta.id,
                                    );
                                    const data = overview.blocks[meta.id];
                                    return (
                                        <article
                                            key={meta.id}
                                            className="ov-library-card"
                                        >
                                            <div className="ov-library-card-top">
                                                <span className="ov-eyebrow">
                                                    {meta.category}
                                                </span>
                                                <button
                                                    className="ov-icon-button"
                                                    aria-label={`${current.favorites.includes(meta.id) ? 'Unfavourite' : 'Favourite'} ${meta.title}`}
                                                    aria-pressed={current.favorites.includes(
                                                        meta.id,
                                                    )}
                                                    onClick={() =>
                                                        favorite(meta.id)
                                                    }
                                                >
                                                    <Star
                                                        size={17}
                                                        fill={
                                                            current.favorites.includes(
                                                                meta.id,
                                                            )
                                                                ? 'currentColor'
                                                                : 'none'
                                                        }
                                                    />
                                                </button>
                                            </div>
                                            <button
                                                className="ov-preview-thumb"
                                                aria-label={`Preview ${meta.title}`}
                                                onClick={() =>
                                                    setPreviewId(meta.id)
                                                }
                                            >
                                                {data?.state === 'ready' &&
                                                data.total !== null ? (
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
                                                        <LayoutDashboard
                                                            size={29}
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
                                                                    ? 'No measurement available'
                                                                    : 'Proposed summary'}
                                                        </span>
                                                    </>
                                                )}
                                            </button>
                                            <h3>{meta.title}</h3>
                                            <p>{meta.description}</p>
                                            <div className="ov-library-card-footer">
                                                <button
                                                    className="ov-text-button"
                                                    onClick={() =>
                                                        setPreviewId(meta.id)
                                                    }
                                                >
                                                    Preview
                                                </button>
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
                                    data={overview.blocks[previewMeta.id]}
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
                                    onClick={() => setPreviewId(null)}
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
                                                6 BLOCKS · SUGGESTED
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
                                        <button
                                            className="ov-icon-button"
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
                                            <Trash2 size={16} />
                                        </button>
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
                                {overview.urgent
                                    .filter((r) =>
                                        r.label
                                            .toLowerCase()
                                            .includes(query.toLowerCase()),
                                    )
                                    .map((row) => (
                                        <div
                                            key={row.label}
                                            className="ov-find-urgent"
                                        >
                                            <ShieldCheck size={18} />
                                            <strong>{row.label}</strong>
                                            {row.href && (
                                                <a href={row.href}>
                                                    Open workspace{' '}
                                                    <ArrowUpRight size={14} />
                                                </a>
                                            )}
                                        </div>
                                    ))}
                                {roleMeta.blocks
                                    .filter(
                                        (b) =>
                                            current.blocks.some(
                                                (c) => c.id === b.id,
                                            ) &&
                                            b.title
                                                .toLowerCase()
                                                .includes(query.toLowerCase()),
                                    )
                                    .map((meta) => (
                                        <button
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
        </div>
    );
}
