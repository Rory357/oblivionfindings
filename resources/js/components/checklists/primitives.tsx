import {
    Check,
    ChevronsUpDown,
    ClipboardCheck,
    LayoutGrid,
    type LucideIcon,
    Rows3,
    Search,
    X,
} from 'lucide-react';
import {
    createElement,
    type ReactNode,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

import { cn } from '@/lib/utils';

import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import { catBgVar, catColorVar } from './category';
import { useChecklistConfig } from './context';
import { categoryIcon } from './icons';

/* ---- Floating dropdown (replaces native <select>) ----------------------- */
export interface DropdownOption {
    value: string;
    label: string;
    sub?: string;
    /** Colour dot (CSS value) shown left of the label. */
    dot?: string;
    /** Leading icon (used when there's no dot). */
    Icon?: LucideIcon;
}

interface DropdownPos {
    top: number;
    left: number;
    width: number;
    maxH: number;
}

export function Dropdown({
    value,
    onChange,
    options,
    Icon,
    placeholder = 'Select…',
    searchable = false,
    dark = false,
    align = 'left',
    className,
    menuWidth,
}: {
    value: string;
    onChange: (value: string) => void;
    options: DropdownOption[];
    Icon?: LucideIcon;
    placeholder?: string;
    searchable?: boolean;
    dark?: boolean;
    align?: 'left' | 'right';
    className?: string;
    menuWidth?: number;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [pos, setPos] = useState<DropdownPos | null>(null);
    const ref = useRef<HTMLDivElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            setQ('');
            return;
        }
        const place = () => {
            const el = ref.current;
            if (!el) return;
            const r = el.getBoundingClientRect();
            const w = menuWidth ?? Math.max(r.width, 220);
            let left = align === 'right' ? r.right - w : r.left;
            left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
            setPos({
                top: r.bottom + 6,
                left,
                width: w,
                maxH: window.innerHeight - r.bottom - 20,
            });
        };
        place();
        const onDoc = (e: MouseEvent) => {
            if (
                ref.current &&
                !ref.current.contains(e.target as Node) &&
                menuRef.current &&
                !menuRef.current.contains(e.target as Node)
            ) {
                setOpen(false);
            }
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        const onScroll = () => setOpen(false);
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        window.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', onScroll, true);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll, true);
        };
    }, [open, align, menuWidth]);

    const selected = options.find((o) => String(o.value) === String(value));
    const norm = (s?: string) =>
        (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const filtered = useMemo(() => {
        if (!searchable || !q) return options;
        const nq = norm(q);
        return options.filter(
            (o) => norm(o.label).includes(nq) || norm(o.sub).includes(nq),
        );
    }, [options, q, searchable]);

    const trigger = dark
        ? 'border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/15 focus:border-primary-foreground/50'
        : 'border-input bg-card text-foreground hover:bg-accent/50 focus:border-ring';
    const subColor = dark
        ? 'text-primary-foreground/60'
        : 'text-muted-foreground';

    return (
        <div ref={ref} className={cn('relative', className)}>
            <GuardrailButton
                unstyled
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-haspopup="listbox"
                aria-expanded={open}
                className={cn(
                    'flex h-9 w-full items-center gap-2 rounded-md border px-2.5 text-sm font-medium transition-colors outline-none',
                    trigger,
                )}
            >
                {Icon ? <Icon className={cn('h-3.5 w-3.5', subColor)} /> : null}
                <span
                    className={cn(
                        'flex-1 truncate text-left',
                        !selected && subColor,
                    )}
                >
                    {selected ? selected.label : placeholder}
                </span>
                <ChevronsUpDown className={cn('h-3.5 w-3.5', subColor)} />
            </GuardrailButton>
            {open && pos ? (
                <div
                    ref={menuRef}
                    role="listbox"
                    className="fixed z-50 overflow-hidden rounded-xl border border-border bg-popover text-popover-foreground shadow-xl ring-1 ring-black/5"
                    style={{ top: pos.top, left: pos.left, width: pos.width }}
                >
                    {searchable ? (
                        <div className="border-b border-border p-2">
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                <input
                                    autoFocus
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder="Search…"
                                    className="h-8 w-full rounded-md border border-input bg-background pr-2 pl-8 text-sm outline-none focus:border-ring focus:ring-2 focus:ring-ring/30"
                                />
                            </div>
                        </div>
                    ) : null}
                    <div
                        className="overflow-y-auto p-1"
                        style={{ maxHeight: Math.min(pos.maxH, 320) }}
                    >
                        {filtered.length === 0 ? (
                            <div className="px-3 py-6 text-center text-xs text-muted-foreground">
                                No matches
                            </div>
                        ) : (
                            filtered.map((o) => {
                                const active =
                                    String(o.value) === String(value);
                                const OptIcon = o.Icon;
                                return (
                                    <GuardrailButton
                                        unstyled
                                        key={o.value}
                                        type="button"
                                        role="option"
                                        aria-selected={active}
                                        onClick={() => {
                                            onChange(o.value);
                                            setOpen(false);
                                        }}
                                        className={cn(
                                            'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition-colors',
                                            active
                                                ? 'bg-accent text-accent-foreground'
                                                : 'hover:bg-accent/60',
                                        )}
                                    >
                                        {o.dot ? (
                                            <span
                                                className="h-2.5 w-2.5 shrink-0 rounded-full"
                                                style={{ background: o.dot }}
                                            />
                                        ) : OptIcon ? (
                                            <OptIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                        ) : null}
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">
                                                {o.label}
                                            </span>
                                            {o.sub ? (
                                                <span className="block truncate text-[11px] text-muted-foreground">
                                                    {o.sub}
                                                </span>
                                            ) : null}
                                        </span>
                                        {active ? (
                                            <Check className="h-3.5 w-3.5 shrink-0 text-primary" />
                                        ) : null}
                                    </GuardrailButton>
                                );
                            })
                        )}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

/* ---- View toggle (Board / List) ----------------------------------------- */
export type ChecklistView = 'board' | 'list';

export function ViewToggle({
    value,
    onChange,
}: {
    value: ChecklistView;
    onChange: (v: ChecklistView) => void;
}) {
    const opts: { key: ChecklistView; Icon: LucideIcon; label: string }[] = [
        { key: 'board', Icon: LayoutGrid, label: 'Board' },
        { key: 'list', Icon: Rows3, label: 'List' },
    ];
    return (
        <GuardrailCard
            unstyled
            className="inline-flex items-center rounded-lg border border-border bg-card p-0.5"
        >
            {opts.map((o) => {
                const active = o.key === value;
                const Icon = o.Icon;
                return (
                    <GuardrailButton
                        unstyled
                        key={o.key}
                        type="button"
                        onClick={() => onChange(o.key)}
                        title={o.label}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors',
                            active
                                ? 'bg-primary text-primary-foreground shadow-sm'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        )}
                    >
                        <Icon className="h-3.5 w-3.5" />
                        <span className="hidden sm:inline">{o.label}</span>
                    </GuardrailButton>
                );
            })}
        </GuardrailCard>
    );
}

/* ---- Category icon tile -------------------------------------------------- */
export function CategoryIcon({
    category,
    size = 18,
    box = 36,
    className,
}: {
    category: string | null | undefined;
    size?: number;
    box?: number;
    className?: string;
}) {
    const { categoryMap } = useChecklistConfig();
    const cat = category ? categoryMap[category] : undefined;
    // `createElement` (rather than `<Icon … />` bound to a local) keeps the
    // eslint react-hooks/static-components rule from flagging the dynamic icon
    // lookup as "creating a component during render" — categoryIcon() returns a
    // stable, registry-looked-up component, so this re-uses it, never re-creates it.
    return (
        <span
            className={cn(
                'flex shrink-0 items-center justify-center rounded-lg',
                className,
            )}
            style={{
                width: box,
                height: box,
                background: catBgVar(cat?.tone),
                color: catColorVar(cat?.tone),
            }}
        >
            {createElement(categoryIcon(cat?.icon), {
                style: { width: size, height: size },
            })}
        </span>
    );
}

export function CategoryDot({
    category,
    size = 8,
}: {
    category: string | null | undefined;
    size?: number;
}) {
    const { categoryMap } = useChecklistConfig();
    const cat = category ? categoryMap[category] : undefined;
    return (
        <span
            className="inline-block shrink-0 rounded-full"
            style={{
                width: size,
                height: size,
                background: catColorVar(cat?.tone),
            }}
        />
    );
}

/* ---- Status badge -------------------------------------------------------- */
export type BadgeTone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

const STATUS_TONE: Record<BadgeTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'bg-muted text-muted-foreground',
};

export function StatusBadge({
    tone = 'neutral',
    Icon,
    className,
    children,
}: {
    tone?: BadgeTone;
    Icon?: LucideIcon;
    className?: string;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md border border-transparent px-2 py-0.5 text-xs font-medium',
                STATUS_TONE[tone],
                className,
            )}
        >
            {Icon ? <Icon className="h-3 w-3" /> : null}
            {children}
        </span>
    );
}

export function CountBadge({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md border border-border px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground tabular-nums',
                className,
            )}
        >
            {children}
        </span>
    );
}

/* ---- Progress bar -------------------------------------------------------- */
export function Progress({
    value,
    className,
}: {
    value: number;
    className?: string;
}) {
    const v = Math.min(100, Math.max(0, value || 0));
    const color = v === 100 ? 'var(--status-success)' : 'var(--primary)';
    return (
        <div
            className={cn(
                'h-1.5 overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            <div
                className="h-full rounded-full transition-all"
                style={{ width: `${v}%`, background: color }}
            />
        </div>
    );
}

/* ---- Empty state --------------------------------------------------------- */
export function Empty({
    Icon = ClipboardCheck,
    title,
    sub,
}: {
    Icon?: LucideIcon;
    title: string;
    sub?: string;
}) {
    return (
        <div className="flex flex-col items-center justify-center py-12 text-center">
            <Icon className="mb-2 h-9 w-9 text-muted-foreground/40" />
            <p className="text-sm font-medium">{title}</p>
            {sub ? (
                <p className="text-xs text-muted-foreground">{sub}</p>
            ) : null}
        </div>
    );
}

/* ---- Section header ------------------------------------------------------ */
export function SectionHead({
    title,
    desc,
    children,
}: {
    title: string;
    desc?: string;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 className="text-base font-semibold">{title}</h3>
                {desc ? (
                    <p className="text-sm text-muted-foreground">{desc}</p>
                ) : null}
            </div>
            {children ? (
                <div className="flex flex-wrap items-center gap-2">
                    {children}
                </div>
            ) : null}
        </div>
    );
}

/* ---- Search input -------------------------------------------------------- */
export function SearchInput({
    value,
    onChange,
    placeholder,
    className,
}: {
    value: string;
    onChange: (v: string) => void;
    placeholder?: string;
    className?: string;
}) {
    return (
        <div className={cn('relative', className)}>
            <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
            <input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="h-9 w-full rounded-md border border-input bg-card pr-8 pl-8 text-sm outline-none placeholder:text-muted-foreground focus:border-ring focus:ring-2 focus:ring-ring/30"
            />
            {value ? (
                <GuardrailButton
                    unstyled
                    type="button"
                    onClick={() => onChange('')}
                    className="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                >
                    <X className="h-3.5 w-3.5" />
                </GuardrailButton>
            ) : null}
        </div>
    );
}
