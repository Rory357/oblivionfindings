/* eslint-disable no-restricted-syntax -- The Sites index is a pixel-faithful bespoke design surface:
 * on-gradient filter pills, a segmented Cards/Table toggle, a wrapping saved-view tab strip, a
 * cursor-anchored context menu, a floating bulk-action bar and a custom empty state. These controls
 * intentionally don't map onto the shared <Button>/<Card> primitives, and the hero actions in
 * particular must stay raw to bypass PageHeroActions' `[data-slot=button]` colour overrides. They are
 * therefore built as styled native elements rather than design-system components. */
import type { PageHeroStat } from '@/components/page';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    Archive,
    ArchiveRestore,
    ArrowRight,
    BedDouble,
    Building2,
    Calendar,
    Check,
    CheckCircle2,
    ChevronDown,
    ClipboardCheck,
    Eye,
    Home,
    LayoutGrid,
    List,
    Map as MapIcon,
    MapPin,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    ShieldAlert,
    TrendingUp,
    Users,
    Warehouse,
    X,
} from 'lucide-react';
import {
    type ComponentType,
    type ReactNode,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

import {
    AddSiteDialog,
    type AddSiteReferenceData,
} from '@/components/sites/add-site-dialog';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { residentHue } from '@/pages/my-day/lib/resident-hue';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type SiteType = 'head_office' | 'house' | 'facility' | 'residential';

type Site = {
    id: number;
    name: string;
    type: SiteType;
    region?: string | null;
    address_line_1?: string | null;
    address_line_2?: string | null;
    suburb?: string | null;
    city?: string | null;
    postcode?: string | null;
    country?: string | null;
    is_active: boolean;
    archived: boolean;
    is_high_risk: boolean;
    is_high_needs: boolean;
    primary_contact?: { id: number | null; name: string } | null;
    active_clients_count?: number;
    rooms_total?: number;
    rooms_occupied?: number;
    vacancies?: number;
    open_hazards_count?: number;
    overdue_checklists_count?: number;
    open_maintenance_count?: number;
    readiness?: {
        score: number;
        critical_done: number;
        critical_total: number;
        is_active_but_incomplete: boolean;
    };
    geofence_status?: 'active' | 'inactive' | 'missing' | 'na';
};

type Filters = {
    q?: string | null;
    type?: string | null;
    status?: string | null;
    region?: string | null;
    risk?: string | null;
    manager_id?: string | null;
    audit?: string | null;
    hazards?: string | null;
    maintenance?: string | null;
    readiness?: string | null;
    service?: string | null;
    show_archived?: boolean;
    archived?: boolean;
};

type Summary = {
    total: number;
    active: number;
    inactive: number;
    incomplete: number;
    hazards: number;
    overdue: number;
    regions: number;
    beds_total: number;
    beds_occupied: number;
    occupancy_percent: number;
    clients: number;
    archived: number;
};

type SavedViewCounts = {
    at_risk: number;
    audit_overdue: number;
    open_hazards: number;
    open_maintenance: number;
    active_incomplete: number;
    respite: number;
    inactive: number;
    archived: number;
};

type Can = {
    sites?: { create?: boolean; update?: boolean; archive?: boolean };
    calendar?: { create?: boolean };
    hazards?: { create?: boolean };
    checklists?: { run?: boolean };
};

type PageProps = {
    sites: Site[];
    filters: Filters;
    summary: Summary;
    filterOptions: {
        regions: string[];
        managers: { id: number; name: string }[];
        types: { value: string; label: string }[];
        risks: { value: string; label: string }[];
    };
    savedViewCounts: SavedViewCounts;
    addSite: AddSiteReferenceData;
    auth: { user?: { name?: string } | null; can?: Can };
    labels?: Record<string, string>;
};

type IconType = ComponentType<{ className?: string }>;

type MenuItem = {
    separator?: boolean;
    label?: string;
    icon?: IconType;
    onClick?: () => void;
    danger?: boolean;
};

type ViewKey =
    | 'all'
    | 'at_risk'
    | 'audit_overdue'
    | 'open_hazards'
    | 'active_incomplete'
    | 'inactive'
    | 'archived';

/* ------------------------------------------------------------------ */
/*  Static maps + helpers                                              */
/* ------------------------------------------------------------------ */

const typeIcons: Record<string, IconType> = {
    head_office: Building2,
    house: Home,
    facility: Warehouse,
    residential: Home,
};

const typeLabels: Record<string, string> = {
    head_office: 'Head Office',
    house: 'House',
    facility: 'Facility',
    residential: 'Residential',
};

function addressFor(site: Site): string {
    return [site.address_line_1, site.suburb, site.city, site.postcode]
        .filter((v): v is string => typeof v === 'string' && v.trim() !== '')
        .join(', ');
}

function hazardsOf(s: Site): number {
    return s.open_hazards_count ?? 0;
}

function overdueOf(s: Site): number {
    return (s.overdue_checklists_count ?? 0) + (s.open_maintenance_count ?? 0);
}

/** Top accent-bar colour by health, mapped to a background utility. */
function accentClass(s: Site): string {
    if (s.archived || !s.is_active) return 'bg-muted-foreground';
    const ready = s.readiness?.score ?? 100;
    if (hazardsOf(s) >= 3 || ready < 60 || s.is_high_risk)
        return 'bg-status-critical';
    if (overdueOf(s) > 0 || ready < 80 || s.is_high_needs)
        return 'bg-status-warning';
    return 'bg-primary';
}

/** Readiness ring/number colour as a CSS var so it re-tints with the theme. */
function readinessVar(score: number): string {
    if (score >= 90) return 'var(--status-success)';
    if (score >= 70) return 'var(--status-warning)';
    return 'var(--status-critical)';
}

function initialsFromName(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/** Drop empty/default keys; coerce booleans to 1 for the query string. */
function cleanFilters(
    f: Record<string, unknown>,
): Record<string, string | number> {
    const out: Record<string, string | number> = {};
    for (const [k, v] of Object.entries(f)) {
        if (v === null || v === undefined || v === '' || v === false) continue;
        if ((k === 'type' || k === 'region' || k === 'risk') && v === 'all')
            continue;
        if (k === 'status' && v === 'active') continue;
        out[k] = v === true ? 1 : (v as string | number);
    }
    return out;
}

/** Remove falsy entries and collapse / trim separators. */
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

/* ------------------------------------------------------------------ */
/*  Small presentational pieces                                        */
/* ------------------------------------------------------------------ */

function RiskTag({ s }: { s: Site }) {
    if (s.is_high_risk) {
        return (
            <span className="inline-flex h-[23px] items-center gap-1.5 rounded-full bg-status-critical-bg px-2.5 text-[11.5px] font-semibold text-status-critical">
                <AlertTriangle className="h-3 w-3" />
                High risk
            </span>
        );
    }
    if (s.is_high_needs) {
        return (
            <span className="inline-flex h-[23px] items-center gap-1.5 rounded-full bg-status-warning-bg px-2.5 text-[11.5px] font-semibold text-status-warning">
                <AlertCircle className="h-3 w-3" />
                High needs
            </span>
        );
    }
    return null;
}

function GeoDot({ status }: { status?: Site['geofence_status'] }) {
    if (status === 'active') {
        return (
            <span
                title="Geofence active"
                className="h-2 w-2 shrink-0 rounded-full bg-status-success"
            />
        );
    }
    if (status === 'inactive') {
        return (
            <span
                title="Geofence disabled"
                className="h-2 w-2 shrink-0 rounded-full bg-muted-foreground"
            />
        );
    }
    if (status === 'missing') {
        return (
            <span
                title="Geofence missing — needed for resident tracking"
                className="h-2 w-2 shrink-0 animate-pulse rounded-full bg-status-warning"
            />
        );
    }
    return null;
}

function LeadAvatar({
    name,
    size = 26,
}: {
    name?: string | null;
    size?: number;
}) {
    if (!name) {
        return (
            <span
                style={{ width: size, height: size }}
                className="flex shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
            >
                <Building2 className="h-3.5 w-3.5" />
            </span>
        );
    }
    return (
        <span
            style={{
                width: size,
                height: size,
                fontSize: size * 0.4,
                background: `oklch(0.62 0.14 ${residentHue(name)})`,
            }}
            className="flex shrink-0 items-center justify-center rounded-full font-semibold text-white"
        >
            {initialsFromName(name)}
        </span>
    );
}

function Occupancy({ s }: { s: Site }) {
    const total = s.rooms_total ?? 0;

    if (s.type === 'head_office' || total === 0) {
        const label =
            s.type === 'head_office'
                ? 'Administrative site'
                : 'No beds configured';
        return (
            <div className="min-w-0">
                <div className="mb-1.5 flex items-baseline justify-between gap-2">
                    <span className="inline-flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground">
                        <Building2 className="h-3 w-3" />
                        {label}
                    </span>
                    <span className="text-xs text-muted-foreground">—</span>
                </div>
                <div className="h-[7px] overflow-hidden rounded-full bg-muted">
                    <div className="h-full w-full rounded-full bg-muted-foreground/25" />
                </div>
            </div>
        );
    }

    const occ = s.rooms_occupied ?? 0;
    const pct = Math.round((occ / total) * 100);
    const vac = Math.max(0, total - occ);
    const full = vac === 0;

    return (
        <div className="min-w-0">
            <div className="mb-1.5 flex items-baseline justify-between gap-2">
                <span className="inline-flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground">
                    <BedDouble className="h-3 w-3" />
                    Occupancy
                </span>
                <span className="text-xs font-semibold tabular-nums">
                    {occ}/{total} beds{' '}
                    {full ? (
                        <span className="text-status-warning">· full</span>
                    ) : (
                        <span className="text-status-success">
                            · {vac} vacant
                        </span>
                    )}
                </span>
            </div>
            <div className="h-[7px] overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full',
                        full ? 'bg-status-warning' : 'bg-primary',
                    )}
                    style={{ width: `${Math.max(pct, 3)}%` }}
                />
            </div>
        </div>
    );
}

function ReadinessRing({ score }: { score: number }) {
    const color = readinessVar(score);
    return (
        <div className="flex flex-col items-center gap-1">
            <div
                className="grid h-[50px] w-[50px] place-items-center rounded-full"
                style={{
                    background: `radial-gradient(closest-side, var(--card) 70%, transparent 71% 100%), conic-gradient(${color} ${score}%, var(--muted) 0)`,
                }}
            >
                <span
                    className="text-[13px] font-bold tabular-nums"
                    style={{ color }}
                >
                    {score}
                </span>
            </div>
            <span className="text-[9.5px] font-semibold tracking-wide text-muted-foreground uppercase">
                Ready
            </span>
        </div>
    );
}

function AlertPills({ s }: { s: Site }) {
    const hazards = hazardsOf(s);
    const overdue = overdueOf(s);
    const pills: ReactNode[] = [];

    if (hazards > 0) {
        pills.push(
            <span
                key="h"
                className="inline-flex items-center gap-1.5 rounded-md bg-status-critical-bg px-2 py-0.5 text-[11.5px] font-semibold text-status-critical"
            >
                <ShieldAlert className="h-3 w-3" />
                {`${hazards} ${hazards === 1 ? 'hazard' : 'hazards'}`}
            </span>,
        );
    }
    if (overdue > 0) {
        pills.push(
            <span
                key="o"
                className="inline-flex items-center gap-1.5 rounded-md bg-status-warning-bg px-2 py-0.5 text-[11.5px] font-semibold text-status-warning"
            >
                <ClipboardCheck className="h-3 w-3" />
                {`${overdue} overdue`}
            </span>,
        );
    }
    if (pills.length === 0) {
        pills.push(
            <span
                key="ok"
                className="inline-flex items-center gap-1.5 rounded-md bg-status-success-bg px-2 py-0.5 text-[11.5px] font-semibold text-status-success"
            >
                <CheckCircle2 className="h-3 w-3" />
                All clear
            </span>,
        );
    }
    return <div className="flex flex-wrap gap-1.5">{pills}</div>;
}

function SiteKebab({ actions }: { actions: MenuItem[] }) {
    return (
        <div onClick={(e) => e.stopPropagation()}>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label="More actions"
                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <MoreVertical className="h-4 w-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
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

/* ------------------------------------------------------------------ */
/*  Site card                                                          */
/* ------------------------------------------------------------------ */

function SiteCard({
    s,
    selectMode,
    selected,
    actions,
    onOpen,
    onToggle,
    onContext,
}: {
    s: Site;
    selectMode: boolean;
    selected: boolean;
    actions: MenuItem[];
    onOpen: (s: Site) => void;
    onToggle: (id: number) => void;
    onContext: (e: React.MouseEvent, s: Site) => void;
}) {
    const TypeIcon = typeIcons[s.type] ?? Building2;
    const ready = s.readiness?.score ?? null;
    const handleActivate = () => (selectMode ? onToggle(s.id) : onOpen(s));

    return (
        <Card
            role="button"
            tabIndex={0}
            onClick={handleActivate}
            onContextMenu={(e) => onContext(e, s)}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    handleActivate();
                }
            }}
            className={cn(
                'group relative flex cursor-pointer flex-col gap-0 overflow-hidden rounded-[15px] py-0 shadow-sm transition-all duration-150 hover:-translate-y-0.5 hover:border-primary hover:shadow-lg',
                selected && 'border-primary ring-2 ring-primary/45',
                (!s.is_active || s.archived) && 'opacity-75',
            )}
        >
            <div className={cn('h-1 w-full', accentClass(s))} />

            <div className="flex flex-1 flex-col gap-3 px-4 pt-3.5 pb-3.5">
                {/* header */}
                <div className="flex items-start gap-3">
                    {selectMode ? (
                        <span
                            className={cn(
                                'mt-0.5 flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-[7px] border transition-colors',
                                selected
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-input bg-card text-transparent',
                            )}
                        >
                            <Check className="h-3.5 w-3.5" />
                        </span>
                    ) : (
                        <span className="flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-[11px] bg-primary/15 text-primary">
                            <TypeIcon className="h-5 w-5" />
                        </span>
                    )}
                    <div className="min-w-0 flex-1">
                        <h3 className="truncate text-[15.5px] leading-tight font-[650] tracking-tight">
                            {s.name}
                        </h3>
                        <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                            <MapPin className="h-3 w-3 shrink-0" />
                            <span className="truncate">
                                {addressFor(s) || 'No address'}
                            </span>
                        </div>
                    </div>
                    {!selectMode ? (
                        <div className="-mt-0.5 -mr-1">
                            <SiteKebab actions={actions} />
                        </div>
                    ) : null}
                </div>

                {/* tags */}
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="inline-flex h-[23px] items-center gap-1.5 rounded-full bg-muted px-2.5 text-[11.5px] font-semibold text-muted-foreground">
                        <TypeIcon className="h-3 w-3" />
                        {typeLabels[s.type] ?? s.type}
                    </span>
                    {s.region ? (
                        <span className="inline-flex h-[23px] items-center rounded-full border border-border px-2.5 text-[11.5px] font-semibold text-muted-foreground">
                            {s.region}
                        </span>
                    ) : null}
                    <RiskTag s={s} />
                    {!s.is_active ? (
                        <span className="inline-flex h-[23px] items-center rounded-full bg-muted px-2.5 text-[11.5px] font-semibold text-muted-foreground">
                            {s.archived ? 'Archived' : 'Inactive'}
                        </span>
                    ) : null}
                </div>

                {/* metrics */}
                <div className="grid grid-cols-[1fr_auto] items-center gap-3">
                    <Occupancy s={s} />
                    {ready != null ? <ReadinessRing score={ready} /> : null}
                </div>

                <AlertPills s={s} />
            </div>

            {/* footer */}
            <div className="flex items-center gap-2.5 border-t border-border bg-muted/40 px-4 py-2.5">
                <LeadAvatar name={s.primary_contact?.name} />
                <div className="min-w-0 flex-1 text-xs">
                    <div className="truncate font-semibold">
                        {s.primary_contact?.name ?? 'No site lead'}
                    </div>
                    <div className="truncate text-[11px] text-muted-foreground">
                        Site lead
                        {(s.active_clients_count ?? 0) > 0
                            ? ` · ${s.active_clients_count} ${s.active_clients_count === 1 ? 'client' : 'clients'}`
                            : ''}
                    </div>
                </div>
                {/* "Open" reveals on hover to the LEFT of the dot. Using
                 * hidden → inline-flex (not opacity) means it reserves no space
                 * when hidden, so the geofence dot stays tucked flush in the
                 * corner instead of floating with a phantom gap to its right. */}
                {!selectMode ? (
                    <span className="hidden items-center gap-1 text-xs font-semibold text-primary group-hover:inline-flex">
                        Open
                        <ArrowRight className="h-3.5 w-3.5" />
                    </span>
                ) : null}
                <GeoDot status={s.geofence_status} />
            </div>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Table row                                                          */
/* ------------------------------------------------------------------ */

function SiteRow({
    s,
    selectMode,
    selected,
    actions,
    onOpen,
    onToggle,
    onContext,
}: {
    s: Site;
    selectMode: boolean;
    selected: boolean;
    actions: MenuItem[];
    onOpen: (s: Site) => void;
    onToggle: (id: number) => void;
    onContext: (e: React.MouseEvent, s: Site) => void;
}) {
    const TypeIcon = typeIcons[s.type] ?? Building2;
    const total = s.rooms_total ?? 0;
    const occ = s.rooms_occupied ?? 0;
    const vac = Math.max(0, total - occ);
    const hazards = hazardsOf(s);
    const overdue = overdueOf(s);
    const ready = s.readiness?.score ?? null;

    return (
        <tr
            onClick={() => (selectMode ? onToggle(s.id) : onOpen(s))}
            onContextMenu={(e) => onContext(e, s)}
            className={cn(
                'cursor-pointer border-b border-border transition-colors last:border-0',
                selected ? 'bg-primary/[0.11]' : 'hover:bg-primary/[0.06]',
                (!s.is_active || s.archived) && 'opacity-75',
            )}
        >
            <td className="px-3.5 py-2.5">
                {selectMode ? (
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
                ) : (
                    <span className="flex h-8 w-8 items-center justify-center rounded-[9px] bg-primary/15 text-primary">
                        <TypeIcon className="h-4 w-4" />
                    </span>
                )}
            </td>
            <td className="px-3.5 py-2.5">
                <div className="font-semibold text-foreground">{s.name}</div>
                <div className="flex items-center gap-1 text-[11.5px] text-muted-foreground">
                    <MapPin className="h-3 w-3" />
                    {s.region || '—'}
                </div>
            </td>
            <td className="px-3.5 py-2.5">
                <span className="inline-flex h-[23px] items-center gap-1.5 rounded-full bg-muted px-2.5 text-[11.5px] font-semibold text-muted-foreground">
                    <TypeIcon className="h-3 w-3" />
                    {typeLabels[s.type] ?? s.type}
                </span>
            </td>
            <td className="px-3.5 py-2.5 text-muted-foreground tabular-nums">
                {s.type === 'head_office' || total === 0 ? (
                    '—'
                ) : (
                    <>
                        {occ}/{total}{' '}
                        <span
                            className={
                                vac > 0
                                    ? 'text-status-success'
                                    : 'text-status-warning'
                            }
                        >
                            · {vac} vac
                        </span>
                    </>
                )}
            </td>
            <td className="px-3.5 py-2.5 text-muted-foreground tabular-nums">
                {(s.active_clients_count ?? 0) || '—'}
            </td>
            <td className="px-3.5 py-2.5">
                <div className="flex items-center gap-2">
                    <LeadAvatar name={s.primary_contact?.name} size={24} />
                    <span className="text-[12.5px]">
                        {s.primary_contact?.name ?? '—'}
                    </span>
                </div>
            </td>
            <td className="px-3.5 py-2.5">
                {hazards > 0 ? (
                    <span className="inline-flex items-center rounded-md bg-status-critical-bg px-2 py-0.5 text-[11.5px] font-semibold text-status-critical">
                        {hazards}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </td>
            <td className="px-3.5 py-2.5">
                {overdue > 0 ? (
                    <span className="inline-flex items-center rounded-md bg-status-warning-bg px-2 py-0.5 text-[11.5px] font-semibold text-status-warning">
                        {overdue}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </td>
            <td className="px-3.5 py-2.5">
                {ready != null ? (
                    <div className="flex items-center gap-1.5">
                        <span
                            className="font-bold tabular-nums"
                            style={{ color: readinessVar(ready) }}
                        >
                            {ready}%
                        </span>
                        <GeoDot status={s.geofence_status} />
                    </div>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </td>
            <td className="px-3.5 py-2.5">
                {s.is_high_risk || s.is_high_needs ? (
                    <RiskTag s={s} />
                ) : (
                    <span className="text-[12px] text-muted-foreground">
                        Standard
                    </span>
                )}
            </td>
            <td className="px-3.5 py-2.5">
                <span
                    className={cn(
                        'inline-flex h-[23px] items-center rounded-full px-2.5 text-[11.5px] font-semibold',
                        s.is_active
                            ? 'bg-status-success-bg text-status-success'
                            : 'bg-muted text-muted-foreground',
                    )}
                >
                    {s.archived
                        ? 'Archived'
                        : s.is_active
                          ? 'Active'
                          : 'Inactive'}
                </span>
            </td>
            <td
                className="px-3.5 py-2.5 text-right"
                onClick={(e) => e.stopPropagation()}
            >
                {!selectMode ? <SiteKebab actions={actions} /> : null}
            </td>
        </tr>
    );
}

/* ------------------------------------------------------------------ */
/*  Hero filter pill (dark / on-gradient)                              */
/* ------------------------------------------------------------------ */

function HeroPill({
    icon: Icon,
    label,
    value,
    allValue = 'all',
    options,
    onChange,
}: {
    icon?: IconType;
    label: string;
    value: string;
    allValue?: string;
    options: { value: string; label: string }[];
    onChange: (v: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const current = options.find((o) => o.value === value);
    const active = value !== allValue;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            {/* The pill chrome lives on a non-interactive wrapper so the clear
             * "✕" can be a real sibling <button> rather than an interactive
             * element nested inside the trigger <button> (invalid + awkward for
             * screen readers). Paddings mirror the old single-button spacing. */}
            <div
                className={cn(
                    'inline-flex items-center rounded-full border text-xs font-semibold transition-colors',
                    active
                        ? 'border-primary-foreground bg-primary-foreground text-primary'
                        : 'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
                )}
            >
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full py-1.5 pl-3',
                            active ? 'pr-2' : 'pr-3',
                        )}
                    >
                        {Icon ? <Icon className="h-3.5 w-3.5" /> : null}
                        <span className="max-w-[160px] truncate">
                            {active ? (current?.label ?? label) : label}
                        </span>
                        {!active ? (
                            <ChevronDown className="h-3 w-3 opacity-70" />
                        ) : null}
                    </button>
                </PopoverTrigger>
                {active ? (
                    <button
                        type="button"
                        aria-label="Clear"
                        onClick={() => onChange(allValue)}
                        className="mr-3 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full hover:bg-primary/20"
                    >
                        <X className="h-3 w-3" />
                    </button>
                ) : null}
            </div>
            <PopoverContent align="end" className="w-52 p-1">
                {options.map((o) => (
                    <button
                        key={o.value}
                        type="button"
                        onClick={() => {
                            onChange(o.value);
                            setOpen(false);
                        }}
                        className={cn(
                            'flex w-full items-center justify-between gap-2 rounded-md px-2.5 py-2 text-sm text-foreground hover:bg-muted',
                            o.value === value && 'font-semibold text-primary',
                        )}
                    >
                        {o.label}
                        {o.value === value ? (
                            <Check className="h-4 w-4" />
                        ) : null}
                    </button>
                ))}
            </PopoverContent>
        </Popover>
    );
}

/* ------------------------------------------------------------------ */
/*  Saved-view tab strip (wraps — no horizontal scroll)                */
/* ------------------------------------------------------------------ */

const TAB_BASE =
    'inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground';

function ViewTabs({
    items,
    value,
    onSelect,
}: {
    items: {
        key: ViewKey;
        label: string;
        icon: IconType;
        count: number;
        alert?: boolean;
    }[];
    value: ViewKey;
    onSelect: (key: ViewKey) => void;
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
                                    : it.alert
                                      ? 'bg-status-critical-bg text-status-critical'
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
/*  Bulk action bar + right-click context menu                         */
/* ------------------------------------------------------------------ */

function BulkBar({
    count,
    canArchive,
    onExport,
    onArchive,
    onClear,
}: {
    count: number;
    canArchive: boolean;
    onExport: () => void;
    onArchive: () => void;
    onClear: () => void;
}) {
    return (
        <div className="fixed bottom-5 left-1/2 z-40 flex max-w-[calc(100vw-40px)] -translate-x-1/2 animate-in items-center gap-1.5 rounded-2xl border border-border bg-popover py-2 pr-2.5 pl-4 shadow-xl duration-200 fade-in slide-in-from-bottom-2">
            <span className="inline-flex items-center gap-2 text-sm font-semibold">
                <span className="rounded-full bg-primary px-2 py-0.5 text-xs text-primary-foreground tabular-nums">
                    {count}
                </span>
                {count === 1 ? 'site' : 'sites'} selected
            </span>
            <span className="mx-1 h-6 w-px bg-border" />
            <button
                type="button"
                onClick={onExport}
                className="inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-[12.5px] font-semibold text-foreground hover:bg-muted"
            >
                <TrendingUp className="h-4 w-4" />
                Export
            </button>
            {canArchive ? (
                <button
                    type="button"
                    onClick={onArchive}
                    className="inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-[12.5px] font-semibold text-foreground hover:bg-muted"
                >
                    <Archive className="h-4 w-4" />
                    Archive
                </button>
            ) : null}
            <span className="mx-1 h-6 w-px bg-border" />
            <button
                type="button"
                onClick={onClear}
                className="inline-flex h-8 items-center rounded-lg px-2.5 text-[12.5px] font-semibold text-muted-foreground hover:bg-muted"
            >
                Clear
            </button>
        </div>
    );
}

function SiteContextMenu({
    x,
    y,
    site,
    items,
    onClose,
}: {
    x: number;
    y: number;
    site: Site;
    items: MenuItem[];
    onClose: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({ x, y });

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

    const TypeIcon = typeIcons[site.type] ?? Building2;

    return (
        <div
            ref={ref}
            style={{ left: pos.x, top: pos.y }}
            onMouseDown={(e) => e.stopPropagation()}
            onContextMenu={(e) => e.preventDefault()}
            className="fixed z-[200] min-w-[200px] rounded-xl border border-border bg-popover p-1.5 shadow-xl"
        >
            <div className="mb-1 flex items-center gap-2 border-b border-border px-2 pt-1 pb-2">
                <span className="flex h-[26px] w-[26px] items-center justify-center rounded-md bg-primary/15 text-primary">
                    <TypeIcon className="h-3.5 w-3.5" />
                </span>
                <span className="truncate text-[12.5px] font-semibold">
                    {site.name}
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
                            'flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-[13px] hover:bg-muted',
                            it.danger
                                ? 'text-status-critical'
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
/*  CSV export (client-side)                                           */
/* ------------------------------------------------------------------ */

function csvCell(value: string | number): string {
    const s = String(value ?? '');
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function exportSitesCsv(rows: Site[]) {
    const header = [
        'Name',
        'Type',
        'Region',
        'Status',
        'Beds',
        'Clients',
        'Site lead',
        'Open hazards',
        'Overdue',
        'Readiness %',
        'Risk',
    ];
    const lines = rows.map((s) => {
        const risk = s.is_high_risk
            ? 'High risk'
            : s.is_high_needs
              ? 'High needs'
              : 'Standard';
        const beds =
            (s.rooms_total ?? 0) > 0
                ? `${s.rooms_occupied ?? 0}/${s.rooms_total}`
                : '';
        return [
            s.name,
            typeLabels[s.type] ?? s.type,
            s.region ?? '',
            s.archived ? 'Archived' : s.is_active ? 'Active' : 'Inactive',
            beds,
            s.active_clients_count ?? 0,
            s.primary_contact?.name ?? '',
            s.open_hazards_count ?? 0,
            overdueOf(s),
            s.readiness?.score ?? '',
            risk,
        ]
            .map(csvCell)
            .join(',');
    });
    const csv = [header.join(','), ...lines].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'sites.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function SitesIndex() {
    const {
        auth,
        filters,
        filterOptions,
        savedViewCounts,
        sites,
        summary,
        labels,
        addSite,
    } = usePage<PageProps>().props;
    const can = auth?.can ?? {};
    const [addSiteOpen, setAddSiteOpen] = useState(false);
    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const sitePlural = labels?.['site.plural'] ?? 'Sites';
    const firstName =
        (auth?.user?.name ?? '').trim().split(/\s+/)[0] || 'there';

    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const [groupBy, setGroupBy] = useState<'none' | 'region' | 'type'>('none');
    const [selectMode, setSelectMode] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(() => new Set());
    const [search, setSearch] = useState(filters.q ?? '');
    const [ctx, setCtx] = useState<{ x: number; y: number; site: Site } | null>(
        null,
    );

    // Keep the search box in sync when the server echoes a different query.
    useEffect(() => {
        setSearch(filters.q ?? '');
    }, [filters.q]);

    // Leaving select mode clears the selection.
    useEffect(() => {
        if (!selectMode) setSelected(new Set());
    }, [selectMode]);

    const go = (next: Record<string, unknown>) => {
        router.get('/sites', cleanFilters(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // Merge a partial change onto the current filters (preserves the active view).
    const patchFilters = (patch: Partial<Filters>) =>
        go({ ...filters, ...patch });

    // Debounced live search.
    useEffect(() => {
        const handle = setTimeout(() => {
            if ((filters.q ?? '') !== search) {
                patchFilters({ q: search.trim() || null });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const activeView: ViewKey = filters.archived
        ? 'archived'
        : filters.status === 'inactive'
          ? 'inactive'
          : filters.risk === 'at_risk'
            ? 'at_risk'
            : filters.audit === 'overdue'
              ? 'audit_overdue'
              : filters.hazards === 'open'
                ? 'open_hazards'
                : filters.readiness === 'incomplete'
                  ? 'active_incomplete'
                  : 'all';

    const selectView = (key: ViewKey) => {
        const base: Record<string, unknown> = {
            q: filters.q,
            type: filters.type,
            region: filters.region,
            show_archived: filters.show_archived,
        };
        if (key === 'at_risk') base.risk = 'at_risk';
        else if (key === 'audit_overdue') base.audit = 'overdue';
        else if (key === 'open_hazards') base.hazards = 'open';
        else if (key === 'active_incomplete') base.readiness = 'incomplete';
        else if (key === 'inactive') base.status = 'inactive';
        else if (key === 'archived') {
            base.show_archived = true;
            base.archived = true;
        }
        go(base);
    };

    const setShowArchived = (on: boolean) => {
        const base: Record<string, unknown> = { ...filters, show_archived: on };
        if (!on) base.archived = false;
        go(base);
    };

    const clearFilters = () => go({ show_archived: filters.show_archived });

    const hasFilters = !!(
        filters.q ||
        filters.type ||
        filters.region ||
        filters.risk ||
        filters.audit ||
        filters.hazards ||
        filters.maintenance ||
        filters.readiness ||
        filters.service ||
        (filters.status && filters.status !== 'active') ||
        filters.archived
    );

    const toggleSel = (id: number) =>
        setSelected((prev) => {
            const n = new Set(prev);
            if (n.has(id)) n.delete(id);
            else n.add(id);
            return n;
        });

    const openSite = (s: Site) => router.visit(`/sites/${s.id}`);
    const openCtx = (e: React.MouseEvent, s: Site) => {
        e.preventDefault();
        setCtx({ x: e.clientX, y: e.clientY, site: s });
    };

    const toggleActive = (s: Site) =>
        router.patch(
            `/sites/${s.id}/active`,
            { is_active: !s.is_active },
            { preserveScroll: true },
        );
    const restoreSite = (s: Site) =>
        router.patch(`/sites/${s.id}/unarchive`, {}, { preserveScroll: true });

    const bulkArchive = () => {
        if (selected.size === 0) return;
        router.post(
            '/sites/bulk/archive',
            { ids: Array.from(selected) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected(new Set());
                    setSelectMode(false);
                },
            },
        );
    };

    const exportSelected = () =>
        exportSitesCsv(sites.filter((s) => selected.has(s.id)));

    // Per-site action list, shared by the kebab and the right-click menu.
    const actionsFor = (s: Site): MenuItem[] =>
        compactMenu([
            { label: 'Open site', icon: Eye, onClick: () => openSite(s) },
            {
                label: selected.has(s.id) ? 'Deselect site' : 'Select site',
                icon: Check,
                onClick: () => {
                    setSelectMode(true);
                    toggleSel(s.id);
                },
            },
            { separator: true },
            can.sites?.update && {
                label: 'Edit details',
                icon: Pencil,
                onClick: () => router.visit(`/sites/${s.id}/edit`),
            },
            can.calendar?.create && {
                label: 'Add calendar event',
                icon: Calendar,
                onClick: () =>
                    router.visit(`/sites/${s.id}/calendar?action=add`),
            },
            can.hazards?.create && {
                label: 'Report hazard',
                icon: ShieldAlert,
                onClick: () =>
                    router.visit(`/sites/${s.id}/hazards?action=add`),
            },
            can.checklists?.run && {
                label: 'Run checklist',
                icon: ClipboardCheck,
                onClick: () => router.visit(`/sites/${s.id}/checklists/runs`),
            },
            { separator: true },
            s.archived
                ? can.sites?.archive && {
                      label: 'Restore site',
                      icon: ArchiveRestore,
                      onClick: () => restoreSite(s),
                  }
                : can.sites?.update && {
                      label: s.is_active ? 'Mark inactive' : 'Mark active',
                      icon: s.is_active ? X : Check,
                      danger: s.is_active,
                      onClick: () => toggleActive(s),
                  },
        ]);

    const viewItems: {
        key: ViewKey;
        label: string;
        icon: IconType;
        count: number;
        alert?: boolean;
    }[] = [
        {
            key: 'all',
            label: 'All sites',
            icon: LayoutGrid,
            count: summary.total,
        },
        {
            key: 'at_risk',
            label: 'At risk',
            icon: AlertTriangle,
            count: savedViewCounts.at_risk,
            alert: true,
        },
        {
            key: 'audit_overdue',
            label: 'Audit overdue',
            icon: ClipboardCheck,
            count: savedViewCounts.audit_overdue,
            alert: true,
        },
        {
            key: 'open_hazards',
            label: 'Open hazards',
            icon: ShieldAlert,
            count: savedViewCounts.open_hazards,
            alert: true,
        },
        {
            key: 'active_incomplete',
            label: 'Active incomplete',
            icon: AlertCircle,
            count: savedViewCounts.active_incomplete,
        },
        {
            key: 'inactive',
            label: 'Inactive',
            icon: X,
            count: savedViewCounts.inactive,
        },
        ...(filters.show_archived
            ? [
                  {
                      key: 'archived' as ViewKey,
                      label: 'Archived',
                      icon: Archive,
                      count: savedViewCounts.archived,
                  },
              ]
            : []),
    ];
    const currentViewLabel =
        viewItems.find((v) => v.key === activeView)?.label ?? 'All sites';

    const groups = useMemo(() => {
        if (groupBy === 'none') return null;
        const map = new Map<string, Site[]>();
        for (const s of sites) {
            const key =
                groupBy === 'region'
                    ? s.region || 'No region'
                    : (typeLabels[s.type] ?? s.type);
            if (!map.has(key)) map.set(key, []);
            map.get(key)!.push(s);
        }
        return Array.from(map.entries()).sort((a, b) =>
            a[0].localeCompare(b[0]),
        );
    }, [sites, groupBy]);

    const typeOptions = [
        { value: 'all', label: 'All types' },
        ...filterOptions.types.map((t) => ({ value: t.value, label: t.label })),
    ];
    const regionOptions = [
        { value: 'all', label: 'All regions' },
        ...filterOptions.regions.map((r) => ({ value: r, label: r })),
    ];
    const statusOptions = [
        { value: 'active', label: 'Active only' },
        { value: 'inactive', label: 'Inactive only' },
        { value: 'all', label: 'All statuses' },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Total', value: summary.total },
        { label: 'Active', value: summary.active },
        {
            label: 'Hazards',
            value: summary.hazards,
            tone: summary.hazards > 0 ? 'critical' : 'neutral',
        },
        {
            label: 'Overdue',
            value: summary.overdue,
            tone: summary.overdue > 0 ? 'warning' : 'neutral',
        },
    ];

    const heroMeta = [
        {
            icon: MapIcon,
            label: `${summary.regions} ${summary.regions === 1 ? 'region' : 'regions'}`,
        },
        ...(summary.beds_total > 0
            ? [
                  {
                      icon: BedDouble,
                      label: `${summary.beds_occupied}/${summary.beds_total} beds · ${summary.occupancy_percent}% occupied`,
                  },
              ]
            : []),
        { icon: Users, label: `${summary.clients} clients supported` },
    ];

    const descParts: string[] = [];
    if (summary.incomplete > 0)
        descParts.push(
            `${summary.incomplete} active ${summary.incomplete === 1 ? 'site' : 'sites'} still incomplete`,
        );
    if (summary.hazards > 0)
        descParts.push(
            `${summary.hazards} open ${summary.hazards === 1 ? 'hazard' : 'hazards'}`,
        );
    descParts.push(
        `${summary.overdue} overdue ${summary.overdue === 1 ? 'check' : 'checks'} need attention`,
    );
    const descTail =
        descParts.length > 1
            ? `${descParts.slice(0, -1).join(', ')} and ${descParts[descParts.length - 1]}`
            : descParts[0];
    const heroDescription = `Pick a ${siteSingular.toLowerCase()} to open it, or switch on select mode to act on several at once. ${descTail}.`;

    const heroTitle = (
        <span className="flex flex-col">
            <span className="mb-2 flex items-center justify-center gap-2 text-[10.5px] font-semibold tracking-[0.09em] text-primary-foreground/80 uppercase md:justify-start">
                <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-75" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                </span>
                Live · {summary.total} {summary.total === 1 ? 'site' : 'sites'}{' '}
                synced just now
            </span>
            <span>
                <span className="font-normal text-primary-foreground/80">
                    Kia ora {firstName} — your sites at a glance,{' '}
                </span>
                <span className="border-b-2 border-primary-foreground/40 pb-0.5">
                    {summary.active} active across {summary.regions}{' '}
                    {summary.regions === 1 ? 'region' : 'regions'}
                </span>
            </span>
        </span>
    );

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
                {selectMode ? 'Done selecting' : 'Select'}
            </button>
            {can.sites?.create ? (
                <button
                    type="button"
                    onClick={() => setAddSiteOpen(true)}
                    className="inline-flex h-9 items-center gap-1.5 rounded-md bg-primary-foreground px-3.5 text-sm font-semibold text-primary transition hover:bg-primary-foreground/90"
                >
                    <Plus className="h-4 w-4" />
                    Add {siteSingular.toLowerCase()}
                </button>
            ) : null}
        </>
    );

    const heroFooter = (
        <div className="flex flex-wrap items-center justify-between gap-3 py-3">
            <div className="relative max-w-[340px] min-w-[200px] flex-1">
                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-primary-foreground/70" />
                <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search sites, regions, leads…"
                    className="h-9 w-full rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 pr-3 pl-9 text-sm text-primary-foreground outline-none placeholder:text-primary-foreground/60 focus:border-primary-foreground/50 focus:bg-primary-foreground/15"
                />
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <HeroPill
                    icon={Home}
                    label="All types"
                    value={filters.type ?? 'all'}
                    options={typeOptions}
                    onChange={(v) => patchFilters({ type: v })}
                />
                <HeroPill
                    icon={MapIcon}
                    label="All regions"
                    value={filters.region ?? 'all'}
                    options={regionOptions}
                    onChange={(v) => patchFilters({ region: v })}
                />
                <HeroPill
                    label="Status"
                    value={filters.status ?? 'active'}
                    allValue="active"
                    options={statusOptions}
                    onChange={(v) => patchFilters({ status: v })}
                />
                <button
                    type="button"
                    onClick={() => setShowArchived(!filters.show_archived)}
                    className={cn(
                        'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                        filters.show_archived
                            ? 'border-primary-foreground bg-primary-foreground text-primary'
                            : 'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
                    )}
                >
                    <span
                        className={cn(
                            'flex h-4 w-4 items-center justify-center rounded-[5px] border',
                            filters.show_archived
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-primary-foreground/60 text-transparent',
                        )}
                    >
                        <Check className="h-3 w-3" />
                    </span>
                    Show archived
                </button>
                <div className="inline-flex items-center gap-0.5 rounded-full border border-primary-foreground/30 bg-primary-foreground/10 p-0.5">
                    <button
                        type="button"
                        onClick={() => setLayout('cards')}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition-colors',
                            layout === 'cards'
                                ? 'bg-primary-foreground text-primary'
                                : 'text-primary-foreground/85 hover:text-primary-foreground',
                        )}
                    >
                        <LayoutGrid className="h-3.5 w-3.5" />
                        Cards
                    </button>
                    <button
                        type="button"
                        onClick={() => setLayout('table')}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition-colors',
                            layout === 'table'
                                ? 'bg-primary-foreground text-primary'
                                : 'text-primary-foreground/85 hover:text-primary-foreground',
                        )}
                    >
                        <List className="h-3.5 w-3.5" />
                        Table
                    </button>
                </div>
            </div>
        </div>
    );

    const renderGrid = (items: Site[]) => (
        <div className="grid [grid-template-columns:repeat(auto-fill,minmax(330px,1fr))] gap-[13px]">
            {items.map((s) => (
                <SiteCard
                    key={s.id}
                    s={s}
                    selectMode={selectMode}
                    selected={selected.has(s.id)}
                    actions={actionsFor(s)}
                    onOpen={openSite}
                    onToggle={toggleSel}
                    onContext={openCtx}
                />
            ))}
        </div>
    );

    const renderTable = (items: Site[]) => (
        <Card className="overflow-hidden py-0">
            <div className="overflow-x-auto">
                <table className="w-full text-[13px]">
                    <thead>
                        <tr className="border-b border-border bg-muted/45 text-left text-[10.5px] font-semibold tracking-wide text-muted-foreground uppercase">
                            <th className="w-9 px-3.5 py-3" />
                            <th className="px-3.5 py-3">Site</th>
                            <th className="px-3.5 py-3">Type</th>
                            <th className="px-3.5 py-3">Capacity</th>
                            <th className="px-3.5 py-3">Clients</th>
                            <th className="px-3.5 py-3">Site lead</th>
                            <th className="px-3.5 py-3">Hazards</th>
                            <th className="px-3.5 py-3">Overdue</th>
                            <th className="px-3.5 py-3">Readiness</th>
                            <th className="px-3.5 py-3">Risk</th>
                            <th className="px-3.5 py-3">Status</th>
                            <th className="px-3.5 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((s) => (
                            <SiteRow
                                key={s.id}
                                s={s}
                                selectMode={selectMode}
                                selected={selected.has(s.id)}
                                actions={actionsFor(s)}
                                onOpen={openSite}
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
        <AppLayout breadcrumbs={[{ title: sitePlural, href: '/sites' }]}>
            <Head title={sitePlural} />

            <PageLayout
                hero={
                    <PageHero
                        icon={Building2}
                        title={heroTitle}
                        description={heroDescription}
                        meta={heroMeta}
                        stats={heroStats}
                        actions={heroActions}
                        footer={heroFooter}
                    />
                }
            >
                <ViewTabs
                    items={viewItems}
                    value={activeView}
                    onSelect={selectView}
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-[15px] font-semibold tracking-tight">
                        {currentViewLabel}
                    </h2>
                    <div className="flex items-center gap-3">
                        <Select
                            value={groupBy}
                            onValueChange={(v) =>
                                setGroupBy(v as typeof groupBy)
                            }
                        >
                            <SelectTrigger className="h-8 w-[140px] text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    No grouping
                                </SelectItem>
                                <SelectItem value="region">
                                    Group: Region
                                </SelectItem>
                                <SelectItem value="type">
                                    Group: Type
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        {hasFilters ? (
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="inline-flex h-8 items-center gap-1.5 rounded-md border border-border bg-card px-2.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted"
                            >
                                <X className="h-3.5 w-3.5" />
                                Clear filters
                            </button>
                        ) : null}
                        <span className="text-xs font-medium text-muted-foreground">
                            {sites.length} of{' '}
                            {activeView === 'archived'
                                ? summary.archived
                                : summary.total}{' '}
                            shown
                            {selectMode ? ' · tap to select' : ''}
                        </span>
                    </div>
                </div>

                {sites.length === 0 ? (
                    <div className="flex flex-col items-center gap-1 rounded-xl border border-dashed border-border bg-card/40 px-6 py-16 text-center">
                        <Building2 className="mb-2 h-10 w-10 text-muted-foreground/40" />
                        <div className="font-semibold text-foreground">
                            No {sitePlural.toLowerCase()} match your filters
                        </div>
                        <div className="text-sm text-muted-foreground">
                            {hasFilters
                                ? 'Try clearing a filter or search term.'
                                : `Add a ${siteSingular.toLowerCase()} to get started.`}
                        </div>
                        {hasFilters ? (
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="mt-3 inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-3 text-xs font-medium transition-colors hover:bg-muted"
                            >
                                <X className="h-3.5 w-3.5" />
                                Clear filters
                            </button>
                        ) : null}
                    </div>
                ) : groups ? (
                    <div className="flex flex-col gap-5">
                        {groups.map(([key, items]) => (
                            <div key={key} className="flex flex-col gap-3">
                                <div className="flex items-center gap-2.5">
                                    <span className="text-[13px] font-semibold tracking-tight">
                                        {key}
                                    </span>
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-bold text-muted-foreground tabular-nums">
                                        {items.length}
                                    </span>
                                    <span className="h-px flex-1 bg-border" />
                                </div>
                                {layout === 'table'
                                    ? renderTable(items)
                                    : renderGrid(items)}
                            </div>
                        ))}
                    </div>
                ) : layout === 'table' ? (
                    renderTable(sites)
                ) : (
                    renderGrid(sites)
                )}
            </PageLayout>

            {selectMode && selected.size > 0 ? (
                <BulkBar
                    count={selected.size}
                    canArchive={!!can.sites?.archive}
                    onExport={exportSelected}
                    onArchive={bulkArchive}
                    onClear={() => setSelected(new Set())}
                />
            ) : null}

            {ctx ? (
                <SiteContextMenu
                    x={ctx.x}
                    y={ctx.y}
                    site={ctx.site}
                    items={actionsFor(ctx.site)}
                    onClose={() => setCtx(null)}
                />
            ) : null}

            {can.sites?.create ? (
                <AddSiteDialog
                    isOpen={addSiteOpen}
                    onClose={() => setAddSiteOpen(false)}
                    {...addSite}
                    onSaved={() =>
                        router.reload({
                            only: ['sites', 'summary', 'savedViewCounts'],
                        })
                    }
                />
            ) : null}
        </AppLayout>
    );
}
