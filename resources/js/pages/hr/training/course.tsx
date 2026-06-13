import { PageHero, type PageHeroBadge, type PageHeroMetaItem } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Book,
    BookOpen,
    Calendar,
    CheckCircle2,
    Clock,
    Download,
    Layers,
    MapPin,
    Monitor,
    UserPlus,
    Users,
    Zap,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Session {
    id: number;
    session_date: string;
    start_time: string | null;
    end_time: string | null;
    location: string | null;
    facilitator: string | null;
    max_participants: number | null;
    status: string;
}
interface Enrollment {
    id: number;
    status: string;
    enrolled_at: string;
    completed_at: string | null;
    score: string | null;
    notes: string | null;
    user: { id: number; name: string };
}
interface Course {
    id: number;
    title: string;
    code: string;
    description: string | null;
    category: string | null;
    delivery_method: string;
    duration_hours: string;
    provider: string | null;
    cost: string | null;
    is_mandatory: boolean;
    is_active: boolean;
    max_participants: number | null;
    sessions: Session[];
    enrollments: Enrollment[];
}
interface UserItem {
    id: number;
    name: string;
}
interface Props {
    course: Course;
    users: UserItem[];
    can: { manage: boolean; enroll: boolean };
}

const DELIVERY_LABELS: Record<string, string> = {
    online: 'Online',
    in_person: 'In Person',
    blended: 'Blended',
    self_paced: 'Self-Paced',
};
const DELIVERY_COLORS: Record<string, string> = {
    online: 'bg-status-info-bg text-status-info',
    in_person: 'bg-status-success-bg text-status-success',
    blended: 'bg-primary/10 text-primary',
    self_paced: 'bg-status-warning-bg text-status-warning',
};
const DELIVERY_ICONS: Record<string, typeof Monitor> = {
    online: Monitor,
    in_person: MapPin,
    blended: Layers,
    self_paced: Zap,
};

const STATUS_COLORS: Record<string, string> = {
    enrolled: 'bg-status-info-bg text-status-info',
    in_progress: 'bg-status-warning-bg text-status-warning',
    completed: 'bg-status-success-bg text-status-success',
    withdrawn: 'bg-muted text-muted-foreground',
    failed: 'bg-status-critical-bg text-status-critical',
    scheduled: 'bg-status-info-bg text-status-info',
    cancelled: 'bg-status-critical-bg text-status-critical',
};

function formatDate(v?: string | null) {
    if (!v) return '\u2014';
    const d = new Date(v);
    return isNaN(d.getTime())
        ? v
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
}
function formatCurrency(v: string | null) {
    if (!v) return '\u2014';
    const n = parseFloat(v);
    return isNaN(n)
        ? v
        : new Intl.NumberFormat('en-NZ', {
              style: 'currency',
              currency: 'NZD',
          }).format(n);
}

const AVATAR_COLORS = [
    'bg-status-info',
    'bg-primary',
    'bg-status-success',
    'bg-status-warning',
    'bg-status-critical',
    'bg-status-info',
    'bg-status-critical',
    'bg-primary',
];
function avatarColor(id: number) {
    return AVATAR_COLORS[id % AVATAR_COLORS.length];
}
function getInitials(name: string) {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

export default function CourseDetail({ course, users, can }: Props) {
    const [enrollOpen, setEnrollOpen] = useState(false);
    const [completeOpen, setCompleteOpen] = useState(false);
    const [selectedEnrollment, setSelectedEnrollment] =
        useState<Enrollment | null>(null);
    const [enrollForm, setEnrollForm] = useState({
        user_id: '',
        session_id: '',
        notes: '',
    });
    const [completeForm, setCompleteForm] = useState({ score: '', notes: '' });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Training', href: '/hr/training/catalog' },
        { title: course.title, href: `/hr/training/courses/${course.id}` },
    ];

    const DmIcon = DELIVERY_ICONS[course.delivery_method] || BookOpen;
    const completedCount =
        course.enrollments?.filter((e) => e.status === 'completed').length ?? 0;

    const submitEnroll = (e: FormEvent) => {
        e.preventDefault();
        router.post(
            '/hr/training/enroll',
            {
                ...enrollForm,
                course_id: course.id,
                session_id: enrollForm.session_id || null,
            },
            {
                onSuccess: () => {
                    setEnrollOpen(false);
                    setEnrollForm({ user_id: '', session_id: '', notes: '' });
                },
            },
        );
    };
    const openComplete = (enrollment: Enrollment) => {
        setSelectedEnrollment(enrollment);
        setCompleteForm({ score: '', notes: '' });
        setCompleteOpen(true);
    };
    const submitComplete = (e: FormEvent) => {
        e.preventDefault();
        if (!selectedEnrollment) return;
        router.post(
            `/hr/training/enrollments/${selectedEnrollment.id}/complete`,
            { ...completeForm, score: completeForm.score || null },
            { onSuccess: () => setCompleteOpen(false) },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={course.title} />
            <div className="space-y-6 p-4 lg:p-6">
                {/* Hero Banner */}
                {(() => {
                    const heroBadges: PageHeroBadge[] = [{ label: course.code }];
                    if (course.is_mandatory)
                        heroBadges.push({ label: 'Mandatory', tone: 'critical' });

                    const heroMeta: PageHeroMetaItem[] = [
                        {
                            icon: DELIVERY_ICONS[course.delivery_method] || BookOpen,
                            label: DELIVERY_LABELS[course.delivery_method],
                        },
                        { icon: Clock, label: `${course.duration_hours}h` },
                    ];
                    if (course.provider) heroMeta.push({ label: course.provider });
                    if (course.cost) heroMeta.push({ label: formatCurrency(course.cost) });

                    return (
                        <PageHero category="hr"
                            icon={Book}
                            backHref="/hr/training/catalog"
                            backLabel="Back to Catalog"
                            title={course.title}
                            description={course.description ?? undefined}
                            meta={heroMeta}
                            badges={heroBadges}
                            stats={[
                                { label: 'Enrolled', value: course.enrollments?.length ?? 0 },
                                { label: 'Completed', value: completedCount },
                            ]}
                            actions={
                                can.enroll ? (
                                    <Button
                                        size="sm"
                                        className="gap-1.5 bg-white text-primary shadow-md hover:bg-primary-foreground/90"
                                        onClick={() => setEnrollOpen(true)}
                                    >
                                        <UserPlus className="h-4 w-4" />
                                        Enrol Employee
                                    </Button>
                                ) : undefined
                            }
                        />
                    );
                })()}

                {/* Course Info Cards */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border bg-primary/10 p-3 text-center">
                        <div className="text-lg font-bold text-primary">
                            {course.category || '\u2014'}
                        </div>
                        <div className="text-[10px] tracking-wider text-primary uppercase">
                            Category
                        </div>
                    </div>
                    <div className="rounded-xl border p-3 text-center">
                        <div className="text-lg font-bold">
                            {course.max_participants ?? 'Unlimited'}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Max Participants
                        </div>
                    </div>
                    <div className="rounded-xl border p-3 text-center">
                        <div className="text-lg font-bold">
                            {course.sessions?.length ?? 0}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Sessions
                        </div>
                    </div>
                    <div className="rounded-xl border p-3 text-center">
                        <Badge
                            variant={course.is_active ? 'default' : 'secondary'}
                            className={
                                course.is_active
                                    ? 'border-0 bg-status-success-bg text-status-success'
                                    : ''
                            }
                        >
                            {course.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                        <div className="mt-1 text-[10px] tracking-wider text-muted-foreground uppercase">
                            Status
                        </div>
                    </div>
                </div>

                {/* Sessions */}
                {course.sessions?.length > 0 && (
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-gradient-to-r from-status-info-bg to-transparent pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-status-info-bg">
                                    <Calendar className="h-4 w-4 text-status-info" />
                                </div>
                                Sessions
                                <Badge
                                    variant="secondary"
                                    className="text-[10px]"
                                >
                                    {course.sessions.length}
                                </Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y">
                                {course.sessions.map((s) => (
                                    <div
                                        key={s.id}
                                        className="flex items-center justify-between px-4 py-3 transition-colors hover:bg-status-info-bg"
                                    >
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 flex-col items-center justify-center rounded-lg bg-status-info-bg text-status-info">
                                                <span className="text-[10px] leading-none font-medium">
                                                    {new Date(
                                                        s.session_date,
                                                    ).toLocaleDateString(
                                                        'en-NZ',
                                                        { month: 'short' },
                                                    )}
                                                </span>
                                                <span className="text-sm leading-none font-bold">
                                                    {new Date(
                                                        s.session_date,
                                                    ).getDate()}
                                                </span>
                                            </div>
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {s.start_time
                                                        ? `${s.start_time} \u2013 ${s.end_time || ''}`
                                                        : formatDate(
                                                              s.session_date,
                                                          )}
                                                </p>
                                                <p className="text-[11px] text-muted-foreground">
                                                    {[s.location, s.facilitator]
                                                        .filter(Boolean)
                                                        .join(' \u00b7 ') ||
                                                        '\u2014'}
                                                </p>
                                            </div>
                                        </div>
                                        <Badge
                                            className={`border-0 text-[10px] capitalize ${STATUS_COLORS[s.status] || 'bg-muted text-muted-foreground'}`}
                                        >
                                            {s.status}
                                        </Badge>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Enrollments */}
                <Card className="overflow-hidden">
                    <CardHeader className="border-b bg-gradient-to-r from-primary/10 to-transparent pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
                                    <Users className="h-4 w-4 text-primary" />
                                </div>
                                Enrolments
                                <Badge
                                    variant="secondary"
                                    className="text-[10px]"
                                >
                                    {course.enrollments?.length ?? 0}
                                </Badge>
                            </CardTitle>
                            {can.enroll && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="gap-1 text-xs"
                                    onClick={() => setEnrollOpen(true)}
                                >
                                    <UserPlus className="h-3 w-3" />
                                    Enrol
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        {!course.enrollments?.length ? (
                            <div className="flex flex-col items-center gap-2 py-12">
                                <Users className="h-8 w-8 text-foreground" />
                                <p className="text-sm text-muted-foreground">
                                    No enrolments yet
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y">
                                {course.enrollments.map((e) => (
                                    <div
                                        key={e.id}
                                        className="hover:bg-primary/10/30 flex items-center justify-between px-4 py-3 transition-colors"
                                    >
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={`flex h-9 w-9 items-center justify-center rounded-full text-[10px] font-bold text-primary-foreground ${avatarColor(e.user.id)}`}
                                            >
                                                {getInitials(e.user.name)}
                                            </div>
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {e.user.name}
                                                </p>
                                                <p className="text-[11px] text-muted-foreground">
                                                    Enrolled{' '}
                                                    {formatDate(e.enrolled_at)}
                                                    {e.completed_at &&
                                                        ` \u00b7 Completed ${formatDate(e.completed_at)}`}
                                                    {e.score &&
                                                        ` \u00b7 Score: ${e.score}%`}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                className={`border-0 text-[10px] capitalize ${STATUS_COLORS[e.status] || 'bg-muted text-muted-foreground'}`}
                                            >
                                                {e.status}
                                            </Badge>
                                            {can.manage &&
                                                e.status !== 'completed' &&
                                                e.status !== 'withdrawn' && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="h-7 gap-1 text-xs"
                                                        onClick={() =>
                                                            openComplete(e)
                                                        }
                                                    >
                                                        <CheckCircle2 className="h-3 w-3" />
                                                        Complete
                                                    </Button>
                                                )}
                                            {e.status === 'completed' && (
                                                <a
                                                    href={`/hr/training/enrollments/${e.id}/certificate`}
                                                >
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-7 gap-1 text-xs text-primary"
                                                    >
                                                        <Download className="h-3 w-3" />
                                                        Certificate
                                                    </Button>
                                                </a>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Enrol Dialog */}
            <Dialog open={enrollOpen} onOpenChange={setEnrollOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Enrol Employee</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitEnroll} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Employee *</Label>
                            <Select
                                value={enrollForm.user_id}
                                onValueChange={(v) =>
                                    setEnrollForm((p) => ({ ...p, user_id: v }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select employee" />
                                </SelectTrigger>
                                <SelectContent>
                                    {users.map((u) => (
                                        <SelectItem
                                            key={u.id}
                                            value={String(u.id)}
                                        >
                                            {u.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        {course.sessions?.length > 0 && (
                            <div className="space-y-1.5">
                                <Label>Session (optional)</Label>
                                <Select
                                    value={enrollForm.session_id || 'none'}
                                    onValueChange={(v) =>
                                        setEnrollForm((p) => ({
                                            ...p,
                                            session_id: v === 'none' ? '' : v,
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="No specific session" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            No specific session
                                        </SelectItem>
                                        {course.sessions.map((s) => (
                                            <SelectItem
                                                key={s.id}
                                                value={String(s.id)}
                                            >
                                                {formatDate(s.session_date)}{' '}
                                                {s.start_time || ''}{' '}
                                                {s.location
                                                    ? `\u2013 ${s.location}`
                                                    : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label>Notes</Label>
                            <Textarea
                                value={enrollForm.notes}
                                onChange={(e) =>
                                    setEnrollForm((p) => ({
                                        ...p,
                                        notes: e.target.value,
                                    }))
                                }
                                placeholder="Optional notes"
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEnrollOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="bg-primary hover:bg-primary"
                            >
                                Enrol
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Complete Dialog */}
            <Dialog open={completeOpen} onOpenChange={setCompleteOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            Complete Enrolment: {selectedEnrollment?.user?.name}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitComplete} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Score (%)</Label>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                value={completeForm.score}
                                onChange={(e) =>
                                    setCompleteForm((p) => ({
                                        ...p,
                                        score: e.target.value,
                                    }))
                                }
                                placeholder="Optional"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Notes</Label>
                            <Textarea
                                value={completeForm.notes}
                                onChange={(e) =>
                                    setCompleteForm((p) => ({
                                        ...p,
                                        notes: e.target.value,
                                    }))
                                }
                                placeholder="Optional notes"
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCompleteOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="bg-status-success hover:bg-status-success"
                            >
                                Mark Completed
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
