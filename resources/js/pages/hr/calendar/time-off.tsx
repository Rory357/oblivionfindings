import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CalendarOff, ChevronLeft, ChevronRight } from 'lucide-react';

interface LeaveEntry {
    id: number;
    user_name: string;
    leave_type: string;
    status: string;
}

interface PublicHoliday {
    id: number;
    name: string;
    region: string | null;
    is_national: boolean;
}

interface CalendarDay {
    date: string;
    day: number;
    day_of_week: number;
    is_weekend: boolean;
    leave: LeaveEntry[];
    public_holidays: PublicHoliday[];
}

interface Props {
    calendarDays: CalendarDay[];
    month: string;
    monthLabel: string;
    filters: {
        department: string | null;
        team: string | null;
        site_id: string | null;
    };
    departments: Array<{ id: number; name: string }>;
    teams: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Calendar', href: '/hr/calendar/time-off' },
    { title: 'Time Off', href: '/hr/calendar/time-off' },
];

const leaveTypeColors: Record<string, string> = {
    annual: 'bg-status-info-bg text-status-info',
    sick: 'bg-status-critical-bg text-status-critical',
    personal: 'bg-primary/20 text-primary',
    bereavement: 'bg-muted text-foreground',
    parental: 'bg-status-critical-bg text-status-critical',
    public_holiday: 'bg-status-success-bg text-status-success',
    unpaid: 'bg-status-warning-bg text-status-warning',
    other: 'bg-status-warning-bg text-status-warning',
};

const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function TimeOffCalendar({
    calendarDays,
    month,
    monthLabel,
    filters,
    departments,
    teams,
}: Props) {
    const navigateMonth = (direction: number) => {
        const [year, m] = month.split('-').map(Number);
        const d = new Date(year, m - 1 + direction, 1);
        const newMonth = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
        router.get(
            '/hr/calendar/time-off',
            { ...filters, month: newMonth },
            { preserveState: true },
        );
    };

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/calendar/time-off',
            { ...filters, ...next, month },
            { preserveState: true, preserveScroll: true },
        );
    };

    // Pad the beginning of the grid with empty cells
    const firstDayOfWeek =
        calendarDays.length > 0 ? calendarDays[0].day_of_week : 0;
    const paddedDays: (CalendarDay | null)[] = [
        ...Array(firstDayOfWeek).fill(null),
        ...calendarDays,
    ];

    // Pad the end to complete the last week
    const remainder = paddedDays.length % 7;
    if (remainder > 0) {
        paddedDays.push(...Array(7 - remainder).fill(null));
    }

    const today = new Date().toISOString().split('T')[0];

    const totalOff = calendarDays.reduce((sum, d) => sum + d.leave.length, 0);
    const totalHolidays = calendarDays.reduce(
        (sum, d) => sum + d.public_holidays.length,
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Time Off Calendar - ${monthLabel}`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={CalendarOff}
                        title="Time Off Calendar"
                        description="See who is off each day, colour-coded by leave type."
                        stats={[
                            { label: 'Month', value: monthLabel },
                            { label: 'Off this month', value: totalOff },
                            { label: 'Public holidays', value: totalHolidays },
                        ]}
                        actions={
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={() => navigateMonth(-1)}
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={() => navigateMonth(1)}
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        }
                    />
                }
            >
                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Department
                            </Label>
                            <Select
                                value={filters.department || 'all'}
                                onValueChange={(val) =>
                                    onFilter({
                                        department: val === 'all' ? null : val,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Departments
                                    </SelectItem>
                                    {departments.map((d) => (
                                        <SelectItem
                                            key={d.id}
                                            value={String(d.id)}
                                        >
                                            {d.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Team
                            </Label>
                            <Select
                                value={filters.team || 'all'}
                                onValueChange={(val) =>
                                    onFilter({
                                        team: val === 'all' ? null : val,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Teams
                                    </SelectItem>
                                    {teams.map((t) => (
                                        <SelectItem key={t} value={t}>
                                            {t}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-end">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    onFilter({
                                        department: null,
                                        team: null,
                                        site_id: null,
                                    })
                                }
                            >
                                Clear Filters
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Legend */}
                <div className="flex flex-wrap gap-3">
                    {Object.entries(leaveTypeColors).map(([type, color]) => (
                        <div key={type} className="flex items-center gap-1.5">
                            <div className={`h-3 w-3 rounded ${color}`} />
                            <span className="text-xs text-muted-foreground capitalize">
                                {type.replace('_', ' ')}
                            </span>
                        </div>
                    ))}
                </div>

                {/* Calendar Grid */}
                <Card>
                    <CardContent className="p-0">
                        {/* Day headers */}
                        <div className="grid grid-cols-7 border-b">
                            {dayNames.map((name) => (
                                <div
                                    key={name}
                                    className="border-r p-2 text-center text-xs font-medium text-muted-foreground last:border-r-0"
                                >
                                    {name}
                                </div>
                            ))}
                        </div>

                        {/* Calendar cells */}
                        <div className="grid grid-cols-7">
                            {paddedDays.map((day, idx) => (
                                <div
                                    key={idx}
                                    className={`min-h-[100px] border-r border-b p-1.5 last:border-r-0 ${
                                        !day
                                            ? 'bg-muted'
                                            : day.is_weekend
                                              ? 'bg-muted'
                                              : day.date === today
                                                ? 'bg-status-info-bg'
                                                : ''
                                    }`}
                                >
                                    {day && (
                                        <>
                                            <div
                                                className={`mb-1 text-xs font-medium ${
                                                    day.date === today
                                                        ? 'text-status-info'
                                                        : day.is_weekend
                                                          ? 'text-muted-foreground'
                                                          : 'text-foreground'
                                                }`}
                                            >
                                                {day.day}
                                            </div>
                                            <div className="space-y-0.5">
                                                {day.public_holidays.map(
                                                    (holiday) => (
                                                        <div
                                                            key={`holiday-${holiday.id}`}
                                                            className="truncate rounded bg-status-success-bg px-1 py-0.5 text-[10px] leading-tight text-status-success"
                                                            title={`${holiday.name} - ${holiday.region ?? 'national'}`}
                                                        >
                                                            {holiday.name}
                                                        </div>
                                                    ),
                                                )}
                                                {day.leave.map((entry) => (
                                                    <div
                                                        key={entry.id}
                                                        className={`truncate rounded px-1 py-0.5 text-[10px] leading-tight ${leaveTypeColors[entry.leave_type] ?? 'bg-muted text-foreground'}`}
                                                        title={`${entry.user_name} - ${entry.leave_type}`}
                                                    >
                                                        {entry.user_name}
                                                    </div>
                                                ))}
                                            </div>
                                        </>
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
