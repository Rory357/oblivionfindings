import { cn } from '@/lib/utils';
import {
    Bell,
    Building2,
    CalendarRange,
    Check,
    ChefHat,
    ChevronLeft,
    ChevronRight,
    ChevronsUpDown,
    CircleCheck,
    Home,
    MapPin,
    Package,
    Plus,
    Search,
    Settings,
    ShieldAlert,
    ShoppingCart,
    Users,
    type LucideIcon,
} from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { formatMoneyFromCents, type SiteInfo, type SiteSearchItem } from './_helpers';

export type HeroStats = {
    mealsPlanned: number;
    served: number;
    overrides: number;
    weekCostCents: number;
    lowStock: number;
    outOfStock: number;
    itemsTracked: number;
    fillPct: number;
    unresolved: number;
};

export type HeroNotification = {
    id: string;
    icon: LucideIcon;
    tone: 'critical' | 'warning' | 'info';
    label: string;
    sub?: string;
    tab: string;
};

const SITES_GRADIENT_STYLE: CSSProperties = {
    ['--hero-base' as string]: 'var(--category-sites)',
};
const SITES_GRADIENT_CLASS =
    'bg-[linear-gradient(to_bottom_right,color-mix(in_oklch,var(--hero-base)_90%,transparent),var(--hero-base),color-mix(in_oklch,var(--hero-base)_80%,transparent))]';

function HeroStat({ label, value, sub, emphasis }: { label: string; value: ReactNode; sub?: string; emphasis?: boolean }) {
    return (
        <div
            className={cn(
                'rounded-xl px-4 py-2.5 text-center backdrop-blur-sm transition-colors',
                emphasis ? 'bg-primary-foreground/20 ring-1 ring-primary-foreground/25' : 'bg-primary-foreground/10',
            )}
        >
            <div className="text-[22px] font-bold leading-none tabular-nums text-primary-foreground">{value}</div>
            <div className="mt-1 text-[11px] font-medium uppercase tracking-wide text-primary-foreground/70">{label}</div>
            {sub && <div className="mt-0.5 text-[10.5px] text-primary-foreground/55">{sub}</div>}
        </div>
    );
}

function HeroBadge({ tone, icon: Icon, children }: { tone: 'success' | 'warning' | 'critical' | 'info'; icon?: LucideIcon; children: ReactNode }) {
    const tones: Record<string, string> = {
        success: 'bg-primary-foreground/15 text-primary-foreground ring-1 ring-primary-foreground/20',
        warning: 'bg-amber-300/25 text-amber-50 ring-1 ring-amber-200/30',
        critical: 'bg-red-400/25 text-red-50 ring-1 ring-red-200/30',
        info: 'bg-primary-foreground/15 text-primary-foreground ring-1 ring-primary-foreground/20',
    };
    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12px] font-semibold', tones[tone])}>
            {Icon && <Icon className="h-3.5 w-3.5" strokeWidth={2.4} />}
            {children}
        </span>
    );
}

function HeroMeta({ icon: Icon, children }: { icon: LucideIcon; children: ReactNode }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-[12.5px] text-primary-foreground/75">
            <Icon className="h-3.5 w-3.5 text-primary-foreground/60" />
            {children}
        </span>
    );
}

function HeroBell({ notifications, onClick }: { notifications: HeroNotification[]; onClick: (tab: string) => void }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        function onDoc(e: MouseEvent) {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        }
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);
    const count = notifications.length;
    const toneDot: Record<string, string> = { critical: 'text-status-critical', warning: 'text-status-warning', info: 'text-primary' };
    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-label="Notifications"
                className="relative inline-flex h-10 w-10 items-center justify-center rounded-md border border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground transition hover:bg-primary-foreground/20"
            >
                <Bell className="h-[17px] w-[17px]" />
                {count > 0 && (
                    <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-[var(--hero-base)]">
                        {count}
                    </span>
                )}
            </button>
            {open && (
                <div className="animate-pop absolute right-0 z-[120] mt-2 w-[290px] overflow-hidden rounded-xl border border-border bg-popover text-foreground shadow-float">
                    <div className="border-b border-border px-3.5 py-2.5 text-[13px] font-semibold">Notifications</div>
                    {count === 0 ? (
                        <div className="px-3.5 py-6 text-center text-[13px] text-muted-foreground">
                            <CircleCheck className="mx-auto mb-1 h-5 w-5 text-status-success" />
                            All clear — nothing needs attention.
                        </div>
                    ) : (
                        <div className="nice-scroll max-h-[300px] overflow-y-auto p-1.5">
                            {notifications.map((n) => {
                                const NIcon = n.icon;
                                return (
                                    <button
                                        key={n.id}
                                        type="button"
                                        onClick={() => {
                                            setOpen(false);
                                            onClick(n.tab);
                                        }}
                                        className="flex w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors hover:bg-accent"
                                    >
                                        <NIcon className={cn('mt-0.5 h-4 w-4 shrink-0', toneDot[n.tone])} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-[12.5px] font-medium text-foreground">{n.label}</span>
                                            {n.sub && <span className="block truncate text-[11px] text-muted-foreground">{n.sub}</span>}
                                        </span>
                                        <ChevronRight className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground/60" />
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function SiteSearch({
    sites,
    currentSiteId,
    onSelect,
}: {
    sites: SiteSearchItem[];
    currentSiteId: number;
    onSelect: (id: number) => void;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [active, setActive] = useState(0);
    const [rect, setRect] = useState<DOMRect | null>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const popRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const place = () => {
        if (triggerRef.current) setRect(triggerRef.current.getBoundingClientRect());
    };
    useEffect(() => {
        function onDoc(e: MouseEvent) {
            if (triggerRef.current?.contains(e.target as Node)) return;
            if (popRef.current?.contains(e.target as Node)) return;
            setOpen(false);
        }
        function onScroll() {
            if (open) place();
        }
        document.addEventListener('mousedown', onDoc);
        window.addEventListener('resize', onScroll);
        window.addEventListener('scroll', onScroll, true);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            window.removeEventListener('resize', onScroll);
            window.removeEventListener('scroll', onScroll, true);
        };
    }, [open]);
    useEffect(() => {
        if (open) {
            setQ('');
            setActive(0);
            place();
            const t = setTimeout(() => inputRef.current?.focus(), 20);
            return () => clearTimeout(t);
        }
    }, [open]);

    const current = sites.find((s) => s.id === currentSiteId);
    const filtered = useMemo(() => {
        const needle = q.trim().toLowerCase();
        return sites.filter((s) => !needle || `${s.name} ${s.suburb ?? ''} ${s.region ?? ''} ${s.type}`.toLowerCase().includes(needle));
    }, [q, sites]);

    const groups = useMemo(() => {
        const houses = filtered.filter((s) => s.type === 'house');
        const offices = filtered.filter((s) => s.type !== 'house');
        const out: { label: string; icon: LucideIcon; items: SiteSearchItem[] }[] = [];
        if (houses.length) out.push({ label: 'Houses', icon: Home, items: houses });
        if (offices.length) out.push({ label: 'Offices & facilities', icon: Building2, items: offices });
        return out;
    }, [filtered]);

    const flat = groups.flatMap((g) => g.items);
    const choose = (s: SiteSearchItem) => {
        onSelect(s.id);
        setOpen(false);
    };
    function onKey(e: React.KeyboardEvent) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((a) => Math.min(flat.length - 1, a + 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((a) => Math.max(0, a - 1));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (flat[active]) choose(flat[active]);
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    }

    let runningIndex = -1;

    return (
        <div className="relative">
            <button
                ref={triggerRef}
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="inline-flex w-full items-center gap-2 rounded-md border border-primary-foreground/25 bg-primary-foreground/10 px-3 py-1.5 text-[12.5px] font-semibold text-primary-foreground transition hover:bg-primary-foreground/20 md:w-[260px]"
            >
                <Search className="h-3.5 w-3.5 shrink-0 text-primary-foreground/70" />
                <span className="flex-1 truncate text-left">{current ? current.name : 'Find a site…'}</span>
                <span className="hidden shrink-0 rounded-full bg-primary-foreground/15 px-1.5 py-px text-[10px] font-medium capitalize text-primary-foreground/80 sm:inline">
                    {current?.type}
                </span>
                <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 text-primary-foreground/60" />
            </button>

            {open &&
                rect &&
                createPortal(
                    <div
                        ref={popRef}
                        className="animate-pop fixed z-[120] w-[300px] overflow-hidden rounded-xl border border-border bg-popover text-foreground shadow-float"
                        style={{ top: rect.bottom + 8, left: Math.max(8, Math.min(rect.right - 300, window.innerWidth - 308)) }}
                    >
                        <div className="border-b border-border p-2">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-1/2 h-[15px] w-[15px] -translate-y-1/2 text-muted-foreground" />
                                <input
                                    ref={inputRef}
                                    value={q}
                                    onChange={(e) => {
                                        setQ(e.target.value);
                                        setActive(0);
                                    }}
                                    onKeyDown={onKey}
                                    placeholder="Search houses & offices…"
                                    className="h-9 w-full rounded-md border border-input bg-card pl-8 pr-3 text-sm text-foreground placeholder:text-muted-foreground/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </div>
                        </div>
                        <div className="nice-scroll max-h-[300px] overflow-y-auto p-1.5">
                            {flat.length === 0 && <div className="px-3 py-6 text-center text-[13px] text-muted-foreground">No sites match “{q}”.</div>}
                            {groups.map((g) => {
                                const GIcon = g.icon;
                                return (
                                    <div key={g.label} className="mb-1 last:mb-0">
                                        <div className="flex items-center gap-1.5 px-2 py-1 text-[10.5px] font-semibold uppercase tracking-wide text-muted-foreground">
                                            <GIcon className="h-3 w-3" /> {g.label}
                                        </div>
                                        {g.items.map((s) => {
                                            runningIndex += 1;
                                            const idx = runningIndex;
                                            const isCurrent = s.id === currentSiteId;
                                            const isActive = idx === active;
                                            return (
                                                <button
                                                    key={s.id}
                                                    type="button"
                                                    onMouseEnter={() => setActive(idx)}
                                                    onClick={() => choose(s)}
                                                    className={cn(
                                                        'flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left transition-colors',
                                                        isActive ? 'bg-sites-bg' : 'hover:bg-accent/60',
                                                    )}
                                                >
                                                    <span
                                                        className={cn(
                                                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                                                            s.type === 'house' ? 'bg-sites-bg text-sites-deep' : 'bg-accent text-primary',
                                                        )}
                                                    >
                                                        {s.type === 'house' ? <Home className="h-4 w-4" /> : <Building2 className="h-4 w-4" />}
                                                    </span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="flex items-center gap-1.5">
                                                            <span className="truncate text-[13.5px] font-medium text-foreground">{s.name}</span>
                                                            {s.beds > 0 && <span className="shrink-0 text-[10.5px] text-muted-foreground">· {s.beds} beds</span>}
                                                        </span>
                                                        <span className="block truncate text-[11.5px] text-muted-foreground">
                                                            {[s.suburb, s.region].filter(Boolean).join(' · ')}
                                                        </span>
                                                    </span>
                                                    {isCurrent && <Check className="h-4 w-4 shrink-0 text-sites-deep" />}
                                                </button>
                                            );
                                        })}
                                    </div>
                                );
                            })}
                        </div>
                    </div>,
                    document.body,
                )}
        </div>
    );
}

export type MealPlannerHeroProps = {
    site: SiteInfo;
    firstName: string;
    weekLabel: string;
    rangeStart: string;
    rangeEnd: string;
    isThisWeek: boolean;
    isHouse: boolean;
    residentCount: number;
    stats: HeroStats;
    sites: SiteSearchItem[];
    notifications: HeroNotification[];
    backHref?: string;
    canPlan: boolean;
    canShop: boolean;
    onSelectSite: (id: number) => void;
    onNotificationClick: (tab: string) => void;
    onPlan: () => void;
    onBuildList: () => void;
    onOpenSettings: () => void;
    onPrevWeek: () => void;
    onNextWeek: () => void;
    onThisWeek: () => void;
    onReviewConflicts: () => void;
};

export default function MealPlannerHero(props: MealPlannerHeroProps) {
    const { site, firstName, weekLabel, rangeStart, rangeEnd, isThisWeek, isHouse, residentCount, stats, sites } = props;

    const badges: { tone: 'success' | 'warning' | 'critical' | 'info'; icon?: LucideIcon; label: string }[] = [];
    if (isHouse && stats.overrides > 0)
        badges.push({ tone: 'warning', icon: ShieldAlert, label: `${stats.overrides} override${stats.overrides === 1 ? '' : 's'} logged` });
    if (stats.lowStock > 0) badges.push({ tone: 'warning', icon: Package, label: `${stats.lowStock} item${stats.lowStock === 1 ? '' : 's'} below par` });
    badges.push({ tone: 'success', icon: CircleCheck, label: isHouse ? `${stats.served} meals served` : 'Kitchen stocked' });

    return (
        <div style={SITES_GRADIENT_STYLE} className={cn('relative overflow-hidden rounded-2xl text-primary-foreground shadow-hero', SITES_GRADIENT_CLASS)}>
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                <div className="absolute -right-20 -top-24 h-72 w-72 rounded-full bg-primary-foreground/[0.07]" />
                <div className="absolute -bottom-24 -left-16 h-56 w-56 rounded-full bg-primary-foreground/[0.06]" />
                <div className="absolute right-1/3 top-1/3 h-28 w-28 rounded-full bg-primary-foreground/[0.05]" />
            </div>

            <div className="relative p-5 sm:p-7">
                <div className="mb-2 flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-[0.14em] text-primary-foreground/80">
                    <span className="relative inline-flex h-2 w-2">
                        <span className="absolute inline-flex h-2 w-2 animate-ping rounded-full bg-emerald-200 opacity-75" />
                        <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-100" />
                    </span>
                    Meal Planner · {isHouse ? 'Resident meals' : 'Kitchen supplies'} · updated just now
                </div>

                <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                    <div className="hidden h-24 w-24 shrink-0 items-center justify-center rounded-3xl border-4 border-primary-foreground/20 bg-primary-foreground/10 shadow-xl sm:flex">
                        <ChefHat className="h-12 w-12 text-primary-foreground" />
                    </div>

                    <div className="min-w-0 flex-1">
                        <h1 className="text-[26px] font-bold leading-tight tracking-tight sm:text-[30px]">
                            <span className="font-normal text-primary-foreground/80">Kia ora {firstName}, </span>
                            {isHouse ? "the week's kitchen at " : 'the kitchen at '}
                            {site.name}
                        </h1>

                        <p className="mt-2 max-w-2xl text-[14px] leading-relaxed text-primary-foreground/75">
                            {isHouse ? (
                                <>
                                    <span className="font-semibold text-primary-foreground">{stats.mealsPlanned} meals</span> planned for{' '}
                                    <span className="font-semibold text-primary-foreground">{residentCount} residents</span>
                                    {stats.overrides > 0 && (
                                        <>
                                            {' '}
                                            · <span className="font-semibold text-amber-50">{stats.overrides} allergen override{stats.overrides === 1 ? '' : 's'}</span> on file
                                        </>
                                    )}{' '}
                                    · <span className="border-b-2 border-primary-foreground/40 pb-px">{rangeStart} → {rangeEnd}</span>
                                </>
                            ) : (
                                <>
                                    <span className="font-semibold text-primary-foreground">{stats.itemsTracked} items</span> tracked across the staff kitchen
                                    {stats.lowStock > 0 && (
                                        <>
                                            {' '}
                                            · <span className="font-semibold text-amber-50">{stats.lowStock} below par</span>
                                        </>
                                    )}{' '}
                                    · <span className="border-b-2 border-primary-foreground/40 pb-px">{rangeStart} → {rangeEnd}</span>
                                </>
                            )}
                        </p>

                        <div className="mt-3.5 flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroMeta icon={CalendarRange}>{weekLabel} · Mon–Sun</HeroMeta>
                            {site.suburb && <HeroMeta icon={MapPin}>{site.suburb}</HeroMeta>}
                            {isHouse ? (
                                <HeroMeta icon={Users}>{residentCount} residents</HeroMeta>
                            ) : (
                                <HeroMeta icon={Package}>{stats.itemsTracked} items tracked</HeroMeta>
                            )}
                        </div>

                        {badges.length > 0 && (
                            <div className="mt-3.5 flex flex-wrap gap-2">
                                {badges.map((b, i) => (
                                    <HeroBadge key={i} tone={b.tone} icon={b.icon}>
                                        {b.label}
                                    </HeroBadge>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="flex w-full shrink-0 flex-col items-stretch gap-3.5 lg:w-auto lg:items-end">
                        <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                            {props.canPlan && (
                                <button
                                    type="button"
                                    onClick={props.onPlan}
                                    className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-primary-foreground px-4 text-sm font-semibold text-sites-deep shadow-sm transition hover:bg-primary-foreground/90"
                                >
                                    <Plus className="h-4 w-4" strokeWidth={2.5} />
                                    {isHouse ? 'Plan a meal' : 'Add a meal'}
                                </button>
                            )}
                            {props.canShop && (
                                <button
                                    type="button"
                                    onClick={props.onBuildList}
                                    className="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-primary-foreground/30 bg-primary-foreground/10 px-4 text-sm font-semibold text-primary-foreground transition hover:bg-primary-foreground/20"
                                >
                                    <ShoppingCart className="h-4 w-4" />
                                    Build list
                                </button>
                            )}
                            <HeroBell notifications={props.notifications} onClick={props.onNotificationClick} />
                            {props.canShop && (
                                <button
                                    type="button"
                                    onClick={props.onOpenSettings}
                                    aria-label="Meal planner settings"
                                    className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground transition hover:bg-primary-foreground/20"
                                >
                                    <Settings className="h-[17px] w-[17px]" />
                                </button>
                            )}
                        </div>

                        <div className="grid w-full grid-cols-2 gap-2 sm:grid-cols-4 lg:w-auto">
                            <HeroStat label={isHouse ? 'Meals' : 'Items'} value={isHouse ? stats.mealsPlanned : stats.itemsTracked} sub={isHouse ? `${stats.served} served` : 'tracked'} emphasis />
                            <HeroStat label="Week cost" value={formatMoneyFromCents(stats.weekCostCents)} sub={isHouse ? 'planned' : 'on hand'} />
                            <HeroStat label="Low stock" value={stats.lowStock} sub={`${stats.outOfStock} out`} />
                            <HeroStat label={isHouse ? 'Plan filled' : 'At par'} value={`${stats.fillPct}%`} sub={isHouse ? 'of slots' : 'of items'} />
                        </div>
                    </div>
                </div>
            </div>

            {isHouse && stats.unresolved > 0 && (
                <div className="relative flex px-5 pb-3 sm:px-7 md:justify-end">
                    <div className="flex w-full items-center gap-2.5 rounded-lg bg-red-400/20 px-3 py-1.5 ring-1 ring-red-200/35 md:w-auto">
                        <ShieldAlert className="h-[15px] w-[15px] shrink-0 text-red-50" />
                        <span className="flex-1 text-[12.5px] font-medium text-primary-foreground">
                            {stats.unresolved} planned meal{stats.unresolved === 1 ? '' : 's'} contain{stats.unresolved === 1 ? 's' : ''} allergens for current residents
                        </span>
                        <button
                            type="button"
                            onClick={props.onReviewConflicts}
                            className="shrink-0 rounded-md bg-primary-foreground px-2.5 py-1 text-[12px] font-semibold text-red-700 transition hover:bg-primary-foreground/90"
                        >
                            Review
                        </button>
                    </div>
                </div>
            )}

            <div className="relative border-t border-primary-foreground/15 px-5 py-3 sm:px-7">
                <div className="flex flex-col items-stretch gap-2.5 md:flex-row md:items-center md:justify-between">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            onClick={props.onPrevWeek}
                            className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-[12px] font-semibold text-primary-foreground transition hover:bg-primary-foreground/20"
                        >
                            <ChevronLeft className="h-3.5 w-3.5" /> Prev
                        </button>
                        <button
                            type="button"
                            onClick={props.onThisWeek}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-[12px] font-semibold text-primary-foreground transition',
                                isThisWeek
                                    ? 'border-primary-foreground/40 bg-primary-foreground/25'
                                    : 'border-primary-foreground/25 bg-primary-foreground/15 hover:bg-primary-foreground/25',
                            )}
                        >
                            <CalendarRange className="h-3.5 w-3.5" /> {weekLabel} · {rangeStart} → {rangeEnd}
                        </button>
                        <button
                            type="button"
                            onClick={props.onNextWeek}
                            className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-[12px] font-semibold text-primary-foreground transition hover:bg-primary-foreground/20"
                        >
                            Next <ChevronRight className="h-3.5 w-3.5" />
                        </button>
                    </div>

                    {sites.length > 1 && (
                        <div className="flex items-center gap-2 md:justify-end">
                            <span className="hidden shrink-0 text-[11px] font-medium uppercase tracking-wide text-primary-foreground/55 lg:inline">Site</span>
                            <SiteSearch sites={sites} currentSiteId={site.id} onSelect={props.onSelectSite} />
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
