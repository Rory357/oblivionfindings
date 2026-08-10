import { Link, router } from '@inertiajs/react';
import {
    Building2,
    CalendarRange,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    LayoutGrid,
    Network,
    Search,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';

import { WeekPicker } from '@/components/rostering/week-picker';
import { cn } from '@/lib/utils';

import { Button as GuardrailButton } from '@/components/ui/button';
import { catColorVar } from './category';
import { useChecklistConfig } from './context';
import { Dropdown, type DropdownOption } from './primitives';

export interface WeekInfo {
    label: string;
    range: string;
    prevLabel: string;
    nextLabel: string;
}

const onDarkInput =
    'h-9 rounded-md border border-primary-foreground/25 bg-primary-foreground/10 text-sm text-primary-foreground outline-none transition-colors placeholder:text-primary-foreground/55 focus:border-primary-foreground/50 focus:bg-primary-foreground/15';

export function HeroFooter({
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
}: {
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
}) {
    const { categories, scope, typeLabels } = useChecklistConfig();
    const site = scope.mode === 'site' ? scope.site : null;
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

    const siteOptions: DropdownOption[] = sites.map((s) => ({
        value: String(s.id),
        label: s.name,
        sub: typeLabels[s.type ?? ''] ?? s.type,
        Icon: Building2,
    }));

    const orgActive = scope.mode === 'org';
    const segBase =
        'inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1.5 text-xs font-semibold transition-colors';

    return (
        <div className="flex flex-col items-stretch gap-2.5 py-3">
            <div className="flex flex-col items-stretch gap-2.5 md:flex-row md:items-center md:justify-between">
                {/* Week stepper */}
                <div className="flex flex-wrap items-center gap-1.5">
                    <GuardrailButton
                        unstyled
                        type="button"
                        onClick={onPrevWeek}
                        className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                    >
                        <ChevronLeft className="h-3.5 w-3.5" />
                        <span className="hidden sm:inline">
                            {week.prevLabel}
                        </span>
                    </GuardrailButton>
                    <GuardrailButton
                        unstyled
                        ref={weekBtnRef}
                        type="button"
                        onClick={() => setPickerOpen((v) => !v)}
                        aria-haspopup="dialog"
                        aria-expanded={pickerOpen}
                        className="inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/35 bg-primary-foreground/20 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/30"
                    >
                        <CalendarRange className="h-3.5 w-3.5" />
                        <span>
                            {week.label}
                            <span className="text-primary-foreground/60">
                                {' '}
                                · {week.range}
                            </span>
                        </span>
                        <ChevronDown className="h-3 w-3 text-primary-foreground/70" />
                    </GuardrailButton>
                    <GuardrailButton
                        unstyled
                        type="button"
                        onClick={onNextWeek}
                        className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                    >
                        <span className="hidden sm:inline">
                            {week.nextLabel}
                        </span>
                        <ChevronRight className="h-3.5 w-3.5" />
                    </GuardrailButton>
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

                {/* Search + category + scope */}
                <div className="flex flex-wrap items-center gap-2 md:justify-end">
                    <div className="relative flex-1 md:flex-none">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                        <input
                            value={query}
                            onChange={(e) => onQuery(e.target.value)}
                            placeholder={
                                site
                                    ? `Search ${site.name}…`
                                    : 'Search checklists, sites, categories…'
                            }
                            className={cn(
                                onDarkInput,
                                'w-full pr-8 pl-8 md:w-56',
                            )}
                        />
                        {query ? (
                            <GuardrailButton
                                unstyled
                                type="button"
                                onClick={() => onQuery('')}
                                className="absolute top-1/2 right-2 -translate-y-1/2 text-primary-foreground/60 hover:text-primary-foreground"
                            >
                                <X className="h-3.5 w-3.5" />
                            </GuardrailButton>
                        ) : null}
                    </div>

                    <Dropdown
                        dark
                        Icon={LayoutGrid}
                        value={cat}
                        onChange={onCat}
                        align="right"
                        className="w-44"
                        menuWidth={240}
                        options={catOptions}
                    />

                    <span className="hidden h-5 w-px bg-primary-foreground/20 sm:block" />

                    {/* Scope: real routes, not a client toggle */}
                    <div className="flex items-center rounded-lg border border-primary-foreground/25 bg-primary-foreground/10 p-0.5">
                        <Link
                            href="/checklists"
                            className={cn(
                                segBase,
                                orgActive
                                    ? 'bg-primary-foreground shadow-sm'
                                    : 'text-primary-foreground/75 hover:text-primary-foreground',
                            )}
                            style={
                                orgActive
                                    ? { color: 'var(--category-ops)' }
                                    : undefined
                            }
                        >
                            <Network className="h-3.5 w-3.5" />
                            Org-wide
                        </Link>
                        {site ? (
                            <span
                                className={cn(
                                    segBase,
                                    'bg-primary-foreground shadow-sm',
                                )}
                                style={{ color: 'var(--category-ops)' }}
                            >
                                <Building2 className="h-3.5 w-3.5" />
                                <span className="max-w-[120px] truncate">
                                    {site.name}
                                </span>
                            </span>
                        ) : null}
                    </div>

                    {scope.mode === 'org' && siteOptions.length > 0 ? (
                        <Dropdown
                            dark
                            Icon={Building2}
                            searchable
                            value=""
                            placeholder="Jump to a site…"
                            onChange={(v) =>
                                router.visit(`/sites/${v}/checklists`)
                            }
                            align="right"
                            className="w-44"
                            menuWidth={260}
                            options={siteOptions}
                        />
                    ) : null}
                </div>
            </div>
        </div>
    );
}
