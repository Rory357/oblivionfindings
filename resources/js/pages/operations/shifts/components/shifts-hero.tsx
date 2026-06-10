import { useMemo, useRef, useState } from 'react';
import {
    AlertTriangle,
    Building,
    CalendarDays,
    CalendarRange,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock,
    Download,
    FileText,
    Filter,
    Plus,
    Search,
    Users,
    X,
} from 'lucide-react';

import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { PageHero, type PageHeroBadge } from '@/components/page';
import { Button } from '@/components/ui/button';
import { MultiEntityFilter } from '@/components/rostering/multi-entity-filter';
import {
    WeekPicker,
    weekLabel as isoWeekLabel,
} from '@/components/rostering/week-picker';
import { cn } from '@/lib/utils';

export type HeroStats = {
    total: number;
    open: number;
    today: number;
    in_progress: number;
    hours: number;
    sites: number;
    staff: number;
    unassigned: number;
};

export type HeroFilters = {
    statuses: string[];
    site_ids: number[];
    user_ids: number[];
    client_ids: number[];
    q: string;
};

type StatusOption = { value: string; label: string };
type EntityItem = { id: number; name: string; description?: string | null };

type Props = {
    greetingName?: string;
    weekLabel: string;
    /** Monday of the week currently being viewed — used to seed the WeekPicker. */
    weekStart: Date;
    stats: HeroStats;
    filters: HeroFilters;
    onChangeFilter: <K extends keyof HeroFilters>(
        key: K,
        value: HeroFilters[K],
    ) => void;
    statusOptions: StatusOption[];
    siteItems: EntityItem[];
    staffItems: EntityItem[];
    clientItems: EntityItem[];
    onCreate?: () => void;
    /** Called when the user picks a week from the calendar popover or clicks "This week". */
    onPickWeek: (weekStart: Date) => void;
    onPrevWeek: () => void;
    onNextWeek: () => void;
    onExport?: () => void;
    canCreate?: boolean;
};

export function ShiftsHero({
    greetingName,
    weekLabel,
    weekStart,
    stats,
    filters,
    onChangeFilter,
    statusOptions,
    siteItems,
    staffItems,
    clientItems,
    onCreate,
    onPrevWeek,
    onNextWeek,
    onPickWeek,
    onExport,
    canCreate = true,
}: Props) {
    const weekBtnRef = useRef<HTMLButtonElement | null>(null);
    const [pickerOpen, setPickerOpen] = useState(false);
    const pickerLabel = `${isoWeekLabel(weekStart)} · ${weekLabel}`;

    const draftCount = Math.max(0, stats.unassigned - stats.open);
    const badges: PageHeroBadge[] = [
        stats.open > 0
            ? {
                  icon: AlertTriangle,
                  label: `${stats.open} open`,
                  tone: 'warning' as const,
              }
            : {
                  icon: CheckCircle2,
                  label: 'Week covered',
                  tone: 'success' as const,
              },
        ...(draftCount > 0
            ? [
                  {
                      icon: FileText,
                      label: `${draftCount} unassigned draft${draftCount === 1 ? '' : 's'}`,
                      tone: 'default' as const,
                  },
              ]
            : []),
    ];

    return (
        <>
            <PageHero
                category="ops"
                icon={CalendarDays}
                title={
                    <span>
                        <span className="mb-2 flex items-center gap-2 text-[10.5px] font-semibold tracking-wider text-primary-foreground/80 uppercase">
                            <span
                                aria-hidden="true"
                                className="relative inline-flex h-2 w-2"
                            >
                                <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-status-success/70" />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success ring-2 ring-status-success/30" />
                            </span>
                            Live shifts · refreshed just now
                        </span>
                        <span className="block">
                            <span className="font-normal text-primary-foreground/80">
                                {greetingName
                                    ? `Kia ora ${greetingName}, your shifts —`
                                    : 'Your shifts —'}
                            </span>{' '}
                            <span className="border-b-2 border-primary-foreground/40 pb-0.5">
                                {weekLabel}
                            </span>
                        </span>
                    </span>
                }
                description={
                    <span>
                        {stats.total} shift{stats.total === 1 ? '' : 's'} across{' '}
                        {stats.sites} site{stats.sites === 1 ? '' : 's'}.{' '}
                        {stats.open > 0 ? (
                            <>
                                {stats.open} need cover
                                {draftCount > 0
                                    ? `, ${draftCount} unassigned drafts`
                                    : ''}
                                .
                            </>
                        ) : (
                            'No open shifts — week is fully covered.'
                        )}
                    </span>
                }
                meta={[
                    { icon: CalendarDays, label: weekLabel },
                    {
                        icon: Building,
                        label: `${stats.sites} site${stats.sites === 1 ? '' : 's'}`,
                    },
                    { icon: Users, label: `${stats.staff} staff rostered` },
                    { icon: Clock, label: `${stats.hours}h scheduled` },
                ]}
                badges={badges}
                stats={[
                    { label: 'Total', value: stats.total },
                    {
                        label: 'Open',
                        value: stats.open,
                        tone: stats.open > 0 ? 'warning' : undefined,
                    },
                    { label: 'Today', value: stats.today },
                    { label: 'On now', value: stats.in_progress },
                ]}
                actions={
                    <>
                        {onExport ? (
                            <Button
                                size="sm"
                                variant="outline"
                                className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
                                onClick={onExport}
                            >
                                <Download className="mr-1 h-3.5 w-3.5" /> Export
                            </Button>
                        ) : null}
                        {canCreate ? (
                            <Button
                                size="sm"
                                className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                onClick={onCreate}
                            >
                                <Plus className="mr-1 h-4 w-4" /> Create shift
                            </Button>
                        ) : null}
                    </>
                }
                footer={
                    <div className="flex flex-col gap-2 py-3 md:flex-row md:items-center md:justify-between">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <HeroPillBtn onClick={onPrevWeek}>
                                <ChevronLeft className="h-3.5 w-3.5" /> Prev
                                week
                            </HeroPillBtn>
                            <HeroPillBtn
                                buttonRef={weekBtnRef}
                                onClick={() => setPickerOpen((v) => !v)}
                                solid
                                ariaHasPopup="dialog"
                                ariaExpanded={pickerOpen}
                            >
                                <CalendarRange className="h-3.5 w-3.5" />{' '}
                                {pickerLabel} · pick week
                                <ChevronDown className="h-3 w-3" />
                            </HeroPillBtn>
                            <HeroPillBtn onClick={onNextWeek}>
                                Next week{' '}
                                <ChevronRight className="h-3.5 w-3.5" />
                            </HeroPillBtn>
                        </div>
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            <HeroSearchBox
                                value={filters.q}
                                onChange={(v) => onChangeFilter('q', v)}
                            />
                            <StatusChip
                                value={filters.statuses}
                                options={statusOptions}
                                onChange={(next) =>
                                    onChangeFilter('statuses', next)
                                }
                            />
                            <MultiEntityFilter
                                onDark
                                label="Staff"
                                pluralLabel="staff"
                                allLabel="All staff"
                                items={staffItems}
                                value={filters.user_ids}
                                onChange={(next) =>
                                    onChangeFilter('user_ids', next)
                                }
                            />
                            <MultiEntityFilter
                                onDark
                                label="Client"
                                allLabel="All clients"
                                items={clientItems}
                                value={filters.client_ids}
                                onChange={(next) =>
                                    onChangeFilter('client_ids', next)
                                }
                            />
                            <MultiEntityFilter
                                onDark
                                label="Site"
                                allLabel="All sites"
                                items={siteItems}
                                value={filters.site_ids}
                                onChange={(next) =>
                                    onChangeFilter('site_ids', next)
                                }
                            />
                        </div>
                    </div>
                }
            />

            {pickerOpen ? (
                <WeekPicker
                    selectedWeekStart={weekStart}
                    anchorRef={weekBtnRef}
                    onSelect={(next) => {
                        onPickWeek(next);
                    }}
                    onClose={() => setPickerOpen(false)}
                />
            ) : null}
        </>
    );
}

function HeroPillBtn({
    children,
    onClick,
    solid,
    buttonRef,
    ariaHasPopup,
    ariaExpanded,
}: {
    children: React.ReactNode;
    onClick?: () => void;
    solid?: boolean;
    buttonRef?: React.RefObject<HTMLButtonElement | null>;
    ariaHasPopup?: 'dialog' | 'menu' | 'listbox';
    ariaExpanded?: boolean;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- segmented week-stepper on dark hero; not a shadcn Button.
        <button
            ref={buttonRef}
            type="button"
            onClick={onClick}
            aria-haspopup={ariaHasPopup}
            aria-expanded={ariaExpanded}
            className={[
                'inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-1.5 text-xs font-semibold transition',
                solid
                    ? 'border border-primary-foreground/35 bg-primary-foreground/20 text-primary-foreground hover:bg-primary-foreground/30'
                    : 'border border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
            ].join(' ')}
        >
            {children}
        </button>
    );
}

function HeroSearchBox({
    value,
    onChange,
}: {
    value: string;
    onChange: (v: string) => void;
}) {
    return (
        <div className="relative">
            <Search className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
            <input
                value={value || ''}
                onChange={(e) => onChange(e.target.value)}
                placeholder="Search clients, staff, location"
                className="w-56 rounded-full border border-primary-foreground/30 bg-primary-foreground/10 py-1.5 pl-8 pr-3 text-xs font-semibold text-primary-foreground placeholder:font-normal placeholder:text-primary-foreground/60 focus:bg-primary-foreground/20 focus:outline-none"
            />
        </div>
    );
}

function StatusChip({
    value,
    options,
    onChange,
}: {
    value: string[];
    options: StatusOption[];
    onChange: (next: string[]) => void;
}) {
    const [open, setOpen] = useState(false);
    const selectedSet = useMemo(() => new Set(value), [value]);
    const selectedCount = value.length;
    const allSelected = selectedCount === 0;

    const triggerLabel = useMemo(() => {
        if (allSelected) return `All statuses · ${options.length}`;
        if (selectedCount === 1) {
            const found = options.find((o) => o.value === value[0]);
            return found ? found.label : '1 status';
        }
        return `${selectedCount} statuses`;
    }, [allSelected, options, selectedCount, value]);

    const toggle = (v: string) => {
        if (selectedSet.has(v)) {
            onChange(value.filter((x) => x !== v));
        } else {
            onChange([...value, v]);
        }
    };
    const clearAll = () => onChange([]);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverAnchor asChild>
                {/* The pill is a wrapper, not a button, so the clear action can
                    sit as a sibling of the trigger rather than nested inside it
                    (nested <button>s are invalid HTML and break hydration). */}
                <div
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                        allSelected
                            ? 'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20'
                            : 'border-primary-foreground bg-primary-foreground text-primary',
                    )}
                >
                    <PopoverTrigger asChild>
                        <button
                            type="button"
                            aria-haspopup="listbox"
                            aria-expanded={open}
                            className="inline-flex items-center gap-1.5 rounded-full"
                        >
                            <Filter className="h-3.5 w-3.5" aria-hidden="true" />
                            <span className="max-w-[200px] truncate">
                                {triggerLabel}
                            </span>
                            {allSelected ? (
                                <ChevronDown className="h-3 w-3 opacity-70" />
                            ) : null}
                        </button>
                    </PopoverTrigger>
                    {!allSelected ? (
                        // eslint-disable-next-line no-restricted-syntax -- tiny clear affordance nested beside the popover trigger; a shadcn Button would nest invalid markup.
                        <button
                            type="button"
                            aria-label="Clear status filter"
                            className="inline-flex h-4 w-4 items-center justify-center rounded-full hover:bg-primary/30"
                            onClick={clearAll}
                        >
                            <X className="h-3 w-3" />
                        </button>
                    ) : null}
                </div>
            </PopoverAnchor>
            <PopoverContent
                align="end"
                className="w-[240px] p-0"
                sideOffset={6}
            >
                <Command>
                    <CommandList>
                        <CommandEmpty>No statuses match.</CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                value="__all__ all statuses"
                                onSelect={clearAll}
                                className="flex items-center gap-2"
                            >
                                <span
                                    className={cn(
                                        'flex h-4 w-4 shrink-0 items-center justify-center rounded-sm border',
                                        allSelected
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-input bg-background',
                                    )}
                                    aria-hidden="true"
                                >
                                    {allSelected ? (
                                        <Check className="h-3 w-3" />
                                    ) : null}
                                </span>
                                <span className="flex-1 font-medium">
                                    All statuses
                                </span>
                                <span className="text-[10px] tabular-nums text-muted-foreground">
                                    {options.length}
                                </span>
                            </CommandItem>
                        </CommandGroup>
                        <CommandGroup
                            heading={
                                selectedCount > 0
                                    ? `Statuses · ${selectedCount} selected`
                                    : 'Statuses'
                            }
                        >
                            {options.map((o) => {
                                const checked = selectedSet.has(o.value);
                                return (
                                    <CommandItem
                                        key={o.value}
                                        value={o.label}
                                        onSelect={() => toggle(o.value)}
                                        className="flex items-center gap-2"
                                    >
                                        <span
                                            className={cn(
                                                'flex h-4 w-4 shrink-0 items-center justify-center rounded-sm border',
                                                checked
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-input bg-background',
                                            )}
                                            aria-hidden="true"
                                        >
                                            {checked ? (
                                                <Check className="h-3 w-3" />
                                            ) : null}
                                        </span>
                                        <span className="flex-1">{o.label}</span>
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
