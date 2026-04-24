import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { GraduationCap, AlertTriangle, CheckCircle2, Clock } from 'lucide-react';

type Props = {
    filters: {
        q: string;
        status: string | null;
        user_id: string | number | null;
        course_id: string | number | null;
        expiring_soon: string | null;
    };
    trainingRecords: any;
    staff?: Array<{ id: number; first_name: string; last_name: string }>;
    courses?: Array<{ id: number; name: string }>;
    stats?: {
        valid: number;
        expiring_soon: number;
        expired: number;
        pending: number;
    };
};

export default function TrainingIndex({ filters, trainingRecords, staff = [], courses = [], stats }: Props) {
    const ANY = '__any__';
    const { auth } = usePage().props as any;
    const can = auth?.can?.training ?? {};

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/staff/training', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'completed':
            case 'passed':
                return 'bg-status-success-bg text-status-success border-status-success/30';
            case 'in_progress':
                return 'bg-status-info-bg text-status-info border-status-info/30';
            case 'enrolled':
                return 'bg-status-warning-bg text-status-warning border-status-warning/30';
            case 'expired':
                return 'bg-status-critical-bg text-status-critical border-status-critical/30';
            case 'failed':
                return 'bg-status-warning-bg text-status-warning border-status-warning/30';
            case 'exempted':
                return 'bg-primary/10 text-primary border-primary';
            default:
                return 'bg-muted text-foreground border-border';
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Training', href: '/staff/training' }]}>
            <Head title="Staff Training Records" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Staff Training & Development</h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            Track staff training, competency assessments, and compliance
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can.manageCourses && (
                            <Link href="/training/courses" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                Manage Courses
                            </Link>
                        )}
                        {can.viewAny && (
                            <Link href="/training/matrix" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                Training Matrix
                            </Link>
                        )}
                        {can.enrol && (
                            <Link href="/staff/training/enrol">
                                <Button size="sm">
                                    <GraduationCap className="mr-1.5 h-4 w-4" />
                                    Enrol Staff
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-4">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Valid Training</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <CheckCircle2 className="h-5 w-5 text-status-success" />
                                    <div className="text-2xl font-bold">{stats.valid}</div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Expiring Soon</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <Clock className="h-5 w-5 text-status-warning" />
                                    <div className="text-2xl font-bold">{stats.expiring_soon}</div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Expired</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <AlertTriangle className="h-5 w-5 text-status-critical" />
                                    <div className="text-2xl font-bold">{stats.expired}</div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-muted-foreground">In Progress</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.pending}</div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-5">
                        <div className="sm:col-span-2">
                            <Label className="text-xs text-muted-foreground">Search</Label>
                            <Input
                                placeholder="Search training records"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>

                        {staff.length > 0 && (
                            <div>
                                <Label className="text-xs text-muted-foreground">Staff Member</Label>
                                <Select
                                    value={filters.user_id ? String(filters.user_id) : ANY}
                                    onValueChange={(v) => onFilter({ user_id: v === ANY ? null : v })}
                                >
                                    <SelectTrigger><SelectValue placeholder="Staff" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        {staff.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>{s.first_name} {s.last_name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                        <div>
                            <Label className="text-xs text-muted-foreground">Status</Label>
                            <Select
                                value={filters.status ?? ANY}
                                onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['enrolled', 'in_progress', 'completed', 'passed', 'failed', 'expired', 'exempted'].map((s) => (
                                        <SelectItem key={s} value={s}>{s.replace(/_/g, ' ')}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {courses.length > 0 && (
                            <div>
                                <Label className="text-xs text-muted-foreground">Course</Label>
                                <Select
                                    value={filters.course_id ? String(filters.course_id) : ANY}
                                    onValueChange={(v) => onFilter({ course_id: v === ANY ? null : v })}
                                >
                                    <SelectTrigger><SelectValue placeholder="Course" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        {courses.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {trainingRecords.data.map((record: any) => (
                        <Card key={record.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {record.training_course.name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={getStatusColor(record.status)}>
                                                    {record.status.replace(/_/g, ' ')}
                                                </Badge>
                                                {record.is_expiring_soon && (
                                                    <Badge variant="outline" className="border-status-warning/30 bg-status-warning-bg text-status-warning">
                                                        <Clock className="mr-1 h-3 w-3" />
                                                        Expiring Soon
                                                    </Badge>
                                                )}
                                                {record.is_expired && (
                                                    <Badge variant="outline" className="border-status-critical/30 bg-status-critical-bg text-status-critical">
                                                        <AlertTriangle className="mr-1 h-3 w-3" />
                                                        Expired
                                                    </Badge>
                                                )}
                                                {record.assessment_passed && (
                                                    <Badge variant="outline" className="border-status-success/30 bg-status-success-bg text-status-success">
                                                        Passed ({record.assessment_score}%)
                                                    </Badge>
                                                )}
                                                {record.status === 'exempted' && (
                                                    <Badge variant="outline" className="border-primary bg-primary/10 text-primary">
                                                        Exempted
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Staff: {record.user.first_name} {record.user.last_name}
                                                {record.enrolled_at && ` • Enrolled: ${new Date(record.enrolled_at).toLocaleDateString()}`}
                                                {record.completed_at && ` • Completed: ${new Date(record.completed_at).toLocaleDateString()}`}
                                                {record.expires_at && ` • Expires: ${new Date(record.expires_at).toLocaleDateString()}`}
                                                {record.cpd_points && ` • ${record.cpd_points} CPD points`}
                                            </div>
                                        </div>
                                        <Link href={`/staff/training/${record.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!trainingRecords.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No training records found.
                        </div>
                    )}
                </div>

                {trainingRecords?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {trainingRecords.links.map((l: any) => (
                            <Button
                                key={l.label}
                                type="button"
                                variant={l.active ? 'secondary' : 'outline'}
                                size="sm"
                                disabled={!l.url}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
