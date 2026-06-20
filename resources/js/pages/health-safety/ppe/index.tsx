/**
 * PPE & Equipment register — H&S gold-standard command centre.
 * Hero (bespoke Catalogue→Retire ribbon + two clusters + NZ compliance badges +
 * footer filter bar) · TabStrip (9 server-counted tabs) · three register tables
 * (inventory / allocations / catalogue) with left-click→detail + right-click→menu
 * + keyboard · detail-as-modal · every workflow an Add-Client-style modal.
 * Semantic tokens only. NZ-only, web-only.
 */
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import {
    HeroCluster,
    HeroClusterTile,
    HeroComplianceBadges,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
    fmt,
    type HeroComplianceBadge,
} from '@/pages/health-safety/components/hs-hero-kit';
import {
    FlagBadge,
    RegisterTableHeader,
    entityTone,
    initials,
} from '@/pages/health-safety/components/register-row-kit';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    BadgeCheck,
    Ban,
    BarChart3,
    Bell,
    Check,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Copy,
    ExternalLink,
    Eye,
    Hexagon,
    List,
    MousePointer2,
    Package,
    PackageCheck,
    Pencil,
    Plus,
    Reply,
    Search,
    ShieldCheck,
    Trash2,
    User,
    UserPlus,
    X,
} from 'lucide-react';
import {
    createElement,
    useState,
    type KeyboardEvent as ReactKeyboardEvent,
    type MouseEvent as ReactMouseEvent,
    type ReactNode,
} from 'react';
import {
    CondemnDialog,
    DisposeDialog,
    InspectionDialog,
    ReturnDialog,
} from './ppe-action-dialogs';
import {
    AllocateDialog,
    InventoryDialog,
    TypeDialog,
} from './ppe-create-wizards';
import { PpeDetailDialog, type DetailAction } from './ppe-detail-dialog';
import {
    PpeChip,
    catIcon,
    catLabel,
    catTone,
    condLabel,
    condTone,
    daysUntil,
    fmtDateNZ,
    inventoryFlags,
    statusLabel,
    statusTone,
    type AllocationRow,
    type InventoryRow,
    type PpePageProps,
    type TypeRow,
} from './ppe-shared';

const PPE_URL = '/health-safety/ppe';

const CAT_FILTER_OPTIONS = [
    { value: 'respiratory', label: 'Respiratory' },
    { value: 'head', label: 'Head' },
    { value: 'eye', label: 'Eye' },
    { value: 'ear', label: 'Hearing' },
    { value: 'hand', label: 'Hand' },
    { value: 'foot', label: 'Foot' },
    { value: 'high_visibility', label: 'Hi-vis' },
    { value: 'fall_protection', label: 'Fall protection' },
    { value: 'body', label: 'Body' },
    { value: 'other', label: 'Other' },
];
const STATUS_FILTER_OPTIONS = [
    { value: 'available', label: 'Available' },
    { value: 'allocated', label: 'Allocated' },
    { value: 'maintenance', label: 'In repair' },
    { value: 'condemned', label: 'Condemned' },
    { value: 'disposed', label: 'Disposed' },
];

// ───────────────────────── Bespoke on-dark workflow ribbon ─────────────────────────

const RIBBON = [
    { label: 'Catalogue', icon: Hexagon, tab: 'types' },
    { label: 'Stock', icon: Package, tab: 'inv_all' },
    { label: 'Issue', icon: User, tab: 'alloc_active' },
    { label: 'Inspect', icon: ClipboardCheck, tab: 'inv_inspection' },
    { label: 'Retire', icon: Ban, tab: 'inv_condemned' },
];

function stageForTab(tab: string): number {
    if (tab === 'types') return 0;
    if (tab === 'inv_inspection') return 3;
    if (tab === 'inv_condemned' || tab === 'inv_expiring') return 4;
    if (tab.startsWith('alloc')) return 2;
    return 1;
}

function PpeRibbon({
    current,
    onStage,
}: {
    current: number;
    onStage: (tab: string) => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {RIBBON.map((s, i) => {
                const lit = i <= current;
                const Icon = s.icon;
                return (
                    <span key={s.label} className="flex items-center gap-1.5">
                        {/* eslint-disable-next-line no-restricted-syntax -- on-dark ribbon pill, not a shadcn Button */}
                        <button
                            type="button"
                            onClick={() => onStage(s.tab)}
                            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold transition-colors ${
                                lit
                                    ? 'bg-primary-foreground/20 text-primary-foreground'
                                    : 'bg-primary-foreground/[0.07] text-primary-foreground/55 hover:text-primary-foreground/80'
                            }`}
                        >
                            <Icon className="h-3 w-3" /> {s.label}
                        </button>
                        {i < RIBBON.length - 1 ? (
                            <span className="text-primary-foreground/40">
                                ›
                            </span>
                        ) : null}
                    </span>
                );
            })}
        </div>
    );
}

function HeroSelect({
    label,
    value,
    onChange,
    options,
    allLabel,
}: {
    label: string;
    value: string | null;
    onChange: (v: string | null) => void;
    options: { value: string; label: string }[];
    allLabel: string;
}) {
    return (
        <label className="inline-flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
            {label}
            <select
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value || null)}
                className="rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-medium text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
            >
                <option value="" className="text-foreground">
                    {allLabel}
                </option>
                {options.map((o) => (
                    <option
                        key={o.value}
                        value={o.value}
                        className="text-foreground"
                    >
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

// ───────────────────────── Cells ─────────────────────────

function DateCell({
    date,
    warnWin,
    icon: Icon,
}: {
    date: string | null;
    warnWin: number;
    icon: typeof Clock;
}) {
    if (!date) return <span className="text-muted-foreground">—</span>;
    const d = daysUntil(date);
    let flag: ReactNode = null;
    if (d !== null && d < 0)
        flag = (
            <FlagBadge icon={Icon} tone="critical" title="Overdue">
                {Math.abs(d)}d overdue
            </FlagBadge>
        );
    else if (d !== null && d <= warnWin)
        flag = (
            <FlagBadge icon={Icon} tone="warning" title="Due soon">
                in {d}d
            </FlagBadge>
        );
    return (
        <div className="flex flex-col gap-1">
            <span className="text-[12.5px]">{fmtDateNZ(date)}</span>
            {flag}
        </div>
    );
}

const TH =
    'px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wide text-muted-foreground whitespace-nowrap';
const TD = 'px-4 py-3 align-top';
const rowCls =
    'cursor-pointer transition-colors hover:bg-muted/45 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary';

function rowKeyOpen(e: ReactKeyboardEvent, open: () => void) {
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        open();
    }
}

function CatTile({ category }: { category?: string | null }) {
    const tone = catTone(category);
    const cls = {
        success: 'bg-status-success-bg text-status-success',
        warning: 'bg-status-warning-bg text-status-warning',
        critical: 'bg-status-critical-bg text-status-critical',
        info: 'bg-accent text-primary',
        neutral: 'bg-muted text-muted-foreground',
    }[tone];
    return (
        <span
            className={`grid h-[30px] w-[30px] shrink-0 place-items-center rounded-lg ${cls}`}
        >
            {createElement(catIcon(category), { className: 'h-4 w-4' })}
        </span>
    );
}

function EmptyRow({
    colSpan,
    icon: Icon,
    title,
    sub,
}: {
    colSpan: number;
    icon: typeof Package;
    title: string;
    sub: string;
}) {
    return (
        <tr>
            <td colSpan={colSpan} className="px-4 py-14 text-center">
                <div className="flex flex-col items-center gap-1.5">
                    <Icon className="h-9 w-9 text-muted-foreground" />
                    <div className="text-sm font-semibold">{title}</div>
                    <div className="text-xs text-muted-foreground">{sub}</div>
                </div>
            </td>
        </tr>
    );
}

// ───────────────────────── Tables ─────────────────────────

function InventoryTable({
    rows,
    onOpen,
    onCtx,
}: {
    rows: InventoryRow[];
    onOpen: (id: number) => void;
    onCtx: (e: ReactMouseEvent, r: InventoryRow) => void;
}) {
    return (
        <table className="w-full text-sm">
            <thead>
                <tr className="border-b border-border">
                    <th className={TH}>Type</th>
                    <th className={TH}>Site / location</th>
                    <th className={TH}>Identification</th>
                    <th className={TH}>Condition</th>
                    <th className={TH}>Status</th>
                    <th className={TH}>Next inspection</th>
                    <th className={TH}>Expiry</th>
                    <th className={TH}>Flags</th>
                </tr>
            </thead>
            <tbody className="divide-y divide-border">
                {rows.length === 0 ? (
                    <EmptyRow
                        colSpan={8}
                        icon={Package}
                        title="No inventory here"
                        sub="Nothing matches this tab and the active filters."
                    />
                ) : null}
                {rows.map((r) => {
                    const flags = inventoryFlags(r);
                    return (
                        <tr
                            key={r.id}
                            className={rowCls}
                            tabIndex={0}
                            onClick={() => onOpen(r.id)}
                            onContextMenu={(e) => onCtx(e, r)}
                            onKeyDown={(e) => rowKeyOpen(e, () => onOpen(r.id))}
                        >
                            <td className={TD}>
                                <div className="flex items-center gap-2.5">
                                    <CatTile category={r.ppe_type?.category} />
                                    <div className="min-w-0">
                                        <div className="font-semibold">
                                            {r.ppe_type?.name ?? '—'}
                                        </div>
                                        <div className="truncate text-[11.5px] text-muted-foreground">
                                            {catLabel(r.ppe_type?.category)}
                                            {r.ppe_type?.standards_reference
                                                ? ` · ${r.ppe_type.standards_reference}`
                                                : ''}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td className={TD}>
                                <div className="font-medium">
                                    {r.site?.name ?? '—'}
                                </div>
                                <div className="text-[12px] text-muted-foreground">
                                    {r.location ?? ''}
                                </div>
                            </td>
                            <td className={TD}>
                                <div className="font-medium">
                                    {[r.brand, r.model]
                                        .filter(Boolean)
                                        .join(' ') || '—'}
                                </div>
                                <div className="text-[11.5px] text-muted-foreground tabular-nums">
                                    {r.serial_number ?? '—'}
                                    {r.quantity > 1 ? ` · ×${r.quantity}` : ''}
                                </div>
                            </td>
                            <td className={TD}>
                                <PpeChip tone={condTone(r.condition)}>
                                    {condLabel(r.condition)}
                                </PpeChip>
                            </td>
                            <td className={TD}>
                                <PpeChip tone={statusTone(r.status)}>
                                    {statusLabel(r.status)}
                                </PpeChip>
                            </td>
                            <td className={TD}>
                                <DateCell
                                    date={r.next_inspection_due}
                                    warnWin={30}
                                    icon={Clock}
                                />
                            </td>
                            <td className={TD}>
                                <DateCell
                                    date={r.expiry_date}
                                    warnWin={60}
                                    icon={AlertTriangle}
                                />
                            </td>
                            <td className={TD}>
                                {flags.length ? (
                                    <div className="flex flex-wrap gap-1">
                                        {flags.map((fl, i) => (
                                            <FlagBadge
                                                key={i}
                                                icon={fl.icon}
                                                tone={fl.tone}
                                                title={fl.title}
                                            >
                                                {fl.label}
                                            </FlagBadge>
                                        ))}
                                    </div>
                                ) : (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </td>
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}

function AllocationTable({
    rows,
    onOpen,
    onCtx,
}: {
    rows: AllocationRow[];
    onOpen: (id: number) => void;
    onCtx: (e: ReactMouseEvent, r: AllocationRow) => void;
}) {
    return (
        <table className="w-full text-sm">
            <thead>
                <tr className="border-b border-border">
                    <th className={TH}>Worker</th>
                    <th className={TH}>Item</th>
                    <th className={TH}>Allocated</th>
                    <th className={TH}>Fit-test</th>
                    <th className={TH}>Training</th>
                    <th className={TH}>Acknowledged</th>
                </tr>
            </thead>
            <tbody className="divide-y divide-border">
                {rows.length === 0 ? (
                    <EmptyRow
                        colSpan={6}
                        icon={BadgeCheck}
                        title="No allocations here"
                        sub="Nothing matches this tab and the active filters."
                    />
                ) : null}
                {rows.map((r) => {
                    const rpe = r.ppe_type?.category === 'respiratory';
                    return (
                        <tr
                            key={r.id}
                            className={rowCls}
                            tabIndex={0}
                            onClick={() => onOpen(r.id)}
                            onContextMenu={(e) => onCtx(e, r)}
                            onKeyDown={(e) => rowKeyOpen(e, () => onOpen(r.id))}
                        >
                            <td className={TD}>
                                <div className="flex items-center gap-2.5">
                                    <span
                                        className={`grid h-[30px] w-[30px] shrink-0 place-items-center rounded-full text-[11px] font-bold ${entityTone(r.user?.id ?? 0)}`}
                                    >
                                        {initials(r.user?.name)}
                                    </span>
                                    <span className="font-semibold">
                                        {r.user?.name ?? '—'}
                                    </span>
                                </div>
                            </td>
                            <td className={TD}>
                                <div className="flex items-center gap-2">
                                    <CatTile category={r.ppe_type?.category} />
                                    <div className="min-w-0">
                                        <div className="font-medium">
                                            {r.ppe_type?.name ?? '—'}
                                        </div>
                                        <div className="text-[11.5px] text-muted-foreground">
                                            {r.inventory_item?.serial_number ??
                                                '—'}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td className={TD}>
                                <span className="text-[12.5px] text-muted-foreground">
                                    {fmtDateNZ(r.allocated_at)}
                                </span>
                            </td>
                            <td className={TD}>
                                {rpe ? (
                                    r.fit_test_completed ? (
                                        <PpeChip tone="success" icon={Check}>
                                            Pass
                                            {r.fit_test_date
                                                ? ` · ${fmtDateNZ(r.fit_test_date)}`
                                                : ''}
                                        </PpeChip>
                                    ) : (
                                        <PpeChip
                                            tone="critical"
                                            icon={AlertTriangle}
                                        >
                                            Required
                                        </PpeChip>
                                    )
                                ) : (
                                    <span className="text-[12px] text-muted-foreground">
                                        N/A
                                    </span>
                                )}
                            </td>
                            <td className={TD}>
                                {r.training_completed ? (
                                    <PpeChip tone="success" icon={Check}>
                                        Done
                                    </PpeChip>
                                ) : (
                                    <PpeChip tone="warning">
                                        Outstanding
                                    </PpeChip>
                                )}
                            </td>
                            <td className={TD}>
                                {r.acknowledged ? (
                                    <PpeChip tone="success" icon={BadgeCheck}>
                                        Acknowledged
                                    </PpeChip>
                                ) : (
                                    <PpeChip tone="warning">Pending</PpeChip>
                                )}
                            </td>
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}

function TypeTable({
    rows,
    onEdit,
    onCtx,
}: {
    rows: TypeRow[];
    onEdit: (r: TypeRow) => void;
    onCtx: (e: ReactMouseEvent, r: TypeRow) => void;
}) {
    return (
        <table className="w-full text-sm">
            <thead>
                <tr className="border-b border-border">
                    <th className={TH}>Type</th>
                    <th className={TH}>Category</th>
                    <th className={TH}>Standard</th>
                    <th className={TH}>Inspection</th>
                    <th className={TH}>Lifespan</th>
                    <th className={TH}>Status</th>
                </tr>
            </thead>
            <tbody className="divide-y divide-border">
                {rows.length === 0 ? (
                    <EmptyRow
                        colSpan={6}
                        icon={Hexagon}
                        title="No types"
                        sub="Nothing matches the active filters."
                    />
                ) : null}
                {rows.map((r) => (
                    <tr
                        key={r.id}
                        className={rowCls}
                        tabIndex={0}
                        onClick={() => onEdit(r)}
                        onContextMenu={(e) => onCtx(e, r)}
                        onKeyDown={(e) => rowKeyOpen(e, () => onEdit(r))}
                    >
                        <td className={TD}>
                            <div className="flex items-center gap-2.5">
                                <CatTile category={r.category} />
                                <div className="min-w-0">
                                    <div className="font-semibold">
                                        {r.name}
                                    </div>
                                    <div className="max-w-[320px] truncate text-[11.5px] text-muted-foreground">
                                        {r.hazards_addressed ?? ''}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td className={TD}>
                            <PpeChip tone={catTone(r.category)}>
                                {catLabel(r.category)}
                            </PpeChip>
                        </td>
                        <td className={TD}>
                            <span className="text-[12.5px] font-medium">
                                {r.standards_reference ?? '—'}
                            </span>
                        </td>
                        <td className={TD}>
                            <span className="text-[12.5px] text-muted-foreground capitalize">
                                {r.inspection_frequency ?? '—'}
                            </span>
                        </td>
                        <td className={TD}>
                            <span className="text-[12.5px] text-muted-foreground">
                                {r.typical_lifespan_months
                                    ? `${r.typical_lifespan_months} mo`
                                    : '—'}
                            </span>
                        </td>
                        <td className={TD}>
                            {r.is_active ? (
                                <PpeChip tone="success" icon={Check}>
                                    Active
                                </PpeChip>
                            ) : (
                                <PpeChip tone="neutral">Retired</PpeChip>
                            )}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

// ───────────────────────── Modal orchestration state ─────────────────────────

type Modal =
    | { kind: 'inventory'; edit?: InventoryRow | null; lockedTypeId?: number }
    | {
          kind: 'allocate';
          lockedItem?: {
              id: number;
              label: string;
              category: string | null;
          } | null;
      }
    | { kind: 'type'; edit?: TypeRow | null }
    | {
          kind: 'return';
          allocationId: number;
          worker: string;
          itemLabel: string;
      }
    | { kind: 'inspect'; inventoryId: number; itemLabel: string }
    | { kind: 'condemn'; inventoryId: number; itemLabel: string }
    | { kind: 'dispose'; inventoryId: number; itemLabel: string };

export default function PpeIndex({
    tab,
    filters,
    inventory,
    allocations,
    types,
    tabCounts,
    hero,
    sites,
    staff,
    allocatable,
    detail,
    can,
}: PpePageProps) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [launcherOpen, setLauncherOpen] = useState(false);
    const [modal, setModal] = useState<Modal | null>(null);
    const closeModal = () => setModal(null);

    const f = filters;
    const go = (next: Record<string, unknown>) =>
        router.get(
            PPE_URL,
            { ...f, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const setTab = (id: string) =>
        router.get(PPE_URL, { ...f, tab: id }, { preserveScroll: true });
    const openItem = (id: number) =>
        router.get(
            PPE_URL,
            { ...f, item: id },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    const openAllocation = (id: number) =>
        router.get(
            PPE_URL,
            { ...f, allocation: id },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    const closeDetail = () =>
        router.get(
            PPE_URL,
            { ...f },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    const clearFilters = () =>
        router.get(
            PPE_URL,
            { tab },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const hasFilters = !!(
        f.site_id ||
        f.category ||
        f.status ||
        f.ppe_type_id ||
        f.search
    );
    const copyLink = (param: 'item' | 'allocation', id: number) =>
        navigator.clipboard?.writeText(
            `${window.location.origin}${PPE_URL}?${param}=${id}`,
        );
    const acknowledge = (id: number) =>
        router.post(
            `${PPE_URL}/allocations/${id}/acknowledge`,
            {},
            { preserveScroll: true, preserveState: true },
        );
    const toggleType = (r: TypeRow) =>
        router.patch(
            `${PPE_URL}/types/${r.id}/${r.is_active ? 'deactivate' : 'activate'}`,
            {},
            { preserveScroll: true, preserveState: true },
        );
    const exportCsv = () => {
        const params = new URLSearchParams(
            Object.entries(f)
                .filter(([, v]) => v != null && v !== '')
                .map(([k, v]) => [k, String(v)]),
        );
        window.open(`${PPE_URL}/export?${params.toString()}`, '_blank');
    };

    const c = hero.compliance;
    const badges: HeroComplianceBadge[] = [
        {
            icon: c.rpe_fit_test_due > 0 ? AlertTriangle : CheckCircle2,
            // RPE issued without a current fit-test is an AS/NZS 1715 breach (can't lawfully
            // use the respirator) — escalate to critical, not a soft warning.
            tone: c.rpe_fit_test_due > 0 ? 'critical' : 'success',
            label:
                c.rpe_fit_test_due > 0
                    ? `RPE fit-test · ${c.rpe_fit_test_due} due`
                    : 'RPE fit-test · all current',
        },
        {
            icon: c.inspections_overdue > 0 ? AlertTriangle : CheckCircle2,
            tone: c.inspections_overdue > 0 ? 'critical' : 'success',
            label:
                c.inspections_overdue > 0
                    ? `Inspections · ${c.inspections_overdue} overdue`
                    : 'Inspections · current',
        },
        {
            icon: c.items_expiring > 0 ? AlertTriangle : CheckCircle2,
            tone: c.items_expiring > 0 ? 'warning' : 'success',
            label:
                c.items_expiring > 0
                    ? `Expiry · ${c.items_expiring} item${c.items_expiring === 1 ? '' : 's'} expiring`
                    : 'Expiry · all in date',
        },
        {
            icon: c.condemned_awaiting > 0 ? Ban : CheckCircle2,
            tone: c.condemned_awaiting > 0 ? 'warning' : 'success',
            label:
                c.condemned_awaiting > 0
                    ? `Condemned · ${c.condemned_awaiting} awaiting disposal`
                    : 'Disposal · clear',
        },
        {
            icon: ShieldCheck,
            tone:
                c.hi_vis_covered && c.footwear_covered ? 'success' : 'warning',
            label:
                c.hi_vis_covered && c.footwear_covered
                    ? 'Hi-vis & footwear · Covered'
                    : 'Hi-vis & footwear · Gaps',
        },
    ];

    const live = hero.clusters.live;
    const att = hero.clusters.attention;

    const TABS: RosterTabItem[] = [
        {
            id: 'inv_all',
            label: 'All inventory',
            icon: List,
            tone: 'primary',
            badge: tabCounts.inv_all || undefined,
        },
        {
            id: 'inv_available',
            label: 'Available',
            icon: PackageCheck,
            tone: 'success',
            badge: tabCounts.inv_available || undefined,
        },
        {
            id: 'inv_allocated',
            label: 'Allocated',
            icon: User,
            tone: 'info',
            badge: tabCounts.inv_allocated || undefined,
        },
        {
            id: 'inv_inspection',
            label: 'Inspection due',
            icon: ClipboardCheck,
            tone: 'warning',
            badge: tabCounts.inv_inspection || undefined,
        },
        {
            id: 'inv_expiring',
            label: 'Expiring',
            icon: AlertTriangle,
            tone: 'critical',
            badge: tabCounts.inv_expiring || undefined,
        },
        {
            id: 'inv_condemned',
            label: 'Condemned',
            icon: Ban,
            tone: 'critical',
            badge: tabCounts.inv_condemned || undefined,
        },
        {
            id: 'alloc_active',
            label: 'Allocations',
            icon: BadgeCheck,
            tone: 'info',
            badge: tabCounts.alloc_active || undefined,
        },
        {
            id: 'alloc_unack',
            label: 'Unacknowledged',
            icon: AlertTriangle,
            tone: 'warning',
            badge: tabCounts.alloc_unack || undefined,
        },
        {
            id: 'types',
            label: 'Catalogue',
            icon: Hexagon,
            tone: 'primary',
            badge: tabCounts.types || undefined,
        },
    ];

    const rowsKind: 'inventory' | 'allocations' | 'types' = tab.startsWith(
        'alloc',
    )
        ? 'allocations'
        : tab === 'types'
          ? 'types'
          : 'inventory';

    const onDetailAction = (a: DetailAction) => {
        switch (a.kind) {
            case 'allocate':
                setModal({
                    kind: 'allocate',
                    lockedItem: {
                        id: a.itemId,
                        label: a.label,
                        category: a.category,
                    },
                });
                break;
            case 'inspect':
                setModal({
                    kind: 'inspect',
                    inventoryId: a.itemId,
                    itemLabel: a.label,
                });
                break;
            case 'condemn':
                setModal({
                    kind: 'condemn',
                    inventoryId: a.itemId,
                    itemLabel: a.label,
                });
                break;
            case 'dispose':
                setModal({
                    kind: 'dispose',
                    inventoryId: a.itemId,
                    itemLabel: a.label,
                });
                break;
            case 'return':
                setModal({
                    kind: 'return',
                    allocationId: a.allocationId,
                    worker: a.worker,
                    itemLabel: a.itemLabel,
                });
                break;
            case 'acknowledge':
                acknowledge(a.allocationId);
                break;
        }
    };

    const invCtx = (e: ReactMouseEvent, r: InventoryRow) => {
        e.preventDefault();
        const label = `${r.ppe_type?.name ?? 'PPE'}${r.serial_number ? ` (${r.serial_number})` : ''}`;
        const condemned = r.status === 'condemned';
        const disposed = r.status === 'disposed';
        const items: ShiftCtxItem[] = [
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'View item',
                sub: r.serial_number ?? undefined,
                tone: 'primary',
                onClick: () => openItem(r.id),
            },
            ...(can.manage
                ? [
                      {
                          icon: <Pencil className="h-3.5 w-3.5" />,
                          label: 'Edit item',
                          onClick: () =>
                              setModal({ kind: 'inventory', edit: r }),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            ...(can.manage && r.status === 'available'
                ? [
                      {
                          icon: <UserPlus className="h-3.5 w-3.5" />,
                          label: 'Allocate to worker',
                          onClick: () =>
                              setModal({
                                  kind: 'allocate',
                                  lockedItem: {
                                      id: r.id,
                                      label,
                                      category: r.ppe_type?.category ?? null,
                                  },
                              }),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            ...(can.manage && !disposed
                ? [
                      {
                          icon: <ClipboardCheck className="h-3.5 w-3.5" />,
                          label: 'Record inspection',
                          onClick: () =>
                              setModal({
                                  kind: 'inspect',
                                  inventoryId: r.id,
                                  itemLabel: label,
                              }),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            ...(can.manage && !condemned && !disposed
                ? [
                      { sep: true } satisfies ShiftCtxItem,
                      {
                          icon: <Ban className="h-3.5 w-3.5" />,
                          label: 'Condemn',
                          tone: 'critical',
                          onClick: () =>
                              setModal({
                                  kind: 'condemn',
                                  inventoryId: r.id,
                                  itemLabel: label,
                              }),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            ...(can.manage && condemned
                ? [
                      {
                          icon: <Trash2 className="h-3.5 w-3.5" />,
                          label: 'Dispose',
                          tone: 'critical',
                          onClick: () =>
                              setModal({
                                  kind: 'dispose',
                                  inventoryId: r.id,
                                  itemLabel: label,
                              }),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            { sep: true },
            {
                icon: <Copy className="h-3.5 w-3.5" />,
                label: 'Copy link',
                onClick: () => copyLink('item', r.id),
            },
        ];
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: catLabel(r.ppe_type?.category),
            meta: `${r.ppe_type?.name ?? 'PPE'} · ${r.site?.name ?? '—'}`,
            items,
        });
    };

    const allocCtx = (e: ReactMouseEvent, r: AllocationRow) => {
        e.preventDefault();
        const label = `${r.ppe_type?.name ?? 'PPE'}${r.inventory_item?.serial_number ? ` (${r.inventory_item.serial_number})` : ''}`;
        const items: ShiftCtxItem[] = [
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'View allocation',
                sub: r.user?.name ?? undefined,
                tone: 'primary',
                onClick: () => openAllocation(r.id),
            },
            ...(can.manage && !r.acknowledged
                ? [
                      {
                          icon: <BadgeCheck className="h-3.5 w-3.5" />,
                          label: 'Mark acknowledged',
                          onClick: () => acknowledge(r.id),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            ...(can.manage
                ? [
                      {
                          icon: <Reply className="h-3.5 w-3.5" />,
                          label: 'Return PPE',
                          onClick: () =>
                              setModal({
                                  kind: 'return',
                                  allocationId: r.id,
                                  worker: r.user?.name ?? 'worker',
                                  itemLabel: label,
                              }),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            ...(can.manage && r.inventory_item
                ? [
                      {
                          icon: <ClipboardCheck className="h-3.5 w-3.5" />,
                          label: 'Record inspection',
                          onClick: () =>
                              setModal({
                                  kind: 'inspect',
                                  inventoryId: r.inventory_item!.id,
                                  itemLabel: label,
                              }),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            { sep: true },
            {
                icon: <Copy className="h-3.5 w-3.5" />,
                label: 'Copy link',
                onClick: () => copyLink('allocation', r.id),
            },
        ];
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: 'Issued',
            meta: `${r.user?.name ?? 'Worker'} · ${r.ppe_type?.name ?? 'PPE'}`,
            items,
        });
    };

    const typeCtx = (e: ReactMouseEvent, r: TypeRow) => {
        e.preventDefault();
        if (!can.manage) return;
        const items: ShiftCtxItem[] = [
            {
                icon: <Pencil className="h-3.5 w-3.5" />,
                label: 'Edit type',
                tone: 'primary',
                onClick: () => setModal({ kind: 'type', edit: r }),
            },
            {
                icon: r.is_active ? (
                    <Ban className="h-3.5 w-3.5" />
                ) : (
                    <Check className="h-3.5 w-3.5" />
                ),
                label: r.is_active ? 'Deactivate' : 'Activate',
                onClick: () => toggleType(r),
            },
            { sep: true },
            {
                icon: <Package className="h-3.5 w-3.5" />,
                label: 'Add inventory of this type',
                onClick: () =>
                    setModal({ kind: 'inventory', lockedTypeId: r.id }),
            },
        ];
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: catLabel(r.category),
            meta: r.name,
            items,
        });
    };

    const heroCtx = (e: ReactMouseEvent) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [
            ...(can.manage
                ? [
                      {
                          icon: <Hexagon className="h-3.5 w-3.5" />,
                          label: 'Add PPE type',
                          tone: 'primary',
                          onClick: () => setModal({ kind: 'type' }),
                      } satisfies ShiftCtxItem,
                      {
                          icon: <Package className="h-3.5 w-3.5" />,
                          label: 'Add inventory',
                          onClick: () => setModal({ kind: 'inventory' }),
                      } satisfies ShiftCtxItem,
                      {
                          icon: <User className="h-3.5 w-3.5" />,
                          label: 'Allocate PPE',
                          onClick: () => setModal({ kind: 'allocate' }),
                      } satisfies ShiftCtxItem,
                      { sep: true } satisfies ShiftCtxItem,
                  ]
                : []),
            {
                icon: <ExternalLink className="h-3.5 w-3.5" />,
                label: 'Export register (CSV)',
                onClick: exportCsv,
            },
            {
                icon: <BarChart3 className="h-3.5 w-3.5" />,
                label: 'Go to analytics',
                onClick: () => router.visit('/health-safety/analytics'),
            },
        ];
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: 'PPE',
            meta: 'Quick actions',
            items,
        });
    };

    const addMenu = [
        {
            icon: Hexagon,
            title: 'Add PPE type',
            blurb: 'New catalogue entry',
            onClick: () => {
                setLauncherOpen(false);
                setModal({ kind: 'type' });
            },
        },
        {
            icon: Package,
            title: 'Add inventory item',
            blurb: 'Physical stock at a site',
            onClick: () => {
                setLauncherOpen(false);
                setModal({ kind: 'inventory' });
            },
        },
        {
            icon: User,
            title: 'Allocate PPE',
            blurb: 'Issue an item to a worker',
            onClick: () => {
                setLauncherOpen(false);
                setModal({ kind: 'allocate' });
            },
        },
    ];

    const tableMeta =
        rowsKind === 'inventory'
            ? {
                  icon: Package,
                  title: 'Inventory',
                  sub: `${inventory.total} item${inventory.total === 1 ? '' : 's'}`,
                  hint: 'Right-click a row for the full lifecycle',
              }
            : rowsKind === 'allocations'
              ? {
                    icon: BadgeCheck,
                    title: 'Allocations',
                    sub: `${allocations.total} active issue${allocations.total === 1 ? '' : 's'}`,
                    hint: 'Right-click a row for the full lifecycle',
                }
              : {
                    icon: Hexagon,
                    title: 'PPE catalogue',
                    sub: `${types.length} type${types.length === 1 ? '' : 's'}`,
                    hint: 'Right-click to edit or retire',
                };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'PPE & Equipment', href: PPE_URL },
            ]}
        >
            <Head title="PPE & Equipment" />

            <div className="flex flex-col gap-6 p-6">
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                                Filter
                            </span>
                            {sites?.length ? (
                                <EntityFilter
                                    label="Site"
                                    allLabel="All sites"
                                    items={sites}
                                    value={f.site_id ? Number(f.site_id) : null}
                                    onChange={(id) => go({ site_id: id })}
                                    onDark
                                />
                            ) : null}
                            <HeroSelect
                                label="Category"
                                allLabel="All categories"
                                value={f.category ?? null}
                                onChange={(v) => go({ category: v })}
                                options={CAT_FILTER_OPTIONS}
                            />
                            <HeroSelect
                                label="Status"
                                allLabel="Any status"
                                value={f.status ?? null}
                                onChange={(v) => go({ status: v })}
                                options={STATUS_FILTER_OPTIONS}
                            />
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    placeholder="Search PPE…"
                                    defaultValue={f.search ?? ''}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter')
                                            go({
                                                search: (
                                                    e.target as HTMLInputElement
                                                ).value,
                                            });
                                    }}
                                    className="w-48 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                />
                            </div>
                            {hasFilters ? (
                                // eslint-disable-next-line no-restricted-syntax -- onDark clear affordance on the hero footer
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground"
                                >
                                    <X className="h-3 w-3" /> Clear
                                </button>
                            ) : null}
                        </div>
                    }
                >
                    <div
                        onContextMenu={heroCtx}
                        className="flex flex-col gap-5"
                    >
                        <PpeRibbon
                            current={stageForTab(tab)}
                            onStage={setTab}
                        />

                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-start gap-4">
                                <HeroMedallion icon={ShieldCheck} />
                                <div className="flex flex-col gap-1.5">
                                    <HeroStatusPill>
                                        PPE register · synced just now
                                    </HeroStatusPill>
                                    <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">
                                        PPE &amp; Equipment
                                    </h1>
                                    <p className="max-w-xl text-sm text-primary-foreground/70">
                                        Catalogue, issue, inspect and retire
                                        personal protective equipment —
                                        fit-tested, in-date and acknowledged
                                        across every site.
                                    </p>
                                </div>
                            </div>

                            {can.manage ? (
                                <Popover
                                    open={launcherOpen}
                                    onOpenChange={setLauncherOpen}
                                >
                                    <PopoverTrigger asChild>
                                        {/* eslint-disable-next-line no-restricted-syntax -- white on-dark hero affordance */}
                                        <button
                                            type="button"
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary-foreground px-3.5 py-2 text-[13px] font-semibold text-primary shadow-sm transition-colors hover:bg-primary-foreground/90"
                                        >
                                            <Plus className="h-4 w-4" /> Add to
                                            register
                                        </button>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        align="end"
                                        className="w-64 p-1.5"
                                    >
                                        {addMenu.map((m) => (
                                            // eslint-disable-next-line no-restricted-syntax -- popover menu item, custom icon+label layout
                                            <button
                                                key={m.title}
                                                type="button"
                                                onClick={m.onClick}
                                                className="flex w-full items-start gap-2.5 rounded-md p-2.5 text-left transition-colors hover:bg-accent"
                                            >
                                                <span className="mt-0.5 grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md bg-accent text-primary">
                                                    <m.icon className="h-4 w-4" />
                                                </span>
                                                <span>
                                                    <span className="block text-[13px] font-semibold">
                                                        {m.title}
                                                    </span>
                                                    <span className="block text-[11px] text-muted-foreground">
                                                        {m.blurb}
                                                    </span>
                                                </span>
                                            </button>
                                        ))}
                                    </PopoverContent>
                                </Popover>
                            ) : null}
                        </div>

                        <div className="grid gap-3 lg:grid-cols-2">
                            <HeroCluster
                                title="Live · register"
                                icon={Activity}
                            >
                                <HeroClusterTile
                                    href={`${PPE_URL}?tab=inv_all`}
                                    label="Total items"
                                    value={fmt(live.total)}
                                    caption="in register"
                                    tone="neutral"
                                />
                                <HeroClusterTile
                                    href={`${PPE_URL}?tab=inv_allocated`}
                                    label="Allocated"
                                    value={fmt(live.allocated)}
                                    caption="issued out"
                                    tone="neutral"
                                />
                                <HeroClusterTile
                                    href={`${PPE_URL}?tab=inv_available`}
                                    label="Available"
                                    value={fmt(live.available)}
                                    caption="ready to issue"
                                    tone="success"
                                />
                                <HeroClusterTile
                                    href={`${PPE_URL}?tab=inv_inspection`}
                                    label="Inspections due"
                                    value={fmt(live.inspections_due)}
                                    caption="next 30 days"
                                    tone="warning"
                                />
                            </HeroCluster>
                            <HeroCluster title="Needs attention" icon={Bell}>
                                <HeroClusterTile
                                    href={`${PPE_URL}?tab=inv_inspection`}
                                    label="Insp. overdue"
                                    value={fmt(att.inspection_overdue)}
                                    caption="past cadence"
                                    tone={
                                        att.inspection_overdue > 0
                                            ? 'critical'
                                            : 'success'
                                    }
                                />
                                <HeroClusterTile
                                    href={`${PPE_URL}?tab=inv_expiring`}
                                    label="Expiring"
                                    value={fmt(att.expiring)}
                                    caption="≤60 days / expired"
                                    tone={
                                        att.expiring > 0 ? 'warning' : 'success'
                                    }
                                />
                                <HeroClusterTile
                                    href={`${PPE_URL}?tab=inv_condemned`}
                                    label="Condemned"
                                    value={fmt(att.condemned)}
                                    caption="awaiting disposal"
                                    tone={
                                        att.condemned > 0
                                            ? 'critical'
                                            : 'success'
                                    }
                                />
                                <HeroClusterTile
                                    href={`${PPE_URL}?tab=alloc_unack`}
                                    label="Unacknowledged"
                                    value={fmt(att.unacknowledged)}
                                    caption="allocations"
                                    tone={
                                        att.unacknowledged > 0
                                            ? 'warning'
                                            : 'success'
                                    }
                                />
                            </HeroCluster>
                        </div>

                        <HeroComplianceBadges items={badges} />
                    </div>
                </HeroShell>

                <TabStrip
                    value={tab}
                    onChange={setTab}
                    items={TABS}
                    ariaLabel="PPE views"
                />

                <Card>
                    <CardContent className="p-0">
                        <RegisterTableHeader
                            icon={tableMeta.icon}
                            title={tableMeta.title}
                            subtitle={tableMeta.sub}
                            hint={tableMeta.hint}
                            hintIcon={MousePointer2}
                        />
                        <div className="overflow-x-auto">
                            {rowsKind === 'inventory' ? (
                                <InventoryTable
                                    rows={inventory.data}
                                    onOpen={openItem}
                                    onCtx={invCtx}
                                />
                            ) : null}
                            {rowsKind === 'allocations' ? (
                                <AllocationTable
                                    rows={allocations.data}
                                    onOpen={openAllocation}
                                    onCtx={allocCtx}
                                />
                            ) : null}
                            {rowsKind === 'types' ? (
                                <TypeTable
                                    rows={types}
                                    onEdit={(r) =>
                                        can.manage &&
                                        setModal({ kind: 'type', edit: r })
                                    }
                                    onCtx={typeCtx}
                                />
                            ) : null}
                        </div>
                    </CardContent>
                </Card>

                {rowsKind === 'inventory' && inventory.last_page > 1 ? (
                    <LaravelPagination links={inventory.links} />
                ) : null}
                {rowsKind === 'allocations' && allocations.last_page > 1 ? (
                    <LaravelPagination links={allocations.links} />
                ) : null}
            </div>

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}
            {detail ? (
                <PpeDetailDialog
                    detail={detail}
                    canManage={can.manage}
                    onClose={closeDetail}
                    onAction={onDetailAction}
                />
            ) : null}

            {modal?.kind === 'inventory' ? (
                <InventoryDialog
                    open
                    onClose={closeModal}
                    types={types}
                    sites={sites}
                    edit={modal.edit}
                    lockedTypeId={modal.lockedTypeId}
                />
            ) : null}
            {modal?.kind === 'allocate' ? (
                <AllocateDialog
                    open
                    onClose={closeModal}
                    staff={staff}
                    allocatable={allocatable}
                    lockedItem={modal.lockedItem}
                />
            ) : null}
            {modal?.kind === 'type' ? (
                <TypeDialog open onClose={closeModal} edit={modal.edit} />
            ) : null}
            {modal?.kind === 'return' ? (
                <ReturnDialog
                    open
                    onClose={closeModal}
                    allocationId={modal.allocationId}
                    worker={modal.worker}
                    itemLabel={modal.itemLabel}
                />
            ) : null}
            {modal?.kind === 'inspect' ? (
                <InspectionDialog
                    open
                    onClose={closeModal}
                    inventoryId={modal.inventoryId}
                    itemLabel={modal.itemLabel}
                />
            ) : null}
            {modal?.kind === 'condemn' ? (
                <CondemnDialog
                    open
                    onClose={closeModal}
                    inventoryId={modal.inventoryId}
                    itemLabel={modal.itemLabel}
                />
            ) : null}
            {modal?.kind === 'dispose' ? (
                <DisposeDialog
                    open
                    onClose={closeModal}
                    inventoryId={modal.inventoryId}
                    itemLabel={modal.itemLabel}
                />
            ) : null}
        </AppLayout>
    );
}
