import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock,
    MapPin,
    Plus,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface MeetingItem {
    id: number;
    title: string;
    meeting_type: string;
    scheduled_at: string;
    duration_minutes: number;
    location: string | null;
    status: string;
    quorum_met: boolean;
    chair: { name: string } | null;
    secretary: { name: string } | null;
}

interface MeetingTypeOption {
    value: string;
    label: string;
}

interface Props extends PageProps {
    month: string;
    monthLabel: string;
    previousMonth: string;
    nextMonth: string;
    selectedDate: string;
    selectedMeetingType: string;
    meetingTypes: MeetingTypeOption[];
    meetings: MeetingItem[];
}

const WEEK_DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export default function MeetingsCalendar({
    auth,
    month,
    monthLabel,
    previousMonth,
    nextMonth,
    selectedDate,
    selectedMeetingType,
    meetingTypes,
    meetings,
}: Props) {
    const [activeDate, setActiveDate] = useState(selectedDate);

    const monthStart = useMemo(() => {
        const date = new Date(`${month}-01T00:00:00`);
        return date;
    }, [month]);

    const calendarDays = useMemo(() => {
        const start = new Date(monthStart);
        const mondayOffset = (start.getDay() + 6) % 7;
        start.setDate(start.getDate() - mondayOffset);

        const days: Array<{
            date: Date;
            dateKey: string;
            inMonth: boolean;
            isToday: boolean;
        }> = [];
        const todayKey = formatDateKey(new Date());

        for (let i = 0; i < 42; i++) {
            const date = new Date(start);
            date.setDate(start.getDate() + i);
            const dateKey = formatDateKey(date);

            days.push({
                date,
                dateKey,
                inMonth: date.getMonth() === monthStart.getMonth(),
                isToday: dateKey === todayKey,
            });
        }

        return days;
    }, [monthStart]);

    const meetingsByDate = useMemo(() => {
        const grouped: Record<string, MeetingItem[]> = {};
        for (const meeting of meetings) {
            const dateKey = formatDateKey(new Date(meeting.scheduled_at));
            grouped[dateKey] = grouped[dateKey] || [];
            grouped[dateKey].push(meeting);
        }
        return grouped;
    }, [meetings]);

    const selectedDateMeetings = meetingsByDate[activeDate] || [];

    const changeMonth = (targetMonth: string) => {
        router.get(
            '/governance/meetings/calendar',
            {
                month: targetMonth,
                meeting_type: selectedMeetingType,
                date: activeDate,
            },
            {
                preserveState: false,
                replace: true,
            },
        );
    };

    const changeMeetingType = (value: string) => {
        router.get(
            '/governance/meetings/calendar',
            {
                month,
                meeting_type: value,
                date: activeDate,
            },
            {
                preserveState: false,
                replace: true,
            },
        );
    };

    const jumpToToday = () => {
        const today = new Date();
        router.get(
            '/governance/meetings/calendar',
            {
                month: `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`,
                date: formatDateKey(today),
                meeting_type: selectedMeetingType,
            },
            {
                preserveState: false,
                replace: true,
            },
        );
    };

    const getStatusColor = (status: string) => governanceStatusColor(status);

    const meetingTypeLabel = (type: string) => {
        return (
            meetingTypes.find((option) => option.value === type)?.label ?? type
        );
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Meetings', href: '/governance/meetings' },
                { title: 'Calendar', href: '/governance/meetings/calendar' },
            ]}
        >
            <Head title="Board Meeting Calendar" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Users}
                        title="Board Meeting Calendar"
                        description="Click any date to view meetings and open records."
                        stats={[
                            { label: 'This month', value: meetings.length },
                            { label: 'Period', value: monthLabel },
                        ]}
                        actions={
                            <>
                                <Button
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    asChild
                                >
                                    <Link href="/governance/meetings">
                                        List View
                                    </Link>
                                </Button>
                                <Button asChild>
                                    <Link href="/governance/meetings/create">
                                        <Plus className="mr-1 h-4 w-4" />
                                        Schedule Meeting
                                    </Link>
                                </Button>
                            </>
                        }
                    />
                }
            >
                <Card className="mb-4 flex flex-col gap-3 bg-card p-4 md:flex-row md:items-center md:justify-between">
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            onClick={() => changeMonth(previousMonth)}
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <div className="min-w-[180px] text-center font-semibold">
                            {monthLabel}
                        </div>
                        <Button
                            variant="outline"
                            size="icon"
                            onClick={() => changeMonth(nextMonth)}
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                        <Button variant="outline" onClick={jumpToToday}>
                            Today
                        </Button>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="text-sm text-muted-foreground">
                            Filter
                        </span>
                        <Select
                            value={selectedMeetingType}
                            onValueChange={changeMeetingType}
                        >
                            <SelectTrigger className="w-52">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {meetingTypes.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </Card>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CalendarDays className="h-5 w-5 text-status-info" />
                                Monthly Calendar
                            </CardTitle>
                            <CardDescription>
                                Dates with meetings are highlighted and
                                clickable.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-2 grid grid-cols-7 gap-2">
                                {WEEK_DAY_LABELS.map((day) => (
                                    <div
                                        key={day}
                                        className="px-2 py-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                    >
                                        {day}
                                    </div>
                                ))}
                            </div>
                            <div className="grid grid-cols-7 gap-2">
                                {calendarDays.map((day) => {
                                    const dayMeetings =
                                        meetingsByDate[day.dateKey] || [];
                                    const isActive = day.dateKey === activeDate;

                                    return (
                                        <Button
                                            key={day.dateKey}
                                            type="button"
                                            variant="ghost"
                                            onClick={() =>
                                                setActiveDate(day.dateKey)
                                            }
                                            className={cn(
                                                'h-auto min-h-[92px] justify-start rounded-lg border p-2 text-left',
                                                day.inMonth
                                                    ? 'bg-card'
                                                    : 'bg-muted text-muted-foreground',
                                                isActive &&
                                                    'border-status-info/30 ring-1 ring-status-info',
                                                !isActive &&
                                                    day.isToday &&
                                                    'border-status-info/30',
                                                dayMeetings.length > 0 &&
                                                    'border-status-info/30',
                                                'hover:bg-status-info-bg',
                                            )}
                                        >
                                            <div className="mb-1 flex items-center justify-between">
                                                <span
                                                    className={cn(
                                                        'text-sm font-medium',
                                                        day.isToday &&
                                                            'text-status-info',
                                                    )}
                                                >
                                                    {day.date.getDate()}
                                                </span>
                                                {dayMeetings.length > 0 && (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px]"
                                                    >
                                                        {dayMeetings.length}
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="space-y-1">
                                                {dayMeetings
                                                    .slice(0, 2)
                                                    .map((meeting) => (
                                                        <div
                                                            key={meeting.id}
                                                            className="truncate rounded bg-status-info-bg px-1.5 py-0.5 text-[11px] font-medium text-status-info"
                                                        >
                                                            {formatTime(
                                                                meeting.scheduled_at,
                                                            )}{' '}
                                                            {meeting.title}
                                                        </div>
                                                    ))}
                                                {dayMeetings.length > 2 && (
                                                    <div className="text-[11px] text-muted-foreground">
                                                        +
                                                        {dayMeetings.length - 2}{' '}
                                                        more
                                                    </div>
                                                )}
                                            </div>
                                        </Button>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {formatReadableDate(activeDate)}
                            </CardTitle>
                            <CardDescription>
                                {selectedDateMeetings.length} meeting(s)
                                scheduled
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {selectedDateMeetings.length > 0 ? (
                                <div className="space-y-3">
                                    {selectedDateMeetings.map((meeting) => (
                                        <div
                                            key={meeting.id}
                                            className="rounded-lg border p-3"
                                        >
                                            <div className="mb-2 flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="font-semibold text-foreground">
                                                        {meeting.title}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {meetingTypeLabel(
                                                            meeting.meeting_type,
                                                        )}
                                                    </p>
                                                </div>
                                                <Badge
                                                    className={getStatusColor(
                                                        meeting.status,
                                                    )}
                                                >
                                                    {meeting.status.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </div>
                                            <div className="space-y-1 text-sm text-muted-foreground">
                                                <div className="flex items-center gap-1">
                                                    <Clock className="h-3.5 w-3.5" />
                                                    {formatTime(
                                                        meeting.scheduled_at,
                                                    )}{' '}
                                                    ({meeting.duration_minutes}{' '}
                                                    mins)
                                                </div>
                                                {meeting.location && (
                                                    <div className="flex items-center gap-1">
                                                        <MapPin className="h-3.5 w-3.5" />
                                                        {meeting.location}
                                                    </div>
                                                )}
                                            </div>
                                            <div className="mt-3 flex items-center justify-between">
                                                <div className="text-xs text-muted-foreground">
                                                    {meeting.chair
                                                        ? `Chair: ${meeting.chair.name}`
                                                        : 'Chair not assigned'}
                                                </div>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/governance/meetings/${meeting.id}`}
                                                    >
                                                        Open
                                                    </Link>
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                    No meetings on this date.
                                    <div className="mt-2">
                                        <Button size="sm" asChild>
                                            <Link href="/governance/meetings/create">
                                                Schedule one
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}

function formatDateKey(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function formatTime(dateString: string): string {
    return new Date(dateString).toLocaleTimeString('en-NZ', {
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatReadableDate(dateKey: string): string {
    return new Date(`${dateKey}T00:00:00`).toLocaleDateString('en-NZ', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}
