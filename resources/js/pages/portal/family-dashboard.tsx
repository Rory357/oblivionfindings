import { PresenceBadge, PresenceDot } from '@/components/presence-dot';
import ShiftTimelineSummary from '@/components/shift-timeline-summary';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CalendarPlus,
    Heart,
    Home,
    MapPin,
    MessageSquare,
    Phone,
    ShieldAlert,
    Users,
    Video,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

function getGreeting(): { text: string; emoji: string } {
    const hour = new Date().getHours();
    if (hour < 12) return { text: 'Good morning', emoji: '☀️' };
    if (hour < 17) return { text: 'Good afternoon', emoji: '🌤️' };
    if (hour < 21) return { text: 'Good evening', emoji: '🌙' };
    return { text: 'Good night', emoji: '✨' };
}

type Staff = {
    id: number;
    name: string;
    avatar?: string | null;
    email?: string;
    presence?: string;
};
type ShiftWorker = {
    id: number;
    name: string;
    avatar?: string | null;
    presence?: string;
    shift_starts_at?: string;
    shift_ends_at?: string;
    shift_type?: string | null;
    service_context?: string | null;
    location?: string | null;
};
type ShiftItem = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    type: string;
    shift_type?: string | null;
    service_context?: string | null;
    location?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    expected_break_minutes?: number | null;
    staff?: { id: number; name: string; avatar?: string | null } | null;
};
type MonthShift = {
    id: number;
    date: string;
    starts_at: string;
    ends_at: string;
    status: string;
    type: string;
    shift_type?: string | null;
    service_context?: string | null;
    location?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    staff_name?: string | null;
};
type ReactionGroup = {
    emoji: string;
    count: number;
    user_ids: number[];
};
type EventItem = {
    id: number;
    type: string;
    subject: string;
    body?: string | null;
    occurred_at: string;
    actor_name?: string | null;
    meta?: Record<string, unknown> | null;
    reactions?: ReactionGroup[];
};
type IncidentItem = {
    id: number;
    type: string;
    severity: string;
    occurred_at: string;
    description?: string | null;
};
type VisitRequest = {
    id: number;
    requested_date: string;
    preferred_time_start?: string | null;
    preferred_time_end?: string | null;
    visit_type: string;
    notes?: string | null;
    status: string;
    review_notes?: string | null;
};

type Props = {
    client: {
        id: number;
        first_name: string;
        last_name: string;
        preferred_name?: string | null;
        date_of_birth?: string | null;
        status: string;
        avatar?: string | null;
        profile_photo_url?: string | null;
        phone?: string | null;
        address_line_1?: string | null;
        city?: string | null;
        interests_hobbies?: string | null;
        dietary_requirements?: string | null;
        mobility_needs?: string | null;
    };
    site?: {
        id: number;
        name: string;
        address?: string | null;
        city?: string | null;
    } | null;
    keyWorker?: Staff | null;
    supportWorkers: Staff[];
    currentShiftWorker?: ShiftWorker | null;
    nextShiftWorker?: ShiftWorker | null;
    todayShifts: ShiftItem[];
    weekShifts: ShiftItem[];
    monthShifts: MonthShift[];
    recentEvents: EventItem[];
    recentIncidents: IncidentItem[];
    visitRequests: VisitRequest[];
    pendingConsentRequests?: Array<{
        id: number;
        consent_type: { id: number; name: string; category: string } | null;
        requested_by: { id: number; name: string } | null;
        purpose: string;
        sent_at: string | null;
        expires_at: string | null;
        action_url: string;
    }>;
    stats: {
        shiftsToday: number;
        shiftsThisWeek: number;
        shiftsThisMonth: number;
        pendingVisitRequests: number;
        pendingConsentRequests?: number;
        incidentsLast30Days: number;
    };
    relation?: string | null;
    medicalSummary?: {
        allergies?: string | null;
        disabilities?: string | null;
        notes?: string | null;
    } | null;
    criticalAlerts: IncidentItem[];
    dailySummary: {
        completedToday: number;
        scheduledToday: number;
        lastEvent?: { subject: string; occurred_at: string } | null;
    };
    carePlan?: {
        title?: string | null;
        goals_count: number;
        goals_completed: number;
        important_to_me?: string | null;
        ideal_day?: string | null;
        how_to_support?: string | null;
        likes?: string | null;
        dislikes?: string | null;
    } | null;
    emotionSummary?: {
        today: Record<string, number>;
        week: Record<string, number>;
        month: Record<string, number>;
    };
    familyNotesSummary?: {
        open: number;
        overdue: number;
        recent: Array<{
            id: number;
            title: string;
            note_type: string;
            priority: string;
            due_date?: string | null;
            is_overdue: boolean;
            assigned_shift?: {
                starts_at?: string | null;
                shift_type?: string | null;
            } | null;
        }>;
    };
};

const EMOTION_INFO: Record<
    string,
    { emoji: string; label: string; color: string }
> = {
    happy: {
        emoji: '😊',
        label: 'Happy',
        color: 'bg-emerald-100 text-emerald-700 border-emerald-200',
    },
    calm: {
        emoji: '😌',
        label: 'Calm',
        color: 'bg-sky-100 text-sky-700 border-sky-200',
    },
    excited: {
        emoji: '🤩',
        label: 'Excited',
        color: 'bg-amber-100 text-amber-700 border-amber-200',
    },
    tired: {
        emoji: '😴',
        label: 'Tired',
        color: 'bg-primary/10 text-primary border-primary',
    },
    anxious: {
        emoji: '😰',
        label: 'Anxious',
        color: 'bg-orange-100 text-orange-700 border-orange-200',
    },
    sad: {
        emoji: '😢',
        label: 'Sad',
        color: 'bg-blue-100 text-blue-700 border-blue-200',
    },
    frustrated: {
        emoji: '😤',
        label: 'Frustrated',
        color: 'bg-red-100 text-red-700 border-red-200',
    },
    confused: {
        emoji: '😕',
        label: 'Confused',
        color: 'bg-primary/10 text-primary border-primary',
    },
};

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatDate(dateStr: string): string {
    return new Date(dateStr + 'T00:00:00').toLocaleDateString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
}

function formatFullDate(iso: string): string {
    return new Date(iso).toLocaleDateString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatShiftTypeLabel(value?: string | null): string {
    return (value ?? 'standard').replace(/_/g, ' ');
}

const statusColors: Record<string, string> = {
    scheduled: 'bg-blue-100 text-blue-800',
    in_progress: 'bg-amber-100 text-amber-800',
    completed: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-muted text-muted-foreground',
    pending: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-emerald-100 text-emerald-800',
    declined: 'bg-red-100 text-red-800',
};

const visitTypeLabels: Record<
    string,
    { label: string; icon: typeof Calendar }
> = {
    in_person: { label: 'In Person', icon: Users },
    video_call: { label: 'Video Call', icon: Video },
    outing: { label: 'Outing', icon: MapPin },
};

const severityColors: Record<string, string> = {
    low: 'bg-blue-100 text-blue-800',
    medium: 'bg-yellow-100 text-yellow-800',
    high: 'bg-orange-100 text-orange-800',
    critical: 'bg-red-100 text-red-800',
};

function calculateAge(dob: string): number {
    const birth = new Date(dob);
    const now = new Date();
    let age = now.getFullYear() - birth.getFullYear();
    const m = now.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && now.getDate() < birth.getDate())) age--;
    return age;
}

export default function FamilyDashboard({
    client,
    site,
    keyWorker,
    supportWorkers,
    currentShiftWorker,
    nextShiftWorker,
    todayShifts,
    weekShifts,
    monthShifts,
    recentEvents,
    recentIncidents,
    visitRequests,
    pendingConsentRequests = [],
    stats,
    relation,
    medicalSummary,
    criticalAlerts,
    dailySummary,
    carePlan,
    emotionSummary,
    familyNotesSummary,
}: Props) {
    const getInitials = useInitials();
    const name =
        client.preferred_name ||
        `${client.first_name} ${client.last_name}`.trim();
    const fullName = `${client.first_name} ${client.last_name}`.trim();
    const [bookingOpen, setBookingOpen] = useState(false);
    const [calendarView, setCalendarView] = useState<'week' | 'month'>('week');
    const greeting = getGreeting();

    const form = useForm({
        requested_date: '',
        preferred_time_start: '',
        preferred_time_end: '',
        visit_type: 'in_person' as string,
        notes: '',
    });

    const submitVisit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/portal/clients/${client.id}/visit-requests`, {
            preserveScroll: true,
            onSuccess: () => {
                setBookingOpen(false);
                form.reset();
                toast.success('Visit request submitted!');
            },
            onError: () => toast.error('Please check the form and try again.'),
        });
    };

    const cancelVisit = (visitId: number) => {
        router.post(
            `/portal/clients/${client.id}/visit-requests/${visitId}/cancel`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Visit request cancelled.'),
            },
        );
    };

    // Build calendar grid for month view
    const calendarDays = useMemo(() => {
        const today = new Date();
        const days: { date: string; isToday: boolean; shifts: MonthShift[] }[] =
            [];
        for (let i = 0; i < 28; i++) {
            const d = new Date(today);
            d.setDate(d.getDate() + i);
            const dateStr = d.toISOString().split('T')[0] ?? d.toISOString();
            days.push({
                date: dateStr,
                isToday: i === 0,
                shifts: monthShifts.filter((s) => s.date === dateStr),
            });
        }
        return days;
    }, [monthShifts]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                { title: name, href: `/portal/clients/${client.id}/dashboard` },
            ]}
        >
            <Head title={`${name} - Family Portal`} />

            <div className="mx-auto max-w-7xl space-y-6 p-4 md:p-6">
                {/* ── Hero header ──────────────────────────────── */}
                <div className="relative overflow-hidden rounded-2xl border bg-gradient-to-r from-amber-50 via-orange-50/30 to-rose-50/20 p-6 dark:from-amber-950/20 dark:via-orange-950/10 dark:to-rose-950/10">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-4">
                            <Avatar className="h-16 w-16 ring-2 ring-amber-200 ring-offset-2 dark:ring-amber-700">
                                <AvatarImage
                                    src={
                                        client.avatar ??
                                        client.profile_photo_url ??
                                        undefined
                                    }
                                    alt={fullName}
                                />
                                <AvatarFallback className="bg-amber-100 text-lg font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                    {getInitials(fullName)}
                                </AvatarFallback>
                            </Avatar>
                            <div>
                                <h1 className="text-2xl font-bold tracking-tight">
                                    {greeting.emoji} {greeting.text}!
                                </h1>
                                <p className="text-sm text-muted-foreground">
                                    Here's how {name} is doing today
                                </p>
                                <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                    {relation && (
                                        <Badge
                                            variant="outline"
                                            className="border-amber-200 bg-amber-50 text-amber-700 capitalize dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
                                        >
                                            <Heart className="mr-1 h-3 w-3" />
                                            {relation}
                                        </Badge>
                                    )}
                                    {client.date_of_birth && (
                                        <span>
                                            Age{' '}
                                            {calculateAge(client.date_of_birth)}
                                        </span>
                                    )}
                                    {client.status && (
                                        <Badge
                                            variant="secondary"
                                            className={
                                                client.status === 'active'
                                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300'
                                                    : ''
                                            }
                                        >
                                            {client.status}
                                        </Badge>
                                    )}
                                </div>
                            </div>
                        </div>
                        <Dialog
                            open={bookingOpen}
                            onOpenChange={setBookingOpen}
                        >
                            <DialogTrigger asChild>
                                <Button size="lg" className="gap-2 shadow-md">
                                    <CalendarPlus className="h-5 w-5" />
                                    Plan a Visit 💛
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-md">
                                <DialogHeader>
                                    <DialogTitle>Request a Visit</DialogTitle>
                                    <DialogDescription>
                                        Submit a visit request to see {name}.
                                        The care team will review and confirm.
                                    </DialogDescription>
                                </DialogHeader>
                                <form
                                    onSubmit={submitVisit}
                                    className="space-y-4"
                                >
                                    <div>
                                        <Label htmlFor="visit-date">
                                            Date *
                                        </Label>
                                        <Input
                                            id="visit-date"
                                            type="date"
                                            value={form.data.requested_date}
                                            onChange={(e) =>
                                                form.setData(
                                                    'requested_date',
                                                    e.target.value,
                                                )
                                            }
                                            min={
                                                new Date()
                                                    .toISOString()
                                                    .split('T')[0]
                                            }
                                        />
                                        {form.errors.requested_date && (
                                            <p className="mt-1 text-xs text-red-500">
                                                {form.errors.requested_date}
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div>
                                            <Label htmlFor="time-start">
                                                From
                                            </Label>
                                            <Input
                                                id="time-start"
                                                type="time"
                                                value={
                                                    form.data
                                                        .preferred_time_start
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'preferred_time_start',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="time-end">To</Label>
                                            <Input
                                                id="time-end"
                                                type="time"
                                                value={
                                                    form.data.preferred_time_end
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'preferred_time_end',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                    <div>
                                        <Label>Visit Type *</Label>
                                        <div className="mt-2 grid grid-cols-3 gap-2">
                                            {(
                                                [
                                                    'in_person',
                                                    'video_call',
                                                    'outing',
                                                ] as const
                                            ).map((type) => {
                                                const visitType =
                                                    visitTypeLabels[type] ??
                                                    visitTypeLabels.in_person!;
                                                const { label, icon: Icon } =
                                                    visitType;
                                                const selected =
                                                    form.data.visit_type ===
                                                    type;
                                                return (
                                                    <button
                                                        key={type}
                                                        type="button"
                                                        onClick={() =>
                                                            form.setData(
                                                                'visit_type',
                                                                type,
                                                            )
                                                        }
                                                        className={`flex flex-col items-center gap-1.5 rounded-lg border-2 p-3 text-xs font-medium transition-all ${
                                                            selected
                                                                ? 'border-primary bg-primary/5 text-primary'
                                                                : 'border-border text-muted-foreground hover:border-primary/30'
                                                        }`}
                                                    >
                                                        <Icon className="h-5 w-5" />
                                                        {label}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                    <div>
                                        <Label htmlFor="visit-notes">
                                            Notes
                                        </Label>
                                        <textarea
                                            id="visit-notes"
                                            className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                            rows={3}
                                            placeholder="Any special requests or things to note..."
                                            value={form.data.notes}
                                            onChange={(e) =>
                                                form.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="flex justify-end gap-2 pt-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                setBookingOpen(false)
                                            }
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={
                                                form.processing ||
                                                !form.data.requested_date
                                            }
                                        >
                                            {form.processing
                                                ? 'Submitting...'
                                                : 'Submit Request'}
                                        </Button>
                                    </div>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                {/* ── Quick Actions Bar ────────────────────────── */}
                <div className="flex flex-wrap gap-2 sm:gap-3">
                    <Link
                        href={`/portal/clients/${client.id}/messages`}
                        className="inline-flex items-center gap-2 rounded-full border bg-card px-4 py-2 text-sm font-medium shadow-sm transition-all hover:border-primary/30 hover:shadow-md"
                    >
                        <span>💬</span> Send a Message
                    </Link>
                    <button
                        onClick={() => setBookingOpen(true)}
                        className="inline-flex items-center gap-2 rounded-full border bg-card px-4 py-2 text-sm font-medium shadow-sm transition-all hover:border-primary/30 hover:shadow-md"
                    >
                        <span>📅</span> Plan a Visit
                    </button>
                    <Link
                        href={`/portal/clients/${client.id}/photos`}
                        className="inline-flex items-center gap-2 rounded-full border bg-card px-4 py-2 text-sm font-medium shadow-sm transition-all hover:border-primary/30 hover:shadow-md"
                    >
                        <span>📸</span> Photos
                    </Link>
                    <Link
                        href={`/portal/clients/${client.id}/timeline`}
                        className="inline-flex items-center gap-2 rounded-full border bg-card px-4 py-2 text-sm font-medium shadow-sm transition-all hover:border-primary/30 hover:shadow-md"
                    >
                        <span>📋</span> Timeline
                    </Link>
                    <Link
                        href={`/portal/clients/${client.id}/documents`}
                        className="inline-flex items-center gap-2 rounded-full border bg-card px-4 py-2 text-sm font-medium shadow-sm transition-all hover:border-primary/30 hover:shadow-md"
                    >
                        <span>📄</span> Documents
                    </Link>
                    <Link
                        href={`/portal/clients/${client.id}/health`}
                        className="inline-flex items-center gap-2 rounded-full border bg-card px-4 py-2 text-sm font-medium shadow-sm transition-all hover:border-primary/30 hover:shadow-md"
                    >
                        <span>🏥</span> Health
                    </Link>
                </div>

                {/* ── Glance Cards ────────────────────────────── */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <GlanceCard
                        emoji={stats.shiftsToday > 0 ? '😊' : '🌿'}
                        message={
                            stats.shiftsToday > 0
                                ? `${stats.shiftsToday} visit${stats.shiftsToday !== 1 ? 's' : ''} planned for today`
                                : 'A quiet day \u2014 no visits scheduled'
                        }
                        bgClass="bg-gradient-to-r from-sky-50 to-blue-50 dark:from-sky-950/20 dark:to-blue-950/20"
                    />
                    <GlanceCard
                        emoji={
                            stats.shiftsThisWeek > 3
                                ? '📅'
                                : stats.shiftsThisWeek > 0
                                  ? '🗓️'
                                  : '🌈'
                        }
                        message={
                            stats.shiftsThisWeek > 3
                                ? `A busy week with ${stats.shiftsThisWeek} visits!`
                                : stats.shiftsThisWeek > 0
                                  ? `${stats.shiftsThisWeek} visit${stats.shiftsThisWeek !== 1 ? 's' : ''} this week`
                                  : 'A clear week ahead \u2014 enjoy!'
                        }
                        bgClass="bg-gradient-to-r from-violet-50 to-purple-50 dark:from-violet-950/20 dark:to-purple-950/20"
                    />
                    <GlanceCard
                        emoji={stats.pendingVisitRequests > 0 ? '⏳' : '✅'}
                        message={
                            stats.pendingVisitRequests > 0
                                ? `${stats.pendingVisitRequests} visit request${stats.pendingVisitRequests !== 1 ? 's' : ''} being reviewed`
                                : 'All caught up! No pending requests'
                        }
                        bgClass="bg-gradient-to-r from-amber-50 to-yellow-50 dark:from-amber-950/20 dark:to-yellow-950/20"
                    />
                </div>

                {/* ── Mood Summary ────────────────────────────── */}
                {emotionSummary &&
                    (Object.keys(emotionSummary.today).length > 0 ||
                        Object.keys(emotionSummary.week).length > 0 ||
                        Object.keys(emotionSummary.month).length > 0) &&
                    (() => {
                        const renderMoodCard = (
                            title: string,
                            data: Record<string, number>,
                            emoji: string,
                        ) => {
                            const sorted = Object.entries(data).sort(
                                ([, a], [, b]) => b - a,
                            );
                            const top = sorted[0];
                            if (!top)
                                return (
                                    <div className="rounded-2xl border bg-card p-4 text-center">
                                        <p className="text-xs text-muted-foreground">
                                            {emoji} {title}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            No mood recorded
                                        </p>
                                    </div>
                                );
                            const info = EMOTION_INFO[top[0]];
                            return (
                                <div className="rounded-2xl border bg-card p-4">
                                    <p className="text-xs text-muted-foreground">
                                        {emoji} {title}
                                    </p>
                                    <div className="mt-2 flex items-center gap-2">
                                        <span className="text-2xl">
                                            {info?.emoji ?? top[0]}
                                        </span>
                                        <span className="text-sm font-semibold">
                                            {info?.label ?? top[0]}
                                        </span>
                                    </div>
                                    {sorted.length > 1 && (
                                        <div className="mt-2 flex flex-wrap gap-1">
                                            {sorted
                                                .slice(1)
                                                .map(([key, count]) => (
                                                    <span
                                                        key={key}
                                                        className={`inline-flex items-center gap-0.5 rounded-full border px-2 py-0.5 text-[10px] font-medium ${EMOTION_INFO[key]?.color ?? 'bg-muted'}`}
                                                    >
                                                        {
                                                            EMOTION_INFO[key]
                                                                ?.emoji
                                                        }{' '}
                                                        {count}
                                                    </span>
                                                ))}
                                        </div>
                                    )}
                                </div>
                            );
                        };

                        return (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                {renderMoodCard(
                                    'Today',
                                    emotionSummary.today,
                                    '🌤️',
                                )}
                                {renderMoodCard(
                                    'This Week',
                                    emotionSummary.week,
                                    '📅',
                                )}
                                {renderMoodCard(
                                    'This Month',
                                    emotionSummary.month,
                                    '🗓️',
                                )}
                            </div>
                        );
                    })()}

                {/* ── Family Notes Summary ────────────────────── */}
                {familyNotesSummary &&
                    (familyNotesSummary.open > 0 ||
                        (familyNotesSummary.recent ?? []).length > 0) && (
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <span>📝</span> Your Notes & To-Dos
                                </CardTitle>
                                <Button variant="ghost" size="sm" asChild>
                                    <Link
                                        href={`/portal/clients/${client.id}/family-notes`}
                                    >
                                        View all →
                                    </Link>
                                </Button>
                            </CardHeader>
                            <CardContent>
                                <div className="mb-3 flex items-center gap-4">
                                    <span className="text-sm">
                                        <span className="font-bold text-blue-700">
                                            {familyNotesSummary.open}
                                        </span>{' '}
                                        open
                                    </span>
                                    {familyNotesSummary.overdue > 0 && (
                                        <span className="text-sm font-medium text-red-600">
                                            {familyNotesSummary.overdue} overdue
                                        </span>
                                    )}
                                </div>
                                {(familyNotesSummary.recent ?? []).length >
                                    0 && (
                                    <div className="space-y-1.5">
                                        {familyNotesSummary.recent.map((n) => (
                                            <div
                                                key={n.id}
                                                className={`flex items-center justify-between rounded-lg border p-2 ${n.is_overdue ? 'border-red-200 bg-red-50/30' : ''}`}
                                            >
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm">
                                                        {n.note_type === 'todo'
                                                            ? '✅'
                                                            : n.note_type ===
                                                                'request'
                                                              ? '🙏'
                                                              : n.note_type ===
                                                                  'reminder'
                                                                ? '⏰'
                                                                : '📝'}
                                                    </span>
                                                    <div>
                                                        <span className="text-sm font-medium">
                                                            {n.title}
                                                        </span>
                                                        {n.assigned_shift
                                                            ?.starts_at && (
                                                            <p className="text-[10px] text-primary">
                                                                Assigned to{' '}
                                                                {formatShiftTypeLabel(
                                                                    n
                                                                        .assigned_shift
                                                                        .shift_type,
                                                                )}{' '}
                                                                shift on{' '}
                                                                {new Date(
                                                                    n
                                                                        .assigned_shift
                                                                        .starts_at,
                                                                ).toLocaleDateString(
                                                                    'en-NZ',
                                                                    {
                                                                        day: 'numeric',
                                                                        month: 'short',
                                                                    },
                                                                )}
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                                {n.due_date && (
                                                    <span className="text-[10px] text-muted-foreground">
                                                        {new Date(
                                                            n.due_date +
                                                                'T00:00:00',
                                                        ).toLocaleDateString(
                                                            'en-NZ',
                                                            {
                                                                day: 'numeric',
                                                                month: 'short',
                                                            },
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                {/* ── Main content grid ──────────────────────── */}
                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Left column: 2/3 width */}
                    <div className="space-y-6 lg:col-span-2">
                        {/* Today's Schedule */}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <span>📅</span>
                                    Today's Schedule
                                </CardTitle>
                                <span className="text-sm text-muted-foreground">
                                    {new Date().toLocaleDateString([], {
                                        weekday: 'long',
                                        month: 'long',
                                        day: 'numeric',
                                    })}
                                </span>
                            </CardHeader>
                            <CardContent>
                                {todayShifts.length > 0 ? (
                                    <div className="space-y-3">
                                        {todayShifts.map((shift) => (
                                            <ShiftRow
                                                key={shift.id}
                                                shift={shift}
                                            />
                                        ))}
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center justify-center py-8 text-center">
                                        <span className="mb-2 text-3xl">
                                            🌿
                                        </span>
                                        <p className="text-sm text-muted-foreground">
                                            Nothing on the schedule today
                                            &mdash; time to relax!
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Today's Snapshot */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <span>📋</span>
                                    Today's Snapshot
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-3">
                                    <div className="flex items-center gap-3 rounded-lg bg-amber-50/50 p-3 dark:bg-amber-950/10">
                                        <span className="text-2xl">
                                            {dailySummary.completedToday > 0 &&
                                            dailySummary.scheduledToday === 0
                                                ? '🌟'
                                                : dailySummary.completedToday >
                                                    0
                                                  ? '😊'
                                                  : dailySummary.scheduledToday >
                                                      0
                                                    ? '👍'
                                                    : '🌿'}
                                        </span>
                                        <div>
                                            <p className="text-sm font-medium">
                                                {dailySummary.completedToday >
                                                    0 &&
                                                dailySummary.scheduledToday > 0
                                                    ? `${name} has had ${dailySummary.completedToday} visit${dailySummary.completedToday !== 1 ? 's' : ''} so far, with ${dailySummary.scheduledToday} more to come!`
                                                    : dailySummary.completedToday >
                                                        0
                                                      ? `All done for today! ${name} had ${dailySummary.completedToday} visit${dailySummary.completedToday !== 1 ? 's' : ''}`
                                                      : dailySummary.scheduledToday >
                                                          0
                                                        ? `${dailySummary.scheduledToday} visit${dailySummary.scheduledToday !== 1 ? 's' : ''} coming up today \u2014 we'll keep you updated!`
                                                        : `A peaceful day for ${name} \u2014 nothing on the schedule`}
                                            </p>
                                            {dailySummary.lastEvent && (
                                                <p className="text-xs text-muted-foreground">
                                                    Last activity:{' '}
                                                    {
                                                        dailySummary.lastEvent
                                                            .subject
                                                    }{' '}
                                                    at{' '}
                                                    {new Date(
                                                        dailySummary.lastEvent
                                                            .occurred_at,
                                                    ).toLocaleTimeString([], {
                                                        hour: '2-digit',
                                                        minute: '2-digit',
                                                    })}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Care Plan Summary */}
                        {carePlan && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <span>🎯</span>
                                        {carePlan.title ||
                                            `${name}'s Care Plan`}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {carePlan.goals_count > 0 && (
                                        <div>
                                            <div className="flex items-center justify-between text-sm">
                                                <span className="font-medium">
                                                    Goals Progress
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {carePlan.goals_completed}/
                                                    {carePlan.goals_count}
                                                </span>
                                            </div>
                                            <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-primary transition-all"
                                                    style={{
                                                        width: `${carePlan.goals_count > 0 ? (carePlan.goals_completed / carePlan.goals_count) * 100 : 0}%`,
                                                    }}
                                                />
                                            </div>
                                            {carePlan.goals_completed ===
                                                carePlan.goals_count &&
                                                carePlan.goals_count > 0 && (
                                                    <p className="mt-1.5 text-xs font-medium text-emerald-600">
                                                        All goals achieved! 🎉
                                                    </p>
                                                )}
                                        </div>
                                    )}
                                    {carePlan.important_to_me && (
                                        <div className="rounded-lg bg-amber-50/70 p-3 dark:bg-amber-950/10">
                                            <p className="mb-1 text-xs font-medium text-amber-700 dark:text-amber-400">
                                                ⭐ What's Important to Me
                                            </p>
                                            <p className="text-sm leading-relaxed">
                                                {carePlan.important_to_me}
                                            </p>
                                        </div>
                                    )}
                                    {carePlan.ideal_day && (
                                        <div>
                                            <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                🌅 My Ideal Day
                                            </p>
                                            <p className="text-sm leading-relaxed">
                                                {carePlan.ideal_day}
                                            </p>
                                        </div>
                                    )}
                                    {carePlan.how_to_support && (
                                        <div>
                                            <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                🤝 How to Support Me
                                            </p>
                                            <p className="text-sm leading-relaxed">
                                                {carePlan.how_to_support}
                                            </p>
                                        </div>
                                    )}
                                    {(carePlan.likes || carePlan.dislikes) && (
                                        <div className="grid grid-cols-2 gap-3">
                                            {carePlan.likes && (
                                                <div className="rounded-lg bg-emerald-50 p-3 dark:bg-emerald-950/20">
                                                    <p className="mb-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                                        💚 Things I Love
                                                    </p>
                                                    <p className="text-xs leading-relaxed text-emerald-800 dark:text-emerald-300">
                                                        {carePlan.likes}
                                                    </p>
                                                </div>
                                            )}
                                            {carePlan.dislikes && (
                                                <div className="rounded-lg bg-rose-50 p-3 dark:bg-rose-950/20">
                                                    <p className="mb-1 text-xs font-medium text-rose-700 dark:text-rose-400">
                                                        Not a Fan Of
                                                    </p>
                                                    <p className="text-xs leading-relaxed text-rose-800 dark:text-rose-300">
                                                        {carePlan.dislikes}
                                                    </p>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Week/Month Calendar Toggle */}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <span>🗓️</span>
                                    Coming Up
                                </CardTitle>
                                <div className="flex gap-1 rounded-lg border p-0.5">
                                    <button
                                        onClick={() => setCalendarView('week')}
                                        className={`rounded-md px-3 py-1 text-xs font-medium transition-colors ${
                                            calendarView === 'week'
                                                ? 'bg-primary text-primary-foreground'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        Week
                                    </button>
                                    <button
                                        onClick={() => setCalendarView('month')}
                                        className={`rounded-md px-3 py-1 text-xs font-medium transition-colors ${
                                            calendarView === 'month'
                                                ? 'bg-primary text-primary-foreground'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        Month
                                    </button>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {calendarView === 'week' ? (
                                    <div className="space-y-3">
                                        {weekShifts.length > 0 ? (
                                            weekShifts.map((shift) => (
                                                <ShiftRow
                                                    key={shift.id}
                                                    shift={shift}
                                                    showDate
                                                />
                                            ))
                                        ) : (
                                            <div className="flex flex-col items-center justify-center py-6 text-center">
                                                <span className="mb-2 text-3xl">
                                                    🌈
                                                </span>
                                                <p className="text-sm text-muted-foreground">
                                                    A clear week ahead &mdash;
                                                    enjoy the downtime!
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    <div className="grid grid-cols-7 gap-1">
                                        {[
                                            'Mon',
                                            'Tue',
                                            'Wed',
                                            'Thu',
                                            'Fri',
                                            'Sat',
                                            'Sun',
                                        ].map((d) => (
                                            <div
                                                key={d}
                                                className="pb-2 text-center text-xs font-medium text-muted-foreground"
                                            >
                                                {d}
                                            </div>
                                        ))}
                                        {/* Pad start */}
                                        {(() => {
                                            const firstCalendarDay =
                                                calendarDays[0];
                                            if (!firstCalendarDay) {
                                                return null;
                                            }
                                            const firstDay = new Date(
                                                firstCalendarDay.date +
                                                    'T00:00:00',
                                            ).getDay();
                                            const offset =
                                                firstDay === 0
                                                    ? 6
                                                    : firstDay - 1;
                                            return Array.from({
                                                length: offset,
                                            }).map((_, i) => (
                                                <div
                                                    key={`pad-${i}`}
                                                    className="h-16"
                                                />
                                            ));
                                        })()}
                                        {calendarDays.map((day) => (
                                            <div
                                                key={day.date}
                                                className={`relative flex h-16 flex-col items-center rounded-lg border p-1 text-xs transition-colors ${
                                                    day.isToday
                                                        ? 'border-primary/50 bg-primary/5'
                                                        : day.shifts.length > 0
                                                          ? 'border-border bg-card'
                                                          : 'border-transparent bg-muted/30'
                                                }`}
                                            >
                                                <span
                                                    className={`font-medium ${
                                                        day.isToday
                                                            ? 'text-primary'
                                                            : 'text-foreground'
                                                    }`}
                                                >
                                                    {new Date(
                                                        day.date + 'T00:00:00',
                                                    ).getDate()}
                                                </span>
                                                {day.shifts.length > 0 && (
                                                    <div className="mt-auto flex gap-0.5">
                                                        {day.shifts
                                                            .slice(0, 3)
                                                            .map((s) => (
                                                                <div
                                                                    key={s.id}
                                                                    className={`h-1.5 w-1.5 rounded-full ${
                                                                        s.status ===
                                                                        'completed'
                                                                            ? 'bg-emerald-500'
                                                                            : s.status ===
                                                                                'in_progress'
                                                                              ? 'bg-amber-500'
                                                                              : 'bg-blue-500'
                                                                    }`}
                                                                    title={`${s.staff_name ?? 'Staff'} - ${s.status}`}
                                                                />
                                                            ))}
                                                        {day.shifts.length >
                                                            3 && (
                                                            <span className="text-[9px] text-muted-foreground">
                                                                +
                                                                {day.shifts
                                                                    .length - 3}
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <div className="mt-4 flex justify-center">
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link
                                            href={`/portal/clients/${client.id}/schedule`}
                                        >
                                            View full schedule →
                                        </Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Consent Requests */}
                        {pendingConsentRequests.length > 0 && (
                            <Card className="border-amber-300 bg-amber-50">
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <span>🔏</span>
                                        Consent reviews waiting for you
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {pendingConsentRequests.map((cr) => (
                                            <div
                                                key={cr.id}
                                                className="flex items-start justify-between gap-3 rounded-lg border bg-white p-3"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-sm font-medium">
                                                        {cr.consent_type?.name ?? 'Consent request'}
                                                    </div>
                                                    <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                                                        {cr.purpose}
                                                    </p>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        From {cr.requested_by?.name ?? 'care team'}
                                                        {cr.expires_at && (
                                                            <>
                                                                {' '}
                                                                · respond by{' '}
                                                                {new Date(cr.expires_at).toLocaleDateString(
                                                                    'en-NZ',
                                                                    { day: 'numeric', month: 'short' },
                                                                )}
                                                            </>
                                                        )}
                                                    </div>
                                                </div>
                                                <Button asChild size="sm">
                                                    <a href={cr.action_url}>Review</a>
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Visit Requests */}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <span>✈️</span>
                                    Your Visits
                                </CardTitle>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="gap-1.5"
                                    onClick={() => setBookingOpen(true)}
                                >
                                    <CalendarPlus className="h-3.5 w-3.5" />
                                    New Request
                                </Button>
                            </CardHeader>
                            <CardContent>
                                {visitRequests.length > 0 ? (
                                    <div className="space-y-3">
                                        {visitRequests.map((visit) => {
                                            const vt =
                                                visitTypeLabels[
                                                    visit.visit_type
                                                ] ?? visitTypeLabels.in_person!;
                                            const VtIcon = vt.icon;
                                            return (
                                                <div
                                                    key={visit.id}
                                                    className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                                >
                                                    <div className="flex items-center gap-3">
                                                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                                                            <VtIcon className="h-5 w-5 text-primary" />
                                                        </div>
                                                        <div>
                                                            <div className="text-sm font-medium">
                                                                {formatDate(
                                                                    visit.requested_date,
                                                                )}
                                                                {visit.preferred_time_start && (
                                                                    <span className="ml-2 font-normal text-muted-foreground">
                                                                        {
                                                                            visit.preferred_time_start
                                                                        }
                                                                        {visit.preferred_time_end &&
                                                                            ` - ${visit.preferred_time_end}`}
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {vt.label}
                                                                {visit.notes &&
                                                                    ` \u2022 ${visit.notes}`}
                                                            </div>
                                                            {visit.review_notes && (
                                                                <div className="mt-1 text-xs text-muted-foreground italic">
                                                                    Staff note:{' '}
                                                                    {
                                                                        visit.review_notes
                                                                    }
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Badge
                                                            className={`${statusColors[visit.status] ?? ''} border-0 capitalize`}
                                                        >
                                                            {visit.status}
                                                        </Badge>
                                                        {visit.status ===
                                                            'pending' && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="h-7 w-7 p-0 text-muted-foreground hover:text-red-500"
                                                                onClick={() =>
                                                                    cancelVisit(
                                                                        visit.id,
                                                                    )
                                                                }
                                                            >
                                                                <X className="h-4 w-4" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center justify-center py-8 text-center">
                                        <span className="mb-2 text-3xl">
                                            💛
                                        </span>
                                        <p className="text-sm text-muted-foreground">
                                            No visits planned yet &mdash; ready
                                            when you are!
                                        </p>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="mt-3 gap-1.5"
                                            onClick={() => setBookingOpen(true)}
                                        >
                                            <CalendarPlus className="h-3.5 w-3.5" />
                                            Plan a Visit
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Recent Activity */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <span>📰</span>
                                    What's Been Happening
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {recentEvents.length > 0 ? (
                                    <div className="relative space-y-0">
                                        {recentEvents.map((event, idx) => (
                                            <div
                                                key={event.id}
                                                className="relative flex gap-4 pb-4 last:pb-0"
                                            >
                                                {/* Timeline line */}
                                                {idx <
                                                    recentEvents.length - 1 && (
                                                    <div className="absolute top-6 bottom-0 left-[11px] w-px bg-border" />
                                                )}
                                                {/* Dot */}
                                                <div className="relative z-10 mt-1.5 h-[9px] w-[9px] shrink-0 rounded-full border-2 border-primary bg-background" />
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <p className="text-sm leading-tight font-medium">
                                                            {event.subject ||
                                                                event.type}
                                                        </p>
                                                        <span className="shrink-0 text-xs text-muted-foreground">
                                                            {formatFullDate(
                                                                event.occurred_at,
                                                            )}
                                                        </span>
                                                    </div>
                                                    {event.body && (
                                                        <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                                                            {event.body}
                                                        </p>
                                                    )}
                                                    <ShiftTimelineSummary
                                                        eventType={event.type}
                                                        meta={event.meta as any}
                                                        className="mt-1.5"
                                                    />
                                                    {event.actor_name && (
                                                        <p className="mt-0.5 text-xs text-muted-foreground/70">
                                                            By{' '}
                                                            {event.actor_name}
                                                        </p>
                                                    )}
                                                    {event.reactions &&
                                                        event.reactions.length >
                                                            0 && (
                                                            <div className="mt-1.5 flex flex-wrap gap-1">
                                                                {event.reactions.map(
                                                                    (r) => (
                                                                        <span
                                                                            key={
                                                                                r.emoji
                                                                            }
                                                                            className="inline-flex items-center gap-0.5 rounded-full border bg-muted/50 px-1.5 py-0.5 text-[10px]"
                                                                        >
                                                                            {
                                                                                r.emoji
                                                                            }{' '}
                                                                            {
                                                                                r.count
                                                                            }
                                                                        </span>
                                                                    ),
                                                                )}
                                                            </div>
                                                        )}
                                                </div>
                                            </div>
                                        ))}
                                        <div className="mt-4 flex justify-center">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/portal/clients/${client.id}/timeline`}
                                                >
                                                    View all activity →
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center justify-center py-6 text-center">
                                        <span className="mb-2 text-3xl">
                                            📬
                                        </span>
                                        <p className="text-sm text-muted-foreground">
                                            All quiet for now &mdash; we'll keep
                                            you posted!
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right column: 1/3 width */}
                    <div className="space-y-6">
                        {/* Quick Info */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <span>💜</span>
                                    About {name}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                {client.phone && (
                                    <div className="flex items-center gap-2 text-muted-foreground">
                                        <Phone className="h-3.5 w-3.5" />
                                        <span>{client.phone}</span>
                                    </div>
                                )}
                                {(client.address_line_1 || client.city) && (
                                    <div className="flex items-center gap-2 text-muted-foreground">
                                        <MapPin className="h-3.5 w-3.5" />
                                        <span>
                                            {[
                                                client.address_line_1,
                                                client.city,
                                            ]
                                                .filter(Boolean)
                                                .join(', ')}
                                        </span>
                                    </div>
                                )}
                                {site && (
                                    <div className="flex items-center gap-2 text-muted-foreground">
                                        <Home className="h-3.5 w-3.5" />
                                        <span>{site.name}</span>
                                    </div>
                                )}
                                {client.interests_hobbies && (
                                    <>
                                        <Separator />
                                        <div>
                                            <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                Interests & Hobbies
                                            </p>
                                            <p className="text-sm">
                                                {client.interests_hobbies}
                                            </p>
                                        </div>
                                    </>
                                )}
                                {client.dietary_requirements && (
                                    <div>
                                        <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                            Dietary Requirements
                                        </p>
                                        <p className="text-sm">
                                            {client.dietary_requirements}
                                        </p>
                                    </div>
                                )}
                                {client.mobility_needs && (
                                    <div>
                                        <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                            Mobility Needs
                                        </p>
                                        <p className="text-sm">
                                            {client.mobility_needs}
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* On Shift Now */}
                        {(currentShiftWorker || nextShiftWorker) && (
                            <Card className="border-emerald-200 bg-emerald-50/30 dark:bg-emerald-950/10">
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <span>🟢</span>
                                        On Shift
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {currentShiftWorker ? (
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-3">
                                                <div className="relative">
                                                    <Avatar className="h-10 w-10">
                                                        <AvatarImage
                                                            src={
                                                                currentShiftWorker.avatar ??
                                                                undefined
                                                            }
                                                        />
                                                        <AvatarFallback className="text-xs">
                                                            {getInitials(
                                                                currentShiftWorker.name,
                                                            )}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                    <span className="absolute -right-0.5 -bottom-0.5">
                                                        <PresenceDot
                                                            status={
                                                                currentShiftWorker.presence ??
                                                                'offline'
                                                            }
                                                        />
                                                    </span>
                                                </div>
                                                <div>
                                                    <p className="text-sm font-semibold">
                                                        {
                                                            currentShiftWorker.name
                                                        }
                                                    </p>
                                                    <p className="text-[10px] text-emerald-600">
                                                        Currently on shift
                                                    </p>
                                                    {(currentShiftWorker.shift_type ||
                                                        currentShiftWorker.service_context ||
                                                        currentShiftWorker.location) && (
                                                        <p className="text-[10px] text-muted-foreground">
                                                            {formatShiftTypeLabel(
                                                                currentShiftWorker.shift_type,
                                                            )}
                                                            {currentShiftWorker.service_context
                                                                ? ` · ${currentShiftWorker.service_context}`
                                                                : ''}
                                                            {currentShiftWorker.location
                                                                ? ` · ${currentShiftWorker.location}`
                                                                : ''}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="h-7 gap-1 text-xs"
                                                asChild
                                            >
                                                <Link
                                                    href={`/portal/clients/${client.id}/messages`}
                                                >
                                                    <MessageSquare className="h-3 w-3" />
                                                    Chat
                                                </Link>
                                            </Button>
                                        </div>
                                    ) : (
                                        <p className="text-xs text-muted-foreground">
                                            No one currently on shift
                                        </p>
                                    )}
                                    {nextShiftWorker && (
                                        <div className="flex items-center justify-between border-t pt-2">
                                            <div className="flex items-center gap-3">
                                                <div className="relative">
                                                    <Avatar className="h-9 w-9">
                                                        <AvatarImage
                                                            src={
                                                                nextShiftWorker.avatar ??
                                                                undefined
                                                            }
                                                        />
                                                        <AvatarFallback className="text-xs">
                                                            {getInitials(
                                                                nextShiftWorker.name,
                                                            )}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                    <span className="absolute -right-0.5 -bottom-0.5">
                                                        <PresenceDot
                                                            status={
                                                                nextShiftWorker.presence ??
                                                                'offline'
                                                            }
                                                        />
                                                    </span>
                                                </div>
                                                <div>
                                                    <p className="text-sm font-medium">
                                                        {nextShiftWorker.name}
                                                    </p>
                                                    <p className="text-[10px] text-muted-foreground">
                                                        Next:{' '}
                                                        {nextShiftWorker.shift_starts_at
                                                            ? new Date(
                                                                  nextShiftWorker.shift_starts_at,
                                                              ).toLocaleTimeString(
                                                                  'en-NZ',
                                                                  {
                                                                      hour: '2-digit',
                                                                      minute: '2-digit',
                                                                  },
                                                              )
                                                            : '—'}
                                                    </p>
                                                    {(nextShiftWorker.shift_type ||
                                                        nextShiftWorker.service_context ||
                                                        nextShiftWorker.location) && (
                                                        <p className="text-[10px] text-muted-foreground">
                                                            {formatShiftTypeLabel(
                                                                nextShiftWorker.shift_type,
                                                            )}
                                                            {nextShiftWorker.service_context
                                                                ? ` · ${nextShiftWorker.service_context}`
                                                                : ''}
                                                            {nextShiftWorker.location
                                                                ? ` · ${nextShiftWorker.location}`
                                                                : ''}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="h-7 gap-1 text-xs"
                                                asChild
                                            >
                                                <Link
                                                    href={`/portal/clients/${client.id}/messages`}
                                                >
                                                    <MessageSquare className="h-3 w-3" />
                                                </Link>
                                            </Button>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Key Worker */}
                        {keyWorker && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <span>⭐</span>
                                        Key Worker
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-3">
                                        <div className="relative">
                                            <Avatar className="h-11 w-11 ring-2 ring-amber-100 ring-offset-1 dark:ring-amber-800">
                                                <AvatarImage
                                                    src={
                                                        keyWorker.avatar ??
                                                        undefined
                                                    }
                                                    alt={keyWorker.name}
                                                />
                                                <AvatarFallback className="bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300">
                                                    {getInitials(
                                                        keyWorker.name,
                                                    )}
                                                </AvatarFallback>
                                            </Avatar>
                                            <span className="absolute -right-0.5 -bottom-0.5">
                                                <PresenceDot
                                                    status={
                                                        keyWorker.presence ??
                                                        'offline'
                                                    }
                                                />
                                            </span>
                                        </div>
                                        <div>
                                            <p className="font-medium">
                                                {keyWorker.name}
                                            </p>
                                            <PresenceBadge
                                                status={
                                                    keyWorker.presence ??
                                                    'offline'
                                                }
                                            />
                                        </div>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="mt-3 w-full gap-1.5"
                                        asChild
                                    >
                                        <Link
                                            href={`/portal/clients/${client.id}/messages`}
                                        >
                                            <MessageSquare className="h-3.5 w-3.5" />
                                            Send a Message
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        )}

                        {/* Support Team */}
                        {supportWorkers.length > 0 && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <span>👥</span>
                                        {name}'s Team
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {supportWorkers.map((worker) => (
                                            <div
                                                key={worker.id}
                                                className="flex items-center justify-between"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <div className="relative">
                                                        <Avatar className="h-9 w-9">
                                                            <AvatarImage
                                                                src={
                                                                    worker.avatar ??
                                                                    undefined
                                                                }
                                                                alt={
                                                                    worker.name
                                                                }
                                                            />
                                                            <AvatarFallback className="text-xs">
                                                                {getInitials(
                                                                    worker.name,
                                                                )}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <span className="absolute -right-0.5 -bottom-0.5">
                                                            <PresenceDot
                                                                status={
                                                                    worker.presence ??
                                                                    'offline'
                                                                }
                                                                size="sm"
                                                            />
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-medium">
                                                            {worker.name}
                                                        </p>
                                                        <PresenceBadge
                                                            status={
                                                                worker.presence ??
                                                                'offline'
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 gap-1 text-xs"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/portal/clients/${client.id}/messages`}
                                                    >
                                                        <MessageSquare className="h-3 w-3" />
                                                    </Link>
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Medical Summary */}
                        {medicalSummary &&
                            (medicalSummary.allergies ||
                                medicalSummary.disabilities) && (
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <span>🏥</span>
                                            Health at a Glance
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3 text-sm">
                                        {medicalSummary.allergies && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium tracking-wider text-rose-600 uppercase">
                                                    Allergies
                                                </p>
                                                <p className="rounded-md bg-rose-50 px-3 py-2 text-rose-800">
                                                    {medicalSummary.allergies}
                                                </p>
                                            </div>
                                        )}
                                        {medicalSummary.disabilities && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                    Disabilities
                                                </p>
                                                <p>
                                                    {
                                                        medicalSummary.disabilities
                                                    }
                                                </p>
                                            </div>
                                        )}
                                        {medicalSummary.notes && (
                                            <div>
                                                <p className="mb-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                    Notes
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {medicalSummary.notes}
                                                </p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            )}

                        {/* Critical Alerts */}
                        {criticalAlerts.length > 0 && (
                            <Card className="border-red-200 bg-red-50/30">
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base text-red-700">
                                        <ShieldAlert className="h-4 w-4" />
                                        Critical Alerts
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {criticalAlerts.map((alert) => (
                                            <div
                                                key={alert.id}
                                                className="rounded-lg border border-red-200 bg-white p-3"
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <span className="text-sm font-medium">
                                                        {alert.type}
                                                    </span>
                                                    <Badge
                                                        className={`${severityColors[alert.severity] ?? ''} border-0 text-[10px]`}
                                                    >
                                                        {alert.severity}
                                                    </Badge>
                                                </div>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {formatFullDate(
                                                        alert.occurred_at,
                                                    )}
                                                </p>
                                                {alert.description && (
                                                    <p className="mt-1.5 line-clamp-2 text-xs text-muted-foreground">
                                                        {alert.description}
                                                    </p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Recent Incidents */}
                        {recentIncidents.length > 0 && (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <AlertTriangle className="h-4 w-4 text-amber-500" />
                                        Recent Incidents
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-3">
                                        {recentIncidents.map((inc) => (
                                            <div
                                                key={inc.id}
                                                className="rounded-lg border p-3"
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <span className="text-sm font-medium">
                                                        {inc.type}
                                                    </span>
                                                    <Badge
                                                        className={`${severityColors[inc.severity] ?? ''} border-0 text-[10px]`}
                                                    >
                                                        {inc.severity}
                                                    </Badge>
                                                </div>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {formatFullDate(
                                                        inc.occurred_at,
                                                    )}
                                                </p>
                                                {inc.description && (
                                                    <p className="mt-1.5 line-clamp-2 text-xs text-muted-foreground">
                                                        {inc.description}
                                                    </p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

/* ── Subcomponents ─────────────────────────────────────────────────── */

function GlanceCard({
    emoji,
    message,
    bgClass,
}: {
    emoji: string;
    message: string;
    bgClass: string;
}) {
    return (
        <div
            className={`flex items-center gap-3 rounded-2xl border p-4 shadow-sm ${bgClass}`}
        >
            <span className="text-2xl">{emoji}</span>
            <p className="text-sm font-medium">{message}</p>
        </div>
    );
}

function ShiftRow({
    shift,
    showDate,
}: {
    shift: ShiftItem;
    showDate?: boolean;
}) {
    const getInitials = useInitials();
    return (
        <div className="flex items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/50">
            {shift.staff && (
                <Avatar className="h-9 w-9">
                    <AvatarImage
                        src={shift.staff.avatar ?? undefined}
                        alt={shift.staff.name}
                    />
                    <AvatarFallback className="text-xs">
                        {getInitials(shift.staff.name)}
                    </AvatarFallback>
                </Avatar>
            )}
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium">
                        {shift.staff?.name ?? 'Staff TBC'}
                    </span>
                    <Badge
                        className={`${statusColors[shift.status] ?? ''} border-0 text-[10px]`}
                    >
                        {shift.status.replace('_', ' ')}
                    </Badge>
                </div>
                <div className="text-xs text-muted-foreground">
                    {showDate && (
                        <span className="mr-1.5">
                            {new Date(shift.starts_at).toLocaleDateString([], {
                                weekday: 'short',
                                month: 'short',
                                day: 'numeric',
                            })}
                            {' \u2022 '}
                        </span>
                    )}
                    {formatTime(shift.starts_at)} - {formatTime(shift.ends_at)}
                </div>
                {(shift.shift_type ||
                    shift.service_context ||
                    shift.location ||
                    shift.is_sleepover ||
                    shift.is_on_call) && (
                    <div className="mt-1 text-[11px] text-muted-foreground">
                        {formatShiftTypeLabel(shift.shift_type || shift.type)}
                        {shift.service_context
                            ? ` · ${shift.service_context}`
                            : ''}
                        {shift.location ? ` · ${shift.location}` : ''}
                        {shift.is_sleepover ? ' · Sleepover' : ''}
                        {shift.is_on_call ? ' · On-call' : ''}
                    </div>
                )}
            </div>
        </div>
    );
}
