import { Link } from '@inertiajs/react';
import { CalendarDays, Clock3, Coffee, LogOut, Play } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatTime } from '@/lib/datetime';

interface Props {
    location?: string | null;
    startsAt?: string | null;
    endsAt?: string | null;
    clockInAt?: string | null;
    clockedIn: boolean;
    onBreak: boolean;
    elapsed: string;
    hasShift: boolean;
    canClock: boolean;
    canReviewTime: boolean;
    onClock: () => void;
    onToggleBreak: () => void;
    onReviewTime: () => void;
}

export function ShiftSummary(p: Props) {
    return (
        <Card aria-label="Your shift">
            <CardContent className="space-y-4 p-5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-section-title">Your shift</h2>
                    <StatusBadge variant={p.clockedIn ? 'success' : 'neutral'}>
                        <Clock3 className="size-3.5" />
                        {p.onBreak
                            ? 'On a break'
                            : p.clockedIn
                              ? 'Clocked in'
                              : 'Not clocked in'}
                    </StatusBadge>
                </div>
                <div className="space-y-1">
                    {p.startsAt && p.endsAt ? (
                        <p className="text-page-title tabular-nums">
                            {formatTime(p.startsAt)} – {formatTime(p.endsAt)}
                        </p>
                    ) : (
                        <p className="text-section-title">No rostered shift</p>
                    )}
                    {p.location && <p className="text-subtle">{p.location}</p>}
                    {p.clockedIn && (
                        <p className="text-subtle">
                            {p.clockInAt
                                ? `Clocked in at ${formatTime(p.clockInAt)} · `
                                : ''}
                            {p.elapsed} elapsed
                        </p>
                    )}
                </div>
                {!p.hasShift && (
                    <p className="text-subtle">
                        {p.clockedIn
                            ? 'Your time is being recorded. Tasks and people appear when you have a current rostered shift.'
                            : 'Check your calendar for your next shift. Tasks and people appear here when that shift is current.'}
                    </p>
                )}
                <div className="grid grid-cols-2 gap-2">
                    {p.clockedIn && p.canClock && (
                        <Button
                            className="frontline-tap"
                            variant="outline"
                            onClick={p.onToggleBreak}
                        >
                            <Coffee className="size-4" />
                            {p.onBreak ? 'End break' : 'Take a break'}
                        </Button>
                    )}
                    {p.canClock && (
                        <Button
                            className="frontline-tap"
                            variant={p.clockedIn ? 'outline' : 'default'}
                            onClick={p.onClock}
                        >
                            {p.clockedIn ? (
                                <LogOut className="size-4" />
                            ) : (
                                <Play className="size-4" />
                            )}
                            {p.clockedIn ? 'Finish shift' : 'Clock in'}
                        </Button>
                    )}
                </div>
                {p.canReviewTime && (
                    <Button
                        variant="outline"
                        className="frontline-tap w-full"
                        onClick={p.onReviewTime}
                    >
                        <Clock3 className="size-4" />
                        Review my timesheet
                    </Button>
                )}
                {!p.hasShift && (
                    <Button
                        variant="outline"
                        className="frontline-tap w-full"
                        asChild
                    >
                        <Link href="/my-calendar">
                            <CalendarDays className="size-4" />
                            View my roster
                        </Link>
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}
