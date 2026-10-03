/* Meds today (P01 C3) — one scheduled dose as a row: the state badge and
 * its plain lines, the medicine cell, the row's main action, and the one
 * MenuItem[] that feeds the kebab, right-click and Shift+F10 (LIST_STYLE_GUIDE
 * §1). What a dose needs and allows comes from the server (row.req, the same
 * answer the recording dialog reads). Approved P01 v2 `doses.tsx`. */
import { EntityChip } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    Check,
    CheckCircle2,
    ClipboardList,
    Clock3,
    Flag,
    HelpCircle,
    Info,
    Lock,
    LogOut,
    PauseCircle,
    Repeat,
    ShieldCheck,
    UserRound,
    Users,
    X,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type { ScheduleRow } from './types';

export const TZ = 'Pacific/Auckland';

/** "9:00 am" in NZ time. */
export const nzTime = (iso: string | null | undefined): string =>
    iso
        ? new Date(iso)
              .toLocaleTimeString('en-NZ', {
                  hour: 'numeric',
                  minute: '2-digit',
                  timeZone: TZ,
              })
              .replace(
                  /\s?([ap])\.?m\.?$/i,
                  (_, p: string) => ` ${p.toLowerCase()}m`,
              )
        : '—';

/** "NZDT" or "NZST" at that instant (falls back to "NZ time"). */
export const nzZone = (iso: string | null | undefined): string => {
    try {
        const name = new Intl.DateTimeFormat('en-NZ', {
            timeZone: TZ,
            timeZoneName: 'short',
        })
            .formatToParts(iso ? new Date(iso) : new Date())
            .find((p) => p.type === 'timeZoneName')?.value;
        return name && /^NZ[DS]T$/.test(name) ? name : 'NZ time';
    } catch {
        return 'NZ time';
    }
};

/** "4:10 pm" from a "16:10" wall-clock time. */
export const clockTime = (hhmm: string | null | undefined): string => {
    if (!hhmm) return '—';
    const [h, m] = hhmm.split(':').map(Number);
    return `${h % 12 === 0 ? 12 : h % 12}:${String(m).padStart(2, '0')} ${h >= 12 ? 'pm' : 'am'}`;
};

/** Due or late and not yet recorded: the doses a worker acts on. */
export const isOpen = (row: ScheduleRow): boolean =>
    row.recorded === null && (row.status === 'due' || row.status === 'overdue');

/** The block that stops recording (all, or "given"), as the server keys it. */
export const blockOf = (row: ScheduleRow): string | null =>
    row.req?.block_all ?? row.req?.block_given ?? null;

const COMPETENCY_STOPS = ['expired', 'not_current', 'restricted', 'area'];

/** Can't be recorded as given by this worker: a block, or their competency. */
export const needsHelp = (row: ScheduleRow): boolean =>
    (row.status === 'pending_check' &&
        row.recorded === null &&
        !!blockOf(row)) ||
    (isOpen(row) &&
        (!!blockOf(row) ||
            COMPETENCY_STOPS.includes(row.req?.competency ?? '') ||
            (row.req?.competency === 'cosigner' &&
                !row.req?.witness_available)));

/** The words for a block on a row (the dialog's block titles, P00 v5). */
export function blockLine(
    key: string,
    person: string,
): { text: string; critical: boolean } {
    switch (key) {
        case 'notClockedIn':
            return { text: 'You’re not clocked in', critical: false };
        case 'notOnShift':
            return { text: `${person} isn’t on your shift`, critical: false };
        case 'siteNotApproved':
            return { text: 'This house isn’t in your access', critical: false };
        case 'controlledNotAllowed':
            return {
                text: 'You can’t record controlled medicines',
                critical: false,
            };
        case 'awaitingVerification':
            return {
                text: 'This order is waiting to be checked',
                critical: false,
            };
        case 'covertMissing':
            return {
                text: 'No current covert plan — can’t be recorded as given',
                critical: true,
            };
        case 'allergyBlocked':
            return {
                text: 'Allergy match — can’t be recorded as given',
                critical: true,
            };
        case 'noWitness':
            return { text: 'No eligible witness on shift', critical: false };
        case 'prnLimit':
            return { text: 'As-needed limit reached', critical: true };
        default:
            return { text: 'A safety check stops “given”', critical: true };
    }
}

/** The "Needs help" meter's words for a block. */
export const BLOCK_CAPTION: Record<string, string> = {
    notClockedIn: 'not clocked in',
    notOnShift: 'not on your shift',
    siteNotApproved: 'house not in your access',
    controlledNotAllowed: 'controlled medicine',
    awaitingVerification: 'order to check',
    covertMissing: 'no covert plan',
    allergyBlocked: 'allergy match',
    safetyBlocked: 'safety check',
    noWitness: 'no witness on shift',
    competency: 'your competency',
};

/* ───────────── state badge ───────────── */
type BadgeKey =
    | 'selfmanaged'
    | 'notdue'
    | 'duesoon'
    | 'due'
    | 'late'
    | 'given'
    | 'reoffered'
    | 'refused'
    | 'withheld'
    | 'missed'
    | 'away'
    | 'check';
const BADGE: Record<
    BadgeKey,
    {
        label: string;
        variant: 'neutral' | 'info' | 'warning' | 'success' | 'critical';
        icon: LucideIcon;
    }
> = {
    selfmanaged: { label: 'Self-managed', variant: 'neutral', icon: UserRound },
    notdue: { label: 'Not yet due', variant: 'neutral', icon: Clock3 },
    duesoon: { label: 'Due soon', variant: 'neutral', icon: Clock3 },
    due: { label: 'Due', variant: 'info', icon: Clock3 },
    late: { label: 'Late', variant: 'warning', icon: AlertTriangle },
    given: { label: 'Given', variant: 'success', icon: Check },
    reoffered: {
        label: 'Given after re-offer',
        variant: 'success',
        icon: Repeat,
    },
    refused: { label: 'Refused', variant: 'warning', icon: X },
    withheld: { label: 'Withheld', variant: 'warning', icon: PauseCircle },
    missed: { label: 'Missed', variant: 'critical', icon: AlertTriangle },
    away: { label: 'Away', variant: 'neutral', icon: LogOut },
    check: { label: 'Order to check', variant: 'warning', icon: AlertTriangle },
};

export function badgeKeyFor(row: ScheduleRow): BadgeKey {
    if (!row.recorded && row.state === 'self_managed') return 'selfmanaged';
    if (row.recorded) {
        const s = row.recorded.status;
        if (s === 'given')
            return row.recorded.reoffer_of_id ? 'reoffered' : 'given';
        if (s === 'refused') return 'refused';
        if (s === 'missed') return 'missed';
        return 'withheld';
    }
    switch (row.status) {
        case 'due':
            return isDueSoon(row) ? 'duesoon' : 'due';
        case 'overdue':
            return 'late';
        case 'away':
            return 'away';
        case 'pending_check':
            return 'check';
        case 'missed':
            return 'missed';
        default:
            return 'notdue';
    }
}

/** Due-soon visibility starts before the canonical recording window opens. */
export function isDueSoon(row: ScheduleRow): boolean {
    return (
        row.recorded === null &&
        row.status === 'due' &&
        (row.state === 'not_due' || row.req?.window === 'notdue')
    );
}

export const isStaffDose = (row: ScheduleRow): boolean =>
    !['away', 'pending_check', 'self_managed'].includes(
        row.state ?? row.status,
    );
export const isDueNow = (row: ScheduleRow): boolean =>
    row.recorded === null &&
    row.status === 'due' &&
    !isDueSoon(row) &&
    isStaffDose(row);

export function DoseBadge({ row }: { row: ScheduleRow }) {
    const b = BADGE[badgeKeyFor(row)];
    const Icon = b.icon;
    return (
        <StatusBadge
            variant={b.variant}
            className="rounded-[8px] font-semibold"
        >
            <Icon className="size-3" aria-hidden="true" />
            {b.label}
        </StatusBadge>
    );
}

/* ───────────── state cell: badge + plain lines ───────────── */
function Line({
    tone,
    children,
}: {
    tone?: 'warning' | 'critical' | 'success';
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'flex items-start gap-1 text-[12px] leading-snug',
                tone === 'critical'
                    ? 'font-semibold text-status-critical'
                    : tone === 'warning'
                      ? 'font-semibold text-status-warning'
                      : tone === 'success'
                        ? 'text-status-success'
                        : 'text-muted-foreground',
            )}
        >
            {children}
        </span>
    );
}

const OUTCOME_WORD: Record<string, string> = {
    given: 'Given',
    refused: 'Refused',
    withheld: 'Withheld',
    missed: 'Missed',
};

export function DoseStateCell({
    row,
    person,
}: {
    row: ScheduleRow;
    person: string;
}) {
    const lines: ReactNode[] = [];
    const r = row.recorded;
    const open = isOpen(row);
    const opens = nzTime(row.window_opens_at);
    const ends = nzTime(row.window_ends_at);
    if (r) {
        const word = r.reoffer_of_id
            ? r.status === 'given'
                ? 'Given after re-offer'
                : 'Refused again'
            : (OUTCOME_WORD[r.status] ?? r.status);
        // Not given: the reason, unless it only repeats the outcome, then what was said.
        const repeats = (w: string | null | undefined) =>
            !w ||
            [OUTCOME_WORD[r.status] ?? '', r.reason_label ?? '', r.status].some(
                (x) => x.toLowerCase() === w.trim().toLowerCase(),
            );
        const said = !repeats(r.reason)
            ? r.reason
            : !repeats(r.notes)
              ? r.notes
              : null;
        const why =
            r.status === 'given'
                ? []
                : [
                      r.reason_label &&
                      r.reason_label.toLowerCase() !==
                          (OUTCOME_WORD[r.status] ?? '').toLowerCase()
                          ? r.reason_label
                          : null,
                      said,
                  ].filter((w): w is string => !!w);
        lines.push(
            <Line key="r">
                {[`${word} ${clockTime(r.time)}`, r.by, ...why]
                    .filter(Boolean)
                    .join(' · ')}
            </Line>,
        );
        if (row.follow_up && r.status === 'refused')
            lines.push(
                <Line key="fu">
                    {[
                        'Follow-up',
                        row.follow_up.owner,
                        row.follow_up.due_time
                            ? `offer again by ${row.follow_up.due_time}`
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </Line>,
            );
        if (r.witness && r.second_person_status !== 'not_confirmed') {
            const what =
                r.second_person_kind === 'amount'
                    ? 'Different amount confirmed by'
                    : r.second_person_kind === 'witness' || row.is_controlled
                      ? 'Witnessed by'
                      : 'Confirmed by';
            lines.push(
                <Line key="w">
                    <Users
                        className="mt-0.5 size-3 shrink-0"
                        aria-hidden="true"
                    />{' '}
                    {what} {r.witness} (witness PIN)
                </Line>,
            );
        }
        if (r.second_person_status === 'not_confirmed')
            lines.push(
                <Line key="nc" tone="warning">
                    Not confirmed by a second person · follow-up for the house
                    lead
                </Line>,
            );
        if (r.amount_mode === 'less' && r.dose_given)
            lines.push(
                <Line key="less" tone="warning">
                    Less than ordered: {r.dose_given}
                </Line>,
            );
        if (r.amount_mode === 'more' && r.dose_given)
            lines.push(
                <Line key="more" tone="critical">
                    More than ordered: {r.dose_given} · medication error
                    reported
                </Line>,
            );
        if (r.late_reason)
            lines.push(
                <Line key="late">
                    Outside the dose window — reason recorded
                </Line>,
            );
    } else if (row.status === 'away') {
        lines.push(
            <Line key="a">
                {row.away_reason
                    ? `Away · ${row.away_reason}`
                    : 'Away — not due while away'}
            </Line>,
        );
    } else if (row.status === 'pending_check') {
        lines.push(
            <Line key="pc">
                Due {nzTime(row.scheduled_for)} · waiting for the order check
            </Line>,
        );
    } else if (row.state === 'self_managed') {
        lines.push(
            <Line key="self">Self-managed · not a staff dose to record</Line>,
        );
    } else if (row.status === 'upcoming') {
        lines.push(
            <Line key="nd">
                Due {nzTime(row.scheduled_for)} · window opens {opens}
            </Line>,
        );
    } else if (row.status === 'due') {
        lines.push(
            <Line key="du">
                {isDueSoon(row)
                    ? `Due soon · ${nzTime(row.scheduled_for)} · window opens ${opens}`
                    : `Due now · ${nzTime(row.scheduled_for)} · window until ${ends}`}
            </Line>,
        );
    } else if (row.status === 'overdue') {
        lines.push(
            <Line key="la">
                Due {nzTime(row.scheduled_for)} · outside today’s window (
                {opens}–{ends})
            </Line>,
        );
    }
    const block = blockOf(row);
    if ((open || row.status === 'pending_check') && block) {
        const b = blockLine(block, person);
        lines.push(
            <Line key="b" tone={b.critical ? 'critical' : 'warning'}>
                <Lock className="mt-0.5 size-3 shrink-0" aria-hidden="true" />{' '}
                {b.text}
            </Line>,
        );
    } else if (open && COMPETENCY_STOPS.includes(row.req?.competency ?? '')) {
        lines.push(
            <Line key="c" tone="critical">
                <Lock className="mt-0.5 size-3 shrink-0" aria-hidden="true" />{' '}
                {row.req?.competency === 'expired'
                    ? 'Can’t record as given — your competency expired. Refusal, withhold or absence still OK.'
                    : 'Can’t sign as given — check your eligibility. Refusal, withhold or absence still OK.'}
            </Line>,
        );
    } else if (open && row.req?.competency === 'cosigner') {
        lines.push(
            <Line key="cs" tone="warning">
                <Users className="mt-0.5 size-3 shrink-0" aria-hidden="true" />{' '}
                Needs a co-signer — your competency is restricted
            </Line>,
        );
    }
    if (open && row.req?.allergy_match && !block)
        lines.push(
            <Line key="al" tone="critical">
                <ShieldCheck
                    className="mt-0.5 size-3 shrink-0"
                    aria-hidden="true"
                />{' '}
                Possible allergy match — check before giving
            </Line>,
        );
    return (
        <span className="flex flex-col items-start gap-1 py-0.5">
            <DoseBadge row={row} />
            {lines}
        </span>
    );
}

/* ───────────── medicine cell ───────────── */
export function MedicineCell({ row }: { row: ScheduleRow }) {
    return (
        <span className="flex min-w-0 flex-col gap-1 py-0.5">
            <span className="truncate text-[13px] font-semibold">
                {row.medication_name}
            </span>
            <span className="flex flex-wrap items-center gap-1.5">
                {row.is_controlled ? (
                    <EntityChip icon={ShieldCheck}>Controlled</EntityChip>
                ) : null}
                <span className="text-[12px] text-muted-foreground">
                    {[
                        row.dose,
                        row.route
                            ? row.route.toLowerCase() === 'oral'
                                ? 'by mouth'
                                : row.route.toLowerCase()
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            </span>
        </span>
    );
}

/* ───────────── the row's actions ───────────── */
export interface RowActions {
    record: (row: ScheduleRow) => void;
    notGiven: (row: ScheduleRow) => void;
    reoffer: (row: ScheduleRow) => void;
    why: (row: ScheduleRow) => void;
    detail: (row: ScheduleRow) => void;
    chart: (row: ScheduleRow) => void;
    reportError: (row: ScheduleRow) => void;
}

/** What may be done with a dose from this account (recording permission). */
export interface RowPermissions {
    canRecord: (row: ScheduleRow) => boolean;
    canReportError: boolean;
}

const canReoffer = (row: ScheduleRow) =>
    row.recorded?.status === 'refused' && !row.recorded.reoffer_of_id;

/** The row's main action (row click and its button): record / why / not given / re-offer / view. */
export function mainAction(
    row: ScheduleRow,
    can: RowPermissions,
    a: RowActions,
): (() => void) | null {
    if (isOpen(row) && can.canRecord(row)) {
        if (blockOf(row)) return () => a.why(row);
        if (needsHelp(row)) return () => a.notGiven(row);
        return () => a.record(row);
    }
    if (row.status === 'pending_check' && blockOf(row)) return () => a.why(row);
    if (canReoffer(row) && can.canRecord(row)) return () => a.reoffer(row);
    if (row.recorded || row.status === 'upcoming' || row.status === 'away')
        return () => a.detail(row);
    return null;
}

export function DoseActionCell({
    row,
    can,
    actions,
}: {
    row: ScheduleRow;
    can: RowPermissions;
    actions: RowActions;
}) {
    const stop = (fn: () => void) => (e: React.MouseEvent) => {
        e.stopPropagation();
        fn();
    };
    const recordable = can.canRecord(row);
    if (isOpen(row) && recordable && blockOf(row))
        return (
            <Button
                data-return={row.key}
                variant="outline"
                className="frontline-tap"
                onClick={stop(() => actions.why(row))}
            >
                <HelpCircle className="size-4" aria-hidden="true" /> Why can’t I
                record?
            </Button>
        );
    if (isOpen(row) && recordable && needsHelp(row))
        return (
            <Button
                data-return={row.key}
                variant="outline"
                className="frontline-tap"
                onClick={stop(() => actions.notGiven(row))}
            >
                Record not given
            </Button>
        );
    if (isOpen(row) && recordable)
        return (
            <Button
                data-return={row.key}
                className="frontline-tap"
                onClick={stop(() => actions.record(row))}
            >
                Record
            </Button>
        );
    if (row.status === 'pending_check' && blockOf(row))
        return (
            <Button
                data-return={row.key}
                variant="outline"
                className="frontline-tap"
                onClick={stop(() => actions.why(row))}
            >
                <HelpCircle className="size-4" aria-hidden="true" /> Why can’t I
                record?
            </Button>
        );
    if (canReoffer(row) && recordable)
        return (
            <Button
                data-return={row.key}
                variant="outline"
                className="frontline-tap"
                onClick={stop(() => actions.reoffer(row))}
            >
                <Repeat className="size-4" aria-hidden="true" /> Record re-offer
            </Button>
        );
    if (row.recorded || row.status === 'upcoming' || row.status === 'away')
        return (
            <Button
                data-return={row.key}
                variant="ghost"
                className="frontline-tap"
                onClick={stop(() => actions.detail(row))}
            >
                View
            </Button>
        );
    return null;
}

/** The one menu for a dose: kebab, right-click and Shift+F10. */
export function doseMenu(
    row: ScheduleRow,
    person: string,
    can: RowPermissions,
    a: RowActions,
): MenuItem[] {
    const items: (MenuItem | false)[] = [];
    const recordable = can.canRecord(row);
    if (isOpen(row) && recordable) {
        if (blockOf(row)) {
            if (!row.req?.block_all)
                items.push({
                    label: 'Record not given',
                    icon: PauseCircle,
                    onClick: () => a.notGiven(row),
                });
            items.push({
                label: 'Why can’t I record this?',
                icon: HelpCircle,
                onClick: () => a.why(row),
            });
        } else if (needsHelp(row)) {
            items.push({
                label: 'Record not given',
                icon: PauseCircle,
                onClick: () => a.notGiven(row),
            });
            items.push({
                label: 'Why can’t I record this?',
                icon: HelpCircle,
                onClick: () => a.why(row),
            });
        } else {
            // One entry: the dialog's first step offers every outcome.
            items.push({
                label: 'Record dose',
                icon: CheckCircle2,
                onClick: () => a.record(row),
            });
        }
    } else if (row.status === 'pending_check' && blockOf(row)) {
        items.push({
            label: 'Why can’t I record this?',
            icon: HelpCircle,
            onClick: () => a.why(row),
        });
    } else if (canReoffer(row) && recordable) {
        items.push({
            label: 'Record re-offer',
            icon: Repeat,
            onClick: () => a.reoffer(row),
        });
        items.push({
            label: 'View dose details',
            icon: Info,
            onClick: () => a.detail(row),
        });
    } else if (
        row.recorded ||
        row.status === 'upcoming' ||
        row.status === 'away'
    ) {
        items.push({
            label: 'View dose details',
            icon: Info,
            onClick: () => a.detail(row),
        });
    }
    items.push({ separator: true });
    if (row.mar_url)
        items.push({
            label: `Open ${person}’s medication record`,
            icon: ClipboardList,
            onClick: () => a.chart(row),
        });
    if (can.canReportError)
        items.push({
            label: 'Report a medication error',
            icon: Flag,
            onClick: () => a.reportError(row),
        });
    return compactMenu(items);
}
