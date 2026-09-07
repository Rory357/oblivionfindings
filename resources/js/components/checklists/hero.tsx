/**
 * The Checklists workspace page header — the Event Horizon band
 * (design_styles/PAGE_HEADER_STYLE_GUIDE.md) for both the org-wide
 * /checklists roll-up and a single site's /sites/{site}/checklists page.
 * Scoped search + Start/New template in the top row, the meter row of
 * linked instrument blocks, week/category/scope pills in the filter row
 * and the workspace panes as the connected-tab rail. The site-profile
 * embed uses ChecklistsEmbeddedHeader instead, not this band.
 */
import { router } from '@inertiajs/react';
import {
    Building2,
    CalendarRange,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    LayoutGrid,
    Network,
    PlayCircle,
    Plus,
    Search,
} from 'lucide-react';
import { useRef, useState } from 'react';

import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    type PageHeaderRailItem,
} from '@/components/page';
import { WeekPicker } from '@/components/rostering/week-picker';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

import { useChecklistConfig } from './context';
import type { ChecklistStats, WeekInfo } from './types';

export function ChecklistHero({
    stats,
    siteCount,
    templateCount,
    categoryCount,
    tab,
    onTab,
    railItems,
    week,
    onPrevWeek,
    onNextWeek,
    selectedWeekStart,
    today,
    onJumpToWeek,
    query,
    onQuery,
    cat,
    onCat,
    sites,
    onStart,
    onNewTemplate,
}: {
    stats: ChecklistStats;
    siteCount: number;
    templateCount: number;
    categoryCount: number;
    tab: string;
    onTab: (tab: string) => void;
    railItems: PageHeaderRailItem<string>[];
    week: WeekInfo;
    onPrevWeek: () => void;
    onNextWeek: () => void;
    selectedWeekStart: Date;
    today: Date;
    onJumpToWeek: (weekStart: Date) => void;
    query: string;
    onQuery: (v: string) => void;
    cat: string;
    onCat: (v: string) => void;
    sites: { id: number; name: string; type?: string }[];
    onStart: () => void;
    onNewTemplate: () => void;
}) {
    const { scope, can, categories } = useChecklistConfig();
    const site = scope.mode === 'site' ? scope.site : null;

    const weekBtnRef = useRef<HTMLButtonElement>(null);
    const [pickerOpen, setPickerOpen] = useState(false);

    return (
        <PageHeader
            variant={site ? 'profile' : 'index'}
            icon={ClipboardCheck}
            backHref={scope.mode === 'site' ? scope.backHref : undefined}
            title={site ? site.name : 'Checklists'}
            titleChip={
                stats.overdue > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {stats.overdue} overdue
                    </PageHeaderStatusChip>
                ) : stats.dueToday > 0 ? (
                    <PageHeaderStatusChip variant="warning">
                        {stats.dueToday} due today
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        On track
                    </PageHeaderStatusChip>
                )
            }
            subline={
                site
                    ? `${siteTypeLabel(site.type)} checklists · ${templateCount} templates · ${categoryCount} categories · ${stats.scheduled} scheduled this week`
                    : `Compliance checklists · ${siteCount} ${siteCount === 1 ? 'site' : 'sites'} · ${templateCount} templates · ${categoryCount} categories · ${stats.scheduled} scheduled this week`
            }
            actions={
                <>
                    <PageHeaderSearch
                        value={query}
                        onChange={onQuery}
                        placeholder={
                            site
                                ? `Search ${site.name}…`
                                : 'Search checklists, sites, categories…'
                        }
                    />
                    {can.manageTemplates ? (
                        can.run ? (
                            <PageHeaderGlassButton
                                icon={Plus}
                                onClick={onNewTemplate}
                            >
                                New template
                            </PageHeaderGlassButton>
                        ) : (
                            <PageHeaderPrimaryButton
                                icon={Plus}
                                onClick={onNewTemplate}
                            >
                                New template
                            </PageHeaderPrimaryButton>
                        )
                    ) : null}
                    {can.run ? (
                        <PageHeaderPrimaryButton
                            icon={PlayCircle}
                            onClick={onStart}
                        >
                            Start a checklist
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="On track"
                        ariaLabel="View compliance reports"
                        onClick={() => onTab('reports')}
                    >
                        <PageHeaderMeterDonut
                            percent={stats.onTrack}
                            caption={
                                <>
                                    checklists
                                    <br />
                                    on schedule
                                </>
                            }
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Due today"
                        tone={stats.dueToday > 0 ? 'warning' : 'brand'}
                        ariaLabel="View checklists due now"
                        onClick={() => onTab('due')}
                    >
                        <PageHeaderMeterBig>
                            {stats.dueToday}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            waiting to be run
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue"
                        tone={stats.overdue > 0 ? 'critical' : 'success'}
                        ariaLabel="View overdue checklists"
                        onClick={() => onTab('due')}
                    >
                        <PageHeaderMeterBig>{stats.overdue}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            need clearing
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="In progress"
                        ariaLabel="View part-complete runs"
                        onClick={() => onTab('runs')}
                    >
                        <PageHeaderMeterBig>
                            {stats.inProgress}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            part-complete runs
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Done · 30d"
                        ariaLabel="View completed runs"
                        onClick={() => onTab('runs')}
                    >
                        <PageHeaderMeterBig>
                            {stats.completed30}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            completed, last 30 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Failures"
                        tone={stats.failures > 0 ? 'critical' : 'success'}
                        ariaLabel="View top failures in reports"
                        onClick={() => onTab('reports')}
                    >
                        <PageHeaderMeterBig>
                            {stats.failures}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            failed items → hazards
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterButton
                        icon={ChevronLeft}
                        aria-label={`Go to ${week.prevLabel.toLowerCase()}`}
                        onClick={onPrevWeek}
                    />
                    <PageHeaderFilterButton
                        ref={weekBtnRef}
                        icon={CalendarRange}
                        aria-haspopup="dialog"
                        aria-expanded={pickerOpen}
                        onClick={() => setPickerOpen((v) => !v)}
                        className="tnum"
                    >
                        {week.label} · {week.range}
                        <ChevronDown className="size-3 opacity-70" />
                    </PageHeaderFilterButton>
                    <PageHeaderFilterButton
                        icon={ChevronRight}
                        aria-label={`Go to ${week.nextLabel.toLowerCase()}`}
                        onClick={onNextWeek}
                    />
                    {pickerOpen ? (
                        <WeekPicker
                            selectedWeekStart={selectedWeekStart}
                            anchorRef={weekBtnRef}
                            today={today}
                            showContextMenu={false}
                            onSelect={(weekStart) => {
                                onJumpToWeek(weekStart);
                                setPickerOpen(false);
                            }}
                            onClose={() => setPickerOpen(false)}
                        />
                    ) : null}
                    <PageHeaderFilterSelect
                        icon={LayoutGrid}
                        label="All categories"
                        value={cat}
                        options={[
                            { value: 'all', label: 'All categories' },
                            ...categories.map((c) => ({
                                value: c.key,
                                label: c.label,
                            })),
                        ]}
                        onChange={onCat}
                    />
                    {site ? (
                        <PageHeaderFilterButton
                            icon={Network}
                            onClick={() => router.visit('/checklists')}
                        >
                            Org-wide
                        </PageHeaderFilterButton>
                    ) : sites.length > 0 ? (
                        <SiteJumpPill sites={sites} />
                    ) : null}
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={tab}
                    onSelect={onTab}
                    ariaLabel="Checklist views"
                />
            }
        />
    );
}

/** Searchable "jump to a site's checklists" pill for the org-wide scope —
 *  navigation to real routes, not a client-side toggle. */
function SiteJumpPill({
    sites,
}: {
    sites: { id: number; name: string; type?: string }[];
}) {
    const { typeLabels } = useChecklistConfig();
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const list = sites.filter((s) =>
        s.name.toLowerCase().includes(q.trim().toLowerCase()),
    );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <PageHeaderFilterButton icon={Building2}>
                    Jump to a site
                    <ChevronDown className="size-3 opacity-70" />
                </PageHeaderFilterButton>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-[280px] p-0">
                <div className="border-b p-2">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            autoFocus
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder={`Search ${sites.length} sites…`}
                            className="h-9 w-full rounded-md border bg-background pr-2 pl-8 text-[13px] outline-none focus:ring-2 focus:ring-ring"
                        />
                    </div>
                </div>
                <div className="max-h-[300px] overflow-y-auto p-1.5">
                    {list.length === 0 ? (
                        <p className="px-2.5 py-3 text-center text-[13px] text-muted-foreground">
                            No sites match.
                        </p>
                    ) : (
                        list.map((s) => (
                            <Button
                                unstyled
                                key={s.id}
                                type="button"
                                onClick={() => {
                                    setOpen(false);
                                    router.visit(`/sites/${s.id}/checklists`);
                                }}
                                className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors hover:bg-accent/60"
                            >
                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                    <Building2 className="h-4 w-4" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[13.5px] font-semibold">
                                        {s.name}
                                    </span>
                                    <span className="block truncate text-[11.5px] text-muted-foreground">
                                        {typeLabels[s.type ?? ''] ??
                                            s.type ??
                                            'Site'}
                                    </span>
                                </span>
                            </Button>
                        ))
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}

function siteTypeLabel(type?: string): string {
    if (type === 'house') return 'House';
    if (type === 'head_office') return 'Head Office';
    if (type === 'facility') return 'Facility';
    return 'Site';
}
