import { useEffect, useRef, useState } from 'react';
import {
    AlertTriangle,
    Building,
    CalendarDays,
    CalendarRange,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronsUpDown,
    Clock,
    Download,
    MoreHorizontal,
    Plus,
    Search,
    Users,
    Zap,
} from 'lucide-react';

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
    status: string | null;
    site_id: string | null;
    user_id: string | null;
    q: string;
};

type FilterChipOption = [value: string, label: string];

type Props = {
    greetingName?: string;
    weekLabel: string;
    stats: HeroStats;
    filters: HeroFilters;
    onChangeFilter: (key: keyof HeroFilters, value: string | null) => void;
    statusOptions: FilterChipOption[];
    siteOptions: FilterChipOption[];
    staffOptions: FilterChipOption[];
    onCreate?: () => void;
    onPrevWeek: () => void;
    onNextWeek: () => void;
    onToday: () => void;
    onExport?: () => void;
    canCreate?: boolean;
};

export function ShiftsHero({
    greetingName,
    weekLabel,
    stats,
    filters,
    onChangeFilter,
    statusOptions,
    siteOptions,
    staffOptions,
    onCreate,
    onPrevWeek,
    onNextWeek,
    onToday,
    onExport,
    canCreate = true,
}: Props) {
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
                    <HeroPillBtn onClick={onToday} solid>
                        <CalendarRange className="h-3.5 w-3.5" /> {weekLabel}
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
                    <HeroFilterChip
                        label="Status"
                        value={filters.status}
                        options={statusOptions}
                        onSelect={(v) => onChangeFilter('status', v)}
                        onClear={() => onChangeFilter('status', null)}
                    />
                    <HeroFilterChip
                        label="Site"
                        value={filters.site_id}
                        options={siteOptions}
                        onSelect={(v) => onChangeFilter('site_id', v)}
                        onClear={() => onChangeFilter('site_id', null)}
                    />
                    <HeroFilterChip
                        label="Staff"
                        value={filters.user_id}
                        options={staffOptions}
                        onSelect={(v) => onChangeFilter('user_id', v)}
                        onClear={() => onChangeFilter('user_id', null)}
                    />
                </div>
            </div>
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
}: {
    children: React.ReactNode;
    onClick?: () => void;
    solid?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
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
            <Search className="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
            <input
                value={value || ''}
                onChange={(e) => onChange(e.target.value)}
                placeholder="Search clients, staff, location"
                className="w-56 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pl-8 pr-2.5 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus:bg-primary-foreground/20 focus:outline-none"
            />
        </div>
    );
}

function HeroFilterChip({
    label,
    value,
    options,
    onSelect,
    onClear,
}: {
    label: string;
    value: string | null;
    options: FilterChipOption[];
    onSelect: (v: string) => void;
    onClear: () => void;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement | null>(null);
    const current = options.find(([v]) => v === value);

    useEffect(() => {
        if (!open) return;
        const onDown = (e: MouseEvent) => {
            if (!ref.current?.contains(e.target as Node)) setOpen(false);
        };
        window.addEventListener('mousedown', onDown);
        return () => window.removeEventListener('mousedown', onDown);
    }, [open]);

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((x) => !x)}
                className="inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary-foreground/20"
            >
                <span className="text-primary-foreground/70">{label}:</span>
                <span>{current ? current[1] : 'All'}</span>
                <ChevronsUpDown className="h-3 w-3 opacity-70" />
            </button>
            {open ? (
                <div className="absolute right-0 top-full z-30 mt-1 w-52 rounded-lg border border-border bg-popover text-foreground shadow-lg">
                    <ul className="max-h-72 overflow-auto py-1 text-sm">
                        <li>
                            <button
                                type="button"
                                onClick={() => {
                                    onClear();
                                    setOpen(false);
                                }}
                                className="w-full px-3 py-1.5 text-left text-muted-foreground hover:bg-muted"
                            >
                                All
                            </button>
                        </li>
                        {options.length === 0 ? (
                            <li className="px-3 py-1.5 text-xs text-muted-foreground">
                                Nothing to choose
                            </li>
                        ) : (
                            options.map(([v, l]) => (
                                <li key={v}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            onSelect(v);
                                            setOpen(false);
                                        }}
                                        className={`w-full px-3 py-1.5 text-left hover:bg-muted ${value === v ? 'font-medium text-primary' : ''}`}
                                    >
                                        {l}
                                    </button>
                                </li>
                            ))
                        )}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}
