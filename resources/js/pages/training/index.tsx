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
                return 'bg-green-100 text-green-800 border-green-200';
            case 'in_progress':
                return 'bg-blue-100 text-blue-800 border-blue-200';
            case 'enrolled':
                return 'bg-yellow-100 text-yellow-800 border-yellow-200';
            case 'expired':
                return 'bg-red-100 text-red-800 border-red-200';
            case 'failed':
                return 'bg-orange-100 text-orange-800 border-orange-200';
            case 'exempted':
                return 'bg-purple-100 text-purple-800 border-purple-200';
            default:
                return 'bg-slate-100 text-slate-800 border-slate-200';
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Training', href: '/staff/training' }]}>
            <Head title="Staff Training Records" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Staff Training & Development</h1>
                        <div className="mt-1 text-sm text-slate-500">
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
                                <CardTitle className="text-sm font-medium text-slate-500">Valid Training</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <CheckCircle2 className="h-5 w-5 text-green-500" />
                                    <div className="text-2xl font-bold">{stats.valid}</div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Expiring Soon</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <Clock className="h-5 w-5 text-orange-500" />
                                    <div className="text-2xl font-bold">{stats.expiring_soon}</div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Expired</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <AlertTriangle className="h-5 w-5 text-red-500" />
                                    <div className="text-2xl font-bold">{stats.expired}</div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">In Progress</CardTitle>
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
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Search training records"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>

                        {staff.length > 0 && (
                            <div>
                                <Label className="text-xs text-slate-500">Staff Member</Label>
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
                            <Label className="text-xs text-slate-500">Status</Label>
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
                                <Label className="text-xs text-slate-500">Course</Label>
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
                                                    <Badge variant="outline" className="border-orange-200 bg-orange-50 text-orange-700">
                                                        <Clock className="mr-1 h-3 w-3" />
                                                        Expiring Soon
                                                    </Badge>
                                                )}
                                                {record.is_expired && (
                                                    <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700">
                                                        <AlertTriangle className="mr-1 h-3 w-3" />
                                                        Expired
                                                    </Badge>
                                                )}
                                                {record.assessment_passed && (
                                                    <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700">
                                                        Passed ({record.assessment_score}%)
                                                    </Badge>
                                                )}
                                                {record.status === 'exempted' && (
                                                    <Badge variant="outline" className="border-purple-200 bg-purple-50 text-purple-700">
                                                        Exempted
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
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
                        <div className="py-8 text-center text-sm text-slate-500">
                            No training records found.
                        </div>
                    )}
                </div>

                {trainingRecords?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {trainingRecords.links.map((l: any) => (
                            <button
                                key={l.label}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
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
