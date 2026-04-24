import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    ChevronRight,
    ClipboardList,
    Clock,
    Home,
    Menu,
    Pill,
    Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import PrnSheet, { type PrnMedication } from '@/components/prn-sheet';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import type { StaffBottomNavItem } from '@/components/staff-bottom-nav';
import StaffPageShell from '@/layouts/staff-page-shell';
import { formatTime } from '@/lib/datetime';

/* -------------------------------------------------------------------------- */
/*  PR 12 — Worker-facing medication home at `/meds/today`                    */
/* -------------------------------------------------------------------------- */
/*
 * The frontline replacement for landing on the admin-heavy eMAR dashboard.
 * Focus is deliberately narrow: what's due now, what can be started or
 * resumed, and what's coming later today. Compliance, registers, reviews and
 * competency live on `/emar` for managers and medication leads.
 *
 * Source of truth:
 *   - Medication doses: the same dose-time aggregation used by `/my-day`.
 *   - Active round: `GuidedRoundService` (identical numbers to the My Day
 *     banner, so the worker never sees two different "10 of 12" readings).
 *
 * Entry points:
 *   - `/my-day` bottom nav "Meds" → `/meds/today`
 *   - sidebar primary "Meds today" for frontline workers
 *   - the My Day footer button (admin vs worker variants)
 */

interface ActiveRound {
    id: number;
    name: string;
    status: 'pending' | 'in_progress' | string;
    scheduled_time: string;
    given: number;
    total: number;
    completed: number;
    percent: number;
    url: string;
}

interface UpcomingRound {
    id: number;
    name: string;
    status: string;
    scheduled_time: string;
    total: number;
    completed: number;
    percent: number;
    url: string;
}

interface MedDue {
    client_id: number;
    client_name: string;
    medication_id: number;
    medication_name: string;
    dose: string | null;
    route: string | null;
    is_controlled: boolean;
    scheduled_for: string;
    status: 'overdue' | 'due' | 'upcoming';
    mar_url: string;
}

interface Props {
    today: string;
    stats: {
        meds_due: number;
        meds_overdue: number;
        due_now: number;
        due_later: number;
        upcoming_rounds: number;
    };
    active_round: ActiveRound | null;
    upcoming_rounds: UpcomingRound[];
    due_now: MedDue[];
    due_later: MedDue[];
    prn_medications: PrnMedication[];
    has_shift_context: boolean;
}

function formatClock(hhmm?: string | null): string {
    if (!hhmm) return '';
    return hhmm.slice(0, 5);
}

/* -------------------------------------------------------------------------- */
/*  Compact summary pill                                                      */
/* -------------------------------------------------------------------------- */

function SummaryPill({
    label,
    value,
    icon: Icon,
    tone = 'default',
}: {
    label: string;
    value: number | string;
    icon: typeof Clock;
    tone?: 'default' | 'warn' | 'danger';
}) {
    const ring =
        tone === 'danger'
            ? 'border-red-300 bg-red-50/70 dark:border-red-800/60 dark:bg-red-950/20'
            : tone === 'warn'
                ? 'border-amber-300 bg-amber-50/70 dark:border-amber-800/60 dark:bg-amber-950/20'
                : 'border-border bg-card';
    const iconTone =
        tone === 'danger'
            ? 'text-red-600 dark:text-red-400'
            : tone === 'warn'
                ? 'text-amber-600 dark:text-amber-400'
                : 'text-muted-foreground';
    return (
        <div className={`flex items-center gap-3 rounded-lg border px-3 py-2.5 ${ring}`}>
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-background/60">
                <Icon className={`h-4 w-4 ${iconTone}`} />
            </div>
            <div className="min-w-0">
                <div className="text-lg font-semibold leading-none">{value}</div>
                <div className="mt-0.5 text-xs text-muted-foreground">{label}</div>
            </div>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/*  Meds list row                                                             */
/* -------------------------------------------------------------------------- */

function MedRow({ med }: { med: MedDue }) {
    // Worker-facing pill tone. Overdue is the only one that should read red —
    // "due" and "upcoming" are normal states, not errors, so we stay calm.
    const pill =
        med.status === 'overdue'
            ? { label: 'Overdue', className: 'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100' }
            : med.status === 'due'
                ? { label: 'Due', className: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100' }
                : { label: 'Later', className: 'border-border bg-muted text-foreground dark:border-border dark:bg-muted/60 dark:text-foreground' };

    return (
        <li className="flex items-center justify-between gap-3 py-2.5">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <Link
                        href={`/operations/clients/${med.client_id}/care`}
                        className="truncate text-sm font-medium hover:underline"
                    >
                        {med.client_name}
                    </Link>
                    {med.is_controlled && (
                        <Badge
                            variant="outline"
                            className="shrink-0 border-primary text-[10px] uppercase tracking-wide text-primary dark:border-primary/30 dark:text-primary/70"
                        >
                            CD
                        </Badge>
                    )}
                </div>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                    <Link
                        href={med.mar_url}
                        className="truncate hover:underline"
                    >
                        {med.medication_name}
                    </Link>
                    {med.dose && (
                        <>
                            <span aria-hidden>·</span>
                            <span className="shrink-0">{med.dose}</span>
                        </>
                    )}
                    <span aria-hidden>·</span>
                    <span className="shrink-0">{formatTime(med.scheduled_for)}</span>
                </div>
            </div>
            <span
                className={`shrink-0 rounded-full border px-2 py-0.5 text-[11px] font-medium ${pill.className}`}
            >
                {pill.label}
            </span>
        </li>
    );
}

/* -------------------------------------------------------------------------- */
/*  Component                                                                 */
/* -------------------------------------------------------------------------- */

export default function MedsToday({
    today,
    stats,
    active_round,
    upcoming_rounds,
    due_now,
    due_later,
    prn_medications,
    has_shift_context,
}: Props) {
    const [prnOpen, setPrnOpen] = useState(false);
    const bottomNavItems = useMemo<StaffBottomNavItem[]>(
        () => [
            { key: 'home', label: 'Home', icon: Home, href: '/my-day' },
            { key: 'meds', label: 'Meds', icon: Pill, href: '/meds/today' },
            { key: 'clock', label: 'Clock', icon: Clock, href: '/my-day#clock' },
            {
                key: 'report',
                label: 'Report',
                icon: ClipboardList,
                href: '/incidents',
            },
            { key: 'more', label: 'More', icon: Menu, href: '/' },
        ],
        [],
    );

    const nothingToShow =
        !active_round &&
        due_now.length === 0 &&
        due_later.length === 0 &&
        upcoming_rounds.length === 0;

    return (
        <StaffPageShell
            title="Meds today"
            subtitle={today}
            bottomNavItems={bottomNavItems}
        >
            <Head title="Meds today" />

            <div className="mx-auto w-full max-w-3xl space-y-5">
                {/* ── Summary strip (3 compact items) ──────────────────── */}
                <div className="grid grid-cols-3 gap-2 sm:gap-3">
                    <SummaryPill
                        label="Due now"
                        value={stats.due_now}
                        icon={Zap}
                        tone={
                            stats.meds_overdue > 0
                                ? 'danger'
                                : stats.due_now > 0
                                    ? 'warn'
                                    : 'default'
                        }
                    />
                    <SummaryPill
                        label="Due later"
                        value={stats.due_later}
                        icon={Clock}
                    />
                    <SummaryPill
                        label="Rounds"
                        value={stats.upcoming_rounds}
                        icon={Pill}
                    />
                </div>

                {/* ── PRN quick action (PR 13) ─────────────────────────── */}
                {/* Lives above the active round because a PRN is usually
                    given in response to a symptom right now, and shouldn't
                    be buried behind the rounds walk. Disabled only when
                    no PRN meds are configured for today's assigned clients. */}
                <button
                    type="button"
                    onClick={() => setPrnOpen(true)}
                    disabled={prn_medications.length === 0}
                    aria-label={
                        prn_medications.length === 0
                            ? 'Give as-needed med — none set up'
                            : `Give as-needed med (${prn_medications.length} available)`
                    }
                    className="frontline-focus group flex w-full items-center gap-3 rounded-xl border border-amber-300 bg-amber-50/70 p-4 text-left transition-shadow hover:shadow-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-amber-800/60 dark:bg-amber-950/20"
                >
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-600 text-white">
                        <Zap className="h-5 w-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold leading-tight">Give as-needed med</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {prn_medications.length === 0
                                ? 'No as-needed meds set up for your clients today'
                                : `${prn_medications.length} as-needed med${prn_medications.length === 1 ? '' : 's'} ready \u00b7 quick record`}
                        </p>
                    </div>
                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5" />
                </button>

                {/* ── Active round banner (resume / start) ─────────────── */}
                {active_round && (
                    <Link
                        href={active_round.url}
                        aria-label={`${active_round.status === 'in_progress' ? 'Resume' : 'Start'} ${active_round.name}`}
                        className="frontline-focus group block rounded-xl border border-emerald-300 bg-emerald-50/70 p-4 transition-shadow hover:shadow-sm dark:border-emerald-800/60 dark:bg-emerald-950/20"
                    >
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white">
                                <Pill className="h-5 w-5" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold leading-tight">
                                    {active_round.status === 'in_progress'
                                        ? `Resume ${active_round.name}`
                                        : `Start ${active_round.name}`}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {active_round.completed} of {active_round.total} done
                                    {active_round.scheduled_time
                                        ? ` · ${formatClock(active_round.scheduled_time)}`
                                        : ''}
                                </p>
                                <Progress
                                    value={active_round.percent}
                                    className="mt-2 h-1.5"
                                />
                            </div>
                            <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5" />
                        </div>
                    </Link>
                )}

                {/* ── Due now ──────────────────────────────────────────── */}
                {due_now.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Zap className="h-4 w-4" />
                                Due now
                                {stats.meds_overdue > 0 && (
                                    <Badge variant="destructive" className="ml-1">
                                        {stats.meds_overdue} overdue
                                    </Badge>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <ul className="divide-y">
                                {due_now.map((med) => (
                                    <MedRow
                                        key={`${med.medication_id}-${med.scheduled_for}`}
                                        med={med}
                                    />
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {/* ── Upcoming rounds (read-only, tap to walk) ─────────── */}
                {upcoming_rounds.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Pill className="h-4 w-4" />
                                Rounds today
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <ul className="divide-y">
                                {upcoming_rounds.map((round) => {
                                    const isActive =
                                        active_round?.id === round.id ||
                                        round.status === 'in_progress';
                                    return (
                                        <li key={round.id}>
                                            <Link
                                                href={round.url}
                                                aria-label={`${isActive ? 'Resume' : 'Start'} ${round.name}: ${round.completed} of ${round.total} done`}
                                                className="frontline-focus group flex min-h-14 items-center gap-3 py-2.5"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="truncate text-sm font-medium group-hover:text-primary">
                                                            {round.name}
                                                        </span>
                                                        {isActive && (
                                                            <Badge
                                                                variant="outline"
                                                                className="shrink-0 border-emerald-300 text-[10px] uppercase tracking-wide text-emerald-700 dark:border-emerald-800 dark:text-emerald-300"
                                                            >
                                                                In progress
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                                        <Clock className="h-3 w-3" />
                                                        <span>{formatClock(round.scheduled_time)}</span>
                                                        <span aria-hidden>·</span>
                                                        <span>
                                                            {round.completed} of {round.total} done
                                                        </span>
                                                    </div>
                                                </div>
                                                <Button
                                                    size="sm"
                                                    variant={isActive ? 'default' : 'outline'}
                                                    asChild
                                                >
                                                    <span>
                                                        {isActive ? 'Resume round' : 'Start round'}
                                                        <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                                                    </span>
                                                </Button>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {/* ── Due later ────────────────────────────────────────── */}
                {due_later.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Clock className="h-4 w-4" />
                                Due later
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <ul className="divide-y">
                                {due_later.map((med) => (
                                    <MedRow
                                        key={`${med.medication_id}-${med.scheduled_for}`}
                                        med={med}
                                    />
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {/* ── Empty state ─────────────────────────────────────── */}
                {nothingToShow && (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
                            <CheckCircle2 className="h-8 w-8 text-green-500" />
                            <p className="text-sm font-medium">Nothing due right now</p>
                            <p className="max-w-sm text-xs text-muted-foreground">
                                {has_shift_context
                                    ? 'No doses due in the next few hours for the clients on your shift.'
                                    : 'You don\u2019t have a shift today. Once you clock in, meds due for that client will show here.'}
                            </p>
                            <Button variant="outline" size="sm" asChild className="mt-2">
                                <Link href="/my-day">
                                    <Home className="mr-2 h-4 w-4" />
                                    Back to My Day
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {/* ── Safety / follow-up hint ─────────────────────────── */}
                {stats.meds_overdue > 0 && (
                    <div className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50/70 p-3 text-sm dark:border-red-900 dark:bg-red-950/20">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-600 dark:text-red-400" />
                        <div className="min-w-0">
                            <p className="font-medium text-red-800 dark:text-red-100">
                                {stats.meds_overdue} dose{stats.meds_overdue === 1 ? '' : 's'} past due
                            </p>
                            <p className="mt-0.5 text-xs text-red-700 dark:text-red-200">
                                Give now if safe, or tell your supervisor. Don&rsquo;t skip a dose
                                without writing down why.
                            </p>
                        </div>
                    </div>
                )}

                {/* ── Footer links ────────────────────────────────────── */}
                {/* The worker PRN surface is the sheet above; we don't send
                    frontline staff back into the admin /emar/prn register. */}
                <div className="flex flex-wrap gap-2 pt-1">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/my-day">
                            <Home className="mr-2 h-4 w-4" />
                            Back to My Day
                        </Link>
                    </Button>
                </div>
            </div>

            <PrnSheet
                open={prnOpen}
                onOpenChange={setPrnOpen}
                medications={prn_medications}
            />
        </StaffPageShell>
    );
}
