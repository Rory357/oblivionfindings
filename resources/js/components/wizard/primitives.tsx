/* eslint-disable no-restricted-syntax -- Shared wizard primitives extracted from
 * the Add Client wizard (resources/js/components/clients/add-client-dialog.tsx),
 * the reference implementation for every multi-step popup workflow. The tile
 * pickers, chips and segmented controls are intentionally styled native
 * controls; every colour comes from semantic design tokens per
 * docs/DESIGN_TOKENS.md. Wizard chrome constants (rail width, progress-bar
 * height) live here so Handover/Shift Note/Template wizards stay visually
 * identical to the reference. */
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { AlertTriangle, Check } from 'lucide-react';
import {
    cloneElement,
    isValidElement,
    useId,
    type ComponentType,
    type ReactElement,
    type ReactNode,
} from 'react';

export type IconType = ComponentType<{ className?: string }>;

/* ------------------------------------------------------------------ */
/*  Wizard chrome constants (Add Client reference contract)            */
/* ------------------------------------------------------------------ */

/** Stepper rail: fixed width sidebar with the app sidebar surface colour. */
export const WIZARD_RAIL_CLASS =
    'hidden w-[248px] shrink-0 flex-col gap-1 overflow-y-auto border-r border-sidebar-border bg-sidebar p-4 sm:flex';

/** Thin progress strip under the wizard header. */
export const WIZARD_PROGRESS_TRACK_CLASS = 'h-[3px] shrink-0 bg-muted';
export const WIZARD_PROGRESS_BAR_CLASS =
    'h-full bg-primary transition-[width] duration-300';

/** Footer band housing Back / Cancel / Continue. */
export const WIZARD_FOOTER_CLASS =
    'flex shrink-0 items-center justify-between gap-3 border-t border-border bg-muted/30 px-5 py-3.5';

/* ------------------------------------------------------------------ */
/*  Field + section primitives                                         */
/* ------------------------------------------------------------------ */

export function FieldErr({ children }: { children?: ReactNode }) {
    if (!children) return null;
    return (
        <p className="mt-1 flex items-center gap-1 text-xs text-status-critical">
            <AlertTriangle className="h-3 w-3 shrink-0" />
            {children}
        </p>
    );
}

export function Field({
    label,
    required,
    hint,
    error,
    span,
    children,
}: {
    label?: string;
    required?: boolean;
    hint?: string;
    error?: string;
    span?: boolean;
    children: ReactNode;
}) {
    // Associate the visible label with its control for screen readers. Only a
    // single child element without its own id is given the generated id (so
    // composite controls — Select/TilePicker/fragments — are left untouched and
    // the htmlFor simply doesn't bind, exactly as before).
    const generatedId = useId();
    const child = isValidElement(children)
        ? (children as ReactElement<{ id?: string }>)
        : null;
    const controlId = child && child.props.id == null ? generatedId : undefined;
    const control = controlId
        ? cloneElement(child as ReactElement<{ id?: string }>, {
              id: controlId,
          })
        : children;

    return (
        <div className={cn('min-w-0', span && 'sm:col-span-2')}>
            {label ? (
                <Label
                    htmlFor={controlId}
                    className="mb-1.5 flex items-center gap-1.5"
                >
                    {label}
                    {required ? (
                        <span className="text-status-critical">*</span>
                    ) : null}
                    {hint ? (
                        <span className="text-xs font-normal text-muted-foreground">
                            {hint}
                        </span>
                    ) : null}
                </Label>
            ) : null}
            {control}
            <FieldErr>{error}</FieldErr>
        </div>
    );
}

export function SubHead({
    icon: Icon,
    children,
}: {
    icon: IconType;
    children: ReactNode;
}) {
    return (
        <div className="col-span-full mt-1 flex items-center gap-2 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
            <Icon className="h-3.5 w-3.5" />
            {children}
        </div>
    );
}

export function StepHead({
    icon: Icon,
    title,
    blurb,
}: {
    icon: IconType;
    title: string;
    blurb: string;
}) {
    return (
        <div className="mb-5 flex items-start gap-3">
            <span className="shrink-0 rounded-xl bg-primary/10 p-2.5 text-primary">
                <Icon className="h-5 w-5" />
            </span>
            <div>
                <h2 className="text-lg font-bold tracking-tight">{title}</h2>
                <p className="mt-0.5 text-sm text-muted-foreground">{blurb}</p>
            </div>
        </div>
    );
}

export function InfoCard({
    icon: Icon,
    tone = 'info',
    children,
}: {
    icon: IconType;
    tone?: 'info' | 'warn' | 'crit';
    children: ReactNode;
}) {
    const tones = {
        info: 'border-primary/35 bg-primary/10 text-primary',
        warn: 'border-status-warning/35 bg-status-warning-bg text-status-warning',
        crit: 'border-status-critical/35 bg-status-critical-bg text-status-critical',
    }[tone];
    return (
        <div
            className={cn(
                'col-span-full flex gap-2.5 rounded-lg border p-3',
                tones,
            )}
        >
            <Icon className="mt-0.5 h-4 w-4 shrink-0" />
            <div className="text-[13px] leading-relaxed text-foreground">
                {children}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Inputs                                                             */
/* ------------------------------------------------------------------ */

export function SelectInput({
    value,
    onChange,
    placeholder,
    options,
    ariaLabel,
}: {
    value: string;
    onChange: (v: string) => void;
    placeholder: string;
    options: { value: string; label: string }[];
    /** Accessible name for the trigger; falls back to the placeholder so a
     *  placeholder-only (unselected) trigger is never a nameless button (axe button-name). */
    ariaLabel?: string;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger
                className="w-full"
                aria-label={ariaLabel ?? placeholder}
            >
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {options.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export function Segmented<T extends string>({
    value,
    onChange,
    options,
}: {
    value: T;
    onChange: (v: T) => void;
    options: { value: T; label: string; icon?: IconType; disabled?: boolean }[];
}) {
    return (
        <div className="inline-flex flex-wrap gap-1 rounded-lg bg-muted p-1">
            {options.map((o) => {
                const active = value === o.value;
                const Icon = o.icon;
                return (
                    <button
                        key={o.value}
                        type="button"
                        onClick={() => onChange(o.value)}
                        aria-pressed={active}
                        disabled={o.disabled}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[13px] font-semibold transition-colors',
                            active
                                ? 'bg-card text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                            o.disabled &&
                                'cursor-not-allowed opacity-50 hover:text-muted-foreground',
                        )}
                    >
                        {Icon ? <Icon className="h-3.5 w-3.5" /> : null}
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}

export function ChipMulti({
    values,
    onChange,
    options,
}: {
    values: string[];
    onChange: (v: string[]) => void;
    options: string[];
}) {
    const toggle = (o: string) =>
        onChange(
            values.includes(o) ? values.filter((v) => v !== o) : [...values, o],
        );
    return (
        <div className="flex flex-wrap gap-1.5">
            {options.map((o) => {
                const active = values.includes(o);
                return (
                    <button
                        key={o}
                        type="button"
                        aria-pressed={active}
                        onClick={() => toggle(o)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                            active
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-border bg-card text-foreground hover:border-primary/50',
                        )}
                    >
                        {active ? <Check className="h-3 w-3" /> : null}
                        {o}
                    </button>
                );
            })}
        </div>
    );
}

export function TilePicker({
    value,
    onChange,
    options,
    cols = 2,
}: {
    value: string;
    onChange: (v: string) => void;
    options: {
        key: string;
        label: string;
        description?: string;
        icon?: IconType;
        accent?: string;
        /** Optional highlighted line under the description (e.g. eligibility). */
        meta?: string;
    }[];
    cols?: 2 | 3;
}) {
    return (
        <div
            className={cn(
                'grid gap-2',
                cols === 3
                    ? 'grid-cols-2 sm:grid-cols-3'
                    : 'grid-cols-1 sm:grid-cols-2',
            )}
        >
            {options.map((o) => {
                const Icon = o.icon;
                const active = value === o.key;
                return (
                    <button
                        key={o.key}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(o.key)}
                        className={cn(
                            'flex items-start gap-2.5 rounded-lg border bg-card/50 p-3 text-left transition-all hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            active
                                ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                : 'border-border',
                        )}
                    >
                        {Icon ? (
                            <span
                                className={cn(
                                    'mt-0.5 shrink-0 rounded-lg p-1.5',
                                    active ? 'bg-primary/15' : 'bg-muted',
                                )}
                            >
                                <Icon
                                    className={cn(
                                        'h-4 w-4',
                                        active
                                            ? 'text-primary'
                                            : (o.accent ??
                                                  'text-muted-foreground'),
                                    )}
                                />
                            </span>
                        ) : null}
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold">
                                {o.label}
                            </span>
                            {o.description ? (
                                <span className="mt-0.5 block text-xs leading-snug text-muted-foreground">
                                    {o.description}
                                </span>
                            ) : null}
                            {o.meta ? (
                                <span className="mt-1 block text-[11px] font-medium text-primary">
                                    {o.meta}
                                </span>
                            ) : null}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

/** SVG completeness ring used in wizard review steps. */
export function Ring({ pct, size = 56 }: { pct: number; size?: number }) {
    const r = (size - 7) / 2;
    const c = 2 * Math.PI * r;
    return (
        <div
            className="relative shrink-0"
            style={{ width: size, height: size }}
        >
            <svg width={size} height={size} className="-rotate-90">
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={r}
                    fill="none"
                    stroke="var(--muted)"
                    strokeWidth="6"
                />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={r}
                    fill="none"
                    stroke="var(--primary)"
                    strokeWidth="6"
                    strokeLinecap="round"
                    strokeDasharray={c}
                    strokeDashoffset={c * (1 - pct / 100)}
                    className="transition-[stroke-dashoffset] duration-500"
                />
            </svg>
            <span className="absolute inset-0 grid place-items-center text-[13px] font-bold">
                {pct}%
            </span>
        </div>
    );
}
