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
    Filter,
    MoreHorizontal,
    Plus,
    Search,
    Users,
    X,
    Zap,
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
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { MultiEntityFilter } from '@/components/rostering/multi-entity-filter';
import { WeekPicker } from '@/components/rostering/week-picker';
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
    return (
        <div
            className="relative overflow-hidden rounded-2xl text-primary-foreground"
            style={{
                background:
                    'linear-gradient(135deg, color-mix(in oklch, var(--primary) 92%, transparent), var(--primary) 52%, oklch(from var(--primary) calc(l - 0.08) c h))',
            }}
        >
            <div className="pointer-events-none absolute inset-0">
                <div className="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-primary-foreground/5" />
                <div className="absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-primary-foreground/5" />
                <div className="absolute right-1/3 top-1/4 h-24 w-24 rounded-full bg-primary-foreground/5" />
            </div>

            <div className="relative p-6 md:p-8">
                <div className="flex flex-col gap-5 md:flex-row md:items-start md:gap-6">
                    <div className="hidden h-24 w-24 shrink-0 items-center justify-center rounded-full border-4 border-primary-foreground/20 bg-primary-foreground/10 shadow-xl md:flex">
                        <CalendarDays className="h-12 w-12 text-primary-foreground" />
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="mb-2 flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wider text-primary-foreground/80">
                            <LiveDot />
                            Live shifts · refreshed just now
                        </div>
                        <h1 className="text-2xl font-bold tracking-tight md:text-3xl">
                            <span className="font-normal text-primary-foreground/80">
                                {greetingName
                                    ? `Kia ora ${greetingName}, your shifts —`
                                    : 'Your shifts —'}
                            </span>{' '}
                            <span className="border-b-2 border-primary-foreground/40 pb-0.5">
                                {weekLabel}
                            </span>
                        </h1>
                        <p className="mt-1 text-sm text-primary-foreground/70">
                            {stats.total} shifts across {stats.sites} site
                            {stats.sites === 1 ? '' : 's'}.{' '}
                            {stats.open > 0 ? (
                                <>
                                    {stats.open} need cover
                                    {stats.unassigned > stats.open
                                        ? `, ${stats.unassigned - stats.open} unassigned drafts`
                                        : ''}
                                    .
                                </>
                            ) : (
                                'No open shifts — week is fully covered.'
                            )}
                        </p>
                        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs text-primary-foreground/80">
                            <span className="inline-flex items-center gap-1.5">
                                <CalendarDays className="h-3.5 w-3.5" />
                                {weekLabel}
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <Building className="h-3.5 w-3.5" />
                                {stats.sites} sites
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <Users className="h-3.5 w-3.5" />
                                {stats.staff} staff rostered
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <Clock className="h-3.5 w-3.5" />
                                {stats.hours}h scheduled
                            </span>
                        </div>

                        <div className="mt-3 flex flex-wrap gap-2">
                            {stats.open > 0 ? (
                                <Pill tone="warning">
                                    <AlertTriangle className="h-3 w-3" /> {stats.open} open
                                </Pill>
                            ) : (
                                <Pill tone="success">
                                    <CheckCircle2 className="h-3 w-3" /> Week covered
                                </Pill>
                            )}
                            <Pill tone="info">
                                <Zap className="h-3 w-3" /> Auto-schedule ready
                            </Pill>
                            <Pill tone="default">
                                <CheckCircle2 className="h-3 w-3" /> Week published
                            </Pill>
                        </div>
                    </div>

                    {/* Right column: actions + stats */}
                    <div className="flex w-full flex-col items-stretch gap-3 md:w-auto md:items-end">
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            {onExport ? (
                                <button
                                    type="button"
                                    onClick={onExport}
                                    className="inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/30 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition hover:bg-primary-foreground/10"
                                >
                                    <Download className="h-3.5 w-3.5" /> Export
                                </button>
                            ) : null}
                            {canCreate ? (
                                <button
                                    type="button"
                                    onClick={onCreate}
                                    className="inline-flex items-center gap-1.5 rounded-md bg-primary-foreground px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-foreground/90"
                                >
                                    <Plus className="h-4 w-4" /> Create shift
                                </button>
                            ) : null}
                            <button
                                type="button"
                                aria-label="More"
                                className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-primary-foreground/30 text-primary-foreground transition hover:bg-primary-foreground/10"
                            >
                                <MoreHorizontal className="h-4 w-4" />
                            </button>
                        </div>

                        <div className="grid grid-cols-4 gap-3 text-right md:gap-5">
                            {[
                                { label: 'Total', value: stats.total },
                                { label: 'Open', value: stats.open },
                                { label: 'Today', value: stats.today },
                                { label: 'On now', value: stats.in_progress },
                            ].map((s) => (
                                <div key={s.label}>
                                    <div className="text-2xl font-bold leading-none tabular-nums md:text-[28px]">
                                        {s.value}
                                    </div>
                                    <div className="mt-1 text-[10.5px] font-medium uppercase tracking-wider text-primary-foreground/70">
                                        {s.label}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {/* Footer: week picker + filters */}
            <div className="relative flex flex-col gap-2 border-t border-primary-foreground/20 px-4 py-3 md:flex-row md:items-center md:justify-between md:px-6">
                <div className="flex flex-wrap items-center gap-1.5">
                    <HeroPillBtn onClick={onPrevWeek}>
                        <ChevronLeft className="h-3.5 w-3.5" /> Prev week
                    </HeroPillBtn>
                    <HeroPillBtn
                        buttonRef={weekBtnRef}
                        onClick={() => setPickerOpen((v) => !v)}
                        solid
                        ariaHasPopup="dialog"
                        ariaExpanded={pickerOpen}
                    >
                        <CalendarRange className="h-3.5 w-3.5" /> {weekLabel} · pick week
                        <ChevronDown className="h-3 w-3" />
                    </HeroPillBtn>
                    <HeroPillBtn onClick={onNextWeek}>
                        Next week <ChevronRight className="h-3.5 w-3.5" />
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
                        onChange={(next) => onChangeFilter('statuses', next)}
                    />
                    <MultiEntityFilter
                        onDark
                        label="Staff"
                        pluralLabel="staff"
                        allLabel="All staff"
                        items={staffItems}
                        value={filters.user_ids}
                        onChange={(next) => onChangeFilter('user_ids', next)}
                    />
                    <MultiEntityFilter
                        onDark
                        label="Client"
                        allLabel="All clients"
                        items={clientItems}
                        value={filters.client_ids}
                        onChange={(next) => onChangeFilter('client_ids', next)}
                    />
                    <MultiEntityFilter
                        onDark
                        label="Site"
                        allLabel="All sites"
                        items={siteItems}
                        value={filters.site_ids}
                        onChange={(next) => onChangeFilter('site_ids', next)}
                    />
                </div>
            </div>

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
        </div>
    );
}

function LiveDot() {
    return (
        <span className="relative inline-flex h-2 w-2">
            <span
                className="absolute inset-0 rounded-full opacity-60"
                style={{
                    background: 'oklch(0.85 0.18 150)',
                    animation: 'shifts-ping 1.6s cubic-bezier(0,0,0.2,1) infinite',
                }}
            />
            <span
                className="relative h-2 w-2 rounded-full"
                style={{
                    background: 'oklch(0.78 0.18 150)',
                    boxShadow: '0 0 0 3px oklch(0.78 0.18 150 / 0.25)',
                }}
            />
            <style>{`@keyframes shifts-ping { 75%,100% { transform: scale(2.2); opacity: 0; } }`}</style>
        </span>
    );
}

function Pill({
    tone,
    children,
}: {
    tone: 'default' | 'info' | 'warning' | 'success' | 'critical';
    children: React.ReactNode;
}) {
    const map: Record<typeof tone, string> = {
        default:
            'bg-primary-foreground/10 border border-primary-foreground/20 text-primary-foreground',
        info: 'bg-primary-foreground/10 border border-primary-foreground/20 text-primary-foreground',
        warning:
            'bg-status-warning/15 border border-status-warning/30 text-primary-foreground',
        success:
            'bg-status-success/15 border border-status-success/30 text-primary-foreground',
        critical:
            'bg-status-critical/15 border border-status-critical/30 text-primary-foreground',
    };
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-medium ${map[tone]}`}
        >
            {children}
        </span>
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
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-haspopup="listbox"
                    aria-expanded={open}
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                        allSelected
                            ? 'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20'
                            : 'border-primary-foreground bg-primary-foreground text-primary',
                    )}
                >
                    <Filter className="h-3.5 w-3.5" aria-hidden="true" />
                    <span className="max-w-[200px] truncate">
                        {triggerLabel}
                    </span>
                    {!allSelected ? (
                        <button
                            type="button"
                            aria-label="Clear status filter"
                            className="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full hover:bg-primary/30"
                            onClick={(e) => {
                                e.stopPropagation();
                                clearAll();
                            }}
                        >
                            <X className="h-3 w-3" />
                        </button>
                    ) : (
                        <ChevronDown className="h-3 w-3 opacity-70" />
                    )}
                </button>
            </PopoverTrigger>
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
