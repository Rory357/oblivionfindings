/* eslint-disable no-restricted-syntax -- The Asset hub mirrors the Leave hub: a
 * brand-gradient hero + tab strip over four dense data surfaces (inventory table
 * with multi-select, assignment rollups, maintenance queue, document grid). Rows
 * are custom flex/table layouts with right-click context menus, not shadcn
 * <Card> cases. Colours stay token-based. */
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Bell,
    Box,
    Boxes,
    CheckCircle2,
    Copy,
    ExternalLink,
    FileText,
    MoreHorizontal,
    Pencil,
    Plus,
    QrCode,
    RotateCcw,
    Search,
    Trash2,
    Upload,
    UserCheck,
    Wrench,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import {
    categoryIcon,
    categoryLabel,
    fdate,
    FleetBadge,
    nzd,
    PersonAvatar,
    StatusPill,
    type AssetDocumentRow,
    type AssetHero,
    type AssignmentRow,
    type CategoryOption,
    type InventoryRow,
    type MaintenanceJob,
    type StaffOption,
} from '@/components/hr/asset-parts';
import {
    AssetWizard,
    type AssetModal,
    type EditableAsset,
} from '@/components/hr/asset-wizards';
import { AssetsHero, type AssetNeedChip } from '@/components/hr/assets-hero';
import {
    AssetsHubTabs,
    type AssetHubTab,
} from '@/components/hr/assets-hub-tabs';
import { useHrTab } from '@/components/hr/hr-tabs';
import {
    useRowContextMenu,
    type RowCtxItem,
} from '@/components/hr/row-context-menu';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';

interface ScheduleItem {
    asset_id: number;
    name: string;
    tag: string;
    label: string;
    date: string;
    tone: 'crit' | 'warn' | 'ok';
}

interface AttentionItem {
    tag: string | null;
    asset_id: number;
    text: string;
    who: string;
    tone: 'crit' | 'warn';
    target: AssetHubTab;
}

interface ActivityItem {
    icon: string;
    tone: string;
    text: string;
    at: string;
}

interface Props {
    hero: AssetHero;
    inventory: InventoryRow[];
    assignments: AssignmentRow[];
    maintenance: {
        jobs: MaintenanceJob[];
        schedule: ScheduleItem[];
        documents: AssetDocumentRow[];
    };
    overview: { attention: AttentionItem[]; activity: ActivityItem[] };
    staff: StaffOption[];
    categories: CategoryOption[];
    filters: { tab: string; seg: string; search: string | null };
    can: { manage: boolean; view_fleet: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Asset Management', href: '/hr/assets' },
];

const ACTIVITY_ICON: Record<string, typeof Box> = {
    assign: UserCheck,
    rotate: RotateCcw,
    wrench: Wrench,
    check: CheckCircle2,
    doc: FileText,
};

const TONE_COLOR: Record<string, string> = {
    primary: 'var(--primary)',
    info: 'var(--status-info)',
    warn: 'var(--status-warning)',
    success: 'var(--status-success)',
    fleet: 'var(--category-fleet)',
};

export default function AssetsIndex({
    hero,
    inventory,
    assignments,
    maintenance,
    overview,
    staff,
    categories,
    filters,
    can,
}: Props) {
    const [tab, setTab] = useHrTab(
        (['overview', 'inventory', 'assignments', 'maintenance'].includes(
            filters.tab,
        )
            ? filters.tab
            : 'overview') as AssetHubTab,
    ) as [AssetHubTab, (t: AssetHubTab) => void];

    const [modal, setModal] = useState<AssetModal | null>(null);
    const { open: openCtx, element: ctxElement } = useRowContextMenu();

    const closeModal = () => setModal(null);
    const openInventory = () => setTab('inventory');
    const goAsset = (id: number) => router.visit(`/hr/assets/${id}`);
    const copyTag = (tag: string) => {
        void navigator.clipboard?.writeText(tag);
        toast.success(`Copied ${tag}`);
    };
    const printLabel = (id: number) =>
        window.open(`/hr/assets/${id}/qr.svg`, '_blank');

    const editableFrom = (a: InventoryRow): EditableAsset => ({
        id: a.id,
        tag: a.tag,
        name: a.name,
        category: a.category,
        make: a.make,
        model: a.model,
        serial: a.serial,
        cost: a.cost,
        supplier: a.supplier,
        warranty: a.warranty,
        fleet_asset_id: a.fleet_asset_id,
    });

    /* Per-row right-click / ⋯ menu, status-aware (mirrors the prototype). */
    const rowMenu = (a: InventoryRow): RowCtxItem[] => {
        const items: RowCtxItem[] = [
            {
                kind: 'item',
                label: 'Open asset',
                icon: ExternalLink,
                onSelect: () => goAsset(a.id),
            },
            { kind: 'divider' },
        ];
        if (can.manage && a.status === 'available')
            items.push({
                kind: 'item',
                label: 'Assign…',
                icon: UserCheck,
                onSelect: () =>
                    setModal({
                        type: 'assign',
                        asset: { id: a.id, name: a.name, tag: a.tag },
                    }),
            });
        if (can.manage && a.status === 'assigned' && a.assignment_id)
            items.push({
                kind: 'item',
                label: 'Return…',
                icon: RotateCcw,
                onSelect: () =>
                    setModal({
                        type: 'return',
                        assignmentId: a.assignment_id!,
                        asset: {
                            id: a.id,
                            name: a.name,
                            tag: a.tag,
                            assignee: a.assignee,
                        },
                    }),
            });
        if (can.manage && a.status !== 'retired' && !a.fleet)
            items.push({
                kind: 'item',
                label: 'Log repair…',
                icon: Wrench,
                onSelect: () =>
                    setModal({
                        type: 'maintenance',
                        asset: { id: a.id, name: a.name, tag: a.tag },
                    }),
            });
        if (can.manage && a.status === 'maintenance')
            items.push({
                kind: 'item',
                label: 'Return to service…',
                icon: CheckCircle2,
                tone: 'success',
                onSelect: () =>
                    setModal({
                        type: 'rfs',
                        asset: { id: a.id, name: a.name, tag: a.tag },
                    }),
            });
        items.push({
            kind: 'item',
            label: 'Print QR label',
            icon: QrCode,
            onSelect: () => printLabel(a.id),
        });
        if (can.manage && !a.fleet) {
            items.push({ kind: 'divider' });
            items.push({
                kind: 'item',
                label: 'Edit asset…',
                icon: Pencil,
                onSelect: () =>
                    setModal({ type: 'new', asset: editableFrom(a) }),
            });
        }
        items.push({
            kind: 'item',
            label: 'Copy tag',
            icon: Copy,
            onSelect: () => copyTag(a.tag),
        });
        if (can.manage && a.status !== 'retired' && !a.fleet) {
            items.push({ kind: 'divider' });
            items.push({
                kind: 'item',
                label: 'Retire / dispose…',
                icon: Trash2,
                tone: 'critical',
                onSelect: () =>
                    setModal({
                        type: 'retire',
                        asset: { id: a.id, name: a.name, tag: a.tag },
                    }),
            });
        }
        return items;
    };

    const needs: AssetNeedChip[] = [];
    if (hero.warranties_30d > 0)
        needs.push({
            key: 'warr',
            label: `${hero.warranties_30d} warranties expiring ≤30d`,
            onClick: () => setTab('inventory'),
        });
    if (hero.overdue_returns > 0)
        needs.push({
            key: 'overdue',
            label: `${hero.overdue_returns} returns overdue`,
            onClick: () => setTab('assignments'),
        });
    if (hero.leaver_held > 0)
        needs.push({
            key: 'leaver',
            label: `${hero.leaver_held} assets to recover from leavers`,
            onClick: () => setTab('assignments'),
        });

    const stats = [
        {
            label: 'Total assets',
            value: hero.total,
            onClick: () => setTab('inventory'),
        },
        {
            label: 'Assigned',
            value: hero.assigned,
            onClick: () => setTab('assignments'),
        },
        {
            label: 'Available',
            value: hero.available,
            onClick: () => setTab('inventory'),
        },
        {
            label: 'In maintenance',
            value: hero.maintenance,
            amber: hero.maintenance > 0,
            onClick: () => setTab('maintenance'),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Asset Management" />

            <PageShell>
                <AssetsHero
                    hero={hero}
                    stats={stats}
                    needs={needs}
                    canManage={can.manage}
                    handlers={{
                        onNewAsset: () => setModal({ type: 'new' }),
                        onAssign: () => {
                            const avail = inventory.find(
                                (a) => a.status === 'available',
                            );
                            if (avail)
                                setModal({
                                    type: 'assign',
                                    asset: {
                                        id: avail.id,
                                        name: avail.name,
                                        tag: avail.tag,
                                    },
                                });
                            else toast.info('No available assets to assign.');
                        },
                        onOpenInventory: openInventory,
                        onExport: () => {
                            window.location.href = '/hr/assets/export';
                        },
                    }}
                />

                <div className="mt-5">
                    <AssetsHubTabs
                        active={tab}
                        onChange={setTab}
                        counts={{
                            inventory: hero.total,
                            overdue: hero.overdue_returns,
                            maintenance: hero.maintenance,
                        }}
                        onItemContextMenu={(id, e) =>
                            openCtx([
                                {
                                    kind: 'item',
                                    label: 'Open in this view',
                                    icon: ExternalLink,
                                    onSelect: () => setTab(id as AssetHubTab),
                                },
                                {
                                    kind: 'item',
                                    label: 'Refresh data',
                                    icon: RotateCcw,
                                    onSelect: () => router.reload(),
                                },
                            ])(e)
                        }
                    />
                </div>

                <div
                    key={tab}
                    className="motion-safe:animate-in motion-safe:duration-300 motion-safe:fade-in-0"
                >
                    {tab === 'overview' && (
                        <OverviewTab
                            hero={hero}
                            overview={overview}
                            onGoTab={setTab}
                            openCtx={openCtx}
                            goAsset={goAsset}
                        />
                    )}
                    {tab === 'inventory' && (
                        <InventoryTab
                            inventory={inventory}
                            can={can}
                            initialSearch={filters.search ?? ''}
                            initialSeg={
                                (filters.seg as 'hr' | 'fleet' | 'all') ?? 'hr'
                            }
                            categories={categories}
                            onNew={() => setModal({ type: 'new' })}
                            rowMenu={rowMenu}
                            openCtx={openCtx}
                            goAsset={goAsset}
                        />
                    )}
                    {tab === 'assignments' && (
                        <AssignmentsTab
                            assignments={assignments}
                            can={can}
                            openCtx={openCtx}
                            goAsset={goAsset}
                            onReturn={(a) =>
                                setModal({
                                    type: 'return',
                                    assignmentId: a.assignment_id,
                                    asset: {
                                        id: a.asset_id,
                                        name: a.name,
                                        tag: a.tag,
                                        assignee: a.assignee,
                                    },
                                })
                            }
                            onTransfer={(a) =>
                                setModal({
                                    type: 'assign',
                                    asset: {
                                        id: a.asset_id,
                                        name: a.name,
                                        tag: a.tag,
                                    },
                                })
                            }
                        />
                    )}
                    {tab === 'maintenance' && (
                        <MaintenanceTab
                            maintenance={maintenance}
                            can={can}
                            openCtx={openCtx}
                            goAsset={goAsset}
                            onReturnToService={(job) =>
                                setModal({
                                    type: 'rfs',
                                    asset: {
                                        id: job.asset_id,
                                        name: job.asset_name,
                                        tag: job.asset_tag,
                                    },
                                })
                            }
                            onEditJob={(job) =>
                                setModal({
                                    type: 'maintenance',
                                    asset: {
                                        id: job.asset_id,
                                        name: job.asset_name,
                                        tag: job.asset_tag,
                                    },
                                })
                            }
                        />
                    )}
                </div>
            </PageShell>

            <AssetWizard
                modal={modal}
                staff={staff}
                categories={categories}
                onClose={closeModal}
            />
            {ctxElement}
        </AppLayout>
    );
}

/* ================================================================== */
/*  Overview                                                          */
/* ================================================================== */

function Card({
    children,
    className,
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'rounded-2xl border border-border bg-card p-[18px] shadow-[0_1px_2px_rgba(0,0,0,0.04)]',
                className,
            )}
        >
            {children}
        </div>
    );
}

function SectionTitle({ title, sub }: { title: string; sub?: string }) {
    return (
        <div className="mb-3.5">
            <div className="text-[15px] font-bold tracking-tight">{title}</div>
            {sub ? (
                <div className="mt-0.5 text-[12.5px] text-muted-foreground">
                    {sub}
                </div>
            ) : null}
        </div>
    );
}

function OverviewTab({
    hero,
    overview,
    onGoTab,
    openCtx,
    goAsset,
}: {
    hero: AssetHero;
    overview: { attention: AttentionItem[]; activity: ActivityItem[] };
    onGoTab: (t: AssetHubTab) => void;
    openCtx: (items: RowCtxItem[]) => (e: React.MouseEvent) => void;
    goAsset: (id: number) => void;
}) {
    const kpis = [
        {
            label: 'Total assets',
            value: hero.total,
            sub: `across ${hero.site_count} sites`,
            icon: Boxes,
            tone: 'var(--status-info)',
            bg: 'var(--status-info-bg)',
        },
        {
            label: 'Assigned',
            value: hero.assigned,
            sub: 'in staff hands',
            icon: UserCheck,
            tone: 'var(--primary)',
            bg: 'color-mix(in oklch, var(--primary) 10%, transparent)',
        },
        {
            label: 'Available',
            value: hero.available,
            sub: 'ready to issue',
            icon: CheckCircle2,
            tone: 'var(--status-success)',
            bg: 'var(--status-success-bg)',
        },
        {
            label: 'In maintenance',
            value: hero.maintenance,
            sub: 'open jobs',
            icon: Wrench,
            tone: 'var(--status-warning)',
            bg: 'var(--status-warning-bg)',
        },
        {
            label: 'HR-owned value',
            value: nzd(hero.owned_value),
            sub: 'purchase cost',
            icon: FileText,
            tone: 'var(--category-fleet)',
            bg: 'color-mix(in oklch, var(--category-fleet) 12%, transparent)',
        },
        {
            label: 'Warranties ≤30d',
            value: hero.warranties_30d,
            sub: `${hero.warranties_90d} within 90 days`,
            icon: AlertTriangle,
            tone: 'var(--status-warning)',
            bg: 'var(--status-warning-bg)',
        },
    ];

    const catEntries = Object.entries(hero.category_mix).sort(
        (a, b) => b[1] - a[1],
    );
    const maxCat = Math.max(1, ...catEntries.map(([, n]) => n));

    return (
        <div className="flex flex-col gap-4">
            <div className="grid [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))] gap-3">
                {kpis.map((k) => {
                    const Icon = k.icon;
                    return (
                        <div
                            key={k.label}
                            className="rounded-[14px] border border-border bg-card px-4 py-[15px] shadow-[0_1px_2px_rgba(0,0,0,0.04)]"
                        >
                            <div className="flex items-center justify-between">
                                <span className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                    {k.label}
                                </span>
                                <span
                                    className="grid h-[30px] w-[30px] place-items-center rounded-[9px]"
                                    style={{ background: k.bg, color: k.tone }}
                                >
                                    <Icon className="h-4 w-4" />
                                </span>
                            </div>
                            <div className="mt-2 text-[26px] font-extrabold tracking-tight tabular-nums">
                                {k.value}
                            </div>
                            <div className="mt-px text-xs text-muted-foreground">
                                {k.sub}
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                <Card>
                    <SectionTitle title="Needs attention" />
                    {overview.attention.length === 0 ? (
                        <div className="py-10 text-center text-[13px] text-muted-foreground">
                            Nothing needs attention — every asset is accounted
                            for.
                        </div>
                    ) : (
                        <div className="flex flex-col">
                            {overview.attention.map((x, i) => (
                                <button
                                    key={`${x.asset_id}-${i}`}
                                    type="button"
                                    onClick={() => onGoTab(x.target)}
                                    onContextMenu={openCtx([
                                        {
                                            kind: 'item',
                                            label: 'Open asset',
                                            icon: ExternalLink,
                                            onSelect: () => goAsset(x.asset_id),
                                        },
                                        {
                                            kind: 'item',
                                            label: 'Go to view',
                                            icon: ArrowRight,
                                            onSelect: () => onGoTab(x.target),
                                        },
                                    ])}
                                    className={cn(
                                        'flex items-center gap-3 px-1 py-[11px] text-left transition-colors hover:bg-accent',
                                        i ? 'border-t border-border' : '',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'h-2 w-2 flex-none rounded-full',
                                            x.tone === 'crit'
                                                ? 'bg-status-critical'
                                                : 'bg-status-warning',
                                        )}
                                    />
                                    <span className="w-16 flex-none font-mono text-[11px] font-semibold text-muted-foreground">
                                        {x.tag}
                                    </span>
                                    <span className="min-w-0 flex-1 text-[13px] font-semibold">
                                        {x.text}
                                    </span>
                                    <span className="flex-none text-xs text-muted-foreground">
                                        {x.who}
                                    </span>
                                    <ArrowRight className="h-[15px] w-[15px] flex-none text-muted-foreground" />
                                </button>
                            ))}
                        </div>
                    )}
                </Card>

                <Card>
                    <SectionTitle title="By category" />
                    {catEntries.length === 0 ? (
                        <div className="py-6 text-center text-[13px] text-muted-foreground">
                            No assets yet.
                        </div>
                    ) : (
                        <div className="flex flex-col gap-2.5">
                            {catEntries.map(([k, n]) => (
                                <div
                                    key={k}
                                    className="flex items-center gap-2.5"
                                >
                                    <span className="w-[90px] text-[12.5px] font-semibold">
                                        {categoryLabel(k)}
                                    </span>
                                    <div className="h-2.5 flex-1 overflow-hidden rounded-md bg-muted">
                                        <div
                                            className="h-full rounded-md bg-primary"
                                            style={{
                                                width: `${(n / maxCat) * 100}%`,
                                            }}
                                        />
                                    </div>
                                    <span className="w-6 text-right text-[12.5px] font-bold tabular-nums">
                                        {n}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            </div>

            <Card>
                <SectionTitle title="Recent activity" />
                {overview.activity.length === 0 ? (
                    <div className="py-6 text-center text-[13px] text-muted-foreground">
                        No recent activity.
                    </div>
                ) : (
                    <div className="flex flex-col gap-0.5">
                        {overview.activity.map((x, i) => {
                            const Icon = ACTIVITY_ICON[x.icon] ?? Box;
                            return (
                                <div
                                    key={i}
                                    className="flex items-center gap-3 px-0.5 py-2"
                                >
                                    <span
                                        className="grid h-[30px] w-[30px] flex-none place-items-center rounded-[9px] bg-muted"
                                        style={{
                                            color:
                                                TONE_COLOR[x.tone] ??
                                                'var(--primary)',
                                        }}
                                    >
                                        <Icon className="h-[15px] w-[15px]" />
                                    </span>
                                    <span className="flex-1 text-[13px] font-medium">
                                        {x.text}
                                    </span>
                                    <span className="flex-none text-[11.5px] text-muted-foreground">
                                        {x.at}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                )}
            </Card>
        </div>
    );
}

/* ================================================================== */
/*  Inventory                                                         */
/* ================================================================== */

function InventoryTab({
    inventory,
    can,
    initialSearch,
    initialSeg,
    categories,
    onNew,
    rowMenu,
    openCtx,
    goAsset,
}: {
    inventory: InventoryRow[];
    can: { manage: boolean; view_fleet: boolean };
    initialSearch: string;
    initialSeg: 'hr' | 'fleet' | 'all';
    categories: CategoryOption[];
    onNew: () => void;
    rowMenu: (a: InventoryRow) => RowCtxItem[];
    openCtx: (items: RowCtxItem[]) => (e: React.MouseEvent) => void;
    goAsset: (id: number) => void;
}) {
    const [seg, setSeg] = useState<'hr' | 'fleet' | 'all'>(initialSeg);
    const [search, setSearch] = useState(initialSearch);
    const [selected, setSelected] = useState<number[]>([]);
    const [bulkCategory, setBulkCategory] = useState('');

    const rows = useMemo(() => {
        let r = inventory.slice();
        if (seg === 'hr') r = r.filter((a) => !a.fleet);
        else if (seg === 'fleet') r = r.filter((a) => a.fleet);
        const q = search.trim().toLowerCase();
        if (q)
            r = r.filter((a) =>
                `${a.tag} ${a.name} ${a.serial ?? ''} ${a.assignee ?? ''}`
                    .toLowerCase()
                    .includes(q),
            );
        return r;
    }, [inventory, seg, search]);

    const allChecked =
        rows.length > 0 && rows.every((r) => selected.includes(r.id));
    const toggle = (id: number) =>
        setSelected((s) =>
            s.includes(id) ? s.filter((x) => x !== id) : [...s, id],
        );
    const toggleAll = () =>
        setSelected(allChecked ? [] : rows.map((r) => r.id));
    const clearSel = () => setSelected([]);

    const runBulk = (action: string, extra: Record<string, unknown> = {}) =>
        router.post(
            '/hr/assets/bulk',
            { action, ids: selected, ...extra },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );

    const segs: Array<[typeof seg, string]> = [
        ['hr', 'HR equipment'],
        ['fleet', 'Fleet (held by staff)'],
        ['all', 'All'],
    ];

    return (
        <>
            <Card>
                <div className="mb-3.5 flex flex-wrap items-center gap-3">
                    <div className="relative min-w-[220px] flex-1 sm:max-w-[340px]">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search tag, serial, name, assignee…"
                            className="pl-8"
                        />
                    </div>
                    <div className="inline-flex gap-0.5 rounded-[10px] bg-muted p-[3px]">
                        {segs.map(([k, l]) => (
                            <button
                                key={k}
                                type="button"
                                onClick={() => {
                                    setSeg(k);
                                    clearSel();
                                }}
                                className={cn(
                                    'rounded-[7px] px-3 py-[7px] text-[12.5px] font-semibold transition-colors',
                                    seg === k
                                        ? 'bg-card text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {l}
                            </button>
                        ))}
                    </div>
                    {can.manage ? (
                        <Button className="ml-auto" onClick={onNew}>
                            <Plus className="h-4 w-4" /> New asset
                        </Button>
                    ) : null}
                </div>

                {rows.length === 0 ? (
                    <div className="px-5 py-14 text-center">
                        <div className="mx-auto mb-3.5 grid h-16 w-16 place-items-center rounded-[18px] bg-accent text-primary">
                            <Boxes className="h-8 w-8" />
                        </div>
                        <div className="text-base font-bold">
                            No assets here yet
                        </div>
                        <div className="mt-1 mb-4 text-[13px] text-muted-foreground">
                            {search
                                ? 'No assets match your search.'
                                : 'Add your first asset to start tracking equipment.'}
                        </div>
                        {can.manage && !search ? (
                            <Button onClick={onNew}>
                                <Boxes className="h-4 w-4" /> Add your first
                                asset
                            </Button>
                        ) : null}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[880px] border-collapse">
                            <thead>
                                <tr className="border-b border-border">
                                    <th className="w-[34px] px-3 pb-2.5">
                                        <CheckBox
                                            checked={allChecked}
                                            onChange={toggleAll}
                                        />
                                    </th>
                                    <Th>Asset</Th>
                                    <Th w="110px">Category</Th>
                                    <Th>Assignee</Th>
                                    <Th w="130px">Site</Th>
                                    <Th w="96px">Value</Th>
                                    <Th w="120px">Status</Th>
                                    <th className="w-10" />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((a, i) => {
                                    const checked = selected.includes(a.id);
                                    return (
                                        <tr
                                            key={a.id}
                                            onClick={() => goAsset(a.id)}
                                            onContextMenu={openCtx(rowMenu(a))}
                                            className={cn(
                                                'cursor-pointer transition-colors',
                                                i < rows.length - 1
                                                    ? 'border-b border-border'
                                                    : '',
                                                checked
                                                    ? 'bg-primary/[0.05]'
                                                    : 'hover:bg-accent',
                                            )}
                                        >
                                            <td
                                                className="px-3 py-3 align-middle"
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <CheckBox
                                                    checked={checked}
                                                    onChange={() =>
                                                        toggle(a.id)
                                                    }
                                                />
                                            </td>
                                            <td className="px-3 py-3">
                                                <div className="flex items-center gap-2.5">
                                                    <span className="grid h-[34px] w-[34px] flex-none place-items-center rounded-[9px] bg-muted text-muted-foreground">
                                                        {(() => {
                                                            const Ic =
                                                                categoryIcon(
                                                                    a.category,
                                                                );
                                                            return (
                                                                <Ic className="h-4 w-4" />
                                                            );
                                                        })()}
                                                    </span>
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-1.5">
                                                            <span className="text-[13.5px] font-bold">
                                                                {a.name}
                                                            </span>
                                                            {a.fleet ? (
                                                                <FleetBadge />
                                                            ) : null}
                                                        </div>
                                                        <div className="mt-px font-mono text-[11px] text-muted-foreground">
                                                            {a.tag}
                                                            {a.serial
                                                                ? ` · ${a.serial}`
                                                                : ''}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-3 py-3">
                                                <span className="inline-flex rounded-[7px] border border-border px-2.5 py-[3px] text-[11.5px] font-semibold text-muted-foreground">
                                                    {categoryLabel(a.category)}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3">
                                                {a.assignee ? (
                                                    <div className="flex items-center gap-2">
                                                        <PersonAvatar
                                                            name={a.assignee}
                                                            leaver={a.leaver}
                                                        />
                                                        <div className="min-w-0">
                                                            <div className="text-[12.5px] font-semibold">
                                                                {a.assignee}
                                                            </div>
                                                            <div
                                                                className={cn(
                                                                    'text-[11px]',
                                                                    a.overdue
                                                                        ? 'text-status-critical'
                                                                        : 'text-muted-foreground',
                                                                )}
                                                            >
                                                                {a.overdue
                                                                    ? `Overdue ${fdate(a.due_by)}`
                                                                    : a.leaver
                                                                      ? 'Leaver — recover'
                                                                      : a.role}
                                                            </div>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <span className="text-[12.5px] text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-3 text-[12.5px] text-muted-foreground">
                                                {a.site ?? '—'}
                                            </td>
                                            <td className="px-3 py-3 text-[12.5px] font-semibold tabular-nums">
                                                {nzd(a.cost)}
                                            </td>
                                            <td className="px-3 py-3">
                                                <StatusPill status={a.status} />
                                            </td>
                                            <td
                                                className="px-3 py-3 text-right"
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <button
                                                    type="button"
                                                    aria-label="Row actions"
                                                    onClick={openCtx(
                                                        rowMenu(a),
                                                    )}
                                                    className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent"
                                                >
                                                    <MoreHorizontal className="h-4 w-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            {can.manage && selected.length > 0 ? (
                <div className="sticky bottom-[18px] z-30 mx-auto mt-3.5 flex max-w-[760px] flex-wrap items-center gap-2.5 rounded-[14px] border border-border bg-popover py-2.5 pr-3 pl-4 shadow-2xl motion-safe:animate-in motion-safe:zoom-in-95">
                    <span className="grid h-[26px] min-w-[26px] place-items-center rounded-lg bg-primary px-1.5 text-[12.5px] font-extrabold text-primary-foreground">
                        {selected.length}
                    </span>
                    <span className="text-[13px] font-semibold">selected</span>
                    <span className="mx-1 h-5 w-px bg-border" />
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => runBulk('label')}
                    >
                        <QrCode className="h-4 w-4" /> Print QR labels
                    </Button>
                    <Select
                        value={bulkCategory}
                        onValueChange={(v) => {
                            setBulkCategory(v);
                            runBulk('set-category', { category: v });
                            setBulkCategory('');
                        }}
                    >
                        <SelectTrigger
                            className="h-8 w-[150px]"
                            aria-label="Set category"
                        >
                            <SelectValue placeholder="Set category" />
                        </SelectTrigger>
                        <SelectContent>
                            {categories
                                .filter((c) => !c.fleet)
                                .map((c) => (
                                    <SelectItem key={c.value} value={c.value}>
                                        {c.label}
                                    </SelectItem>
                                ))}
                        </SelectContent>
                    </Select>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-status-critical hover:bg-status-critical-bg"
                        onClick={() =>
                            runBulk('retire', {
                                disposal_reason: 'end-of-life',
                            })
                        }
                    >
                        <Trash2 className="h-4 w-4" /> Retire
                    </Button>
                    <button
                        type="button"
                        onClick={clearSel}
                        aria-label="Clear selection"
                        className="ml-auto grid h-[30px] w-[30px] place-items-center rounded-md bg-muted text-muted-foreground hover:bg-accent"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            ) : null}
        </>
    );
}

function Th({ children, w }: { children?: React.ReactNode; w?: string }) {
    return (
        <th
            className="px-3 pb-2.5 text-left text-[11px] font-bold tracking-wide text-muted-foreground uppercase"
            style={w ? { width: w } : undefined}
        >
            {children}
        </th>
    );
}

function CheckBox({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={checked}
            onClick={onChange}
            className={cn(
                'grid h-[18px] w-[18px] flex-none place-items-center rounded-[5px] border-[1.5px] transition-colors',
                checked
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'border-border bg-card',
            )}
        >
            {checked ? <CheckCircle2 className="h-3 w-3" /> : null}
        </button>
    );
}

/* ================================================================== */
/*  Assignments                                                       */
/* ================================================================== */

function AssignmentsTab({
    assignments,
    can,
    openCtx,
    goAsset,
    onReturn,
    onTransfer,
}: {
    assignments: AssignmentRow[];
    can: { manage: boolean; view_fleet: boolean };
    openCtx: (items: RowCtxItem[]) => (e: React.MouseEvent) => void;
    goAsset: (id: number) => void;
    onReturn: (a: AssignmentRow) => void;
    onTransfer: (a: AssignmentRow) => void;
}) {
    const [view, setView] = useState<'active' | 'rollup'>('active');

    const rollup = useMemo(() => {
        const by = new Map<
            string,
            { role: string | null; leaver: boolean; items: AssignmentRow[] }
        >();
        for (const a of assignments) {
            const key = a.assignee;
            if (!by.has(key))
                by.set(key, { role: a.role, leaver: a.leaver, items: [] });
            by.get(key)!.items.push(a);
        }
        return Array.from(by.entries());
    }, [assignments]);

    const menu = (a: AssignmentRow): RowCtxItem[] => {
        const items: RowCtxItem[] = [
            {
                kind: 'item',
                label: 'Open asset',
                icon: ExternalLink,
                onSelect: () => goAsset(a.asset_id),
            },
        ];
        if (can.manage) {
            items.push({
                kind: 'item',
                label: 'Return…',
                icon: RotateCcw,
                onSelect: () => onReturn(a),
            });
            items.push({
                kind: 'item',
                label: 'Transfer…',
                icon: ArrowRight,
                onSelect: () => onTransfer(a),
            });
            items.push({
                kind: 'item',
                label: 'Remind assignee',
                icon: Bell,
                onSelect: () => toast.success(`Reminder sent to ${a.assignee}`),
            });
        }
        return items;
    };

    return (
        <Card>
            <div className="mb-3.5 flex items-center justify-between">
                <div>
                    <div className="text-[15px] font-bold">Who has what</div>
                    <div className="mt-0.5 text-[12.5px] text-muted-foreground">
                        {assignments.length} assets currently issued to staff
                    </div>
                </div>
                <div className="inline-flex gap-0.5 rounded-[10px] bg-muted p-[3px]">
                    {(
                        [
                            ['active', 'Active'],
                            ['rollup', 'By employee'],
                        ] as const
                    ).map(([k, l]) => (
                        <button
                            key={k}
                            type="button"
                            onClick={() => setView(k)}
                            className={cn(
                                'rounded-[7px] px-3.5 py-[7px] text-[12.5px] font-semibold transition-colors',
                                view === k
                                    ? 'bg-card text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {l}
                        </button>
                    ))}
                </div>
            </div>

            {assignments.length === 0 ? (
                <div className="py-12 text-center text-[13px] text-muted-foreground">
                    No assets are currently assigned.
                </div>
            ) : view === 'active' ? (
                <div className="flex flex-col">
                    {assignments.map((a, i) => (
                        <div
                            key={a.assignment_id}
                            onContextMenu={openCtx(menu(a))}
                            className={cn(
                                'flex items-center gap-3.5 py-3',
                                i ? 'border-t border-border' : '',
                            )}
                        >
                            <PersonAvatar
                                name={a.assignee}
                                leaver={a.leaver}
                                size={38}
                            />
                            <div className="min-w-[160px]">
                                <div className="flex items-center gap-1.5 text-[13.5px] font-bold">
                                    {a.assignee}
                                    {a.leaver ? (
                                        <span className="rounded-[5px] bg-status-critical-bg px-1.5 py-px text-[9.5px] font-bold text-status-critical uppercase">
                                            Recover
                                        </span>
                                    ) : null}
                                </div>
                                <div className="text-[11.5px] text-muted-foreground">
                                    {a.role ?? '—'}
                                </div>
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-1.5 text-[13px] font-semibold">
                                    {a.name}
                                    {a.fleet ? <FleetBadge /> : null}
                                </div>
                                <div className="font-mono text-[11px] text-muted-foreground">
                                    {a.tag}
                                </div>
                            </div>
                            <div className="min-w-[130px] flex-none text-right">
                                <div className="text-xs text-muted-foreground">
                                    Since {fdate(a.since)}
                                </div>
                                <div
                                    className={cn(
                                        'text-xs font-semibold',
                                        a.overdue
                                            ? 'text-status-critical'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {a.due_by
                                        ? `${a.overdue ? 'Overdue' : 'Due'} ${fdate(a.due_by)}`
                                        : 'No return date'}
                                </div>
                            </div>
                            {can.manage ? (
                                <div className="flex flex-none gap-1.5">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => onReturn(a)}
                                    >
                                        Return
                                    </Button>
                                    <button
                                        type="button"
                                        aria-label="Row actions"
                                        onClick={openCtx(menu(a))}
                                        className="grid h-8 w-8 place-items-center rounded-md border border-border text-muted-foreground hover:bg-accent"
                                    >
                                        <MoreHorizontal className="h-4 w-4" />
                                    </button>
                                </div>
                            ) : null}
                        </div>
                    ))}
                </div>
            ) : (
                <div className="grid [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))] gap-3">
                    {rollup.map(([name, d]) => (
                        <div
                            key={name}
                            className="rounded-[13px] border border-border bg-card p-3.5"
                        >
                            <div className="mb-2.5 flex items-center gap-2.5">
                                <PersonAvatar
                                    name={name}
                                    leaver={d.leaver}
                                    size={36}
                                />
                                <div className="flex-1">
                                    <div className="text-[13.5px] font-bold">
                                        {name}
                                    </div>
                                    <div className="text-[11.5px] text-muted-foreground">
                                        {d.role ?? '—'}
                                    </div>
                                </div>
                                <span className="text-xs font-extrabold text-primary">
                                    {d.items.length} held
                                </span>
                            </div>
                            <div className="flex flex-col gap-1.5">
                                {d.items.map((it) => (
                                    <button
                                        key={it.assignment_id}
                                        type="button"
                                        onClick={() => goAsset(it.asset_id)}
                                        className="flex items-center gap-2 text-left text-[12.5px] hover:text-primary"
                                    >
                                        <span className="h-1.5 w-1.5 flex-none rounded-full bg-primary" />
                                        <span className="flex-1 truncate">
                                            {it.name}
                                        </span>
                                        <span className="font-mono text-[10.5px] text-muted-foreground">
                                            {it.tag}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </Card>
    );
}

/* ================================================================== */
/*  Maintenance & Docs                                                */
/* ================================================================== */

const DOC_TONE: Record<string, string> = {
    invoice: 'var(--category-fleet)',
    certificate: 'var(--status-success)',
    handover: 'var(--primary)',
    photo: 'var(--status-info)',
    manual: 'var(--status-warning)',
};

function MaintenanceTab({
    maintenance,
    can,
    openCtx,
    goAsset,
    onReturnToService,
    onEditJob,
}: {
    maintenance: {
        jobs: MaintenanceJob[];
        schedule: ScheduleItem[];
        documents: AssetDocumentRow[];
    };
    can: { manage: boolean; view_fleet: boolean };
    openCtx: (items: RowCtxItem[]) => (e: React.MouseEvent) => void;
    goAsset: (id: number) => void;
    onReturnToService: (job: MaintenanceJob) => void;
    onEditJob: (job: MaintenanceJob) => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                <Card>
                    <SectionTitle
                        title="Maintenance queue"
                        sub={`${maintenance.jobs.length} open jobs`}
                    />
                    {maintenance.jobs.length === 0 ? (
                        <div className="py-10 text-center text-[13px] text-muted-foreground">
                            No open repairs — everything is in service.
                        </div>
                    ) : (
                        <div className="flex flex-col">
                            {maintenance.jobs.map((job, i) => (
                                <div
                                    key={job.id}
                                    onContextMenu={openCtx([
                                        {
                                            kind: 'item',
                                            label: 'Return to service…',
                                            icon: CheckCircle2,
                                            tone: 'success',
                                            onSelect: () =>
                                                onReturnToService(job),
                                        },
                                        {
                                            kind: 'item',
                                            label: 'Edit job',
                                            icon: Pencil,
                                            onSelect: () => onEditJob(job),
                                        },
                                        {
                                            kind: 'item',
                                            label: 'Open asset',
                                            icon: ExternalLink,
                                            onSelect: () =>
                                                goAsset(job.asset_id),
                                        },
                                    ])}
                                    className={cn(
                                        'flex items-center gap-3.5 py-3',
                                        i ? 'border-t border-border' : '',
                                    )}
                                >
                                    <span className="grid h-9 w-9 flex-none place-items-center rounded-[10px] bg-status-warning-bg text-status-warning">
                                        <Wrench className="h-[17px] w-[17px]" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[13.5px] font-bold">
                                            {job.asset_name}
                                        </div>
                                        <div className="font-mono text-[11px] text-muted-foreground">
                                            {job.asset_tag}
                                        </div>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[13px] font-semibold">
                                            {job.vendor ?? '—'}
                                        </div>
                                        <div className="text-[11.5px] text-muted-foreground">
                                            Sent {fdate(job.sent_at)}
                                            {job.expected_back_at
                                                ? ` · expected ${fdate(job.expected_back_at)}`
                                                : ''}
                                        </div>
                                    </div>
                                    {can.manage ? (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                onReturnToService(job)
                                            }
                                            className="rounded-[9px] bg-status-success-bg px-3 py-2 text-[12.5px] font-bold text-status-success"
                                        >
                                            Return to service
                                        </button>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    )}
                </Card>

                <Card>
                    <SectionTitle title="Service schedule" sub="Next due" />
                    {maintenance.schedule.length === 0 ? (
                        <div className="py-6 text-center text-[13px] text-muted-foreground">
                            No upcoming service or warranty dates.
                        </div>
                    ) : (
                        <div className="flex flex-col gap-2.5">
                            {maintenance.schedule.map((s, i) => (
                                <button
                                    key={`${s.asset_id}-${i}`}
                                    type="button"
                                    onClick={() => goAsset(s.asset_id)}
                                    className="flex items-center gap-2.5 text-left"
                                >
                                    <span
                                        className={cn(
                                            'h-2 w-2 flex-none rounded-full',
                                            s.tone === 'crit'
                                                ? 'bg-status-critical'
                                                : s.tone === 'warn'
                                                  ? 'bg-status-warning'
                                                  : 'bg-status-success',
                                        )}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-[13px] font-semibold">
                                            {s.name}
                                        </div>
                                        <div className="text-[11.5px] text-muted-foreground">
                                            {s.label}
                                        </div>
                                    </div>
                                    <span className="font-mono text-[10.5px] text-muted-foreground">
                                        {s.tag}
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}
                </Card>
            </div>

            <Card>
                <div className="mb-3.5 flex items-center justify-between">
                    <div>
                        <div className="text-[15px] font-bold">
                            Documents library
                        </div>
                        <div className="mt-0.5 text-[12.5px] text-muted-foreground">
                            Warranties, receipts, photos &amp; signed handovers
                        </div>
                    </div>
                    {can.manage ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                toast.info(
                                    'Open an asset to upload its documents.',
                                )
                            }
                        >
                            <Upload className="h-4 w-4" /> Upload
                        </Button>
                    ) : null}
                </div>
                {maintenance.documents.length === 0 ? (
                    <div className="py-10 text-center text-[13px] text-muted-foreground">
                        No documents yet — add receipts and warranties from an
                        asset’s detail page.
                    </div>
                ) : (
                    <div className="grid [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))] gap-2.5">
                        {maintenance.documents.map((d) => (
                            <a
                                key={d.id}
                                href={`/hr/assets/documents/${d.id}/download`}
                                className="flex items-center gap-2.5 rounded-[11px] border border-border bg-card px-3 py-2.5 transition-colors hover:border-primary"
                            >
                                <span
                                    className="grid h-9 w-9 flex-none place-items-center rounded-[9px]"
                                    style={{
                                        color:
                                            DOC_TONE[d.category] ??
                                            'var(--status-warning)',
                                        background: `color-mix(in oklch, ${DOC_TONE[d.category] ?? 'var(--status-warning)'} 14%, transparent)`,
                                    }}
                                >
                                    <FileText className="h-[17px] w-[17px]" />
                                </span>
                                <div className="min-w-0">
                                    <div className="truncate text-[12.5px] font-semibold">
                                        {d.title}
                                    </div>
                                    <div className="mt-px text-[11px] text-muted-foreground">
                                        {[d.asset_tag, fdate(d.created_at)]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </div>
                                </div>
                            </a>
                        ))}
                    </div>
                )}
            </Card>
        </div>
    );
}
