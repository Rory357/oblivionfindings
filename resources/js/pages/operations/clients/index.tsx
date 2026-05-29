/* eslint-disable no-restricted-syntax -- The Clients index is a pixel-faithful bespoke design
 * surface (mirrors the Sites index): on-gradient filter pills + segmented controls, a wrapping
 * saved-view tab strip, status-aware client cards, a cursor-anchored context menu, a floating
 * bulk-action bar and a custom empty state. These controls intentionally don't map onto the
 * shared <Button>/<Card> primitives, and the hero actions in particular must stay raw to bypass
 * PageHeroActions' `[data-slot=button]` colour overrides. They are therefore built as styled
 * native elements rather than design-system components, sourcing every colour from semantic
 * tokens (never hardcoded hex). */
import { AddDailyNoteDialog } from '@/components/add-daily-note-dialog';
import { AssignWorkerDialog } from '@/components/assign-worker-dialog';
import { ClientEditDialog } from '@/components/client-edit-dialog';
import {
    ClientSafetyBadges,
    type ClientSafetySummary,
} from '@/components/client-safety-ribbon';
import {
    PageHero,
    PageLayout,
    type PageHeroBadge,
    type PageHeroMetaItem,
    type PageHeroStat,
} from '@/components/page';
import { MultiEntityFilter } from '@/components/rostering/multi-entity-filter';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Archive,
    ArchiveRestore,
    ArrowRight,
    BedDouble,
    Building,
    Check,
    CheckCircle2,
    Download,
    Eye,
    HeartHandshake,
    Home as HomeIcon,
    LayoutGrid,
    MapPin,
    MoreHorizontal,
    NotebookPen,
    Pencil,
    Plus,
    Rows3,
    Search,
    Shield,
    ShieldAlert,
    UserCheck,
    Users,
    X,
} from 'lucide-react';
import {
    useEffect,
    useMemo,
    useRef,
    useState,
    type ComponentType,
} from 'react';
import { toast } from 'sonner';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type IconType = ComponentType<{ className?: string }>;

type ClientSite = { id: number; name: string };
type KeyWorker = { id: number; name: string; initials: string };
type Onboarding = {
    completed: number;
    total: number;
    percent: number;
    status: 'complete' | 'incomplete';
};

type Client = {
    id: number;
    nhi_number: string | null;
    first_name: string;
    last_name: string;
    profile_photo_url?: string | null;
    avatar?: string | null;
    status: string;
    age: number | null;
    address: string | null;
    site: ClientSite | null;
    key_worker: KeyWorker | null;
    notes_week: number;
    onboarding: Onboarding;
    has_respite: boolean;
    archived: boolean;
    mine: boolean;
    safety: ClientSafetySummary | null;
};

type Tier = 'critical' | 'warning' | 'neutral' | 'success';

type TypeFilter = 'all' | 'permanent' | 'respite';

type TabKey =
    | 'all'
    | 'high-risk'
    | 'onboarding'
    | 'safeguarding'
    | 'inactive'
    | 'archived';

type Filters = {
    q: string;
    mine: boolean;
    siteIds: number[];
    type: TypeFilter;
    showArchived: boolean;
};

type MenuItem = {
    separator?: boolean;
    label?: string;
    icon?: IconType;
    onClick?: () => void;
    danger?: boolean;
};

type Can = {
    clients?: {
        create?: boolean;
        update?: boolean;
        archive?: boolean;
        assignmentsUpdate?: boolean;
    };
    progress_notes?: { create?: boolean };
    timeline?: { create?: boolean };
};

type PageProps = {
    clients: Client[];
    auth: { user?: { name?: string; role?: string | null } | null; can?: Can };
    labels?: Record<string, string>;
};

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

/** Card accent + avatar tint. Safety-driven; inactive clients read neutral. */
function clientTier(c: Client): Tier {
    if (c.status === 'inactive') return 'neutral';
    const s = c.safety;
    if (s && (s.safeguarding || s.risk_level === 'critical')) return 'critical';
    if (s && (s.critical_risks_count > 0 || s.allergies_count > 0))
        return 'warning';
    return 'success';
}

const TIER_ACCENT: Record<Tier, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

const TIER_AVATAR: Record<Tier, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};

function onboardTone(percent: number): 'success' | 'warning' {
    return percent >= 90 ? 'success' : 'warning';
}

type StatusMeta = {
    cls: 'active' | 'inactive' | 'respite';
    label: string;
};

function statusMeta(c: Client): StatusMeta {
    if (c.has_respite || c.status === 'respite')
        return { cls: 'respite', label: 'Respite' };
    if (c.status === 'active') return { cls: 'active', label: 'Active' };
    return { cls: 'inactive', label: 'Inactive' };
}

const STATUS_DOT: Record<StatusMeta['cls'], string> = {
    active: 'bg-status-success ring-2 ring-status-success/20',
    inactive: 'bg-muted-foreground ring-2 ring-muted-foreground/20',
    respite: 'bg-status-warning ring-2 ring-status-warning/20',
};

const STATUS_TEXT: Record<StatusMeta['cls'], string> = {
    active: 'text-status-success',
    inactive: 'text-muted-foreground',
    respite: 'text-status-warning',
};

const TAB_PREDICATES: Record<TabKey, (c: Client) => boolean> = {
    all: () => true,
    'high-risk': (c) => clientTier(c) === 'critical',
    onboarding: (c) => c.onboarding.status !== 'complete',
    safeguarding: (c) => !!c.safety?.safeguarding,
    inactive: (c) => c.status === 'inactive',
    archived: (c) => c.archived,
};

/** Remove falsy entries and collapse leading/trailing/double separators. */
function compactMenu(
    items: (MenuItem | false | null | undefined)[],
): MenuItem[] {
    const cleaned = items.filter(Boolean) as MenuItem[];
    const out: MenuItem[] = [];
    for (const it of cleaned) {
        if (it.separator) {
            if (out.length === 0 || out[out.length - 1].separator) continue;
        }
        out.push(it);
    }
    while (out.length && out[out.length - 1].separator) out.pop();
    return out;
}

function csvCell(value: string | number): string {
    const s = String(value ?? '');
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function exportClientsCsv(rows: Client[]) {
    const header = [
        'NHI',
        'First name',
        'Last name',
        'Age',
        'Status',
        'Home',
        'Key worker',
        'Onboarding %',
        'Address',
    ];
    const lines = rows.map((c) =>
        [
            c.nhi_number ?? '',
            c.first_name,
            c.last_name,
            c.age ?? '',
            statusMeta(c).label,
            c.site?.name ?? '',
            c.key_worker?.name ?? '',
            c.onboarding.percent,
            c.address ?? '',
        ]
            .map(csvCell)
            .join(','),
    );
    const csv = [header.join(','), ...lines].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'clients.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

/* ------------------------------------------------------------------ */
/*  Small presentational pieces                                        */
/* ------------------------------------------------------------------ */

function StatusChip({ c }: { c: Client }) {
    const s = statusMeta(c);
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border border-border bg-secondary px-2.5 py-0.5 text-[11.5px] font-semibold',
                STATUS_TEXT[s.cls],
            )}
        >
            <span
                className={cn('h-1.5 w-1.5 rounded-full', STATUS_DOT[s.cls])}
            />
            {s.label}
        </span>
    );
}

/** Allergy/risk/safeguarding pills (reused) + respite + an "All clear" fallback. */
function SafetyRow({ c }: { c: Client }) {
    const hasAny = !!c.safety?.has_any;
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {hasAny ? (
                <ClientSafetyBadges summary={c.safety} />
            ) : (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-status-success/30 bg-status-success-bg px-2.5 py-1 text-xs font-medium text-status-success">
                    <CheckCircle2 className="h-3.5 w-3.5" />
                    All clear
                </span>
            )}
            {c.has_respite ? (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-status-warning/30 bg-status-warning-bg px-2.5 py-1 text-xs font-medium text-status-warning">
                    <BedDouble className="h-3.5 w-3.5" />
                    Respite
                </span>
            ) : null}
        </div>
    );
}

function KeyWorkerAvatar({
    kw,
    size = 30,
}: {
    kw: KeyWorker | null;
    size?: number;
}) {
    return (
        <span
            style={{ width: size, height: size, fontSize: size * 0.36 }}
            className="flex shrink-0 items-center justify-center rounded-full bg-accent font-bold text-primary"
        >
            {kw ? kw.initials : '—'}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Per-row action menus (kebab dropdown + cursor context menu)        */
/* ------------------------------------------------------------------ */

function ClientKebab({ c, actions }: { c: Client; actions: MenuItem[] }) {
    if (actions.length === 0) return null;
    return (
        <div onClick={(e) => e.stopPropagation()}>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label="Client actions"
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                    >
                        <MoreHorizontal className="h-[18px] w-[18px]" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuLabel className="flex items-center gap-2">
                        <span className="truncate">
                            {c.first_name} {c.last_name}
                        </span>
                        {c.nhi_number ? (
                            <span className="ml-auto text-[11px] font-normal text-muted-foreground tabular-nums">
                                {c.nhi_number}
                            </span>
                        ) : null}
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {actions.map((it, i) =>
                        it.separator ? (
                            <DropdownMenuSeparator key={`s${i}`} />
                        ) : (
                            <DropdownMenuItem
                                key={i}
                                onClick={it.onClick}
                                className={cn(
                                    it.danger &&
                                        'text-status-critical focus:text-status-critical',
                                )}
                            >
                                {it.icon ? (
                                    <it.icon className="h-4 w-4" />
                                ) : null}
                                {it.label}
                            </DropdownMenuItem>
                        ),
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

function ClientContextMenu({
    x,
    y,
    client,
    items,
    onClose,
}: {
    x: number;
    y: number;
    client: Client;
    items: MenuItem[];
    onClose: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({ x, y });
    const getInitials = useInitials();
    const tier = clientTier(client);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const r = el.getBoundingClientRect();
        let nx = x;
        let ny = y;
        if (nx + r.width > window.innerWidth - 8)
            nx = window.innerWidth - r.width - 8;
        if (ny + r.height > window.innerHeight - 8)
            ny = window.innerHeight - r.height - 8;
        setPos({ x: Math.max(8, nx), y: Math.max(8, ny) });
    }, [x, y]);

    useEffect(() => {
        const close = () => onClose();
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('mousedown', close);
        window.addEventListener('scroll', close, true);
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('mousedown', close);
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('keydown', onKey);
        };
    }, [onClose]);

    if (items.length === 0) return null;

    return (
        <div
            ref={ref}
            style={{ left: pos.x, top: pos.y }}
            onMouseDown={(e) => e.stopPropagation()}
            onContextMenu={(e) => e.preventDefault()}
            className="fixed z-[200] min-w-[15rem] rounded-xl border border-border bg-popover p-1.5 shadow-xl"
        >
            <div className="mb-1 flex items-center gap-2.5 border-b border-border px-2 pt-1 pb-2.5">
                <span
                    className={cn(
                        'flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-lg text-[11px] font-bold',
                        TIER_AVATAR[tier],
                    )}
                >
                    {getInitials(`${client.first_name} ${client.last_name}`)}
                </span>
                <span className="min-w-0">
                    <span className="block truncate text-[13px] font-semibold">
                        {client.first_name} {client.last_name}
                    </span>
                    {client.nhi_number ? (
                        <span className="block text-[11px] text-muted-foreground tabular-nums">
                            {client.nhi_number}
                        </span>
                    ) : null}
                </span>
            </div>
            {items.map((it, i) =>
                it.separator ? (
                    <div key={`s${i}`} className="my-1 h-px bg-border" />
                ) : (
                    <button
                        key={i}
                        type="button"
                        onClick={() => {
                            onClose();
                            it.onClick?.();
                        }}
                        className={cn(
                            'flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-[13px] transition-colors hover:bg-secondary',
                            it.danger
                                ? 'text-status-critical hover:bg-status-critical-bg'
                                : 'text-foreground',
                        )}
                    >
                        {it.icon ? (
                            <it.icon
                                className={cn(
                                    'h-4 w-4',
                                    !it.danger && 'opacity-80',
                                )}
                            />
                        ) : null}
                        {it.label}
                    </button>
                ),
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Client card                                                        */
/* ------------------------------------------------------------------ */

function ClientCard({
    c,
    selectMode,
    selected,
    actions,
    onOpen,
    onToggle,
    onContext,
}: {
    c: Client;
    selectMode: boolean;
    selected: boolean;
    actions: MenuItem[];
    onOpen: (c: Client) => void;
    onToggle: (id: number) => void;
    onContext: (e: React.MouseEvent, c: Client) => void;
}) {
    const getInitials = useInitials();
    const tier = clientTier(c);
    const name = `${c.first_name} ${c.last_name}`;
    const openable = !c.archived;
    const handleActivate = () => {
        if (selectMode) onToggle(c.id);
        else if (openable) onOpen(c);
    };

    return (
        <Card
            role="button"
            tabIndex={0}
            onClick={handleActivate}
            onContextMenu={(e) => onContext(e, c)}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    handleActivate();
                }
            }}
            className={cn(
                'group relative flex flex-col gap-0 overflow-hidden rounded-[15px] py-0 shadow-sm transition-all duration-150',
                (selectMode || openable) && 'cursor-pointer',
                'hover:-translate-y-0.5 hover:border-primary hover:shadow-lg',
                selected && 'border-primary ring-2 ring-primary/45',
                c.archived && 'opacity-75',
            )}
        >
            <div className={cn('h-[5px] w-full shrink-0', TIER_ACCENT[tier])} />

            {selectMode ? (
                <span
                    className={cn(
                        'absolute top-3.5 right-3.5 z-[2] flex h-[22px] w-[22px] items-center justify-center rounded-[7px] border transition-colors',
                        selected
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-input bg-card text-transparent',
                    )}
                >
                    <Check className="h-3.5 w-3.5" />
                </span>
            ) : (
                <div className="absolute top-2.5 right-2 z-[2]">
                    <ClientKebab c={c} actions={actions} />
                </div>
            )}

            <div className="flex flex-1 flex-col gap-3 px-4 pt-3.5 pb-3.5">
                {/* head: avatar + identity */}
                <div className="flex items-start gap-3">
                    <span
                        className={cn(
                            'relative flex h-[44px] w-[44px] shrink-0 items-center justify-center overflow-hidden rounded-[11px] text-sm font-bold',
                            TIER_AVATAR[tier],
                        )}
                    >
                        {c.avatar || c.profile_photo_url ? (
                            <img
                                src={
                                    (c.avatar ?? c.profile_photo_url) as string
                                }
                                alt={name}
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            getInitials(name)
                        )}
                    </span>
                    <div className="min-w-0 flex-1 pr-6">
                        <h3 className="truncate text-[16px] leading-tight font-bold tracking-tight">
                            {name}
                        </h3>
                        <div className="mt-1 flex items-center gap-1.5 text-[12px]">
                            <span className="rounded-[5px] bg-accent px-1.5 py-px text-[10px] font-bold tracking-wide text-primary">
                                NHI
                            </span>
                            <span className="font-bold tracking-wide text-foreground tabular-nums">
                                {c.nhi_number ?? '—'}
                            </span>
                            {c.age != null ? (
                                <span className="text-muted-foreground">
                                    · {c.age} yrs
                                </span>
                            ) : null}
                        </div>
                        <div className="mt-1 flex items-center gap-1 text-[12px] text-muted-foreground">
                            <MapPin className="h-3.5 w-3.5 shrink-0" />
                            <span className="truncate">
                                {c.address ?? 'No address on file'}
                            </span>
                        </div>
                    </div>
                </div>

                {/* chips */}
                <div className="flex flex-wrap items-center gap-1.5">
                    <StatusChip c={c} />
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-secondary px-2.5 py-0.5 text-[11.5px] font-semibold text-muted-foreground">
                        <HomeIcon className="h-3 w-3" />
                        {c.site?.name ?? 'Unassigned'}
                    </span>
                    {tier === 'critical' ? (
                        <span className="inline-flex items-center rounded-full border border-status-critical/30 bg-status-critical-bg px-2.5 py-0.5 text-[11.5px] font-semibold text-status-critical">
                            High needs
                        </span>
                    ) : null}
                </div>

                <SafetyRow c={c} />
            </div>

            {/* footer: key worker + open */}
            <div className="mt-auto flex items-center gap-2.5 border-t border-border bg-secondary/45 px-4 py-2.5">
                <KeyWorkerAvatar kw={c.key_worker} />
                <div className="min-w-0 flex-1 text-xs">
                    <div className="truncate font-semibold">
                        {c.key_worker?.name ?? 'No key worker'}
                    </div>
                    <div className="truncate text-[11px] text-muted-foreground">
                        Key worker · {c.notes_week}{' '}
                        {c.notes_week === 1 ? 'note' : 'notes'} this week
                    </div>
                </div>
                {!selectMode && openable ? (
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            onOpen(c);
                        }}
                        className="inline-flex shrink-0 items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-primary transition-colors hover:bg-accent"
                    >
                        Open
                        <ArrowRight className="h-3.5 w-3.5" />
                    </button>
                ) : c.archived ? (
                    <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-[11px] font-semibold text-muted-foreground">
                        <Archive className="h-3 w-3" />
                        Archived
                    </span>
                ) : null}
            </div>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Table row                                                          */
/* ------------------------------------------------------------------ */

function ClientRow({
    c,
    selectMode,
    selected,
    onOpen,
    onToggle,
    onContext,
}: {
    c: Client;
    selectMode: boolean;
    selected: boolean;
    onOpen: (c: Client) => void;
    onToggle: (id: number) => void;
    onContext: (e: React.MouseEvent, c: Client) => void;
}) {
    const getInitials = useInitials();
    const tier = clientTier(c);
    const s = statusMeta(c);
    const ob = c.onboarding;
    const tone = onboardTone(ob.percent);
    const openable = !c.archived;
    const name = `${c.first_name} ${c.last_name}`;

    return (
        <tr
            onClick={() =>
                selectMode ? onToggle(c.id) : openable && onOpen(c)
            }
            onContextMenu={(e) => onContext(e, c)}
            className={cn(
                'border-b border-border transition-colors last:border-0',
                selectMode || openable ? 'cursor-pointer' : '',
                selected ? 'bg-primary/[0.11]' : 'hover:bg-primary/[0.06]',
                c.archived && 'opacity-75',
            )}
        >
            {selectMode ? (
                <td className="px-3.5 py-2.5">
                    <span
                        className={cn(
                            'flex h-5 w-5 items-center justify-center rounded-[6px] border',
                            selected
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-input bg-card text-transparent',
                        )}
                    >
                        <Check className="h-3 w-3" />
                    </span>
                </td>
            ) : null}
            <td className="px-3.5 py-2.5">
                <div className="flex items-center gap-2.5">
                    <span
                        className={cn(
                            'flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-[9px] text-[11px] font-bold',
                            TIER_AVATAR[tier],
                        )}
                    >
                        {getInitials(name)}
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate font-semibold text-foreground">
                            {name}
                        </span>
                        <span className="block text-[11.5px] text-muted-foreground tabular-nums">
                            {c.nhi_number ?? '—'}
                            {c.age != null ? ` · ${c.age} yrs` : ''}
                        </span>
                    </span>
                </div>
            </td>
            <td className="px-3.5 py-2.5">
                <span className="inline-flex items-center gap-1.5 whitespace-nowrap text-muted-foreground">
                    <HomeIcon className="h-3.5 w-3.5" />
                    {c.site?.name ?? 'Unassigned'}
                </span>
            </td>
            <td className="px-3.5 py-2.5">
                <span
                    className={cn(
                        'inline-flex items-center gap-1.5 font-semibold',
                        STATUS_TEXT[s.cls],
                    )}
                >
                    <span
                        className={cn(
                            'h-1.5 w-1.5 rounded-full',
                            STATUS_DOT[s.cls],
                        )}
                    />
                    {s.label}
                </span>
            </td>
            <td className="w-[9.5rem] px-3.5 py-2.5">
                <div className="flex items-center gap-2">
                    <div className="h-[6px] flex-1 overflow-hidden rounded-full bg-muted">
                        <div
                            className={cn(
                                'h-full rounded-full',
                                tone === 'success'
                                    ? 'bg-status-success'
                                    : 'bg-status-warning',
                            )}
                            style={{ width: `${Math.max(ob.percent, 3)}%` }}
                        />
                    </div>
                    <span
                        className={cn(
                            'text-[12px] font-bold tabular-nums',
                            ob.status === 'complete'
                                ? 'text-status-success'
                                : 'text-status-warning',
                        )}
                    >
                        {ob.status === 'complete'
                            ? 'Complete'
                            : `${ob.percent}%`}
                    </span>
                </div>
            </td>
            <td className="px-3.5 py-2.5">
                <div className="flex flex-wrap gap-1.5">
                    {c.safety?.has_any ? (
                        <ClientSafetyBadges summary={c.safety} />
                    ) : (
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-status-success/30 bg-status-success-bg px-2 py-0.5 text-[11px] font-medium text-status-success">
                            <CheckCircle2 className="h-3 w-3" />
                            All clear
                        </span>
                    )}
                </div>
            </td>
            <td className="px-3.5 py-2.5">
                <span className="inline-flex items-center gap-2 whitespace-nowrap">
                    <KeyWorkerAvatar kw={c.key_worker} size={26} />
                    <span className="text-[12.5px]">
                        {c.key_worker?.name ?? '—'}
                    </span>
                </span>
            </td>
            <td
                className="px-3.5 py-2.5 text-right"
                onClick={(e) => e.stopPropagation()}
            >
                {!selectMode && openable ? (
                    <button
                        type="button"
                        onClick={() => onOpen(c)}
                        className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-primary transition-colors hover:bg-accent"
                    >
                        Open
                        <ArrowRight className="h-3.5 w-3.5" />
                    </button>
                ) : c.archived ? (
                    <span className="text-[11px] font-semibold text-muted-foreground">
                        Archived
                    </span>
                ) : null}
            </td>
        </tr>
    );
}

/* ------------------------------------------------------------------ */
/*  Hero footer controls (on-gradient)                                 */
/* ------------------------------------------------------------------ */

const SEG_BTN =
    'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition-colors';

function HeroSegment<T extends string>({
    value,
    onChange,
    options,
    ariaLabel,
}: {
    value: T;
    onChange: (v: T) => void;
    options: { value: T; label: string; icon?: IconType }[];
    ariaLabel: string;
}) {
    return (
        <div
            role="tablist"
            aria-label={ariaLabel}
            className="inline-flex items-center gap-0.5 rounded-full border border-primary-foreground/30 bg-primary-foreground/10 p-0.5"
        >
            {options.map((o) => {
                const Icon = o.icon;
                const on = value === o.value;
                return (
                    <button
                        key={o.value}
                        type="button"
                        role="tab"
                        aria-selected={on}
                        onClick={() => onChange(o.value)}
                        className={cn(
                            SEG_BTN,
                            on
                                ? 'bg-primary-foreground text-primary'
                                : 'text-primary-foreground/85 hover:text-primary-foreground',
                        )}
                    >
                        {Icon ? <Icon className="h-3.5 w-3.5" /> : null}
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Saved-view tab strip                                               */
/* ------------------------------------------------------------------ */

const TAB_BASE =
    'inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground';

function ViewTabs({
    items,
    value,
    onSelect,
}: {
    items: {
        key: TabKey;
        label: string;
        icon: IconType;
        count: number;
        tone: 'neutral' | 'critical' | 'warning';
    }[];
    value: TabKey;
    onSelect: (key: TabKey) => void;
}) {
    return (
        <div
            className="flex flex-wrap items-stretch gap-1 border-b border-border pb-1"
            role="tablist"
        >
            {items.map((it) => {
                const Icon = it.icon;
                const on = value === it.key;
                return (
                    <button
                        key={it.key}
                        type="button"
                        role="tab"
                        aria-selected={on}
                        onClick={() => onSelect(it.key)}
                        className={cn(
                            TAB_BASE,
                            on && 'border-primary bg-primary/10 text-primary',
                        )}
                    >
                        <Icon className="h-4 w-4" />
                        <span>{it.label}</span>
                        <span
                            className={cn(
                                'ml-1 inline-flex items-center rounded-full px-1.5 text-[11px] font-bold tabular-nums',
                                on
                                    ? 'bg-primary/15 text-primary'
                                    : it.tone === 'critical'
                                      ? 'bg-status-critical-bg text-status-critical'
                                      : it.tone === 'warning'
                                        ? 'bg-status-warning-bg text-status-warning'
                                        : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {it.count}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Active filter chips + bulk action bar                              */
/* ------------------------------------------------------------------ */

function ActiveFilterChips({
    filters,
    setFilter,
    siteOptions,
}: {
    filters: Filters;
    setFilter: <K extends keyof Filters>(key: K, value: Filters[K]) => void;
    siteOptions: { id: number; name: string }[];
}) {
    const chips: { key: string; label: string; clear: () => void }[] = [];
    if (filters.mine)
        chips.push({
            key: 'mine',
            label: 'My clients',
            clear: () => setFilter('mine', false),
        });
    if (filters.type !== 'all')
        chips.push({
            key: 'type',
            label:
                filters.type === 'respite' ? 'Respite only' : 'Permanent only',
            clear: () => setFilter('type', 'all'),
        });
    filters.siteIds.forEach((id) => {
        const site = siteOptions.find((s) => s.id === id);
        chips.push({
            key: `site-${id}`,
            label: site ? site.name : 'Home',
            clear: () =>
                setFilter(
                    'siteIds',
                    filters.siteIds.filter((x) => x !== id),
                ),
        });
    });
    if (filters.q.trim())
        chips.push({
            key: 'q',
            label: `“${filters.q.trim()}”`,
            clear: () => setFilter('q', ''),
        });

    if (chips.length === 0) return null;

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {chips.map((c) => (
                <span
                    key={c.key}
                    className="inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-accent px-2.5 py-0.5 text-[12px] font-semibold text-primary"
                >
                    {c.label}
                    <button
                        type="button"
                        onClick={c.clear}
                        aria-label={`Clear ${c.label}`}
                        className="inline-flex h-4 w-4 items-center justify-center rounded-full hover:bg-primary/20"
                    >
                        <X className="h-3 w-3" />
                    </button>
                </span>
            ))}
        </div>
    );
}

function BulkBar({
    count,
    total,
    onSelectAll,
    onExport,
    onClear,
}: {
    count: number;
    total: number;
    onSelectAll: () => void;
    onExport: () => void;
    onClear: () => void;
}) {
    const btn =
        'inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-[12.5px] font-semibold text-foreground transition-colors hover:bg-muted';
    return (
        <div className="fixed bottom-5 left-1/2 z-40 flex max-w-[calc(100vw-32px)] -translate-x-1/2 animate-in flex-wrap items-center gap-1.5 rounded-2xl border border-border bg-popover py-2 pr-2.5 pl-4 shadow-xl duration-200 fade-in slide-in-from-bottom-2">
            <span className="inline-flex items-center gap-2 text-sm font-semibold whitespace-nowrap">
                <span className="rounded-full bg-primary px-2 py-0.5 text-xs text-primary-foreground tabular-nums">
                    {count}
                </span>
                selected
            </span>
            <button
                type="button"
                onClick={onSelectAll}
                className="inline-flex h-8 items-center rounded-full px-2.5 text-[12.5px] font-semibold text-muted-foreground hover:bg-muted"
            >
                Select all {total}
            </button>
            <span className="mx-1 h-6 w-px bg-border" />
            <button type="button" onClick={onExport} className={btn}>
                <Download className="h-4 w-4" />
                Export
            </button>
            <span className="mx-1 h-6 w-px bg-border" />
            <button
                type="button"
                onClick={onClear}
                aria-label="Clear selection"
                className="inline-flex h-8 items-center rounded-full px-2.5 text-[12.5px] font-semibold text-muted-foreground hover:bg-muted"
            >
                Clear
            </button>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function ClientsIndex() {
    const { clients, auth, labels } = usePage<PageProps>().props;
    const can = auth?.can?.clients ?? {};
    const canCreate = !!can.create;
    const canUpdate = !!can.update;
    const canArchive = !!can.archive;
    const canAddNote = !!(
        auth?.can?.progress_notes?.create || auth?.can?.timeline?.create
    );
    // Support workers land on the frontline care view; everyone else opens the
    // full client profile by default (the care view stays one click away in the menu).
    const isSupportWorker = auth?.user?.role === 'support_worker';

    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';
    const firstName =
        (auth?.user?.name ?? '').trim().split(/\s+/)[0] || 'there';

    const [filters, setFilters] = useState<Filters>({
        q: '',
        mine: false,
        siteIds: [],
        type: 'all',
        showArchived: false,
    });
    const [tab, setTab] = useState<TabKey>('all');
    const [view, setView] = useState<'cards' | 'table'>('cards');
    const [selectMode, setSelectMode] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(() => new Set());
    const [ctx, setCtx] = useState<{
        x: number;
        y: number;
        client: Client;
    } | null>(null);
    const [editingClientId, setEditingClientId] = useState<number | null>(null);
    const [assignClient, setAssignClient] = useState<{
        id: number;
        name: string;
    } | null>(null);
    const [noteClient, setNoteClient] = useState<{
        id: number;
        name: string;
        nhi: string | null;
    } | null>(null);

    const setFilter = <K extends keyof Filters>(key: K, value: Filters[K]) =>
        setFilters((f) => ({ ...f, [key]: value }));

    // Leaving select mode clears the selection.
    useEffect(() => {
        if (!selectMode) setSelected(new Set());
    }, [selectMode]);

    // The Archived tab only exists while "Show archived" is on; don't strand its view.
    useEffect(() => {
        if (!filters.showArchived && tab === 'archived') setTab('all');
    }, [filters.showArchived, tab]);

    const stats = useMemo(() => {
        const live = clients.filter((c) => !c.archived);
        const siteIds = new Set(
            live.map((c) => c.site?.id).filter((v): v is number => v != null),
        );
        return {
            total: live.length,
            active: live.filter((c) => c.status === 'active').length,
            respite: live.filter((c) => c.has_respite).length,
            incomplete: live.filter((c) => c.onboarding.status !== 'complete')
                .length,
            complete: live.filter((c) => c.onboarding.status === 'complete')
                .length,
            safeguarding: live.filter((c) => c.safety?.safeguarding).length,
            risks: live.filter((c) => (c.safety?.critical_risks_count ?? 0) > 0)
                .length,
            highRisk: live.filter((c) => clientTier(c) === 'critical').length,
            inactive: live.filter((c) => c.status === 'inactive').length,
            archived: clients.filter((c) => c.archived).length,
            sites: siteIds.size,
        };
    }, [clients]);

    const siteOptions = useMemo(() => {
        const map = new Map<number, string>();
        for (const c of clients) {
            if (c.site && !map.has(c.site.id)) map.set(c.site.id, c.site.name);
        }
        return Array.from(map.entries())
            .map(([id, name]) => ({ id, name }))
            .sort((a, b) => a.name.localeCompare(b.name));
    }, [clients]);

    const tabs: {
        key: TabKey;
        label: string;
        icon: IconType;
        count: number;
        tone: 'neutral' | 'critical' | 'warning';
    }[] = [
        {
            key: 'all',
            label: `All ${clientPlural.toLowerCase()}`,
            icon: LayoutGrid,
            count: stats.total,
            tone: 'neutral',
        },
        {
            key: 'high-risk',
            label: 'High risk',
            icon: ShieldAlert,
            count: stats.highRisk,
            tone: 'critical',
        },
        {
            key: 'onboarding',
            label: 'Onboarding incomplete',
            icon: AlertTriangle,
            count: stats.incomplete,
            tone: 'warning',
        },
        {
            key: 'safeguarding',
            label: 'Safeguarding',
            icon: Shield,
            count: stats.safeguarding,
            tone: 'critical',
        },
        {
            key: 'inactive',
            label: 'Inactive',
            icon: X,
            count: stats.inactive,
            tone: 'neutral',
        },
        ...(filters.showArchived
            ? [
                  {
                      key: 'archived' as TabKey,
                      label: 'Archived',
                      icon: Archive,
                      count: stats.archived,
                      tone: 'neutral' as const,
                  },
              ]
            : []),
    ];
    const tabLabel = tabs.find((t) => t.key === tab)?.label ?? tabs[0].label;

    const filtered = useMemo(() => {
        const q = filters.q.trim().toLowerCase();
        const tabPred = TAB_PREDICATES[tab] ?? TAB_PREDICATES.all;
        return clients.filter((c) => {
            if (!tabPred(c)) return false;
            if (tab !== 'archived' && c.archived && !filters.showArchived)
                return false;
            if (filters.mine && !c.mine) return false;
            if (filters.type === 'permanent' && c.has_respite) return false;
            if (filters.type === 'respite' && !c.has_respite) return false;
            if (
                filters.siteIds.length &&
                !(c.site && filters.siteIds.includes(c.site.id))
            )
                return false;
            if (q) {
                const hay =
                    `${c.first_name} ${c.last_name} ${c.nhi_number ?? ''} ${c.site?.name ?? ''} ${c.status} ${c.key_worker?.name ?? ''}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [clients, filters, tab]);

    const toggleSel = (id: number) =>
        setSelected((prev) => {
            const n = new Set(prev);
            if (n.has(id)) n.delete(id);
            else n.add(id);
            return n;
        });
    const selectAllVisible = () =>
        setSelected(new Set(filtered.map((c) => c.id)));

    const openClient = (c: Client) =>
        router.visit(
            isSupportWorker
                ? `/operations/clients/${c.id}/care`
                : `/operations/clients/${c.id}`,
        );
    const openCtx = (e: React.MouseEvent, c: Client) => {
        e.preventDefault();
        setCtx({ x: e.clientX, y: e.clientY, client: c });
    };

    const archiveClient = (c: Client) =>
        router.delete(`/operations/clients/${c.id}`, { preserveScroll: true });
    const restoreClient = (c: Client) =>
        router.patch(
            `/operations/clients/${c.id}/restore`,
            {},
            { preserveScroll: true },
        );

    const exportSelected = () => {
        const rows = clients.filter((c) => selected.has(c.id));
        exportClientsCsv(rows);
        toast.success(
            `Exported ${rows.length} ${rows.length === 1 ? 'client' : 'clients'} to CSV.`,
        );
    };

    // Per-client action list, shared by the kebab and the right-click menu.
    const actionsFor = (c: Client): MenuItem[] => {
        if (c.archived) {
            return compactMenu([
                canArchive && {
                    label: 'Restore client',
                    icon: ArchiveRestore,
                    onClick: () => restoreClient(c),
                },
            ]);
        }
        return compactMenu([
            {
                label: 'Open care view',
                icon: HeartHandshake,
                onClick: () => router.visit(`/operations/clients/${c.id}/care`),
            },
            {
                label: 'View full profile',
                icon: Eye,
                onClick: () => router.visit(`/operations/clients/${c.id}`),
            },
            canAddNote && {
                label: 'Add daily note',
                icon: NotebookPen,
                onClick: () =>
                    setNoteClient({
                        id: c.id,
                        name: `${c.first_name} ${c.last_name}`,
                        nhi: c.nhi_number,
                    }),
            },
            { separator: true },
            can.assignmentsUpdate && {
                label: 'Assign workers',
                icon: UserCheck,
                onClick: () =>
                    setAssignClient({
                        id: c.id,
                        name: `${c.first_name} ${c.last_name}`,
                    }),
            },
            canUpdate && {
                label: 'Edit details',
                icon: Pencil,
                onClick: () => setEditingClientId(c.id),
            },
            { separator: true },
            canArchive && {
                label: 'Archive client',
                icon: X,
                danger: true,
                onClick: () => archiveClient(c),
            },
        ]);
    };

    const heroTitle = (
        <span className="flex flex-col">
            <span className="mb-2 flex items-center justify-center gap-2 text-[10.5px] font-semibold tracking-[0.09em] text-primary-foreground/80 uppercase md:justify-start">
                <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-75" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                </span>
                Live · {stats.total}{' '}
                {stats.total === 1
                    ? clientSingular.toLowerCase()
                    : clientPlural.toLowerCase()}{' '}
                synced just now
            </span>
            <span>
                <span className="font-normal text-primary-foreground/80">
                    Kia ora {firstName} — the people in your care,{' '}
                </span>
                <span className="border-b-2 border-primary-foreground/40 pb-0.5">
                    {stats.active} active across {stats.sites}{' '}
                    {stats.sites === 1 ? 'home' : 'homes'}
                </span>
            </span>
        </span>
    );

    const heroDescription = `Pick a ${clientSingular.toLowerCase()} to open their profile, or switch on select mode to act on several at once. ${
        stats.incomplete > 0
            ? `${stats.incomplete} onboarding ${stats.incomplete === 1 ? 'profile' : 'profiles'} to finish`
            : 'All profiles onboarded'
    }${
        stats.safeguarding > 0
            ? `, ${stats.safeguarding} safeguarding ${stats.safeguarding === 1 ? 'flag' : 'flags'} and ${stats.risks} active ${stats.risks === 1 ? 'risk' : 'risks'} need attention.`
            : '.'
    }`;

    const heroMeta: PageHeroMetaItem[] = [
        {
            icon: Building,
            label: `${stats.sites} ${stats.sites === 1 ? 'home' : 'homes'}`,
        },
        {
            icon: Activity,
            label: `${stats.active} active · ${stats.respite} on respite`,
        },
        { icon: CheckCircle2, label: `${stats.complete} fully onboarded` },
    ];

    const heroBadges: PageHeroBadge[] = [
        stats.safeguarding > 0 && {
            icon: ShieldAlert,
            label: `${stats.safeguarding} safeguarding`,
            tone: 'critical' as const,
        },
        stats.risks > 0 && {
            icon: AlertTriangle,
            label: `${stats.risks} with active risks`,
            tone: 'warning' as const,
        },
        stats.respite > 0 && {
            icon: BedDouble,
            label: `${stats.respite} respite stays`,
            tone: 'default' as const,
        },
        stats.incomplete === 0 && {
            icon: CheckCircle2,
            label: 'All onboarded',
            tone: 'success' as const,
        },
    ].filter(Boolean) as PageHeroBadge[];

    const heroStats: PageHeroStat[] = [
        { label: 'Total', value: stats.total, hideOnMobile: false },
        { label: 'Active', value: stats.active, hideOnMobile: false },
        { label: 'Onboarding', value: stats.incomplete, hideOnMobile: false },
        {
            label: 'Respite',
            value: stats.respite,
            tone: 'success',
            hideOnMobile: false,
        },
    ];

    const heroActions = (
        <>
            <button
                type="button"
                onClick={() => setSelectMode((v) => !v)}
                className={cn(
                    'inline-flex h-9 items-center gap-1.5 rounded-md px-3.5 text-sm font-semibold transition-colors',
                    selectMode
                        ? 'bg-primary-foreground text-primary hover:bg-primary-foreground/90'
                        : 'border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
                )}
            >
                <Check className="h-4 w-4" />
                {selectMode ? 'Done' : 'Select'}
            </button>
            {canCreate ? (
                <Link
                    href="/operations/clients/create"
                    className="inline-flex h-9 items-center gap-1.5 rounded-md bg-primary-foreground px-3.5 text-sm font-semibold text-primary transition hover:bg-primary-foreground/90"
                >
                    <Plus className="h-4 w-4" />
                    Add {clientSingular.toLowerCase()}
                </Link>
            ) : null}
        </>
    );

    const heroFooter = (
        <div className="flex flex-wrap items-center justify-between gap-3 py-3">
            <div className="relative max-w-[340px] min-w-[200px] flex-1">
                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-primary-foreground/70" />
                <input
                    value={filters.q}
                    onChange={(e) => setFilter('q', e.target.value)}
                    placeholder="Search name, NHI, key worker…"
                    className="h-9 w-full rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 pr-3 pl-9 text-sm text-primary-foreground outline-none placeholder:text-primary-foreground/60 focus:border-primary-foreground/50 focus:bg-primary-foreground/15"
                />
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <HeroSegment<TypeFilter>
                    ariaLabel="Client type"
                    value={filters.type}
                    onChange={(v) => setFilter('type', v)}
                    options={[
                        { value: 'all', label: 'All' },
                        { value: 'permanent', label: 'Permanent' },
                        { value: 'respite', label: 'Respite', icon: BedDouble },
                    ]}
                />
                <MultiEntityFilter
                    label="Home"
                    allLabel="All homes"
                    pluralLabel="homes"
                    items={siteOptions}
                    value={filters.siteIds}
                    onChange={(next) => setFilter('siteIds', next)}
                    onDark
                />
                <button
                    type="button"
                    onClick={() => setFilter('mine', !filters.mine)}
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                        filters.mine
                            ? 'border-primary-foreground bg-primary-foreground text-primary'
                            : 'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
                    )}
                >
                    <UserCheck className="h-3.5 w-3.5" />
                    My clients
                </button>
                <button
                    type="button"
                    onClick={() =>
                        setFilter('showArchived', !filters.showArchived)
                    }
                    className={cn(
                        'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                        filters.showArchived
                            ? 'border-primary-foreground bg-primary-foreground text-primary'
                            : 'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
                    )}
                >
                    <span
                        className={cn(
                            'flex h-4 w-4 items-center justify-center rounded-[5px] border',
                            filters.showArchived
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-primary-foreground/60 text-transparent',
                        )}
                    >
                        <Check className="h-3 w-3" />
                    </span>
                    Show archived
                </button>
                <HeroSegment<'cards' | 'table'>
                    ariaLabel="View"
                    value={view}
                    onChange={setView}
                    options={[
                        { value: 'cards', label: 'Cards', icon: LayoutGrid },
                        { value: 'table', label: 'Table', icon: Rows3 },
                    ]}
                />
            </div>
        </div>
    );

    const renderGrid = (items: Client[]) => (
        <div className="grid [grid-template-columns:repeat(auto-fill,minmax(360px,1fr))] gap-[15px]">
            {items.map((c) => (
                <ClientCard
                    key={c.id}
                    c={c}
                    selectMode={selectMode}
                    selected={selected.has(c.id)}
                    actions={actionsFor(c)}
                    onOpen={openClient}
                    onToggle={toggleSel}
                    onContext={openCtx}
                />
            ))}
        </div>
    );

    const renderTable = (items: Client[]) => (
        <Card className="overflow-hidden py-0">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[820px] text-[13px]">
                    <thead>
                        <tr className="border-b border-border bg-muted/45 text-left text-[10.5px] font-semibold tracking-wide text-muted-foreground uppercase">
                            {selectMode ? (
                                <th className="w-9 px-3.5 py-3" />
                            ) : null}
                            <th className="px-3.5 py-3">{clientSingular}</th>
                            <th className="px-3.5 py-3">Home</th>
                            <th className="px-3.5 py-3">Status</th>
                            <th className="px-3.5 py-3">Onboarding</th>
                            <th className="px-3.5 py-3">Safety</th>
                            <th className="px-3.5 py-3">Key worker</th>
                            <th className="px-3.5 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((c) => (
                            <ClientRow
                                key={c.id}
                                c={c}
                                selectMode={selectMode}
                                selected={selected.has(c.id)}
                                onOpen={openClient}
                                onToggle={toggleSel}
                                onContext={openCtx}
                            />
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );

    return (
        <AppLayout
            breadcrumbs={[{ title: clientPlural, href: '/operations/clients' }]}
        >
            <Head title={clientPlural} />

            <PageLayout
                hero={
                    <PageHero
                        category="ops"
                        icon={HeartHandshake}
                        title={heroTitle}
                        description={heroDescription}
                        meta={heroMeta}
                        badges={heroBadges}
                        stats={heroStats}
                        actions={heroActions}
                        footer={heroFooter}
                    />
                }
            >
                <ViewTabs items={tabs} value={tab} onSelect={setTab} />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="flex items-baseline gap-2.5 text-[15px] font-semibold tracking-tight">
                        {tabLabel}
                        <span className="text-xs font-medium text-muted-foreground">
                            {filtered.length} of{' '}
                            {tab === 'archived' ? stats.archived : stats.total}{' '}
                            shown
                            {selectMode ? ' · tap to select' : ''}
                        </span>
                    </h2>
                    <ActiveFilterChips
                        filters={filters}
                        setFilter={setFilter}
                        siteOptions={siteOptions}
                    />
                </div>

                {filtered.length === 0 ? (
                    <div className="flex flex-col items-center gap-1 rounded-xl border border-dashed border-border bg-card/40 px-6 py-16 text-center">
                        <Users className="mb-2 h-10 w-10 text-muted-foreground/40" />
                        <div className="font-semibold text-foreground">
                            No {clientPlural.toLowerCase()} match this view
                        </div>
                        <div className="text-sm text-muted-foreground">
                            Try a different tab or clear your filters.
                        </div>
                    </div>
                ) : view === 'table' ? (
                    renderTable(filtered)
                ) : (
                    renderGrid(filtered)
                )}
            </PageLayout>

            {selectMode && selected.size > 0 ? (
                <BulkBar
                    count={selected.size}
                    total={filtered.length}
                    onSelectAll={selectAllVisible}
                    onExport={exportSelected}
                    onClear={() => setSelected(new Set())}
                />
            ) : null}

            {ctx ? (
                <ClientContextMenu
                    x={ctx.x}
                    y={ctx.y}
                    client={ctx.client}
                    items={actionsFor(ctx.client)}
                    onClose={() => setCtx(null)}
                />
            ) : null}

            <ClientEditDialog
                clientId={editingClientId}
                open={editingClientId !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) setEditingClientId(null);
                }}
                siteSingular={labels?.['site.singular'] ?? 'Site'}
            />

            <AssignWorkerDialog
                client={assignClient}
                open={assignClient !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) setAssignClient(null);
                }}
            />

            <AddDailyNoteDialog
                client={noteClient}
                open={noteClient !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) setNoteClient(null);
                }}
            />
        </AppLayout>
    );
}
