import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { Users, GraduationCap, AlertTriangle, Clock, CheckCircle2, BookOpen, MapPin } from 'lucide-react';

interface Stats {
    totalRecords: number;
    expiredCount: number;
    dueSoonCount: number;
    completedThisMonth: number;
}

interface StaffUser {
    id: number;
    name: string;
    email: string;
}

interface TrainingCourse {
    id: number;
    name: string;
    code?: string | null;
    category?: string | null;
}

interface TrainingRecord {
    id: number;
    user?: StaffUser | null;
    training_course?: TrainingCourse | null;
    check_date?: string | null;
    completed_at?: string | null;
    expires_at?: string | null;
    status?: string | null;
}

interface SiteSummary {
    site_id: number;
    site_name: string;
    total: number;
    expired: number;
}

interface MatrixEntry {
    course_id: number;
    course_name: string;
    category: string | null;
    count: number;
}

interface RenewalCourse {
    id: number;
    name: string;
    code?: string | null;
    category?: string | null;
    training_records_count?: number;
}

interface Props {
    stats: Stats;
    overdue: TrainingRecord[];
    dueSoon: TrainingRecord[];
    bySite: SiteSummary[];
    matrix: MatrixEntry[];
    renewalNeeded: RenewalCourse[];
    filters: { site_id: string | null };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Training', href: '/hr/compliance/training' },
];

function formatDate(value?: string | null): string {
    if (!value) return '--';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-NZ');
}

function daysUntil(value?: string | null): number | null {
    if (!value) return null;
    const target = new Date(value);
    if (Number.isNaN(target.getTime())) return null;
    const diff = target.getTime() - Date.now();
    return Math.ceil(diff / (1000 * 60 * 60 * 24));
}

export default function TrainingIndex({ stats, overdue, dueSoon, bySite, matrix, renewalNeeded, filters }: Props) {
    function applyFilter(key: string, value: string | null) {
        router.get('/hr/compliance/training', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Training Dashboard" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Training Dashboard</h1>
                        <p className="text-muted-foreground">Monitor training renewals and overdue records</p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href="/hr/compliance">Compliance Dashboard</Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Total Records</p>
                                    <p className="text-3xl font-bold">{stats.totalRecords}</p>
                                </div>
                                <Users className="h-8 w-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Completed This Month</p>
                                    <p className="text-3xl font-bold text-green-600">{stats.completedThisMonth}</p>
                                </div>
                                <GraduationCap className="h-8 w-8 text-green-500" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Due Soon (60 days)</p>
                                    <p className="text-3xl font-bold text-yellow-600">{stats.dueSoonCount}</p>
                                </div>
                                <Clock className="h-8 w-8 text-yellow-500" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Expired</p>
                                    <p className="text-3xl font-bold text-destructive">{stats.expiredCount}</p>
                                </div>
                                <AlertTriangle className="h-8 w-8 text-destructive" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <AlertTriangle className="h-5 w-5 text-yellow-500" />
                                Overdue Training
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {overdue.length === 0 ? (
                                <div className="px-4 py-8 text-center text-muted-foreground">
                                    <CheckCircle2 className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                    <p className="text-sm">No overdue training records.</p>
                                </div>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="px-4 py-2 text-left font-medium">Staff</th>
                                            <th className="px-4 py-2 text-left font-medium">Course</th>
                                            <th className="px-4 py-2 text-left font-medium">Expired</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {overdue.map((record) => (
                                            <tr key={record.id} className="hover:bg-muted/30">
                                                <td className="px-4 py-2">
                                                    <div className="font-medium">{record.user?.name ?? 'Unknown'}</div>
                                                    <div className="text-xs text-muted-foreground">{record.user?.email ?? '--'}</div>
                                                </td>
                                                <td className="px-4 py-2">{record.training_course?.name ?? '--'}</td>
                                                <td className="px-4 py-2">
                                                    <div className="font-medium text-destructive">{formatDate(record.expires_at)}</div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CheckCircle2 className="h-5 w-5 text-green-500" />
                                Due Soon (Next 60 Days)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {dueSoon.length === 0 ? (
                                <div className="px-4 py-8 text-center text-muted-foreground">
                                    <BookOpen className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                    <p className="text-sm">No records expiring soon.</p>
                                </div>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="px-4 py-2 text-left font-medium">Staff</th>
                                            <th className="px-4 py-2 text-left font-medium">Course</th>
                                            <th className="px-4 py-2 text-left font-medium">Expires</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {dueSoon.map((record) => {
                                            const days = daysUntil(record.expires_at);
                                            return (
                                                <tr key={record.id} className="hover:bg-muted/30">
                                                    <td className="px-4 py-2 font-medium">{record.user?.name ?? 'Unknown'}</td>
                                                    <td className="px-4 py-2">{record.training_course?.name ?? '--'}</td>
                                                    <td className="px-4 py-2 text-muted-foreground">
                                                        {formatDate(record.expires_at)}
                                                        {days !== null && (
                                                            <span className="ml-2 text-xs">
                                                                ({days <= 0 ? 'expired' : `${days}d`})
                                                            </span>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2">
                                <GraduationCap className="h-5 w-5" />
                                Course Renewal Pressure
                            </CardTitle>
                            <Select value={filters.site_id || '__all__'} onValueChange={(value) => applyFilter('site_id', value === '__all__' ? null : value)}>
                                <SelectTrigger className="w-56"><SelectValue placeholder="All sites" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__all__">All sites</SelectItem>
                                    {bySite.map((site) => (
                                        <SelectItem key={site.site_id} value={String(site.site_id)}>{site.site_name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Course</th>
                                    <th className="px-4 py-3 text-left font-medium">Category</th>
                                    <th className="px-4 py-3 text-center font-medium">Expiring Soon Count</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {matrix.map((entry) => (
                                    <tr key={entry.course_id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3 font-medium">{entry.course_name}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant="outline" className="capitalize text-xs">{entry.category || '--'}</Badge>
                                        </td>
                                        <td className="px-4 py-3 text-center font-medium">{entry.count}</td>
                                    </tr>
                                ))}
                                {matrix.length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="px-4 py-8 text-center text-muted-foreground">
                                            <GraduationCap className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                            <p>No courses currently under renewal pressure.</p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <MapPin className="h-5 w-5" />
                                By Site
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {bySite.map((site) => (
                                <div key={site.site_id} className="flex items-center justify-between rounded-md border p-2 text-sm">
                                    <div>
                                        <p className="font-medium">{site.site_name}</p>
                                        <p className="text-xs text-muted-foreground">{site.total} records</p>
                                    </div>
                                    <Badge variant={site.expired > 0 ? 'destructive' : 'secondary'}>
                                        {site.expired} expired
                                    </Badge>
                                </div>
                            ))}
                            {bySite.length === 0 && (
                                <p className="text-sm text-muted-foreground">No site breakdown available.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <BookOpen className="h-5 w-5" />
                                Courses Needing Renewal
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {renewalNeeded.map((course) => (
                                <div key={course.id} className="flex items-center justify-between rounded-md border p-2 text-sm">
                                    <div>
                                        <p className="font-medium">{course.name}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {course.category || '--'} {course.code ? `- ${course.code}` : ''}
                                        </p>
                                    </div>
                                    <Badge variant="outline">
                                        {course.training_records_count ?? 0} expired
                                    </Badge>
                                </div>
                            ))}
                            {renewalNeeded.length === 0 && (
                                <p className="text-sm text-muted-foreground">No courses currently need renewal.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
