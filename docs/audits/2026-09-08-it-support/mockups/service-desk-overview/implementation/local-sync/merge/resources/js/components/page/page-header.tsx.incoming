/**
 * The Event Horizon page header (design_styles/PAGE_HEADER_STYLE_GUIDE.md,
 * meter-row revision 2026-09-06).
 *
 * ONE fixed-rhythm sky band opens every page, top to bottom: a top row of
 * identity (ring mark, title + status chip, one fact subline) left and
 * scoped search + actions right; ONE FULL-WIDTH METER ROW of instrument
 * blocks — every block a link to the view where its number lives; the
 * page-specific filter row; and the page's main view tabs on the bottom
 * edge as the Rule 1 connected-tab rail.
 *
 * `variant="index" | "profile"` covers the mark/back-chip difference —
 * never fork the band per page. Pages supply slot CONTENT only, composed
 * from the exported pieces below (PageHeaderSearch, PageHeaderMeterBlock,
 * PageHeaderFilterSelect, PageHeaderRail, …).
 *
 * Theme purity (enforced by the components/page/** ESLint scope): light
 * glass is --primary-foreground tokens, the sky/ring/meter surfaces are
 * the `.eh-header` / `.eh-mark-ring` / `.eh-meter` utilities in app.css —
 * all --primary-derived via color-mix so branding retints everything
 * (safety tones stay the fixed status tokens).
 */
import { Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Check,
    ChevronDown,
    ChevronLeft,
    MoreHorizontal,
    Search,
    TrendingDown,
    TrendingUp,
    X,
} from 'lucide-react';
import {
    type ButtonHTMLAttributes,
    type ComponentType,
    type ReactNode,
    type Ref,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

type IconType = ComponentType<{ className?: string }>;

/* ------------------------------------------------------------------ */
/*  The band                                                           */
/* ------------------------------------------------------------------ */

export interface PageHeaderProps {
    variant?: 'index' | 'profile';
    /** Module icon rendered inside the 48px Event Horizon ring. */
    icon?: IconType;
    /** Custom mark (e.g. an avatar) — wins over `icon`, rendered inside the ring. */
    mark?: ReactNode;
    /** Profile variant only: the glass back chip destination. */
    backHref?: string;
    title: string;
    /** Dusk attribute for the title heading. */
    titleDusk?: string;
    /** Let record titles wrap and reflow actions when their combined width is constrained. */
    wrapTitle?: boolean;
    /** One status chip beside the title — use <PageHeaderStatusChip>. */
    titleChip?: ReactNode;
    /**
     * One line: the page description and/or key facts joined with middle
     * dots ("Occupancy, leads and safety · 3 regions · 28 clients").
     * Never a greeting (DESIGN.md anti-patterns).
     */
    subline?: ReactNode;
    /** Top row, right: scoped search + glass secondaries + exactly ONE primary. */
    actions?: ReactNode;
    /**
     * The full-width meter row: 4–6 <PageHeaderMeterBlock> links. Every
     * block navigates to the view where its number lives; a block whose
     * data source doesn't exist yet is dropped, never faked (DESIGN.md
     * anti-pattern "dead or decorative meter blocks").
     */
    meters?: ReactNode;
    /** Filter row: page-specific filter pills, all 23px tall. */
    filters?: ReactNode;
    /** Bottom edge: the connected-tab rail — <PageHeaderRail>. */
    rail?: ReactNode;
    className?: string;
}

export function PageHeader({
    variant = 'index',
    icon: Icon,
    mark,
    backHref,
    title,
    titleDusk,
    wrapTitle = false,
    titleChip,
    subline,
    actions,
    meters,
    filters,
    rail,
    className,
}: PageHeaderProps) {
    return (
        <header className={cn('eh-header text-primary-foreground', className)}>
            <div className="relative z-[1] flex h-full flex-col">
                <div className="flex flex-col px-[22px] pt-[18px]">
                    {/* top row — identity left, search/actions right */}
                    <div
                        className={cn(
                            'flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-6',
                            wrapTitle && 'lg:flex-wrap',
                        )}
                    >
                        <div
                            className={cn(
                                'flex min-w-0 items-start gap-[13px]',
                                wrapTitle && 'lg:flex-[1_0_28rem]',
                            )}
                        >
                            {variant === 'profile' && backHref ? (
                                <Link
                                    href={backHref}
                                    aria-label="Back"
                                    className="mt-2 flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] border border-primary-foreground/20 bg-primary-foreground/10 transition-colors outline-none hover:bg-primary-foreground/20 focus-visible:ring-2 focus-visible:ring-primary-foreground/70"
                                >
                                    <ChevronLeft className="size-4" />
                                </Link>
                            ) : null}
                            {mark ??
                                (Icon ? (
                                    <span className="eh-mark-ring">
                                        <Icon className="size-5" />
                                    </span>
                                ) : null)}
                            <div className="flex min-w-0 flex-col">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1
                                        dusk={titleDusk}
                                        className={cn(
                                            'text-[22px] leading-tight font-bold tracking-tight',
                                            wrapTitle
                                                ? 'max-w-full min-w-0 break-words whitespace-normal'
                                                : 'truncate',
                                        )}
                                    >
                                        {title}
                                    </h1>
                                    {titleChip}
                                </div>
                                {subline ? (
                                    <p className="mt-[3px] text-[13px] text-primary-foreground/65">
                                        {subline}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                        {actions ? (
                            <div
                                className={cn(
                                    'flex shrink-0 flex-wrap items-center gap-2 lg:justify-end',
                                    wrapTitle && 'lg:ml-auto',
                                )}
                            >
                                {actions}
                            </div>
                        ) : null}
                    </div>

                    {/* the meter row — full width, every block a link */}
                    {meters ? (
                        <div className="mt-[13px] flex min-h-[80px] flex-wrap items-stretch gap-2">
                            {meters}
                        </div>
                    ) : null}

                    {/* filter row */}
                    {filters ? (
                        <div className="mt-[10px] flex flex-wrap items-center justify-end gap-1.5 pb-3">
                            {filters}
                        </div>
                    ) : (
                        <div className="pb-3" />
                    )}
                </div>

                {/* the rail — main view tabs, flush with the bottom edge */}
                {rail ? (
                    <div className="flex min-h-[46px] shrink-0 items-end px-[14px]">
                        {rail}
                    </div>
                ) : (
                    <div className="h-[18px] shrink-0" />
                )}
            </div>
        </header>
    );
}

/* ------------------------------------------------------------------ */
/*  Chips                                                              */
/* ------------------------------------------------------------------ */

/**
 * Status-language chip at the header's radius-8 (the header bans full
 * capsules). Same verified token pairs as <StatusBadge> — safety colours
 * never retint with brand.
 */
export function PageHeaderStatusChip({
    variant,
    icon: Icon,
    children,
    className,
}: {
    variant: StatusVariant;
    icon?: IconType;
    children: ReactNode;
    className?: string;
}) {
    return (
        <StatusBadge
            variant={variant}
            className={cn('rounded-[8px] font-semibold', className)}
        >
            {Icon ? <Icon className="size-3" /> : null}
            {children}
        </StatusBadge>
    );
}

/* ------------------------------------------------------------------ */
/*  Top row — search + actions                                         */
/* ------------------------------------------------------------------ */

/**
 * The scoped section search — ALWAYS inside the header. `/` focuses it
 * (the global ⌘K command search stays in the top bar; the two never merge).
 */
export function PageHeaderSearch({
    value,
    onChange,
    onKeyDown,
    placeholder,
    className,
}: {
    value: string;
    onChange: (value: string) => void;
    onKeyDown?: (e: React.KeyboardEvent<HTMLInputElement>) => void;
    placeholder: string;
    className?: string;
}) {
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
            const t = e.target as HTMLElement | null;
            if (
                t &&
                (t.tagName === 'INPUT' ||
                    t.tagName === 'TEXTAREA' ||
                    t.tagName === 'SELECT' ||
                    t.isContentEditable)
            ) {
                return;
            }
            e.preventDefault();
            inputRef.current?.focus();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    return (
        <div
            className={cn(
                'relative min-w-[200px] flex-1 lg:w-[250px] lg:flex-none',
                className,
            )}
        >
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-primary-foreground/60" />
            <input
                ref={inputRef}
                type="search"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onKeyDown={onKeyDown}
                placeholder={placeholder}
                className="h-9 w-full rounded-[10px] border border-primary-foreground/20 bg-primary-foreground/10 pr-8 pl-9 text-[13px] text-primary-foreground outline-none placeholder:text-primary-foreground/55 focus-visible:border-primary-foreground/50 focus-visible:bg-primary-foreground/15 focus-visible:ring-2 focus-visible:ring-primary-foreground/40"
            />
            <kbd
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 rounded-[5px] border border-primary-foreground/25 px-1.5 py-px text-[10.5px] font-semibold text-primary-foreground/55"
            >
                /
            </kbd>
        </div>
    );
}

/**
 * Search-field-styled trigger for profiles whose scoped search opens a
 * palette (e.g. the section jump palette) instead of filtering in place.
 * Same glass field anatomy as <PageHeaderSearch> so the header reads
 * identically on index and profile pages; the page keeps owning the `/`
 * shortcut that opens the palette.
 */
export function PageHeaderSearchTrigger({
    placeholder,
    onOpen,
    className,
}: {
    placeholder: string;
    onOpen: () => void;
    className?: string;
}) {
    return (
        <button
            type="button"
            onClick={onOpen}
            className={cn(
                'relative inline-flex h-9 min-w-[200px] flex-1 items-center rounded-[10px] border border-primary-foreground/20 bg-primary-foreground/10 pr-8 pl-9 text-[13px] text-primary-foreground/55 transition-colors outline-none hover:bg-primary-foreground/15 focus-visible:border-primary-foreground/50 focus-visible:bg-primary-foreground/15 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 lg:w-[250px] lg:flex-none',
                className,
            )}
        >
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-primary-foreground/60" />
            <span className="truncate">{placeholder}</span>
            <kbd
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 rounded-[5px] border border-primary-foreground/25 px-1.5 py-px text-[10.5px] font-semibold text-primary-foreground/55"
            >
                /
            </kbd>
        </button>
    );
}

/** Glass secondary action (36px, radius 10). `active` inverts to solid. */
export function PageHeaderGlassButton({
    icon: Icon,
    active = false,
    className,
    children,
    ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    icon?: IconType;
    active?: boolean;
}) {
    return (
        <button
            type="button"
            {...rest}
            className={cn(
                'inline-flex h-9 items-center gap-1.5 rounded-[10px] border px-3.5 text-[13px] font-semibold transition-colors outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/70',
                children == null && 'w-9 justify-center px-0',
                active
                    ? 'border-primary-foreground bg-primary-foreground text-primary'
                    : 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
                className,
            )}
        >
            {Icon ? <Icon className="size-4" /> : null}
            {children}
        </button>
    );
}

/** THE primary action — white fill, brand-dark text. Never render two. */
export function PageHeaderPrimaryButton({
    icon: Icon,
    className,
    children,
    ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { icon?: IconType }) {
    return (
        <button
            type="button"
            {...rest}
            className={cn(
                'inline-flex h-9 items-center gap-1.5 rounded-[10px] bg-primary-foreground px-3.5 text-[13px] font-semibold text-primary shadow-sm transition-all outline-none hover:bg-primary-foreground/90 focus-visible:ring-2 focus-visible:ring-primary-foreground/70 active:scale-[0.98]',
                className,
            )}
        >
            {Icon ? <Icon className="size-4" /> : null}
            {children}
        </button>
    );
}

/* ------------------------------------------------------------------ */
/*  The meter row — instrument blocks (every block a link)             */
/* ------------------------------------------------------------------ */

export type PageHeaderMeterTone = 'brand' | 'success' | 'warning' | 'critical';

/**
 * One instrument block on the meter row. Dark glass; toned blocks take
 * the fixed status hue on border, label and headline value. ALWAYS a
 * link to the view where the number lives — pass `href` (page nav) or
 * `onClick` (switch a rail view / in-page layout); hover lifts the
 * block and reveals the corner ↗. Compose the body from
 * <PageHeaderMeterBig/Bar/Spark/Donut/Delta/Caption>.
 */
export function PageHeaderMeterBlock({
    label,
    value,
    tone = 'brand',
    href,
    preserveState,
    preserveScroll,
    onClick,
    ariaLabel,
    className,
    children,
}: {
    label: string;
    /** Optional headline value on the head row (e.g. "0/41 beds"). */
    value?: ReactNode;
    tone?: PageHeaderMeterTone;
    href?: string;
    /** Keep an in-page workspace and its unsent fields mounted when following a meter. */
    preserveState?: boolean;
    preserveScroll?: boolean;
    onClick?: () => void;
    /** Accessible name; defaults to "View <label>". */
    ariaLabel?: string;
    className?: string;
    children?: ReactNode;
}) {
    const body = (
        <>
            <ArrowUpRight aria-hidden="true" className="eh-meter-go" />
            <span className="flex w-full items-baseline justify-between gap-3 pr-2.5">
                <span className="eh-meter-label text-[10px] leading-none font-semibold tracking-[0.08em] whitespace-nowrap uppercase">
                    {label}
                </span>
                {value != null ? (
                    <span className="eh-meter-value text-[13px] leading-none font-bold tabular-nums">
                        {value}
                    </span>
                ) : null}
            </span>
            {children}
        </>
    );
    const shared = {
        'aria-label': ariaLabel ?? `View ${label.toLowerCase()}`,
        className: cn(
            'eh-meter outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/70',
            tone !== 'brand' && `eh-meter--${tone}`,
            className,
        ),
    };
    return href ? (
        <Link
            href={href}
            preserveState={preserveState}
            preserveScroll={preserveScroll}
            {...shared}
        >
            {body}
        </Link>
    ) : (
        <button type="button" onClick={onClick} {...shared}>
            {body}
        </button>
    );
}

/** The big number of a stat / delta-stat block (20px). */
export function PageHeaderMeterBig({ children }: { children: ReactNode }) {
    return (
        <span className="eh-meter-big text-xl leading-none font-bold tabular-nums">
            {children}
        </span>
    );
}

/** Muted 10.5px context line ("across 3 regions", "0% occupied · 41 available"). */
export function PageHeaderMeterCaption({ children }: { children: ReactNode }) {
    return (
        <span className="max-w-full truncate text-[10.5px] leading-tight text-primary-foreground/50">
            {children}
        </span>
    );
}

/** Toned trend delta — colour follows MEANING (good/bad), not direction. */
export function PageHeaderMeterDelta({
    trend,
    good = false,
    children,
}: {
    trend: 'up' | 'down';
    good?: boolean;
    children: ReactNode;
}) {
    const Icon = trend === 'up' ? TrendingUp : TrendingDown;
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 text-[10.5px] leading-none font-semibold',
                good ? 'eh-meter-delta--good' : 'eh-meter-delta--bad',
            )}
        >
            <Icon className="size-2.5" />
            {children}
        </span>
    );
}

/** 5px progress bar toward a capacity (occupancy, funding …). */
export function PageHeaderMeterBar({ percent }: { percent: number }) {
    const p = Math.max(0, Math.min(100, percent));
    return (
        <span className="eh-meter-bar" aria-hidden="true">
            <i style={{ width: `${p}%` }} />
        </span>
    );
}

/** 7-day trend sparkline (bars scale to the series max). */
export function PageHeaderMeterSpark({ values }: { values: number[] }) {
    const max = Math.max(...values, 1);
    return (
        <span className="eh-meter-spark" aria-hidden="true">
            {values.map((v, i) => (
                <i
                    key={i}
                    style={{
                        height: `${Math.max(2, Math.round((v / max) * 16))}px`,
                    }}
                />
            ))}
        </span>
    );
}

export interface PageHeaderMeterAvatar {
    id: number | string;
    name: string;
    photo_url?: string | null;
    /** One short context line under the name ("Active · Room 2"). */
    detail?: string | null;
    /** Deep link to this person's own profile — a face click goes HERE,
     *  not to the block's view (the click stops at the face). */
    href?: string | null;
}

function avatarInitials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

/**
 * Overlapping avatar stack for a people block — photos when available,
 * initials otherwise. The stack tightens as it grows and overflow beyond
 * the fetched faces renders a "+N" disc; hovering a face lifts it and
 * shows the person's name and detail line. The people COUNT belongs in
 * the block's head value, not here.
 */
export function PageHeaderMeterAvatars({
    people,
    overflow = 0,
}: {
    people: PageHeaderMeterAvatar[];
    overflow?: number;
}) {
    const tight = people.length + (overflow > 0 ? 1 : 0) > 6;
    return (
        <span className="flex items-center">
            {people.map((person, index) => (
                <Tooltip key={person.id}>
                    <TooltipTrigger asChild>
                        {/* span trigger — the whole meter block is already a
                         * link/button, so no nested interactive element. A
                         * face click stops there and deep-links to the
                         * person's profile instead of the block's view. */}
                        <span
                            onClick={
                                person.href
                                    ? (event) => {
                                          event.preventDefault();
                                          event.stopPropagation();
                                          router.visit(person.href!);
                                      }
                                    : undefined
                            }
                            className={cn(
                                'relative inline-flex transition-transform hover:z-10 hover:-translate-y-0.5',
                                person.href && 'cursor-pointer',
                                index > 0 && (tight ? '-ml-3' : '-ml-2'),
                            )}
                        >
                            <Avatar className="size-[26px] border-2 border-primary-foreground/35">
                                {person.photo_url ? (
                                    <AvatarImage
                                        src={person.photo_url}
                                        alt={person.name}
                                    />
                                ) : null}
                                <AvatarFallback className="bg-primary-foreground/15 text-[9px] font-semibold text-primary-foreground">
                                    {avatarInitials(person.name)}
                                </AvatarFallback>
                            </Avatar>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                        <span className="block font-semibold">
                            {person.name}
                        </span>
                        {person.detail ? (
                            <span className="block text-primary-foreground/75">
                                {person.detail}
                            </span>
                        ) : null}
                        {person.href ? (
                            <span className="block text-primary-foreground/60">
                                Click to open profile
                            </span>
                        ) : null}
                    </TooltipContent>
                </Tooltip>
            ))}
            {overflow > 0 ? (
                <span
                    className={cn(
                        'z-[1] inline-flex size-[26px] items-center justify-center rounded-full border-2 border-primary-foreground/35 bg-primary-foreground/15 text-[9px] font-bold text-primary-foreground tabular-nums',
                        people.length > 0 && (tight ? '-ml-3' : '-ml-2'),
                    )}
                >
                    +{overflow}
                </span>
            ) : null}
        </span>
    );
}

export interface PageHeaderMeterContact {
    label: string;
    name?: string | null;
    phone?: string | null;
}

/**
 * Compact key-contact rows for a record's contact block — "MANAGER
 * Kiri Waititi · 021 447 902". A row whose contact is missing reads
 * "No info" rather than disappearing, so the roles stay scannable.
 */
export function PageHeaderMeterContacts({
    contacts,
}: {
    contacts: PageHeaderMeterContact[];
}) {
    return (
        <span className="flex w-full min-w-0 flex-col gap-[3px]">
            {contacts.map((contact) => (
                <span
                    key={contact.label}
                    className="flex min-w-0 items-baseline gap-1.5 text-[10.5px] leading-tight"
                >
                    <span className="eh-meter-label shrink-0 text-[9px] leading-none font-semibold tracking-[0.08em] uppercase">
                        {contact.label}
                    </span>
                    {contact.name ? (
                        <span className="min-w-0 truncate font-medium text-primary-foreground/85">
                            {contact.name}
                            {contact.phone ? (
                                <span className="font-normal text-primary-foreground/55">
                                    {' '}
                                    · {contact.phone}
                                </span>
                            ) : null}
                        </span>
                    ) : (
                        <span className="text-primary-foreground/45">
                            No info
                        </span>
                    )}
                </span>
            ))}
        </span>
    );
}

const DONUT_CIRCUMFERENCE = 2 * Math.PI * 16;

/** 34px share-of-a-whole ring with the % centred and the fraction beside. */
export function PageHeaderMeterDonut({
    percent,
    caption,
}: {
    percent: number;
    caption?: ReactNode;
}) {
    const p = Math.max(0, Math.min(100, Math.round(percent)));
    return (
        <span className="flex items-center gap-2.5">
            <span className="relative size-[34px] shrink-0">
                <svg
                    viewBox="0 0 40 40"
                    className="absolute inset-0 -rotate-90"
                >
                    <circle
                        cx="20"
                        cy="20"
                        r="16"
                        fill="none"
                        strokeWidth="4"
                        className="stroke-primary-foreground/15"
                    />
                    <circle
                        cx="20"
                        cy="20"
                        r="16"
                        fill="none"
                        strokeWidth="4"
                        strokeLinecap="round"
                        strokeDasharray={`${(p / 100) * DONUT_CIRCUMFERENCE} ${DONUT_CIRCUMFERENCE}`}
                        className="eh-meter-donut-arc"
                    />
                </svg>
                <span className="eh-meter-donut-v absolute inset-0 grid place-items-center text-[10px] leading-none font-bold tabular-nums">
                    {p}%
                </span>
            </span>
            {caption ? (
                <span className="text-[10.5px] leading-[1.35] text-primary-foreground/50">
                    {caption}
                </span>
            ) : null}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Filter row — filter pills (every field one 23px box)               */
/* ------------------------------------------------------------------ */

const FILTER_FIELD =
    'box-border h-[23px] rounded-[8px] border text-[11.5px] font-semibold transition-colors outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/70';

/**
 * Generic 23px glass filter-row button for page-specific controls that
 * aren't a select/check/toggle — a period stepper, a "Today" jump, a
 * popover trigger. Icon-only when no children; `active` inverts to the
 * white fill like the other filter fields. Accepts a `ref` (React 19
 * ref-as-prop) so it can anchor popovers/pickers.
 */
export function PageHeaderFilterButton({
    icon: Icon,
    active = false,
    className,
    children,
    ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    icon?: IconType;
    active?: boolean;
    ref?: Ref<HTMLButtonElement>;
}) {
    return (
        <button
            type="button"
            {...rest}
            className={cn(
                'inline-flex items-center gap-1 px-2',
                FILTER_FIELD,
                children == null && 'w-[23px] justify-center px-0',
                active
                    ? 'border-primary-foreground bg-primary-foreground text-primary'
                    : 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
                className,
            )}
        >
            {Icon ? <Icon className="size-3 shrink-0" /> : null}
            {children}
        </button>
    );
}

/** Glass dropdown filter chip (label + chevron; ✕ clears when active). */
export function PageHeaderFilterSelect({
    icon: Icon,
    label,
    value,
    allValue = 'all',
    options,
    onChange,
}: {
    icon?: IconType;
    label: string;
    value: string;
    allValue?: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const current = options.find((o) => o.value === value);
    const active = value !== allValue;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            {/* Chrome on a non-interactive wrapper so the clear "✕" is a real
             * sibling <button>, not nested inside the trigger. */}
            <div
                className={cn(
                    'inline-flex items-center',
                    FILTER_FIELD,
                    active
                        ? 'border-primary-foreground bg-primary-foreground text-primary'
                        : 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
                )}
            >
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        className={cn(
                            'inline-flex h-full items-center gap-1 rounded-[8px] pl-2 outline-none',
                            active ? 'pr-1' : 'pr-2',
                        )}
                    >
                        {Icon ? (
                            <Icon className="size-2.5 shrink-0 opacity-70" />
                        ) : null}
                        <span className="max-w-[130px] truncate">
                            {active ? (current?.label ?? label) : label}
                        </span>
                        {!active ? (
                            <ChevronDown className="size-3 opacity-70" />
                        ) : null}
                    </button>
                </PopoverTrigger>
                {active ? (
                    <button
                        type="button"
                        aria-label={`Clear ${label}`}
                        onClick={() => onChange(allValue)}
                        className="mr-1.5 inline-flex size-3.5 shrink-0 items-center justify-center rounded-[4px] hover:bg-primary/20"
                    >
                        <X className="size-2.5" />
                    </button>
                ) : null}
            </div>
            <PopoverContent align="end" className="w-52 p-1">
                {options.map((o) => (
                    <button
                        key={o.value}
                        type="button"
                        onClick={() => {
                            onChange(o.value);
                            setOpen(false);
                        }}
                        className={cn(
                            'flex w-full items-center justify-between gap-2 rounded-md px-2.5 py-2 text-sm text-foreground hover:bg-muted',
                            o.value === value && 'font-semibold text-primary',
                        )}
                    >
                        {o.label}
                        {o.value === value ? (
                            <Check className="size-4" />
                        ) : null}
                    </button>
                ))}
            </PopoverContent>
        </Popover>
    );
}

/** Checkbox filter chip ("Archived"). */
export function PageHeaderFilterCheck({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            className={cn(
                'inline-flex items-center gap-1.5 px-2',
                FILTER_FIELD,
                checked
                    ? 'border-primary-foreground bg-primary-foreground text-primary'
                    : 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'flex size-3 items-center justify-center rounded-[3px] border',
                    checked
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-primary-foreground/60 text-transparent',
                )}
            >
                <Check className="size-2.5" />
            </span>
            {label}
        </button>
    );
}

/** Segmented view toggle (e.g. Cards–Table). White active segment. */
export function PageHeaderViewToggle<K extends string>({
    value,
    onChange,
    options,
    ariaLabel = 'View',
}: {
    value: K;
    onChange: (value: K) => void;
    options: { value: K; label: string; icon?: IconType }[];
    ariaLabel?: string;
}) {
    return (
        <div
            role="radiogroup"
            aria-label={ariaLabel}
            className={cn(
                'inline-flex items-stretch gap-0.5 p-[2px]',
                FILTER_FIELD,
                'border-primary-foreground/20 bg-primary-foreground/10',
            )}
        >
            {options.map((o) => {
                const on = o.value === value;
                const Icon = o.icon;
                return (
                    <button
                        key={o.value}
                        type="button"
                        role="radio"
                        aria-checked={on}
                        onClick={() => onChange(o.value)}
                        className={cn(
                            'inline-flex items-center gap-1 rounded-[6px] px-2 text-[11.5px] font-semibold outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/70',
                            on
                                ? 'bg-primary-foreground text-primary'
                                : 'text-primary-foreground/80 hover:text-primary-foreground',
                        )}
                    >
                        {Icon ? <Icon className="size-2.5" /> : null}
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  The rail — Rule 1 connected tabs                                   */
/* ------------------------------------------------------------------ */

export interface PageHeaderRailItem<K extends string = string> {
    key: K;
    label: string;
    icon?: IconType;
    count?: number;
    /** Alert counts keep the fixed critical pair in every state. */
    alert?: boolean;
}

/** Must match the rail container's `gap-1`. */
const RAIL_GAP = 4;

const railTabClass = (on: boolean) =>
    cn(
        'inline-flex shrink-0 items-center gap-[7px] outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/80',
        on
            ? // pb-[6px] keeps the active label on the
              // inactive pills' optical text line: their
              // centre sits 34/2 + 6px margin = 23px above
              // the band edge; (40 − 6)/2 + 6 = 23px here
              // (DESIGN.md "Sunken active-rail labels").
              'h-10 rounded-t-[12px] bg-background px-[17px] pb-[6px] text-[13.5px] font-semibold text-primary'
            : 'mb-[6px] h-[34px] rounded-[9px] px-[13px] text-[13px] font-medium text-primary-foreground/80 transition-colors hover:bg-primary-foreground/10 hover:text-primary-foreground',
    );

/** Ghost utility pills at the rail's end (⋯ More, ⌕ Find) — inactive-pill geometry. */
const railPillClass =
    'mb-[6px] inline-flex h-[34px] shrink-0 items-center gap-1.5 rounded-[9px] px-[13px] text-[13px] font-medium text-primary-foreground/80 transition-colors outline-none hover:bg-primary-foreground/10 hover:text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/80';

/** Inner content of a rail tab — shared by the live row and the measurement row. */
function RailTabInner({
    item,
    on,
    decoration,
}: {
    item: PageHeaderRailItem;
    on: boolean;
    decoration?: ReactNode;
}) {
    const Icon = item.icon;
    return (
        <>
            {Icon ? <Icon className="size-[15px]" /> : null}
            <span>{item.label}</span>
            {decoration}
            {item.count != null ? (
                <span
                    className={cn(
                        'inline-flex min-w-[22px] items-center justify-center rounded-[6px] px-1.5 py-0.5 text-[11px] font-bold tabular-nums',
                        item.alert && item.count > 0
                            ? 'bg-status-critical-bg text-status-critical'
                            : on
                              ? 'bg-primary/15 text-primary'
                              : 'bg-primary-foreground/15 text-primary-foreground',
                    )}
                >
                    {item.count}
                </span>
            ) : null}
        </>
    );
}

/**
 * The main view tabs on the band's bottom edge. The active tab takes the
 * page --background and sits flush with the edge — the merge with the page
 * ground is the affordance (NAVIGATION_STYLE_GUIDE.md Rule 1). Counters
 * follow the counter state-colour rule and never disappear on activation.
 *
 * EVERY rail ends with the ghost "⌕ Find" chip (guide §7, mandatory
 * 2026-09-08): pages with a richer section palette pass `onFind` (record
 * profiles — `/` opens it there); otherwise the rail opens its own
 * built-in palette over these view items. On pages whose header carries
 * the scoped search input, `/` stays with the search — the built-in chip
 * opens on click and shows no kbd hint.
 *
 * THE RAIL NEVER WRAPS (DESIGN.md anti-pattern "Wrapping rail",
 * 2026-09-10). A hidden measurement row mirrors every tab; when they
 * can't all fit on one line beside the Find chip, the trailing views
 * collapse into a ghost "⋯ More" pill with a popover. Two invariants:
 * the ACTIVE view is always a visible tab (promoted out of the overflow
 * if needed — the flush page-ground merge is the rail's affordance), and
 * alert counters never disappear (overflowed alert counts sum onto the
 * More pill in the fixed critical pair).
 */
export function PageHeaderRail<K extends string>({
    items,
    value,
    onSelect,
    onFind,
    ariaLabel = 'Views',
    onItemContextMenu,
    decorations,
}: {
    items: PageHeaderRailItem<K>[];
    value: K;
    onSelect: (key: K) => void;
    /** Custom palette opener (record profiles); omitted → the rail's own
     *  built-in views palette. */
    onFind?: () => void;
    ariaLabel?: string;
    onItemContextMenu?: (key: K, event: React.MouseEvent) => void;
    decorations?: Partial<Record<K, ReactNode>>;
}) {
    const [viewsFindOpen, setViewsFindOpen] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);
    /** Keys of the tabs shown on the rail; null = everything fits. */
    const [visibleKeys, setVisibleKeys] = useState<K[] | null>(null);
    const containerRef = useRef<HTMLDivElement>(null);
    const measureRef = useRef<HTMLDivElement>(null);

    // Width inputs that aren't observable via the ResizeObserver deps.
    const itemsKey = items
        .map((it) => `${it.key}|${it.label}|${it.count ?? ''}`)
        .join('');

    useLayoutEffect(() => {
        const container = containerRef.current;
        const measureRow = measureRef.current;
        if (!container || !measureRow) return;

        const compute = () => {
            const available = container.clientWidth;
            if (available <= 0) return;
            // Measurement row: one node per item, then More, then Find.
            const nodes = Array.from(measureRow.children) as HTMLElement[];
            if (nodes.length < items.length + 2) return;
            const findW = nodes[nodes.length - 1].offsetWidth;
            const moreW = nodes[nodes.length - 2].offsetWidth;
            const itemW = nodes
                .slice(0, items.length)
                .map((el) => el.offsetWidth);

            const total =
                itemW.reduce((acc, w) => acc + w + RAIL_GAP, 0) + findW;
            if (total <= available) {
                setVisibleKeys(null);
                return;
            }

            // Reserve the More pill + Find chip, each preceded by a gap.
            const budget = available - findW - moreW - 2 * RAIL_GAP;
            let used = 0;
            let fit = 0;
            while (
                fit < items.length &&
                used + itemW[fit] + (fit > 0 ? RAIL_GAP : 0) <= budget
            ) {
                used += itemW[fit] + (fit > 0 ? RAIL_GAP : 0);
                fit += 1;
            }

            const activeIdx = Math.max(
                0,
                items.findIndex((it) => it.key === value),
            );
            let keys: K[];
            if (activeIdx < fit) {
                keys = items.slice(0, fit).map((it) => it.key);
            } else {
                // Promote the active view onto the rail — it never hides.
                let rest = budget - itemW[activeIdx] - RAIL_GAP;
                const kept: K[] = [];
                for (let i = 0; i < items.length; i += 1) {
                    if (i === activeIdx) continue;
                    const w = itemW[i] + (kept.length > 0 ? RAIL_GAP : 0);
                    if (w > rest) break;
                    rest -= w;
                    kept.push(items[i].key);
                }
                keys = [...kept, items[activeIdx].key];
            }
            setVisibleKeys((prev) =>
                prev &&
                prev.length === keys.length &&
                prev.every((k, i) => k === keys[i])
                    ? prev
                    : keys,
            );
        };

        compute();
        const observer = new ResizeObserver(compute);
        observer.observe(container);
        // The w-max measurement row resizes when labels/fonts/counts do.
        observer.observe(measureRow);
        return () => observer.disconnect();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- itemsKey stands in for items
    }, [itemsKey, value, onFind]);

    const visibleItems =
        visibleKeys == null
            ? items
            : visibleKeys.flatMap((key) => {
                  const item = items.find((it) => it.key === key);
                  return item ? [item] : [];
              });
    const overflowItems =
        visibleKeys == null
            ? []
            : items.filter((it) => !visibleKeys.includes(it.key));
    const overflowAlertCount = overflowItems.reduce(
        (acc, it) => acc + (it.alert && it.count ? it.count : 0),
        0,
    );
    // The measurement More pill reserves for the worst case: every alert.
    const totalAlertCount = items.reduce(
        (acc, it) => acc + (it.alert && it.count ? it.count : 0),
        0,
    );

    const morePill = (count: number) => (
        <>
            <MoreHorizontal className="size-[15px]" />
            <span>More</span>
            {count > 0 ? (
                <span className="inline-flex min-w-[22px] items-center justify-center rounded-[6px] bg-status-critical-bg px-1.5 py-0.5 text-[11px] font-bold text-status-critical tabular-nums">
                    {count}
                </span>
            ) : null}
        </>
    );

    const findChip = (
        <>
            <Search className="size-[15px]" />
            <span className="hidden sm:inline">Find</span>
            {onFind ? (
                <kbd
                    aria-hidden="true"
                    className="hidden rounded-[5px] border border-primary-foreground/30 px-1 text-[10px] sm:inline"
                >
                    /
                </kbd>
            ) : null}
        </>
    );

    // Roving tabindex (one tab stop): focus roves over the VISIBLE tabs —
    // overflowed views are reached through the More menu instead.
    const [focusedKey, setFocusedKey] = useState<K>(value);
    const tabButtons = useRef(new Map<K, HTMLButtonElement>());
    const findButton = useRef<HTMLButtonElement>(null);
    const pickedKey = useRef<K | null>(null);
    useEffect(() => setFocusedKey(value), [value]);
    const entryKey = visibleItems.some((item) => item.key === focusedKey)
        ? focusedKey
        : (visibleItems.find((item) => item.key === value)?.key ??
          visibleItems[0]?.key);
    return (
        <div
            role="tablist"
            aria-label={ariaLabel}
            ref={containerRef}
            className="relative flex w-full flex-nowrap items-end gap-1"
        >
            {visibleItems.map((it) => {
                const on = it.key === value;
                return (
                    <button
                        key={it.key}
                        type="button"
                        role="tab"
                        aria-selected={on}
                        ref={(button) => {
                            if (button) tabButtons.current.set(it.key, button);
                            else tabButtons.current.delete(it.key);
                        }}
                        tabIndex={it.key === entryKey ? 0 : -1}
                        onFocus={() => setFocusedKey(it.key)}
                        onKeyDown={(event) => {
                            const index = visibleItems.findIndex(
                                (item) => item.key === it.key,
                            );
                            const next =
                                event.key === 'Home'
                                    ? 0
                                    : event.key === 'End'
                                      ? visibleItems.length - 1
                                      : event.key === 'ArrowRight'
                                        ? (index + 1) % visibleItems.length
                                        : event.key === 'ArrowLeft'
                                          ? (index + visibleItems.length - 1) %
                                            visibleItems.length
                                          : null;
                            if (next === null) return;
                            event.preventDefault();
                            // Manual activation keeps navigation and unsaved-work guards
                            // with the existing Enter/Space/click action.
                            tabButtons.current
                                .get(visibleItems[next].key)
                                ?.focus();
                        }}
                        onClick={() => onSelect(it.key)}
                        onContextMenu={
                            onItemContextMenu
                                ? (event) => onItemContextMenu(it.key, event)
                                : undefined
                        }
                        className={railTabClass(on)}
                    >
                        <RailTabInner
                            item={it}
                            on={on}
                            decoration={decorations?.[it.key]}
                        />
                    </button>
                );
            })}
            {overflowItems.length > 0 ? (
                <Popover open={moreOpen} onOpenChange={setMoreOpen}>
                    <PopoverTrigger asChild>
                        <button
                            type="button"
                            aria-label={`More views (${overflowItems.length})`}
                            className={railPillClass}
                        >
                            {morePill(overflowAlertCount)}
                        </button>
                    </PopoverTrigger>
                    <PopoverContent align="end" className="w-60 p-1.5">
                        {overflowItems.map((it) => {
                            const Icon = it.icon;
                            return (
                                <button
                                    key={it.key}
                                    type="button"
                                    onClick={() => {
                                        setMoreOpen(false);
                                        onSelect(it.key);
                                    }}
                                    onContextMenu={
                                        onItemContextMenu
                                            ? (event) =>
                                                  onItemContextMenu(
                                                      it.key,
                                                      event,
                                                  )
                                            : undefined
                                    }
                                    className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm text-foreground transition-colors hover:bg-muted"
                                >
                                    {Icon ? (
                                        <Icon className="size-4 text-muted-foreground" />
                                    ) : null}
                                    <span className="flex-1 truncate">
                                        {it.label}
                                    </span>
                                    {it.count != null ? (
                                        <span
                                            className={cn(
                                                'text-[11px] tabular-nums',
                                                it.alert && it.count > 0
                                                    ? 'inline-flex min-w-[22px] items-center justify-center rounded-[6px] bg-status-critical-bg px-1.5 py-0.5 font-bold text-status-critical'
                                                    : 'font-semibold text-muted-foreground',
                                            )}
                                        >
                                            {it.count}
                                        </span>
                                    ) : null}
                                </button>
                            );
                        })}
                    </PopoverContent>
                </Popover>
            ) : null}
            <button
                type="button"
                ref={findButton}
                onClick={
                    onFind ??
                    (() => {
                        pickedKey.current = null;
                        setViewsFindOpen(true);
                    })
                }
                title={onFind ? 'Find a section (/)' : 'Find a view'}
                aria-label={onFind ? 'Find a section' : 'Find a view'}
                className={cn(railPillClass, 'ml-auto')}
            >
                {findChip}
            </button>
            {/* Hidden measurement row — mirrors every tab (plus the widest
                possible More pill and the Find chip) so the overflow point
                is computed from real widths, never guessed. */}
            <div
                ref={measureRef}
                aria-hidden="true"
                className="pointer-events-none invisible absolute bottom-0 left-0 flex w-max flex-nowrap items-end gap-1"
            >
                {items.map((it) => {
                    const on = it.key === value;
                    return (
                        <button
                            key={it.key}
                            type="button"
                            tabIndex={-1}
                            className={railTabClass(on)}
                        >
                            <RailTabInner
                                item={it}
                                on={on}
                                decoration={decorations?.[it.key]}
                            />
                        </button>
                    );
                })}
                <button type="button" tabIndex={-1} className={railPillClass}>
                    {morePill(totalAlertCount)}
                </button>
                <button type="button" tabIndex={-1} className={railPillClass}>
                    {findChip}
                </button>
            </div>
            {onFind ? null : (
                <RailViewsPalette
                    items={items}
                    open={viewsFindOpen}
                    onOpenChange={setViewsFindOpen}
                    ariaLabel={`Find a view — ${ariaLabel}`}
                    onCloseAutoFocus={(event) => {
                        const target =
                            (pickedKey.current === null
                                ? null
                                : tabButtons.current.get(pickedKey.current)) ??
                            findButton.current;
                        if (target?.isConnected) {
                            event.preventDefault();
                            target.focus();
                        }
                    }}
                    onPick={(key) => {
                        pickedKey.current = key;
                        setViewsFindOpen(false);
                        onSelect(key);
                    }}
                />
            )}
        </div>
    );
}

/**
 * The rail's built-in fallback palette — a light dialog over its own view
 * items, so every page gets the Find chip without building a palette.
 * Record profiles keep their richer grouped `TabSearchPalette` via `onFind`.
 */
function RailViewsPalette<K extends string>({
    items,
    open,
    onOpenChange,
    onPick,
    ariaLabel,
    onCloseAutoFocus,
}: {
    items: PageHeaderRailItem<K>[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPick: (key: K) => void;
    ariaLabel: string;
    onCloseAutoFocus: (event: Event) => void;
}) {
    const [query, setQuery] = useState('');
    useEffect(() => {
        if (open) setQuery('');
    }, [open]);
    const list = items.filter((it) =>
        it.label.toLowerCase().includes(query.trim().toLowerCase()),
    );
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-w-sm gap-3 p-3"
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <DialogTitle className="sr-only">{ariaLabel}</DialogTitle>
                <DialogDescription className="sr-only">
                    Search the views on this page and jump to one.
                </DialogDescription>
                <input
                    autoFocus
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && list[0]) {
                            e.preventDefault();
                            onPick(list[0].key);
                        }
                    }}
                    placeholder="Find a view…"
                    aria-label={ariaLabel}
                    className="h-9 w-full rounded-md border border-input bg-background pr-9 pl-3 text-sm text-foreground outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring"
                />
                <div className="-mx-1 max-h-[300px] overflow-y-auto px-1">
                    {list.length === 0 ? (
                        <p className="px-2.5 py-4 text-center text-[13px] text-muted-foreground">
                            No views match.
                        </p>
                    ) : (
                        list.map((it) => {
                            const Icon = it.icon;
                            return (
                                <button
                                    key={it.key}
                                    type="button"
                                    onClick={() => onPick(it.key)}
                                    className="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm text-foreground transition-colors hover:bg-muted"
                                >
                                    {Icon ? (
                                        <Icon className="size-4 text-muted-foreground" />
                                    ) : null}
                                    <span className="flex-1 truncate">
                                        {it.label}
                                    </span>
                                    {it.count != null ? (
                                        <span className="text-[11px] font-semibold text-muted-foreground tabular-nums">
                                            {it.count}
                                        </span>
                                    ) : null}
                                </button>
                            );
                        })
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
