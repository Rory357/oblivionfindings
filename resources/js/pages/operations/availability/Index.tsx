import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Calendar, CalendarCheck, CheckCircle, Clock, Search, User, XCircle } from 'lucide-react';
import { useState } from 'react';

type TimeOff = {
    id: number;
    reason: string;
    starts_at: string;
    ends_at: string;
};

type LeaveRequest = {
    id: number;
    leave_type: string;
    starts_at: string;
    ends_at: string;
    status: string;
};

type Availability = {
    id: number;
    day_of_week: number;
    start_time: string;
    end_time: string;
    is_available: boolean;
};

type StaffMember = {
    id: number;
    name: string;
    email: string;
    role?: string;
    staff_availability?: Availability[];
    staff_time_off?: TimeOff[];
};

type Props = {
    staff: StaffMember[];
    upcomingLeave: Record<number, LeaveRequest[]>;
};

const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function AvailabilityIndex({ staff, upcomingLeave }: Props) {
    const [search, setSearch] = useState('');

    const filtered = staff.filter(
        (s) =>
            s.name.toLowerCase().includes(search.toLowerCase()) ||
            s.email.toLowerCase().includes(search.toLowerCase()),
    );

    const availableNow = filtered.filter((s) => {
        const today = new Date().getDay();
        return s.staff_availability?.some((a) => a.day_of_week === today && a.is_available);
    }).length;

    const onLeave = filtered.filter((s) => {
        const leave = upcomingLeave[s.id];
        if (!leave?.length) return false;
        const now = new Date().toISOString();
        return leave.some((l) => l.starts_at <= now && l.ends_at >= now);
    }).length;

    return (
        <AppLayout>
            <Head title="Staff Availability" />
            <PageHero
                icon={CalendarCheck}
                title="Staff Availability"
                description="View staff availability, time-off, and scheduling constraints for rostering."
                stats={[
                    { label: 'Total staff', value: filtered.length },
                    { label: 'Available today', value: availableNow },
                    { label: 'On leave', value: onLeave },
                ]}
            />
            <PageShell>
                {/* Stats */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-5">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info">
                                <User className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{filtered.length}</p>
                                <p className="text-xs text-muted-foreground">Total Staff</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-5">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success">
                                <CheckCircle className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{availableNow}</p>
                                <p className="text-xs text-muted-foreground">Available Today</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-5">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning">
                                <Calendar className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{onLeave}</p>
                                <p className="text-xs text-muted-foreground">Currently on Leave</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Search */}
                <div className="mb-4">
                    <div className="relative max-w-sm">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search staff..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="h-9 pl-9 text-sm"
                        />
                    </div>
                </div>

                {/* Staff cards */}
                <div className="space-y-3">
                    {filtered.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-12">
                                <User className="mb-3 h-10 w-10 text-muted-foreground/30" />
                                <p className="text-sm text-muted-foreground">No staff found.</p>
                            </CardContent>
                        </Card>
                    ) : (
                        filtered.map((member) => {
                            const memberLeave = upcomingLeave[member.id] ?? [];
                            const availability = member.staff_availability ?? [];
                            const timeOff = member.staff_time_off ?? [];

                            return (
                                <Card key={member.id}>
                                    <CardHeader className="pb-2">
                                        <div className="flex items-center justify-between">
                                            <CardTitle className="text-sm font-medium">{member.name}</CardTitle>
                                            {member.role && (
                                                <Badge variant="outline" className="text-xs capitalize">
                                                    {member.role.replace(/_/g, ' ')}
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="text-xs text-muted-foreground">{member.email}</p>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        {/* Weekly availability grid */}
                                        {availability.length > 0 && (
                                            <div>
                                                <p className="mb-1.5 text-xs font-medium text-muted-foreground">Weekly Availability</p>
                                                <div className="flex gap-1">
                                                    {DAY_NAMES.map((day, idx) => {
                                                        const slot = availability.find((a) => a.day_of_week === idx);
                                                        const isAvailable = slot?.is_available ?? false;
                                                        return (
                                                            <div
                                                                key={day}
                                                                className={`flex h-8 w-10 flex-col items-center justify-center rounded text-xs ${
                                                                    isAvailable
                                                                        ? 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success'
                                                                        : 'bg-muted text-muted-foreground'
                                                                }`}
                                                            >
                                                                <span className="text-[10px] font-medium">{day}</span>
                                                                {isAvailable ? (
                                                                    <CheckCircle className="h-3 w-3" />
                                                                ) : (
                                                                    <XCircle className="h-3 w-3 opacity-40" />
                                                                )}
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        )}

                                        {/* Time off */}
                                        {timeOff.length > 0 && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium text-muted-foreground">Scheduled Time Off</p>
                                                <div className="space-y-1">
                                                    {timeOff.map((off) => (
                                                        <div key={off.id} className="flex items-center gap-2 text-xs">
                                                            <Clock className="h-3 w-3 text-status-warning" />
                                                            <span>
                                                                {new Date(off.starts_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}
                                                                {' — '}
                                                                {new Date(off.ends_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}
                                                            </span>
                                                            {off.reason && (
                                                                <span className="text-muted-foreground">({off.reason})</span>
                                                            )}
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {/* Upcoming leave from HR */}
                                        {memberLeave.length > 0 && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium text-muted-foreground">Approved Leave</p>
                                                <div className="space-y-1">
                                                    {memberLeave.map((leave) => (
                                                        <div key={leave.id} className="flex items-center gap-2 text-xs">
                                                            <Calendar className="h-3 w-3 text-status-info" />
                                                            <Badge variant="secondary" className="text-[10px]">
                                                                {leave.leave_type?.replace(/_/g, ' ') ?? 'Leave'}
                                                            </Badge>
                                                            <span>
                                                                {new Date(leave.starts_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}
                                                                {' — '}
                                                                {new Date(leave.ends_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {/* No data */}
                                        {availability.length === 0 && timeOff.length === 0 && memberLeave.length === 0 && (
                                            <p className="text-xs text-muted-foreground/60">No availability data configured.</p>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })
                    )}
                </div>
            </PageShell>
        </AppLayout>
    );
}
