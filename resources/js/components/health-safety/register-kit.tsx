/**
 * G4 registers (boards G4-11 to G4-15, Stephan 1 Nov 2026): the parts every Health and safety register shares.
 *
 * - `RegisterBoxes`: the G2 header's four count boxes (the Overview's own `OverviewHeaderBoxes`, never a copy). A list
 *   box has no weekly bars (decision 17); none of these counts is on the agreed list of provable trends, so each says
 *   "No trend yet" unless the page gives a plain caption. Only a box where someone must act takes the alert tone.
 * - `RegisterRailLine`: one line under the boxes — the hub's pages, then the filters (and the rail's Find).
 * - `RegisterMoreFilters`: the "More filters" chip, holding the filters that don't earn their own chip.
 * - `LeadBlock`: the one lead block ("Needs doing now"), most overdue first. Each row leads with its date and how
 *   overdue it is; a row's problems sit in one chip; only the most urgent row has a filled button. A row the person
 *   can't act on says who does it.
 * - `QuietRows`: the quiet sections as rows, not cards ("Also here · Quiet until you need them").
 */
import { PageHeaderFilterButton } from '@/components/page';
import { EntityKebab, type MenuItem } from '@/components/lists/entity-menu';
import { ActionLink, Ring, Surface, type SgAction } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { OverviewHeaderBoxes, type HeaderBox } from '@/pages/health-safety/components/overview-header-boxes';
import { Link } from '@inertiajs/react';
import { Check, ChevronRight, SlidersHorizontal, type LucideIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Dates                                                              */
/* ------------------------------------------------------------------ */

/** Whole NZ calendar days from a civil date (YYYY-MM-DD) to today; negative when it is still ahead. */
export function daysSince(day: string | null | undefined, today: string = toDateInput(new Date())): number | null {
    if (!day || !/^\d{4}-\d{2}-\d{2}/.test(day) || !today) return null;
    const days = Math.round((Date.parse(`${today}T00:00:00Z`) - Date.parse(`${day.slice(0, 10)}T00:00:00Z`)) / 86_400_000);
    return Number.isFinite(days) ? days : null;
}

/** "5 days overdue", "3 months overdue", "1 year overdue"; "Due today"; null when the date is still ahead. */
export function overdueNote(day: string | null | undefined, today?: string): string | null {
    const days = daysSince(day, today);
    if (days === null || days < 0) return null;
    if (days === 0) return 'Due today';
    if (days < 31) return `${days} day${days === 1 ? '' : 's'} overdue`;
    const months = Math.floor(days / 30.44);
    if (months < 12) return `${months} month${months === 1 ? '' : 's'} overdue`;
    const years = Math.floor(months / 12);
    return `${years} year${years === 1 ? '' : 's'} overdue`;
}

/** "2 Jun" this year, "13 Apr 2027" otherwise — the short date a lead row leads with. */
export function shortDay(day: string | null | undefined, today: string = toDateInput(new Date())): string {
    if (!day) return '';
    const value = formatDateOnly(day.slice(0, 10), '');
    if (!value) return '';
    return day.slice(0, 4) === today.slice(0, 4) ? value.replace(/\s\d{4}$/, '') : value;
}

/* ------------------------------------------------------------------ */
/*  Header boxes and the rail line                                     */
/* ------------------------------------------------------------------ */

export interface RegisterBox {
    key: string;
    label: string;
    /** Null when the count can't be confirmed: the box is left out, never shown as 0. */
    value: number | null;
    href: string;
    icon: LucideIcon;
    /** Someone must act on it (the alert tone shows only when the count is above 0). */
    alert?: boolean;
    /** A plain caption in place of "No trend yet" ("None right now"). */
    caption?: string;
    /** Drawn in the bars' place, such as faces. */
    extra?: ReactNode;
}

export function RegisterBoxes({ boxes }: { boxes: RegisterBox[] }) {
    const shown: HeaderBox[] = boxes
        .filter((box): box is RegisterBox & { value: number } => box.value !== null)
        .map((box) => ({
            key: box.key,
            label: box.label,
            value: box.value,
            tone: box.alert && box.value > 0 ? 'alert' : null,
            href: box.href,
            trend: null,
            weeks: [],
            icon: box.icon,
            caption: box.caption,
            extra: box.extra,
        }));
    return shown.length ? <OverviewHeaderBoxes data={{ set: 'hazards', since: '', boxes: shown }} /> : null;
}

/** One line under the boxes: the hub's pages on the rail, the filters at its right end. */
export function RegisterRailLine({ rail, filters }: { rail: ReactNode; filters?: ReactNode }) {
    return (
        <div className="flex w-full min-w-0 items-end gap-3">
            <div className="min-w-0 flex-1">{rail}</div>
            {filters ? (
                <div data-slot="register-rail-filters" className="mb-[11px] flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                    {filters}
                </div>
            ) : null}
        </div>
    );
}

export interface MoreFilterGroup {
    key: string;
    label: string;
    value: string;
    /** The value that means "no filter". */
    allValue?: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}

/** "More filters": the register's other filters in one pop-over; the chip shows how many are set. */
export function RegisterMoreFilters({ groups, onClear }: { groups: MoreFilterGroup[]; onClear?: () => void }) {
    const [open, setOpen] = useState(false);
    const set = groups.filter((group) => group.value !== (group.allValue ?? 'all')).length;
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <PageHeaderFilterButton icon={SlidersHorizontal} active={set > 0}>
                    {set > 0 ? `More filters · ${set}` : 'More filters'}
                </PageHeaderFilterButton>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-72 max-w-[calc(100vw-2rem)] p-2" aria-label="More filters">
                <div className="grid max-h-[60vh] gap-3 overflow-y-auto">
                    {groups.map((group) => (
                        <div key={group.key} role="group" aria-label={group.label}>
                            <p className="text-caption px-2 pb-1 font-semibold">{group.label}</p>
                            {group.options.map((option) => (
                                // eslint-disable-next-line no-restricted-syntax -- a menu choice row, as in the header's own filter pop-over.
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-pressed={option.value === group.value}
                                    onClick={() => {
                                        group.onChange(option.value);
                                        setOpen(false);
                                    }}
                                    className={cn(
                                        'flex w-full items-center justify-between gap-2 rounded-md px-2.5 py-2 text-left text-sm text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring',
                                        option.value === group.value && 'font-semibold text-primary',
                                    )}
                                >
                                    {option.label}
                                    {option.value === group.value ? <Check className="size-4" aria-hidden="true" /> : null}
                                </button>
                            ))}
                        </div>
                    ))}
                </div>
                {onClear && set > 0 ? (
                    <div className="mt-2 flex justify-end border-t border-border pt-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                onClear();
                                setOpen(false);
                            }}
                        >
                            Clear these filters
                        </Button>
                    </div>
                ) : null}
            </PopoverContent>
        </Popover>
    );
}

/* ------------------------------------------------------------------ */
/*  The lead block                                                     */
/* ------------------------------------------------------------------ */

export interface LeadRow {
    key: string | number;
    /** The date the row leads with ("2 Jun") and what it means ("5 months overdue", "handed out"). */
    when?: { day: string; note?: string | null } | null;
    title: ReactNode;
    sub?: ReactNode;
    /** The row's problems in one chip ("Inspection overdue · Past expiry date · Poor condition"). */
    chip?: { label: string; tone: StatusVariant } | null;
    /** The step this person can take now. Without one, `who` says who does it. */
    action?: (SgAction & { disabled?: boolean }) | null;
    who?: string | null;
    menu?: MenuItem[];
}

function LeadButton({ action, filled }: { action: SgAction; filled: boolean }) {
    const variant = filled ? 'default' : 'outline';
    if (action.href) {
        return (
            <Button asChild size="sm" variant={variant}>
                <Link href={action.href} aria-label={action.ariaLabel}>
                    {action.label}
                </Link>
            </Button>
        );
    }
    return (
        <Button type="button" size="sm" variant={variant} aria-label={action.ariaLabel} onClick={action.onClick}>
            {action.label}
        </Button>
    );
}

/**
 * "Needs doing now" — the one lead block. Rows come most urgent first; the first row with a button gets the filled
 * one. `more` is the closing tally ("2 more drills are overdue … See all"). Nothing waiting → one quiet line.
 */
export function LeadBlock({
    title = 'Needs doing now',
    sub = 'Most overdue first',
    rows,
    more,
    empty = 'Nothing needs doing now.',
    action,
}: {
    title?: string;
    sub?: ReactNode;
    rows: LeadRow[];
    more?: { text: ReactNode; action?: SgAction } | null;
    empty?: ReactNode;
    action?: SgAction | null;
}) {
    const filledKey = rows.find((row) => row.action && !row.action.disabled)?.key;
    return (
        <Surface title={title} sub={sub} action={action} aria-label={title}>
            {rows.length ? (
                <ol className="grid" aria-label={title}>
                    {rows.map((row) => (
                        <li
                            key={row.key}
                            className="grid grid-cols-[88px_minmax(0,1fr)] items-center gap-x-3 gap-y-2 border-t border-border px-[18px] py-3 first:border-t-0 lg:grid-cols-[96px_minmax(0,1fr)_auto_auto]"
                        >
                            <span className="text-caption grid self-start leading-tight">
                                {row.when ? (
                                    <>
                                        <b className="text-sm font-semibold text-foreground tabular-nums">{row.when.day}</b>
                                        {row.when.note ? <span>{row.when.note}</span> : null}
                                    </>
                                ) : null}
                            </span>
                            <span className="min-w-0">
                                <b className="block text-sm font-semibold break-words">{row.title}</b>
                                {row.sub ? <small className="text-caption block break-words">{row.sub}</small> : null}
                            </span>
                            <span className="col-start-2 flex flex-wrap items-center gap-2 lg:col-start-auto lg:justify-end">
                                {row.chip ? (
                                    <StatusBadge variant={row.chip.tone} size="sm">
                                        {row.chip.label}
                                    </StatusBadge>
                                ) : null}
                            </span>
                            <span className="col-start-2 flex items-center gap-2 lg:col-start-auto lg:justify-end">
                                {row.action ? (
                                    <LeadButton action={row.action} filled={row.key === filledKey} />
                                ) : row.who ? (
                                    <small className="text-caption">{row.who}</small>
                                ) : null}
                                {row.menu?.length ? <EntityKebab actions={row.menu} /> : null}
                            </span>
                        </li>
                    ))}
                </ol>
            ) : (
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>{empty}</span>
                </p>
            )}
            {more ? (
                <p className="text-subtle flex flex-wrap items-center gap-2 border-t border-border px-[18px] py-3">
                    <span>{more.text}</span>
                    {more.action ? <ActionLink action={more.action} className="ml-auto" /> : null}
                </p>
            ) : null}
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  Quiet rows                                                         */
/* ------------------------------------------------------------------ */

export interface QuietRow {
    key: string;
    icon: LucideIcon;
    title: string;
    sub?: ReactNode;
    /** Opens the place; a link into another hub or module shows ↗ after its name. */
    href: string;
    elsewhere?: boolean;
    /** A small count or fact at the right ("9", "4 shifts"). */
    aside?: ReactNode;
}

/** Quiet sections as rows, not cards: an icon, the name and one line, the whole row a link. */
export function QuietRows({
    title = 'Also here',
    sub = 'Quiet until you need them',
    rows,
}: {
    title?: string;
    sub?: ReactNode;
    rows: QuietRow[];
}) {
    if (!rows.length) return null;
    return (
        <Surface title={title} sub={sub} aria-label={title || undefined}>
            <ul className={title ? 'sg-list mt-2' : 'sg-list'}>
                {rows.map((row) => {
                    const Icon = row.icon;
                    const body = (
                        <>
                            <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <span className="sg-li-t">
                                <b>
                                    {row.title}
                                    {row.elsewhere ? <span aria-label=" (opens another area)"> ↗</span> : null}
                                </b>
                                {row.sub ? <small>{row.sub}</small> : null}
                            </span>
                            {row.aside ? <span className="text-caption shrink-0">{row.aside}</span> : null}
                            <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        </>
                    );
                    return (
                        <li key={row.key}>
                            {/* A place on this page (#…) is a plain link; anywhere else is an Inertia visit. */}
                            {row.href.startsWith('#') ? (
                                <a href={row.href} className="sg-li">
                                    {body}
                                </a>
                            ) : (
                                <Link href={row.href} className="sg-li">
                                    {body}
                                </Link>
                            )}
                        </li>
                    );
                })}
            </ul>
        </Surface>
    );
}

/** The body under the header: the lead block, with the quiet rows beside it on wide screens. */
export function RegisterBlocks({ lead, side }: { lead: ReactNode; side?: ReactNode }) {
    return side ? (
        <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div className="grid min-w-0 gap-5">{lead}</div>
            <div className="grid min-w-0 gap-5">{side}</div>
        </div>
    ) : (
        <>{lead}</>
    );
}
