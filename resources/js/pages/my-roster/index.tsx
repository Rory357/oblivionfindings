import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    RefreshCw,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import RecentList from '@/components/roster/recent-list';
import ShiftDetailSheet from '@/components/roster/shift-detail-sheet';
import TodayTimeline from '@/components/roster/today-timeline';
import type { RosterShift } from '@/components/roster/types';
import UpcomingList from '@/components/roster/upcoming-list';
import WeekGridOverview from '@/components/roster/week-grid-overview';
import { Button } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import StaffPageShell from '@/layouts/staff-page-shell';
import { formatDate } from '@/lib/datetime';

interface Props {
    today: string;
    today_shifts: RosterShift[];
    upcoming_shifts: RosterShift[];
    recent_shifts: RosterShift[];
    grouped_by_day: Record<string, RosterShift[]>;
    window: {
        timezone: string;
        today: string;
        upcoming_days: number;
        recent_days: number;
    };
    labels?: Record<string, string>;
}

export default function MyRoster({
    today,
    today_shifts,
    upcoming_shifts,
    recent_shifts,
    window,
    labels,
}: Props) {
    const [selectedShift, setSelectedShift] = useState<RosterShift | null>(
        null,
    );
    const [detailOpen, setDetailOpen] = useState(false);

    const nextShift = useMemo(
        () => [...today_shifts, ...upcoming_shifts][0] ?? null,
        [today_shifts, upcoming_shifts],
    );

    const openShift = (shift: RosterShift) => {
        setSelectedShift(shift);
        setDetailOpen(true);
    };

    const refresh = () => {
        router.reload({
            only: [
                'today_shifts',
                'upcoming_shifts',
                'recent_shifts',
                'grouped_by_day',
            ],
            preserveScroll: true,
        });
    };
    const t = (key: string, fallback: string) => labels?.[key] ?? fallback;

    return (
        <StaffPageShell
            title={t('my_roster', 'My roster')}
            subtitle={today}
            headerAction={
                <Button
                    type="button"
                    size="icon"
                    variant="outline"
                    onClick={refresh}
                    aria-label="Refresh roster"
                >
                    <RefreshCw className="h-4 w-4" />
                </Button>
            }
        >
            <Head title={t('my_roster', 'My roster')} />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-5 pb-28">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">
                            {t('this_week', 'This week')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {window.upcoming_days} days ahead,{' '}
                            {window.recent_days} days back
                        </p>
                    </div>
                    <GuardrailCard
                        unstyled
                        className="inline-flex rounded-lg border bg-card p-1"
                    >
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Previous week"
                            disabled
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <Button type="button" variant="ghost" onClick={refresh}>
                            Today
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Next week"
                            disabled
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </GuardrailCard>
                </div>

                {nextShift ? (
                    <GuardrailCard
                        unstyled
                        className="rounded-lg border bg-card p-4"
                    >
                        <div className="flex items-start gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                <CalendarDays className="h-5 w-5" />
                            </div>
                            <div className="min-w-0">
                                <p className="text-sm font-semibold">
                                    {t('next_shift', 'Next shift')}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {formatDate(nextShift.starts_at)} with{' '}
                                    {nextShift.client?.name ??
                                        'the person we support'}
                                </p>
                            </div>
                        </div>
                    </GuardrailCard>
                ) : null}

                <WeekGridOverview
                    todayShifts={today_shifts}
                    upcomingShifts={upcoming_shifts}
                    recentShifts={recent_shifts}
                    onSelect={openShift}
                    today={window.today}
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                    <div className="space-y-6">
                        <section className="space-y-3">
                            <div>
                                <h2 className="text-base font-semibold">
                                    Today
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Full shift timeline for{' '}
                                    {formatDate(window.today)}
                                </p>
                            </div>
                            <TodayTimeline
                                shifts={today_shifts}
                                onSelect={openShift}
                            />
                        </section>

                        <section className="space-y-3">
                            <div>
                                <h2 className="text-base font-semibold">
                                    Upcoming
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Your assigned shifts for the next 14 days
                                </p>
                            </div>
                            <UpcomingList
                                shifts={upcoming_shifts}
                                onSelect={openShift}
                            />
                        </section>
                    </div>

                    <aside className="space-y-4 lg:sticky lg:top-20 lg:self-start">
                        <section className="space-y-3">
                            <div>
                                <h2 className="text-base font-semibold">
                                    Today at a glance
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Tap any block for detail.
                                </p>
                            </div>
                            <TodayTimeline
                                shifts={today_shifts}
                                onSelect={openShift}
                                compact
                            />
                        </section>

                        <RecentList
                            shifts={recent_shifts}
                            onSelect={openShift}
                        />
                    </aside>
                </div>
            </div>

            <ShiftDetailSheet
                shift={selectedShift}
                open={detailOpen}
                onOpenChange={setDetailOpen}
            />
        </StaffPageShell>
    );
}
