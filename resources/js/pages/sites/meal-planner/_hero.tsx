import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    type PageHeaderRailItem,
} from '@/components/page';
import { WeekPicker } from '@/components/rostering/week-picker';
import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import {
    Bell,
    Building2,
    CalendarDays,
    CalendarRange,
    Check,
    ChefHat,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronsUpDown,
    CircleCheck,
    Home,
    LayoutTemplate,
    Loader2,
    Package,
    Plus,
    Search,
    Settings,
    ShieldAlert,
    ShoppingCart,
    Soup,
    TriangleAlert,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
    formatMoneyFromCents,
    type SiteInfo,
    type SiteSearchItem,
} from './_helpers';

export type HeroStats = {
    mealsPlanned: number;
    served: number;
    overrides: number;
    weekCostCents: number;
    cookCostCents: number;
    takeawayCostCents: number;
    lowStock: number;
    outOfStock: number;
    itemsTracked: number;
    fillPct: number;
    unresolved: number;
    /** Residents on a texture-modified diet (IDDSI < 7). */
    textureModified: number;
    /** Planned meals with a texture-modified assignee — need a texture check. */
    textureEntries: number;
    /** Planned meals with a soft (dislike) conflict. */
    softWarnings: number;
};

export type HeroNotification = {
    id: string;
    icon: LucideIcon;
    tone: 'critical' | 'warning' | 'info';
    label: string;
    sub?: string;
    tab: string;
};

function HeroBell({
    notifications,
    onClick,
    light,
    compact,
}: {
    notifications: HeroNotification[];
    onClick: (tab: string) => void;
    light?: boolean;
    compact?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const btnRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const menuId = useId();
    useEffect(() => {
        function onDoc(e: MouseEvent) {
            if (ref.current && !ref.current.contains(e.target as Node))
                setOpen(false);
        }
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);
    // Move focus into the menu on open so keyboard users land on the first item.
    useEffect(() => {
        if (open)
            menuRef.current
                ?.querySelector<HTMLElement>('[role="menuitem"]')
                ?.focus();
    }, [open]);
    function close(restoreFocus = true) {
        setOpen(false);
        if (restoreFocus) btnRef.current?.focus();
    }
    function onMenuKeyDown(e: React.KeyboardEvent) {
        const items = Array.from(
            menuRef.current?.querySelectorAll<HTMLElement>(
                '[role="menuitem"]',
            ) ?? [],
        );
        if (items.length === 0) return;
        const idx = items.indexOf(document.activeElement as HTMLElement);
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            items[(idx + 1) % items.length]?.focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            items[(idx - 1 + items.length) % items.length]?.focus();
        }
    }
    const count = notifications.length;
    const toneDot: Record<string, string> = {
        critical: 'text-status-critical',
        warning: 'text-status-warning',
        info: 'text-primary',
    };
    return (
        <div
            ref={ref}
            className="relative"
            onKeyDown={(e) => {
                if (e.key === 'Escape' && open) {
                    e.stopPropagation();
                    close();
                }
            }}
        >
            <GuardrailButton
                unstyled
                ref={btnRef}
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-label={
                    count > 0
                        ? `Notifications, ${count} need attention`
                        : 'Notifications, all clear'
                }
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                className={cn(
                    'relative inline-flex items-center justify-center rounded-md border transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:outline-none',
                    compact ? 'h-9 w-9' : 'h-10 w-10',
                    light
                        ? 'border-border bg-card text-muted-foreground hover:bg-accent hover:text-foreground'
                        : 'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
                )}
            >
                <Bell className="h-[17px] w-[17px]" aria-hidden="true" />
                {count > 0 && (
                    <span
                        aria-hidden="true"
                        className={cn(
                            'absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-status-critical px-1 text-[10px] font-bold text-white ring-2',
                            light ? 'ring-card' : 'ring-primary',
                        )}
                    >
                        {count}
                    </span>
                )}
            </GuardrailButton>
            {open && (
                <div
                    ref={menuRef}
                    id={menuId}
                    role="menu"
                    aria-label="Notifications"
                    onKeyDown={onMenuKeyDown}
                    className="absolute right-0 z-[120] mt-2 w-[290px] animate-pop overflow-hidden rounded-xl border border-border bg-popover text-foreground shadow-float"
                >
                    <div className="border-b border-border px-3.5 py-2.5 text-[13px] font-semibold">
                        Notifications
                    </div>
                    {count === 0 ? (
                        <div className="px-3.5 py-6 text-center text-[13px] text-muted-foreground">
                            <CircleCheck
                                className="mx-auto mb-1 h-5 w-5 text-status-success"
                                aria-hidden="true"
                            />
                            All clear — nothing needs attention.
                        </div>
                    ) : (
                        <div className="nice-scroll max-h-[300px] overflow-y-auto p-1.5">
                            {notifications.map((n) => {
                                const NIcon = n.icon;
                                return (
                                    <GuardrailButton
                                        unstyled
                                        key={n.id}
                                        type="button"
                                        role="menuitem"
                                        onClick={() => {
                                            close(false);
                                            onClick(n.tab);
                                        }}
                                        className="flex w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                                    >
                                        <NIcon
                                            className={cn(
                                                'mt-0.5 h-4 w-4 shrink-0',
                                                toneDot[n.tone],
                                            )}
                                            aria-hidden="true"
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-[12.5px] font-medium text-foreground">
                                                {n.label}
                                            </span>
                                            {n.sub && (
                                                <span className="block truncate text-[11px] text-muted-foreground">
                                                    {n.sub}
                                                </span>
                                            )}
                                        </span>
                                        <ChevronRight
                                            className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground/60"
                                            aria-hidden="true"
                                        />
                                    </GuardrailButton>
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
        if (triggerRef.current)
            setRect(triggerRef.current.getBoundingClientRect());
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
        return sites.filter(
            (s) =>
                !needle ||
                `${s.name} ${s.suburb ?? ''} ${s.region ?? ''} ${s.type}`
                    .toLowerCase()
                    .includes(needle),
        );
    }, [q, sites]);

    const groups = useMemo(() => {
        const houses = filtered.filter((s) => s.type === 'house');
        const offices = filtered.filter((s) => s.type !== 'house');
        const out: {
            label: string;
            icon: LucideIcon;
            items: SiteSearchItem[];
        }[] = [];
        if (houses.length)
            out.push({ label: 'Houses', icon: Home, items: houses });
        if (offices.length)
            out.push({
                label: 'Offices & facilities',
                icon: Building2,
                items: offices,
            });
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
            <GuardrailButton
                unstyled
                ref={triggerRef}
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="inline-flex w-full items-center gap-2 rounded-md border border-primary-foreground/25 bg-primary-foreground/10 px-3 py-1.5 text-[12.5px] font-semibold text-primary-foreground transition hover:bg-primary-foreground/20 md:w-[260px]"
            >
                <Search className="h-3.5 w-3.5 shrink-0 text-primary-foreground/70" />
                <span className="flex-1 truncate text-left">
                    {current ? current.name : 'Find a site…'}
                </span>
                <span className="hidden shrink-0 rounded-full bg-primary-foreground/15 px-1.5 py-px text-[10px] font-medium text-primary-foreground/80 capitalize sm:inline">
                    {current?.type}
                </span>
                <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 text-primary-foreground/60" />
            </GuardrailButton>

            {open &&
                rect &&
                createPortal(
                    <div
                        ref={popRef}
                        className="fixed z-[120] w-[300px] animate-pop overflow-hidden rounded-xl border border-border bg-popover text-foreground shadow-float"
                        style={{
                            top: rect.bottom + 8,
                            left: Math.max(
                                8,
                                Math.min(
                                    rect.right - 300,
                                    window.innerWidth - 308,
                                ),
                            ),
                        }}
                    >
                        <div className="border-b border-border p-2">
                            <div className="relative">
                                <Search className="absolute top-1/2 left-2.5 h-[15px] w-[15px] -translate-y-1/2 text-muted-foreground" />
                                <input
                                    ref={inputRef}
                                    value={q}
                                    onChange={(e) => {
                                        setQ(e.target.value);
                                        setActive(0);
                                    }}
                                    onKeyDown={onKey}
                                    placeholder="Search houses & offices…"
                                    className="h-9 w-full rounded-md border border-input bg-card pr-3 pl-8 text-sm text-foreground placeholder:text-muted-foreground/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                />
                            </div>
                        </div>
                        <div className="nice-scroll max-h-[300px] overflow-y-auto p-1.5">
                            {flat.length === 0 && (
                                <div className="px-3 py-6 text-center text-[13px] text-muted-foreground">
                                    No sites match “{q}”.
                                </div>
                            )}
                            {groups.map((g) => {
                                const GIcon = g.icon;
                                return (
                                    <div
                                        key={g.label}
                                        className="mb-1 last:mb-0"
                                    >
                                        <div className="flex items-center gap-1.5 px-2 py-1 text-[10.5px] font-semibold tracking-wide text-muted-foreground uppercase">
                                            <GIcon className="h-3 w-3" />{' '}
                                            {g.label}
                                        </div>
                                        {g.items.map((s) => {
                                            runningIndex += 1;
                                            const idx = runningIndex;
                                            const isCurrent =
                                                s.id === currentSiteId;
                                            const isActive = idx === active;
                                            return (
                                                <GuardrailButton
                                                    unstyled
                                                    key={s.id}
                                                    type="button"
                                                    onMouseEnter={() =>
                                                        setActive(idx)
                                                    }
                                                    onClick={() => choose(s)}
                                                    className={cn(
                                                        'flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left transition-colors',
                                                        isActive
                                                            ? 'bg-sites-bg'
                                                            : 'hover:bg-accent/60',
                                                    )}
                                                >
                                                    <span
                                                        className={cn(
                                                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                                                            s.type === 'house'
                                                                ? 'bg-sites-bg text-sites-deep'
                                                                : 'bg-accent text-primary',
                                                        )}
                                                    >
                                                        {s.type === 'house' ? (
                                                            <Home className="h-4 w-4" />
                                                        ) : (
                                                            <Building2 className="h-4 w-4" />
                                                        )}
                                                    </span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="flex items-center gap-1.5">
                                                            <span className="truncate text-[13.5px] font-medium text-foreground">
                                                                {s.name}
                                                            </span>
                                                            {s.beds > 0 && (
                                                                <span className="shrink-0 text-[10.5px] text-muted-foreground">
                                                                    · {s.beds}{' '}
                                                                    beds
                                                                </span>
                                                            )}
                                                        </span>
                                                        <span className="block truncate text-[11.5px] text-muted-foreground">
                                                            {[
                                                                s.suburb,
                                                                s.region,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ')}
                                                        </span>
                                                    </span>
                                                    {isCurrent && (
                                                        <Check className="h-4 w-4 shrink-0 text-sites-deep" />
                                                    )}
                                                </GuardrailButton>
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
    weekLabel: string;
    rangeStart: string;
    rangeEnd: string;
    isThisWeek: boolean;
    isHouse: boolean;
    residentCount: number;
    stats: HeroStats;
    sites: SiteSearchItem[];
    notifications: HeroNotification[];
    /** Monday-start Date of the displayed week — drives the calendar week-picker. */
    weekStart: Date;
    /** Active sub-view — rendered as the header rail. */
    tab: string;
    onTab: (tab: string) => void;
    canPlan: boolean;
    canShop: boolean;
    onSelectSite: (id: number) => void;
    /** Jump to an arbitrary week from the calendar picker. */
    onSelectWeek: (weekStart: Date) => void;
    onNotificationClick: (tab: string) => void;
    onPlan: () => void;
    onBuildList: () => void;
    onOpenSettings: () => void;
    onOpenSpend: () => void;
    onOpenOverrides: () => void;
    onPrevWeek: () => void;
    onNextWeek: () => void;
    onThisWeek: () => void;
    onReviewConflicts: () => void;
};

/**
 * The standalone /catering header — the Event Horizon band
 * (design_styles/PAGE_HEADER_STYLE_GUIDE.md): site identity + kitchen facts
 * in the top row, the linked meter row (plan-fill bar, week cost, allergen
 * conflicts, stock), week nav in the filter row and the planner sub-views on
 * the rail. The Site-profile embed keeps the compact MealPlannerToolbar
 * below instead.
 */
export default function MealPlannerHero(props: MealPlannerHeroProps) {
    const {
        site,
        weekLabel,
        rangeStart,
        rangeEnd,
        isThisWeek,
        isHouse,
        residentCount,
        stats,
        sites,
    } = props;
    const weekBtnRef = useRef<HTMLButtonElement>(null);
    const [weekPickerOpen, setWeekPickerOpen] = useState(false);
    const overBudget =
        site.weekly_food_budget_cents != null &&
        stats.weekCostCents > site.weekly_food_budget_cents;
    const budget = site.weekly_food_budget_cents;
    const budgetSpentPct =
        budget != null && budget > 0
            ? Math.round((stats.weekCostCents / budget) * 100)
            : null;

    // Week cost is spend against the site's weekly food budget — when a
    // budget is configured the block renders the §5 bar meter (the funding /
    // capacity form) with what's left in the caption; without one there is
    // no honest fraction, so it stays a plain money stat (no-fake-data rule).
    const weekCostBlock = (
        <PageHeaderMeterBlock
            label="Week cost"
            tone={overBudget ? 'warning' : 'brand'}
            ariaLabel="Open the spend report"
            onClick={props.onOpenSpend}
        >
            {/* The spend amount stays the block's focal number; the bar
                beneath it tracks the weekly budget. */}
            <PageHeaderMeterBig>
                {formatMoneyFromCents(stats.weekCostCents)}
            </PageHeaderMeterBig>
            {budget != null && budgetSpentPct !== null ? (
                <>
                    <PageHeaderMeterBar percent={budgetSpentPct} />
                    <PageHeaderMeterCaption>
                        {overBudget
                            ? `${formatMoneyFromCents(stats.weekCostCents - budget)} over the ${formatMoneyFromCents(budget)} budget`
                            : `${formatMoneyFromCents(budget - stats.weekCostCents)} left of ${formatMoneyFromCents(budget)}`}
                    </PageHeaderMeterCaption>
                </>
            ) : (
                <PageHeaderMeterCaption>
                    {isHouse ? 'planned this week' : 'on hand'}
                </PageHeaderMeterCaption>
            )}
        </PageHeaderMeterBlock>
    );

    const railItems: PageHeaderRailItem<string>[] = isHouse
        ? [
              { key: 'calendar', label: 'Calendar', icon: CalendarDays },
              { key: 'inventory', label: 'Inventory', icon: Package },
              { key: 'shopping', label: 'Shopping', icon: ShoppingCart },
              { key: 'recipes', label: 'Recipes', icon: ChefHat },
              { key: 'templates', label: 'Templates', icon: LayoutTemplate },
          ]
        : [
              { key: 'inventory', label: 'Inventory', icon: Package },
              { key: 'shopping', label: 'Shopping', icon: ShoppingCart },
              { key: 'recipes', label: 'Recipes', icon: ChefHat },
          ];

    const sublineParts = [
        'Meal planner',
        isHouse ? 'Resident meals' : 'Kitchen supplies',
        isHouse
            ? `${residentCount} ${residentCount === 1 ? 'resident' : 'residents'}`
            : `${stats.itemsTracked} items tracked`,
        site.suburb,
    ].filter(Boolean);

    return (
        <PageHeader
            icon={ChefHat}
            title={site.name}
            titleChip={
                isHouse && stats.unresolved > 0 ? (
                    <PageHeaderStatusChip variant="critical" icon={ShieldAlert}>
                        {stats.unresolved} allergen conflict
                        {stats.unresolved === 1 ? '' : 's'}
                    </PageHeaderStatusChip>
                ) : isHouse ? (
                    stats.mealsPlanned > 0 ? (
                        <PageHeaderStatusChip variant="success">
                            {stats.served}/{stats.mealsPlanned} served
                        </PageHeaderStatusChip>
                    ) : (
                        <PageHeaderStatusChip variant="neutral">
                            No meals planned
                        </PageHeaderStatusChip>
                    )
                ) : (
                    <PageHeaderStatusChip variant="success">
                        Kitchen stocked
                    </PageHeaderStatusChip>
                )
            }
            subline={sublineParts.join(' · ')}
            actions={
                <>
                    {sites.length > 1 ? (
                        <SiteSearch
                            sites={sites}
                            currentSiteId={site.id}
                            onSelect={props.onSelectSite}
                        />
                    ) : null}
                    <HeroBell
                        compact
                        notifications={props.notifications}
                        onClick={props.onNotificationClick}
                    />
                    {props.canShop ? (
                        <PageHeaderGlassButton
                            icon={Settings}
                            aria-label="Meal planner settings"
                            onClick={props.onOpenSettings}
                        />
                    ) : null}
                    {props.canShop ? (
                        <PageHeaderGlassButton
                            icon={ShoppingCart}
                            onClick={props.onBuildList}
                        >
                            Build list
                        </PageHeaderGlassButton>
                    ) : null}
                    {props.canPlan ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={props.onPlan}
                        >
                            {isHouse ? 'Plan a meal' : 'Add a meal'}
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                isHouse ? (
                    <>
                        <PageHeaderMeterBlock
                            label="Plan filled"
                            value={`${stats.mealsPlanned} meals`}
                            ariaLabel="View the week's calendar"
                            onClick={() => props.onTab('calendar')}
                        >
                            <PageHeaderMeterBar percent={stats.fillPct} />
                            <PageHeaderMeterCaption>
                                {stats.fillPct}% of slots · {stats.served}{' '}
                                served
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        {weekCostBlock}
                        <PageHeaderMeterBlock
                            label="Allergen conflicts"
                            tone={stats.unresolved > 0 ? 'critical' : 'success'}
                            ariaLabel="Review allergen conflicts on the calendar"
                            onClick={props.onReviewConflicts}
                        >
                            <PageHeaderMeterBig>
                                {stats.unresolved}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {stats.unresolved > 0
                                    ? 'review before serving'
                                    : 'all clear'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Low stock"
                            tone={
                                stats.outOfStock > 0
                                    ? 'critical'
                                    : stats.lowStock > 0
                                      ? 'warning'
                                      : 'success'
                            }
                            ariaLabel="View inventory"
                            onClick={() => props.onTab('inventory')}
                        >
                            <PageHeaderMeterBig>
                                {stats.lowStock}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {stats.outOfStock} out of stock
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Overrides"
                            tone={stats.overrides > 0 ? 'warning' : 'brand'}
                            ariaLabel="Open the allergen overrides log"
                            onClick={props.onOpenOverrides}
                        >
                            <PageHeaderMeterBig>
                                {stats.overrides}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                allergen overrides logged
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ) : (
                    <>
                        <PageHeaderMeterBlock
                            label="Stock at par"
                            value={`${stats.itemsTracked} items`}
                            ariaLabel="View inventory"
                            onClick={() => props.onTab('inventory')}
                        >
                            <PageHeaderMeterBar percent={stats.fillPct} />
                            <PageHeaderMeterCaption>
                                {stats.fillPct}% at or above par
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        {weekCostBlock}
                        <PageHeaderMeterBlock
                            label="Low stock"
                            tone={
                                stats.outOfStock > 0
                                    ? 'critical'
                                    : stats.lowStock > 0
                                      ? 'warning'
                                      : 'success'
                            }
                            ariaLabel="View inventory"
                            onClick={() => props.onTab('inventory')}
                        >
                            <PageHeaderMeterBig>
                                {stats.lowStock}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {stats.outOfStock} out of stock
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                )
            }
            filters={
                <>
                    <PageHeaderFilterButton
                        icon={ChevronLeft}
                        aria-label="Previous week"
                        onClick={props.onPrevWeek}
                    />
                    <PageHeaderFilterButton
                        ref={weekBtnRef}
                        icon={CalendarRange}
                        aria-haspopup="dialog"
                        aria-expanded={weekPickerOpen}
                        onClick={() => setWeekPickerOpen((v) => !v)}
                        className="tnum"
                    >
                        {weekLabel} · {rangeStart} → {rangeEnd}
                        <ChevronDown className="size-3 opacity-70" />
                    </PageHeaderFilterButton>
                    <PageHeaderFilterButton
                        icon={ChevronRight}
                        aria-label="Next week"
                        onClick={props.onNextWeek}
                    />
                    {!isThisWeek ? (
                        <PageHeaderFilterButton onClick={props.onThisWeek}>
                            Today
                        </PageHeaderFilterButton>
                    ) : null}
                    {weekPickerOpen ? (
                        <WeekPicker
                            selectedWeekStart={props.weekStart}
                            anchorRef={weekBtnRef}
                            showContextMenu={false}
                            onSelect={(d) => {
                                props.onSelectWeek(d);
                                setWeekPickerOpen(false);
                            }}
                            onClose={() => setWeekPickerOpen(false)}
                        />
                    ) : null}
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={props.tab}
                    onSelect={props.onTab}
                    ariaLabel="Meal planner sections"
                />
            }
        />
    );
}

/* ── Compact toolbar (embedded in the Site profile — no green banner) ───── */
export type MealPlannerToolbarProps = {
    weekLabel: string;
    rangeStart: string;
    rangeEnd: string;
    isThisWeek: boolean;
    isHouse: boolean;
    stats: HeroStats;
    notifications: HeroNotification[];
    canPlan: boolean;
    canShop: boolean;
    /** Monday-start Date of the displayed week — drives the embedded week-picker. */
    weekStart: Date;
    onSelectWeek: (weekStart: Date) => void;
    onPlan: () => void;
    onBuildList: () => void;
    onOpenSettings: () => void;
    onPrevWeek: () => void;
    onNextWeek: () => void;
    onThisWeek: () => void;
    onReviewConflicts: () => void;
    onNotificationClick: (tab: string) => void;
    /** Opens the spend report (P2-6) — when omitted the week-cost KPI is static. */
    onOpenSpend?: () => void;
    /** Opens the allergen-overrides rollup (P2-5). */
    onOpenOverrides?: () => void;
    /** True while a per-week reload is in flight — shows an "Updating…" cue. */
    reloading?: boolean;
};

function KpiChip({
    label,
    value,
    onClick,
}: {
    label: string;
    value: ReactNode;
    onClick?: () => void;
}) {
    const inner = (
        <>
            <span className="text-[15px] leading-none font-bold text-foreground tabular-nums">
                {value}
            </span>
            <span className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
        </>
    );
    if (onClick) {
        return (
            <GuardrailButton
                unstyled
                type="button"
                onClick={onClick}
                className="flex shrink-0 flex-col items-start rounded-md px-1 text-left whitespace-nowrap transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {inner}
            </GuardrailButton>
        );
    }
    return (
        <div className="flex shrink-0 flex-col whitespace-nowrap">{inner}</div>
    );
}

export function MealPlannerToolbar(props: MealPlannerToolbarProps) {
    const { isHouse, stats } = props;
    const weekBtnRef = useRef<HTMLButtonElement>(null);
    const [weekPickerOpen, setWeekPickerOpen] = useState(false);

    const actions = (
        <div className="flex min-w-0 flex-wrap items-center gap-2">
            {props.canPlan && (
                <GuardrailButton
                    unstyled
                    type="button"
                    onClick={props.onPlan}
                    className="inline-flex h-9 items-center justify-center gap-1.5 rounded-md bg-sites px-3 text-sm font-semibold text-primary-foreground shadow-sm transition hover:opacity-90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <Plus className="h-4 w-4" strokeWidth={2.5} />{' '}
                    {isHouse ? 'Plan a meal' : 'Add a meal'}
                </GuardrailButton>
            )}
            {props.canShop && (
                <GuardrailButton
                    unstyled
                    type="button"
                    onClick={props.onBuildList}
                    className="inline-flex h-9 items-center justify-center gap-1.5 rounded-md border border-border bg-card px-3 text-sm font-semibold text-foreground transition hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <ShoppingCart className="h-4 w-4" /> Build list
                </GuardrailButton>
            )}
            <HeroBell
                light
                compact
                notifications={props.notifications}
                onClick={props.onNotificationClick}
            />
            {props.canShop && (
                <GuardrailButton
                    unstyled
                    type="button"
                    onClick={props.onOpenSettings}
                    aria-label="Meal planner settings"
                    className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border bg-card text-muted-foreground transition hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <Settings className="h-[17px] w-[17px]" />
                </GuardrailButton>
            )}
        </div>
    );

    const weekNav = (
        <div className="flex flex-wrap items-center gap-1.5">
            <GuardrailButton
                unstyled
                type="button"
                onClick={props.onPrevWeek}
                aria-label="Previous week"
                className="inline-flex items-center gap-1 rounded-md border border-border bg-card px-2.5 py-1.5 text-[12px] font-semibold text-muted-foreground transition hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <ChevronLeft className="h-3.5 w-3.5" /> Prev
            </GuardrailButton>
            <GuardrailButton
                unstyled
                ref={weekBtnRef}
                type="button"
                onClick={() => setWeekPickerOpen((v) => !v)}
                aria-haspopup="dialog"
                aria-expanded={weekPickerOpen}
                className={cn(
                    'inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-[12px] font-semibold transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    props.isThisWeek
                        ? 'border-sites bg-sites-bg text-sites-deep'
                        : 'border-border bg-card text-foreground hover:bg-accent',
                )}
            >
                <CalendarRange className="h-3.5 w-3.5" /> {props.weekLabel} ·{' '}
                {props.rangeStart} → {props.rangeEnd}
                <ChevronsUpDown className="ml-0.5 h-3 w-3 opacity-70" />
            </GuardrailButton>
            {weekPickerOpen && (
                <WeekPicker
                    selectedWeekStart={props.weekStart}
                    anchorRef={weekBtnRef}
                    onSelect={(d) => props.onSelectWeek(d)}
                    onClose={() => setWeekPickerOpen(false)}
                    showContextMenu={false}
                />
            )}
            <GuardrailButton
                unstyled
                type="button"
                onClick={props.onNextWeek}
                aria-label="Next week"
                className="inline-flex items-center gap-1 rounded-md border border-border bg-card px-2.5 py-1.5 text-[12px] font-semibold text-muted-foreground transition hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                Next <ChevronRight className="h-3.5 w-3.5" />
            </GuardrailButton>
            {!props.isThisWeek && (
                <GuardrailButton
                    unstyled
                    type="button"
                    onClick={props.onThisWeek}
                    className="inline-flex items-center gap-1 rounded-md border border-border bg-card px-2.5 py-1.5 text-[12px] font-semibold text-muted-foreground transition hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Today
                </GuardrailButton>
            )}
            {props.reloading && (
                <span
                    role="status"
                    className="inline-flex items-center gap-1 text-[11px] font-medium text-muted-foreground"
                >
                    <Loader2
                        className="h-3.5 w-3.5 animate-spin"
                        aria-hidden="true"
                    />{' '}
                    Updating…
                </span>
            )}
        </div>
    );

    const kpis = (
        <div className="nice-scroll -mx-1 flex items-center gap-x-5 overflow-x-auto px-1 md:mx-0 md:overflow-x-visible">
            <KpiChip
                label={isHouse ? 'Meals' : 'Items'}
                value={isHouse ? stats.mealsPlanned : stats.itemsTracked}
            />
            <KpiChip
                label="Week cost"
                value={formatMoneyFromCents(stats.weekCostCents)}
                onClick={props.onOpenSpend}
            />
            <KpiChip label="Low stock" value={stats.lowStock} />
            <KpiChip
                label={isHouse ? 'Plan filled' : 'At par'}
                value={`${stats.fillPct}%`}
            />
        </div>
    );

    return (
        <div className="space-y-2.5">
            <GuardrailCard
                unstyled
                className="rounded-xl border border-border bg-card p-3 shadow-sm"
            >
                {/* md+: week-nav · KPIs · actions on one row. Narrow: week-nav+actions row, KPIs scroll below. */}
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between md:contents">
                        {weekNav}
                        <div className="md:hidden">{actions}</div>
                    </div>
                    {kpis}
                    <div className="hidden md:block">{actions}</div>
                </div>
            </GuardrailCard>

            {isHouse && stats.unresolved > 0 && (
                <div
                    role="alert"
                    aria-live="assertive"
                    className="flex items-center gap-2.5 rounded-lg border border-status-critical/30 bg-status-critical-bg/60 px-3 py-2"
                >
                    <ShieldAlert
                        className="h-4 w-4 shrink-0 text-status-critical"
                        aria-hidden="true"
                    />
                    <span className="flex-1 text-[12.5px] font-medium text-status-critical">
                        {stats.unresolved} planned meal
                        {stats.unresolved === 1 ? '' : 's'} contain
                        {stats.unresolved === 1 ? 's' : ''} allergens for
                        current residents
                    </span>
                    <GuardrailButton
                        unstyled
                        type="button"
                        onClick={props.onReviewConflicts}
                        aria-label={`Review ${stats.unresolved} allergen conflict${stats.unresolved === 1 ? '' : 's'} on the calendar`}
                        className="shrink-0 rounded-md bg-status-critical px-2.5 py-1 text-[12px] font-semibold text-white transition hover:opacity-90"
                    >
                        Review
                    </GuardrailButton>
                </div>
            )}

            {isHouse &&
                (stats.textureEntries > 0 ||
                    stats.softWarnings > 0 ||
                    stats.textureModified > 0 ||
                    stats.overrides > 0) && (
                    <div className="flex flex-wrap items-center gap-2 text-[11.5px]">
                        {stats.overrides > 0 && (
                            <GuardrailButton
                                unstyled
                                type="button"
                                onClick={props.onOpenOverrides}
                                className="inline-flex items-center gap-1 rounded-full border border-amberx/40 bg-amberx-bg/50 px-2 py-0.5 font-medium text-amberx transition-colors hover:bg-amberx-bg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <ShieldAlert
                                    className="h-3 w-3"
                                    aria-hidden="true"
                                />{' '}
                                {stats.overrides} override
                                {stats.overrides === 1 ? '' : 's'}
                            </GuardrailButton>
                        )}
                        {stats.textureEntries > 0 && (
                            <GuardrailButton
                                unstyled
                                type="button"
                                onClick={props.onReviewConflicts}
                                className="inline-flex items-center gap-1 rounded-full border border-status-warning/40 bg-status-warning-bg/50 px-2 py-0.5 font-medium text-status-warning transition-colors hover:bg-status-warning-bg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <Soup className="h-3 w-3" aria-hidden="true" />{' '}
                                {stats.textureEntries} texture check
                                {stats.textureEntries === 1 ? '' : 's'}
                            </GuardrailButton>
                        )}
                        {stats.softWarnings > 0 && (
                            <GuardrailButton
                                unstyled
                                type="button"
                                onClick={props.onReviewConflicts}
                                className="inline-flex items-center gap-1 rounded-full border border-status-warning/40 bg-status-warning-bg/50 px-2 py-0.5 font-medium text-status-warning transition-colors hover:bg-status-warning-bg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <TriangleAlert
                                    className="h-3 w-3"
                                    aria-hidden="true"
                                />{' '}
                                {stats.softWarnings} soft warning
                                {stats.softWarnings === 1 ? '' : 's'}
                            </GuardrailButton>
                        )}
                        {stats.textureModified > 0 && (
                            <span className="inline-flex items-center gap-1 rounded-full border border-border bg-card px-2 py-0.5 font-medium text-muted-foreground">
                                <Soup className="h-3 w-3" aria-hidden="true" />{' '}
                                {stats.textureModified} on texture-modified diet
                            </span>
                        )}
                    </div>
                )}
        </div>
    );
}
