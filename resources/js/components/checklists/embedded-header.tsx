import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    CalendarRange,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    LayoutGrid,
    Plus,
    PlayCircle,
} from 'lucide-react';
import { useRef, useState } from 'react';

import { WeekPicker } from '@/components/rostering/week-picker';
import { Button } from '@/components/ui/button';

import { catColorVar } from './category';
import { useChecklistConfig } from './context';
import type { WeekInfo } from './hero-footer';
import { Dropdown, SearchInput, StatusBadge, type DropdownOption } from './primitives';
import type { ChecklistStats, SiteRef } from './types';

export function ChecklistsEmbeddedHeader({
    stats,
    site,
    fullHref,
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
    onStart,
    onNewTemplate,
}: {
    stats: ChecklistStats;
    site: SiteRef | null;
    fullHref: string;
    week: WeekInfo;
    onPrevWeek: () => void;
    onNextWeek: () => void;
    selectedWeekStart: Date;
    today: Date;
    onJumpToWeek: (weekStart: Date) => void;
    query: string;
    onQuery: (value: string) => void;
    cat: string;
    onCat: (value: string) => void;
    onStart: () => void;
    onNewTemplate: () => void;
}) {
    const { categories, can } = useChecklistConfig();
    const weekBtnRef = useRef<HTMLButtonElement>(null);
    const [pickerOpen, setPickerOpen] = useState(false);

    const catOptions: DropdownOption[] = [
        { value: 'all', label: 'All categories' },
        ...categories.map((c) => ({
            value: c.key,
            label: c.label,
            dot: catColorVar(c.tone),
        })),
    ];

    return (
        <div className="rounded-xl border bg-card p-3">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <ClipboardCheck className="h-5 w-5" />
                    </span>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-base font-semibold">Checklists</h3>
                            <span className="text-sm text-muted-foreground">
                                {site?.name ?? 'All sites'}
                            </span>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            <StatusBadge tone={stats.onTrack >= 90 ? 'success' : 'warning'}>
                                On-track {stats.onTrack}%
                            </StatusBadge>
                            <StatusBadge tone={stats.dueToday > 0 ? 'warning' : 'neutral'}>
                                Due today {stats.dueToday}
                            </StatusBadge>
                            <StatusBadge tone={stats.overdue > 0 ? 'critical' : 'neutral'}>
                                Overdue {stats.overdue}
                            </StatusBadge>
                        </div>
                    </div>
                </div>

                <div className="flex flex-wrap gap-2 lg:justify-end">
                    <Button asChild variant="outline" size="sm">
                        <Link href={fullHref}>
                            Open full checklists page
                            <ArrowUpRight className="h-4 w-4" />
                        </Link>
                    </Button>
                    {can.run ? (
                        <Button type="button" size="sm" onClick={onStart}>
                            <PlayCircle className="h-4 w-4" />
                            Start a checklist
                        </Button>
                    ) : null}
                    {can.manageTemplates ? (
                        <Button type="button" size="sm" variant="secondary" onClick={onNewTemplate}>
                            <Plus className="h-4 w-4" />
                            New template
                        </Button>
                    ) : null}
                </div>
            </div>

            <div className="mt-3 flex flex-col gap-2 border-t pt-3 md:flex-row md:items-center md:justify-between">
                <div className="flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        onClick={onPrevWeek}
                        className="inline-flex h-9 items-center gap-1 rounded-md border bg-card px-2.5 text-xs font-semibold text-foreground transition-colors hover:bg-accent/50"
                    >
                        <ChevronLeft className="h-3.5 w-3.5" />
                        <span className="hidden sm:inline">{week.prevLabel}</span>
                    </button>
                    <button
                        ref={weekBtnRef}
                        type="button"
                        onClick={() => setPickerOpen((value) => !value)}
                        aria-haspopup="dialog"
                        aria-expanded={pickerOpen}
                        className="inline-flex h-9 items-center gap-1.5 rounded-md border bg-card px-3 text-xs font-semibold text-foreground transition-colors hover:bg-accent/50"
                    >
                        <CalendarRange className="h-3.5 w-3.5" />
                        <span>
                            {week.label}
                            <span className="text-muted-foreground"> · {week.range}</span>
                        </span>
                        <ChevronDown className="h-3 w-3 text-muted-foreground" />
                    </button>
                    <button
                        type="button"
                        onClick={onNextWeek}
                        className="inline-flex h-9 items-center gap-1 rounded-md border bg-card px-2.5 text-xs font-semibold text-foreground transition-colors hover:bg-accent/50"
                    >
                        <span className="hidden sm:inline">{week.nextLabel}</span>
                        <ChevronRight className="h-3.5 w-3.5" />
                    </button>
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
                </div>

                <div className="flex flex-wrap items-center gap-2 md:justify-end">
                    <SearchInput
                        value={query}
                        onChange={onQuery}
                        placeholder={site ? `Search ${site.name}...` : 'Search checklists...'}
                        className="min-w-0 flex-1 md:w-56 md:flex-none"
                    />
                    <Dropdown
                        Icon={LayoutGrid}
                        value={cat}
                        onChange={onCat}
                        align="right"
                        className="w-44"
                        menuWidth={240}
                        options={catOptions}
                    />
                </div>
            </div>
        </div>
    );
}
