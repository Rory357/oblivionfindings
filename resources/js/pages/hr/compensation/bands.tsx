import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { CompensationTabs, CompensationHero } from '@/components/hr';
import type { CompensationQuickAction } from '@/components/hr';
import { StatusBadge, type StatusTone } from '@/components/hr/status-badge';
import {
    Field,
    InfoCard,
    Ring,
    SelectInput,
    StepHead,
    SubHead,
    WizardShell,
    WizardStepPane,
    ReviewCard,
    ReviewRow,
    type WizardStep,
    useWizard,
} from '@/components/hr/wizard';
import { PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    CalendarRange,
    ClipboardCheck,
    ClipboardList,
    Copy,
    DollarSign,
    Download,
    Layers,
    Pencil,
    Plus,
    Receipt,
    Search,
    Tag,
    Users,
} from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';
import { toast } from 'sonner';

type BreadcrumbItem = { title: string; href: string };

type Placement = {
    name: string;
    compa_ratio: number | null;
    position: 'under' | 'in' | 'over';
};

type SalaryBand = {
    id: number;
    position_role: string;
    band_name: string;
    min_salary: string;
    mid_salary: string;
    max_salary: string;
    min_hourly: string;
    max_hourly: string;
    currency: string;
    effective_from: string;
    effective_to: string | null;
    employee_count?: number;
    in_band?: number;
    under_band?: number;
    over_band?: number;
    avg_compa_ratio?: number | null;
    placements?: Placement[];
};

type Stats = {
    bands_total: number;
    roles_covered: number;
    people_placed: number;
    people_in_band: number;
    people_out_of_band: number;
    band_health: number;
    reviews_in_flight: number;
    awaiting_approval: number;
    reimbursed_this_month: number;
    claims_overdue: number;
};

type Props = {
    bands: { data: SalaryBand[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { role: string | null; active_only: boolean; as_of: string };
    stats: Stats;
    can: { manage: boolean; benefits?: boolean; expenses?: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Compensation', href: '/hr/compensation/bands' },
    { title: 'Salary bands', href: '/hr/compensation/bands' },
];

const formatDate = (value?: string | null) => {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const num = (value?: string | number | null) => {
    if (value == null || value === '') return NaN;
    const n = typeof value === 'number' ? value : parseFloat(value);
    return Number.isNaN(n) ? NaN : n;
};

const formatCurrency = (value: string | number | null, currency = 'NZD') => {
    const n = num(value);
    if (Number.isNaN(n)) return '—';
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(n);
};

const compaLabel = (compa: number | null | undefined) =>
    compa == null ? '—' : `${Math.round(compa * 100)}%`;

const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('') || '?';

/** Lifecycle of a band derived from its effective dates. */
function bandLifecycle(band: SalaryBand): { status: string; tone: StatusTone } {
    const now = Date.now();
    const from = new Date(band.effective_from).getTime();
    const to = band.effective_to ? new Date(band.effective_to).getTime() : null;
    if (!Number.isNaN(from) && from > now) return { status: 'scheduled', tone: 'info' };
    if (to != null && !Number.isNaN(to) && to < now) return { status: 'expired', tone: 'neutral' };
    return { status: 'active', tone: 'success' };
}

// Plotted-employee dot colours match the mockup legend: in-band reads as the
// brand blue, anyone outside the band (under OR over) reads critical/red.
const POSITION_DOT: Record<Placement['position'], string> = {
    under: 'bg-status-critical ring-status-critical/30',
    in: 'bg-primary ring-primary/30',
    over: 'bg-status-critical ring-status-critical/30',
};

// Shape also encodes band position (not colour alone, WCAG 1.4.1): in-band is a
// round dot, out-of-band is a diamond.
const POSITION_SHAPE: Record<Placement['position'], string> = {
    under: 'rounded-[2px] rotate-45',
    in: 'rounded-full',
    over: 'rounded-[2px] rotate-45',
};

/* ------------------------------------------------------------------ */
/*  Range bar — min/mid/max with target zone + employee dots           */
/* ------------------------------------------------------------------ */

function RangeBar({
    band,
    placements,
    showDots = true,
    showLabels = true,
}: {
    band: {
        min_salary: string | number;
        mid_salary: string | number;
        max_salary: string | number;
        currency: string;
    };
    placements?: Placement[];
    showDots?: boolean;
    showLabels?: boolean;
}) {
    const min = num(band.min_salary);
    const mid = num(band.mid_salary);
    const max = num(band.max_salary);
    const valid = !Number.isNaN(min) && !Number.isNaN(max) && max > min;
    const midValid = valid && !Number.isNaN(mid);

    const pct = (salary: number) =>
        valid ? Math.min(100, Math.max(0, ((salary - min) / (max - min)) * 100)) : 0;

    const midPct = midValid ? pct(mid) : 50;
    // Target zone = 90%–110% of the midpoint (Mercer compa-ratio convention).
    const zoneStart = midValid ? pct(mid * 0.9) : 35;
    const zoneEnd = midValid ? pct(mid * 1.1) : 65;

    return (
        <div className="w-full">
            <div className="relative h-7">
                {/* base track */}
                <div className="absolute inset-x-0 top-1/2 h-2 -translate-y-1/2 rounded-full bg-muted" />
                {/* target zone */}
                {midValid ? (
                    <div
                        className="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-primary/25"
                        style={{ left: `${zoneStart}%`, width: `${Math.max(0, zoneEnd - zoneStart)}%` }}
                    />
                ) : null}
                {/* mid marker */}
                {midValid ? (
                    <div
                        className="absolute top-1/2 h-4 w-0.5 -translate-x-1/2 -translate-y-1/2 rounded bg-primary"
                        style={{ left: `${midPct}%` }}
                    />
                ) : null}
                {/* employee dots */}
                {showDots && midValid
                    ? (placements ?? []).map((p, i) => {
                          if (p.compa_ratio == null) return null;
                          const salary = p.compa_ratio * mid;
                          const left = pct(salary);
                          const dotLabel = `${p.name}: ${compaLabel(p.compa_ratio)} compa-ratio, ${p.position} band`;
                          return (
                              <TooltipProvider key={`${p.name}-${i}`} delayDuration={100}>
                                  <Tooltip>
                                      <TooltipTrigger asChild>
                                          {/* eslint-disable-next-line no-restricted-syntax -- focusable plotted-employee marker; must be a native button for the tooltip trigger + keyboard access. */}
                                          <button
                                              type="button"
                                              aria-label={dotLabel}
                                              title={dotLabel}
                                              className={cn(
                                                  'absolute top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 ring-2 ring-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                                                  POSITION_DOT[p.position],
                                                  POSITION_SHAPE[p.position],
                                              )}
                                              style={{ left: `${left}%` }}
                                          />
                                      </TooltipTrigger>
                                      <TooltipContent>
                                          <span className="font-medium">{p.name}</span> ·{' '}
                                          {compaLabel(p.compa_ratio)} compa · {p.position}
                                      </TooltipContent>
                                  </Tooltip>
                              </TooltipProvider>
                          );
                      })
                    : null}
            </div>
            {showLabels ? (
                <div className="mt-1 flex justify-between text-[11px] tabular-nums text-muted-foreground">
                    <span>{formatCurrency(band.min_salary, band.currency)}</span>
                    <span className="font-medium text-primary">{formatCurrency(band.mid_salary, band.currency)}</span>
                    <span>{formatCurrency(band.max_salary, band.currency)}</span>
                </div>
            ) : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Band row (full-width, mockup layout)                              */
/* ------------------------------------------------------------------ */

function BandRow({
    band,
    canManage,
    onView,
    onEdit,
    onDuplicate,
}: {
    band: SalaryBand;
    canManage: boolean;
    onView: () => void;
    onEdit: () => void;
    onDuplicate: () => void;
}) {
    const life = bandLifecycle(band);
    const count = band.employee_count ?? 0;
    const outOfBand = (band.under_band ?? 0) + (band.over_band ?? 0);

    return (
        // eslint-disable-next-line no-restricted-syntax -- interactive band row surface (hover group + drawer trigger), not a plain Card.
        <div className="group flex flex-col gap-4 rounded-xl border border-border bg-card p-4 transition-colors hover:border-primary/40 lg:flex-row lg:items-center">
            {/* Identity column */}
            {/* eslint-disable-next-line no-restricted-syntax -- row identity doubles as the drawer-open hit target. */}
            <button
                type="button"
                onClick={onView}
                className="flex w-full shrink-0 items-start gap-3 text-left lg:w-64"
            >
                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-[11px] font-bold text-primary">
                    {initials(band.position_role)}
                </span>
                <span className="min-w-0">
                    <span className="flex items-center gap-2">
                        <span className="truncate text-sm font-semibold">{band.position_role}</span>
                        {life.status !== 'active' ? (
                            <StatusBadge status={life.status} tone={life.tone} />
                        ) : null}
                    </span>
                    <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                        {band.band_name}
                    </span>
                    <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <span className="text-[11px] text-muted-foreground">
                            {count} {count === 1 ? 'person' : 'people'}
                        </span>
                        {count > 0 ? (
                            <StatusBadge
                                status={outOfBand > 0 ? 'out' : 'in'}
                                tone={outOfBand > 0 ? 'critical' : 'success'}
                                label={outOfBand > 0 ? `${outOfBand} out of band` : 'all in band'}
                            />
                        ) : null}
                    </span>
                    <span className="mt-1 block text-[11px] tabular-nums text-muted-foreground">
                        {formatCurrency(band.min_hourly, band.currency)}–
                        {formatCurrency(band.max_hourly, band.currency)}/hr · eff.{' '}
                        {formatDate(band.effective_from)}
                    </span>
                </span>
            </button>

            {/* Range column */}
            <div className="min-w-0 flex-1">
                <div className="mb-1 flex justify-between text-[11px] tabular-nums text-muted-foreground">
                    <span>{formatCurrency(band.min_salary, band.currency)}</span>
                    <span className="font-medium text-foreground">
                        mid {formatCurrency(band.mid_salary, band.currency)}
                    </span>
                    <span>{formatCurrency(band.max_salary, band.currency)}</span>
                </div>
                <RangeBar band={band} placements={band.placements} showLabels={false} />
            </div>

            {/* Actions */}
            {canManage ? (
                <div className="shrink-0 self-start lg:self-center">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 text-muted-foreground opacity-60 transition-opacity group-hover:opacity-100"
                                aria-label={`Actions for ${band.position_role} ${band.band_name}`}
                            >
                                <Pencil className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-44">
                            <DropdownMenuItem onClick={onView}>
                                <Users className="h-3.5 w-3.5" /> View people
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={onEdit}>
                                <Pencil className="h-3.5 w-3.5" /> Edit band
                                <DropdownMenuShortcut>E</DropdownMenuShortcut>
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={onDuplicate}>
                                <Copy className="h-3.5 w-3.5" /> Duplicate
                                <DropdownMenuShortcut>D</DropdownMenuShortcut>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            ) : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  People-in-band drawer                                              */
/* ------------------------------------------------------------------ */

/** One-person position bar: plots the employee's dot on the band's min→max. */
function PersonMiniBar({
    band,
    placement,
}: {
    band: SalaryBand;
    placement: Placement;
}) {
    const min = num(band.min_salary);
    const mid = num(band.mid_salary);
    const max = num(band.max_salary);
    if ([min, mid, max].some(Number.isNaN) || max <= min || placement.compa_ratio == null) {
        return null;
    }
    const salary = placement.compa_ratio * mid;
    const left = Math.min(100, Math.max(0, ((salary - min) / (max - min)) * 100));
    return (
        <div className="relative mt-1.5 h-1.5">
            <div className="absolute inset-x-0 top-1/2 h-1 -translate-y-1/2 rounded-full bg-muted" />
            <span
                className={cn(
                    'absolute top-1/2 h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-card',
                    POSITION_DOT[placement.position].split(' ')[0],
                )}
                style={{ left: `${left}%` }}
            />
        </div>
    );
}

function BandDrawer({
    band,
    open,
    canManage,
    onClose,
    onEdit,
    onDuplicate,
}: {
    band: SalaryBand | null;
    open: boolean;
    canManage: boolean;
    onClose: () => void;
    onEdit: (band: SalaryBand) => void;
    onDuplicate: (band: SalaryBand) => void;
}) {
    return (
        <Sheet open={open} onOpenChange={(o) => !o && onClose()}>
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-md">
                {band ? (
                    <>
                        <SheetHeader className="border-b border-border">
                            <SheetTitle>{band.position_role}</SheetTitle>
                            <SheetDescription>
                                {band.band_name} · {formatCurrency(band.min_salary, band.currency)}–
                                {formatCurrency(band.max_salary, band.currency)} ·{' '}
                                {formatCurrency(band.min_hourly, band.currency)}–
                                {formatCurrency(band.max_hourly, band.currency)}/hr
                            </SheetDescription>
                        </SheetHeader>

                        <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4">
                            <div className="rounded-xl border border-border bg-muted/30 p-4">
                                <RangeBar band={band} placements={band.placements} />
                            </div>

                            <div className="grid grid-cols-3 gap-2 text-center">
                                <div className="rounded-lg border border-border p-2">
                                    <div className="text-lg font-bold text-status-success tabular-nums">
                                        {band.in_band ?? 0}
                                    </div>
                                    <div className="text-[11px] text-muted-foreground">In band</div>
                                </div>
                                <div className="rounded-lg border border-border p-2">
                                    <div className="text-lg font-bold text-status-warning tabular-nums">
                                        {band.under_band ?? 0}
                                    </div>
                                    <div className="text-[11px] text-muted-foreground">Under</div>
                                </div>
                                <div className="rounded-lg border border-border p-2">
                                    <div className="text-lg font-bold tabular-nums">
                                        {compaLabel(band.avg_compa_ratio)}
                                    </div>
                                    <div className="text-[11px] text-muted-foreground">Avg compa</div>
                                </div>
                            </div>

                            <div>
                                <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    People in this band ({band.placements?.length ?? 0})
                                </h4>
                                {band.placements && band.placements.length > 0 ? (
                                    <ul className="space-y-1.5">
                                        {band.placements.map((p, i) => (
                                            <li
                                                key={`${p.name}-${i}`}
                                                className="rounded-lg border border-border px-3 py-2.5"
                                            >
                                                <div className="flex items-center justify-between gap-3">
                                                    <span className="flex min-w-0 items-center gap-2.5">
                                                        <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
                                                            {initials(p.name)}
                                                        </span>
                                                        <span className="min-w-0">
                                                            <span className="block truncate text-sm font-medium">
                                                                {p.name}
                                                            </span>
                                                            <span className="block text-[11px] tabular-nums text-muted-foreground">
                                                                {compaLabel(p.compa_ratio)} compa
                                                            </span>
                                                        </span>
                                                    </span>
                                                    <StatusBadge
                                                        status={p.position}
                                                        tone={
                                                            p.position === 'in'
                                                                ? 'success'
                                                                : p.position === 'under'
                                                                  ? 'warning'
                                                                  : 'critical'
                                                        }
                                                        label={
                                                            p.position === 'in'
                                                                ? 'In band'
                                                                : p.position === 'under'
                                                                  ? 'Under'
                                                                  : 'Over'
                                                        }
                                                    />
                                                </div>
                                                <PersonMiniBar band={band} placement={p} />
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                                        No active employees mapped to this role.
                                    </p>
                                )}
                            </div>
                        </div>

                        {canManage ? (
                            <div className="flex shrink-0 items-center justify-end gap-2 border-t border-border bg-muted/30 px-4 py-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onDuplicate(band)}
                                >
                                    <Copy className="mr-1.5 h-3.5 w-3.5" />
                                    Duplicate
                                </Button>
                                <Button type="button" size="sm" onClick={() => onEdit(band)}>
                                    <Pencil className="mr-1.5 h-3.5 w-3.5" />
                                    Edit band
                                </Button>
                            </div>
                        ) : null}
                    </>
                ) : null}
            </SheetContent>
        </Sheet>
    );
}

/* ------------------------------------------------------------------ */
/*  New / edit band wizard                                             */
/* ------------------------------------------------------------------ */

type BandForm = {
    position_role: string;
    band_name: string;
    min_salary: string;
    mid_salary: string;
    max_salary: string;
    min_hourly: string;
    max_hourly: string;
    currency: string;
    effective_from: string;
    effective_to: string;
};

const emptyForm: BandForm = {
    position_role: '',
    band_name: '',
    min_salary: '',
    mid_salary: '',
    max_salary: '',
    min_hourly: '',
    max_hourly: '',
    currency: 'NZD',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_to: '',
};

const WIZARD_STEPS: readonly WizardStep[] = [
    { key: 'role', label: 'Role & band', blurb: 'Who it covers', icon: Tag },
    { key: 'ranges', label: 'Pay ranges', blurb: 'Min · mid · max', icon: DollarSign },
    { key: 'dates', label: 'Effective dating', blurb: 'When it applies', icon: CalendarRange },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: ClipboardList },
];

const CURRENCY_OPTIONS = [
    { value: 'NZD', label: 'NZD — New Zealand Dollar' },
    { value: 'AUD', label: 'AUD — Australian Dollar' },
    { value: 'USD', label: 'USD — US Dollar' },
];

function BandWizard({
    open,
    editId,
    initial,
    existingBands,
    onClose,
}: {
    open: boolean;
    editId: number | null;
    initial: BandForm;
    existingBands: SalaryBand[];
    onClose: () => void;
}) {
    const [form, setForm] = useState<BandForm>(initial);
    const [saving, setSaving] = useState(false);
    const wiz = useWizard(WIZARD_STEPS.length);

    const set = <K extends keyof BandForm>(key: K, value: BandForm[K]) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const minS = num(form.min_salary);
    const midS = num(form.mid_salary);
    const maxS = num(form.max_salary);
    const minH = num(form.min_hourly);
    const maxH = num(form.max_hourly);

    const rangeError = useMemo(() => {
        if (!Number.isNaN(minS) && !Number.isNaN(midS) && minS > midS)
            return 'Mid salary must be at least the minimum.';
        if (!Number.isNaN(midS) && !Number.isNaN(maxS) && midS > maxS)
            return 'Max salary must be at least the mid salary.';
        if (!Number.isNaN(minH) && !Number.isNaN(maxH) && minH > maxH)
            return 'Max hourly must be at least the minimum hourly.';
        return null;
    }, [minS, midS, maxS, minH, maxH]);

    // Overlap detection: warn if the proposed salary range overlaps another active
    // band for the same role (best-effort against the bands loaded on this page).
    const overlap = useMemo(() => {
        if (Number.isNaN(minS) || Number.isNaN(maxS)) return null;
        const role = form.position_role.trim().toLowerCase();
        if (!role) return null;
        return existingBands.find((b) => {
            if (editId && b.id === editId) return false;
            if (b.position_role.trim().toLowerCase() !== role) return false;
            if (bandLifecycle(b).status === 'expired') return false;
            const bMin = num(b.min_salary);
            const bMax = num(b.max_salary);
            if (Number.isNaN(bMin) || Number.isNaN(bMax)) return false;
            return minS <= bMax && maxS >= bMin;
        });
    }, [existingBands, form.position_role, minS, maxS, editId]);

    const datesError = useMemo(() => {
        if (form.effective_to && form.effective_from && form.effective_to <= form.effective_from)
            return 'Effective-to must be after effective-from.';
        return null;
    }, [form.effective_from, form.effective_to]);

    const stepValid = (i: number) => {
        if (i === 0) return form.position_role.trim() !== '' && form.band_name.trim() !== '';
        if (i === 1)
            return (
                !rangeError &&
                [minS, midS, maxS, minH, maxH].every((v) => !Number.isNaN(v) && v >= 0)
            );
        if (i === 2) return form.effective_from !== '' && !datesError;
        return true;
    };

    // The whole form is valid only when every data step passes. Used for both the
    // submit gate and rail jumps so a user can't skip a step to an invalid submit.
    const formValid = stepValid(0) && stepValid(1) && stepValid(2);

    const completeness = useMemo(() => {
        const checks = [
            form.position_role.trim() !== '',
            form.band_name.trim() !== '',
            !Number.isNaN(minS) && !Number.isNaN(midS) && !Number.isNaN(maxS) && !rangeError,
            !Number.isNaN(minH) && !Number.isNaN(maxH) && !rangeError,
            form.effective_from !== '' && !datesError,
        ];
        return Math.round((checks.filter(Boolean).length / checks.length) * 100);
    }, [form, minS, midS, maxS, minH, maxH, rangeError, datesError]);

    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        if (!formValid) return;
        setSaving(true);
        const payload = { ...form, effective_to: form.effective_to || null };
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(editId ? 'Salary band updated.' : 'Salary band created.');
                onClose();
            },
            onError: () => toast.error('Could not save the band. Check the highlighted fields.'),
            onFinish: () => setSaving(false),
        };
        if (editId) router.put(`/hr/compensation/bands/${editId}`, payload, opts);
        else router.post('/hr/compensation/bands', payload, opts);
    };

    const railExtra = (
        // eslint-disable-next-line no-restricted-syntax -- compact rail preview panel, custom wizard-rail surface.
        <div className="rounded-lg border border-border bg-card/60 p-3">
            <div className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                Live preview
            </div>
            {[minS, midS, maxS].every((v) => !Number.isNaN(v)) ? (
                <RangeBar
                    band={{
                        min_salary: form.min_salary,
                        mid_salary: form.mid_salary,
                        max_salary: form.max_salary,
                        currency: form.currency,
                    }}
                    showDots={false}
                />
            ) : (
                <p className="text-[11px] text-muted-foreground">Enter min/mid/max to preview the range.</p>
            )}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={editId ? 'Edit salary band' : 'New salary band'}
            description="Define a pay range for a position role."
            railIcon={Layers}
            railTitle={editId ? 'Edit band' : 'New band'}
            railSub="Compensation"
            steps={WIZARD_STEPS}
            stepIndex={wiz.index}
            onStepClick={(i) => {
                // Going back is always allowed; jumping forward requires every
                // intervening step to be valid (no skipping to an invalid Review).
                const forwardOk = Array.from({ length: i }, (_, s) => stepValid(s)).every(Boolean);
                if (i <= wiz.index || forwardOk) wiz.goTo(i);
            }}
            pct={completeness}
            railExtra={railExtra}
            footerStart={
                <Button type="button" variant="ghost" onClick={onClose}>
                    Cancel
                </Button>
            }
            footerEnd={
                <>
                    {!wiz.isFirst ? (
                        <Button type="button" variant="outline" onClick={wiz.back}>
                            Back
                        </Button>
                    ) : null}
                    {!wiz.isLast ? (
                        <Button
                            type="button"
                            onClick={wiz.next}
                            disabled={!stepValid(wiz.index)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button type="button" onClick={() => submit()} disabled={saving || !formValid}>
                            {saving ? 'Saving…' : editId ? 'Update band' : 'Create band'}
                        </Button>
                    )}
                </>
            }
        >
            {/* Step 1 — role & band */}
            {wiz.index === 0 ? (
                <WizardStepPane>
                    <StepHead
                        icon={Tag}
                        title="Role & band"
                        blurb="Name the position role this range covers and a label for the band tier."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Position role" required>
                            <Input
                                value={form.position_role}
                                onChange={(e) => set('position_role', e.target.value)}
                                placeholder="Support Worker"
                            />
                        </Field>
                        <Field label="Band name" required hint="e.g. tier or level">
                            <Input
                                value={form.band_name}
                                onChange={(e) => set('band_name', e.target.value)}
                                placeholder="Band B"
                            />
                        </Field>
                        <Field label="Currency" span>
                            <SelectInput
                                value={form.currency}
                                onChange={(v) => set('currency', v)}
                                placeholder="Currency"
                                options={CURRENCY_OPTIONS}
                                ariaLabel="Currency"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* Step 2 — ranges */}
            {wiz.index === 1 ? (
                <WizardStepPane>
                    <StepHead
                        icon={DollarSign}
                        title="Pay ranges"
                        blurb="Set the annual salary range and the hourly equivalent. Min ≤ mid ≤ max."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <SubHead icon={DollarSign}>Annual salary</SubHead>
                        <Field label="Minimum" required>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={form.min_salary}
                                onChange={(e) => set('min_salary', e.target.value)}
                            />
                        </Field>
                        <Field label="Midpoint" required>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={form.mid_salary}
                                onChange={(e) => set('mid_salary', e.target.value)}
                            />
                        </Field>
                        <Field label="Maximum" required>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={form.max_salary}
                                onChange={(e) => set('max_salary', e.target.value)}
                            />
                        </Field>
                        <SubHead icon={DollarSign}>Hourly equivalent</SubHead>
                        <div className="col-span-full -mt-1">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={Number.isNaN(minS) || Number.isNaN(maxS)}
                                onClick={() => {
                                    // Standard NZ full-time year: 40h/week × 52 = 2080h.
                                    const round2 = (n: number) => (Math.round((n / 2080) * 100) / 100).toString();
                                    if (!Number.isNaN(minS)) set('min_hourly', round2(minS));
                                    if (!Number.isNaN(maxS)) set('max_hourly', round2(maxS));
                                }}
                            >
                                Derive from salary (÷2080h)
                            </Button>
                        </div>
                        <Field label="Min hourly" required>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={form.min_hourly}
                                onChange={(e) => set('min_hourly', e.target.value)}
                            />
                        </Field>
                        <Field label="Max hourly" required>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={form.max_hourly}
                                onChange={(e) => set('max_hourly', e.target.value)}
                            />
                        </Field>
                        <div className="hidden sm:block" />

                        {rangeError ? (
                            <InfoCard icon={AlertTriangle} tone="crit">
                                {rangeError}
                            </InfoCard>
                        ) : null}
                        {!rangeError && overlap ? (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                This range overlaps the existing band{' '}
                                <strong>{overlap.band_name}</strong> ({formatCurrency(overlap.min_salary, overlap.currency)}–
                                {formatCurrency(overlap.max_salary, overlap.currency)}) for this role.
                            </InfoCard>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}

            {/* Step 3 — dates */}
            {wiz.index === 2 ? (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarRange}
                        title="Effective dating"
                        blurb="When does this band take effect? Leave the end date blank for an open-ended band."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Effective from" required>
                            <Input
                                type="date"
                                value={form.effective_from}
                                onChange={(e) => set('effective_from', e.target.value)}
                            />
                        </Field>
                        <Field label="Effective to" hint="optional" error={datesError ?? undefined}>
                            <Input
                                type="date"
                                value={form.effective_to}
                                onChange={(e) => set('effective_to', e.target.value)}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* Step 4 — review */}
            {wiz.index === 3 ? (
                <WizardStepPane>
                    {/* eslint-disable-next-line no-restricted-syntax -- review hero summary band, custom layout surface. */}
                    <div className="mb-5 flex items-center gap-4 rounded-xl border border-border bg-muted/30 p-4">
                        <Ring pct={completeness} />
                        <div>
                            <h2 className="text-lg font-bold">Review the band</h2>
                            <p className="text-sm text-muted-foreground">
                                Confirm the details below, then {editId ? 'update' : 'create'} the band.
                            </p>
                        </div>
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <ReviewCard icon={Tag} title="Role & band" onEdit={() => wiz.goTo(0)}>
                            <ReviewRow label="Position role" value={form.position_role} />
                            <ReviewRow label="Band name" value={form.band_name} />
                            <ReviewRow label="Currency" value={form.currency} />
                        </ReviewCard>
                        <ReviewCard icon={CalendarRange} title="Effective dating" onEdit={() => wiz.goTo(2)}>
                            <ReviewRow label="From" value={formatDate(form.effective_from)} />
                            <ReviewRow
                                label="To"
                                value={form.effective_to ? formatDate(form.effective_to) : 'Open-ended'}
                            />
                        </ReviewCard>
                        <ReviewCard icon={DollarSign} title="Pay ranges" onEdit={() => wiz.goTo(1)} span>
                            <ReviewRow label="Salary" value={`${formatCurrency(form.min_salary, form.currency)} · ${formatCurrency(form.mid_salary, form.currency)} · ${formatCurrency(form.max_salary, form.currency)}`} />
                            <ReviewRow label="Hourly" value={`${formatCurrency(form.min_hourly, form.currency)} – ${formatCurrency(form.max_hourly, form.currency)}`} />
                            <div className="pt-3">
                                <RangeBar
                                    band={{
                                        min_salary: form.min_salary,
                                        mid_salary: form.mid_salary,
                                        max_salary: form.max_salary,
                                        currency: form.currency,
                                    }}
                                    showDots={false}
                                />
                            </div>
                        </ReviewCard>
                        {overlap ? (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                Heads up — this range overlaps <strong>{overlap.band_name}</strong> for the same role.
                            </InfoCard>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function SalaryBands({ bands, filters, stats, can }: Props) {
    const [wizardOpen, setWizardOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const [wizardInitial, setWizardInitial] = useState<BandForm>(emptyForm);
    const [drawerBand, setDrawerBand] = useState<SalaryBand | null>(null);
    const [roleQuery, setRoleQuery] = useState(filters.role ?? '');

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/compensation/bands',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const toForm = (band: SalaryBand): BandForm => ({
        position_role: band.position_role,
        band_name: band.band_name,
        min_salary: band.min_salary,
        mid_salary: band.mid_salary,
        max_salary: band.max_salary,
        min_hourly: band.min_hourly,
        max_hourly: band.max_hourly,
        currency: band.currency,
        effective_from: band.effective_from?.slice(0, 10) ?? emptyForm.effective_from,
        effective_to: band.effective_to?.slice(0, 10) ?? '',
    });

    const openCreate = () => {
        setEditId(null);
        setWizardInitial(emptyForm);
        setWizardOpen(true);
    };

    const openEdit = (band: SalaryBand) => {
        setEditId(band.id);
        setWizardInitial(toForm(band));
        setWizardOpen(true);
    };

    const openDuplicate = (band: SalaryBand) => {
        setEditId(null);
        setWizardInitial({
            ...toForm(band),
            band_name: `${band.band_name} (copy)`,
            effective_from: emptyForm.effective_from,
            effective_to: '',
        });
        setWizardOpen(true);
    };

    const exportUrl = useMemo(() => {
        const params = new URLSearchParams();
        // Use the live typed value (not just the committed filter) so Export
        // always reflects what the user currently sees / is typing.
        const role = (roleQuery || filters.role || '').trim();
        if (role) params.set('role', role);
        if (filters.active_only) {
            params.set('active_only', '1');
            if (filters.as_of) params.set('as_of', filters.as_of);
        }
        const qs = params.toString();
        return `/hr/compensation/bands/export${qs ? `?${qs}` : ''}`;
    }, [roleQuery, filters.role, filters.active_only, filters.as_of]);

    const heroActions: CompensationQuickAction[] = [
        ...(can.manage ? [{ label: 'New band', icon: Plus, onClick: openCreate }] : []),
        ...(can.manage
            ? [{ label: 'Start pay review', icon: ClipboardCheck, href: '/hr/compensation/reviews' }]
            : []),
        ...(can.manage ? [{ label: 'Record bonus', icon: Banknote, href: '/hr/compensation/bonuses' }] : []),
        ...(can.expenses
            ? [{ label: 'New claim', icon: Receipt, href: '/hr/compensation/expenses/create' }]
            : []),
        { label: 'Export', icon: Download, href: exportUrl },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Compensation & Benefits" />

            <PageLayout
                hero={<CompensationHero stats={stats} quickActions={heroActions} />}
            >
                <CompensationTabs active="bands" />

                {/* Toolbar */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                        <div className="relative w-full sm:max-w-xs">
                            <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={roleQuery}
                                onChange={(e) => setRoleQuery(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') onFilter({ role: roleQuery || null });
                                }}
                                onBlur={() => {
                                    if ((filters.role ?? '') !== roleQuery) onFilter({ role: roleQuery || null });
                                }}
                                placeholder="Filter by role…"
                                className="pl-8"
                                aria-label="Filter by position role"
                            />
                        </div>
                        <label className="flex items-center gap-2 whitespace-nowrap text-sm text-muted-foreground">
                            <Switch
                                checked={filters.active_only}
                                onCheckedChange={(checked) => onFilter({ active_only: checked })}
                                aria-label="Active bands only"
                            />
                            Active bands only
                        </label>
                        <label className="flex items-center gap-2 whitespace-nowrap text-sm text-muted-foreground">
                            As of
                            <Input
                                type="date"
                                value={filters.as_of ?? ''}
                                onChange={(e) => onFilter({ as_of: e.target.value })}
                                className="h-9 w-auto"
                                aria-label="Bands active as of date"
                            />
                        </label>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href={exportUrl}>
                                <Download className="mr-1.5 h-4 w-4" />
                                Export
                            </a>
                        </Button>
                        {can.manage ? (
                            <Button size="sm" onClick={openCreate}>
                                <Plus className="mr-1.5 h-4 w-4" />
                                New band
                            </Button>
                        ) : null}
                    </div>
                </div>

                {/* Legend */}
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px] text-muted-foreground">
                    <span className="inline-flex items-center gap-1.5">
                        <span className="h-2.5 w-2.5 rounded-full bg-primary" /> In band
                    </span>
                    <span className="inline-flex items-center gap-2">
                        <span className="h-2 w-2 rotate-45 rounded-[2px] bg-status-critical" /> Under / over band
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <span className="h-3 w-0.5 rounded bg-primary" /> Midpoint (compa-ratio 1.0)
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <span className="h-2.5 w-6 rounded-full bg-primary/25" /> Target zone (0.9–1.1)
                    </span>
                </div>

                {/* Band rows */}
                {bands.data.length > 0 ? (
                    <div className="flex flex-col gap-3">
                        {bands.data.map((band) => (
                            <BandRow
                                key={band.id}
                                band={band}
                                canManage={can.manage}
                                onView={() => setDrawerBand(band)}
                                onEdit={() => openEdit(band)}
                                onDuplicate={() => openDuplicate(band)}
                            />
                        ))}
                    </div>
                ) : (
                    <EmptyState
                        icon={Layers}
                        heading={filters.role || filters.active_only ? 'No bands match your filters' : 'No salary bands yet'}
                        description={
                            filters.role || filters.active_only
                                ? 'Try clearing the role filter or the active-only toggle.'
                                : 'Create your first salary band to start placing people by compa-ratio.'
                        }
                        action={
                            can.manage && !(filters.role || filters.active_only) ? (
                                <Button size="sm" onClick={openCreate}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New band
                                </Button>
                            ) : undefined
                        }
                    />
                )}

                {bands?.links?.length ? <LaravelPagination links={bands.links} /> : null}
            </PageLayout>

            <BandDrawer
                band={drawerBand}
                open={drawerBand !== null}
                canManage={can.manage}
                onClose={() => setDrawerBand(null)}
                onEdit={(b) => {
                    setDrawerBand(null);
                    openEdit(b);
                }}
                onDuplicate={(b) => {
                    setDrawerBand(null);
                    openDuplicate(b);
                }}
            />

            {wizardOpen ? (
                <BandWizard
                    key={editId ?? 'new'}
                    open={wizardOpen}
                    editId={editId}
                    initial={wizardInitial}
                    existingBands={bands.data}
                    onClose={() => setWizardOpen(false)}
                />
            ) : null}
        </AppLayout>
    );
}
