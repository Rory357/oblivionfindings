import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    Calendar,
    CheckCircle2,
    Clock,
    GraduationCap,
    MapPin,
    ShieldCheck,
    TrendingUp,
    Users,
} from 'lucide-react';

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
    { title: 'Training Dashboard', href: '/hr/compliance/training' },
];

function formatDate(value?: string | null): string {
    if (!value) return '\u2014';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
}
function daysUntil(value?: string | null): number | null {
    if (!value) return null;
    const target = new Date(value);
    if (Number.isNaN(target.getTime())) return null;
    return Math.ceil((target.getTime() - Date.now()) / 86400000);
}

export default function TrainingIndex({
    stats,
    overdue,
    dueSoon,
    bySite,
    matrix,
    renewalNeeded,
    filters,
}: Props) {
    function applyFilter(key: string, value: string | null) {
        router.get(
            '/hr/compliance/training',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    const complianceRate =
        stats.totalRecords > 0
            ? Math.round(
                  ((stats.totalRecords - stats.expiredCount) /
                      stats.totalRecords) *
                      100,
              )
            : 100;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Training Dashboard" />
            <div className="space-y-6 p-4 lg:p-6">
                {/* Hero Banner */}
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-white shadow-lg">
                    <div className="absolute -top-10 -right-10 h-40 w-40 rounded-full bg-white/5" />
                    <div className="absolute right-20 -bottom-8 h-24 w-24 rounded-full bg-white/5" />
                    <div className="absolute top-0 left-1/2 h-32 w-32 rounded-full bg-white/5" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-bold">
                                Training Dashboard
                            </h1>
                            <p className="mt-1 text-white/70">
                                Monitor training renewals, compliance and
                                overdue records
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            <div className="flex items-center gap-6">
                                <div className="text-center">
                                    <div className="text-3xl font-bold">
                                        {complianceRate}%
                                    </div>
                                    <div className="text-[10px] tracking-wider text-white/60 uppercase">
                                        Compliance
                                    </div>
                                </div>
                                <div className="h-10 w-px bg-white/20" />
                                <div className="text-center">
                                    <div className="text-3xl font-bold">
                                        {stats.totalRecords}
                                    </div>
                                    <div className="text-[10px] tracking-wider text-white/60 uppercase">
                                        Records
                                    </div>
                                </div>
                            </div>
                            <div className="ml-4 flex gap-2">
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    className="gap-1.5 border-white/20 bg-white/15 text-white backdrop-blur-sm hover:bg-white/25"
                                    asChild
                                >
                                    <Link href="/hr/compliance">
                                        <ShieldCheck className="h-4 w-4" />
                                        Compliance
                                    </Link>
                                </Button>
                                <Button
                                    size="sm"
                                    className="gap-1.5 bg-white text-primary shadow-md hover:bg-white/90"
                                    asChild
                                >
                                    <Link href="/hr/training/catalog">
                                        <BookOpen className="h-4 w-4" />
                                        Course Catalog
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                {/* KPI Cards */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {[
                        {
                            label: 'Total Records',
                            value: stats.totalRecords,
                            icon: Users,
                            color: 'violet',
                            gradient: 'from-primary/10 to-primary/5',
                            iconBg: 'bg-primary/10',
                            iconColor: 'text-primary',
                            borderColor: 'hover:border-primary',
                        },
                        {
                            label: 'Completed This Month',
                            value: stats.completedThisMonth,
                            icon: CheckCircle2,
                            color: 'emerald',
                            gradient:
                                'from-status-success/10 to-status-success/5',
                            iconBg: 'bg-status-success-bg',
                            iconColor: 'text-status-success',
                            borderColor: 'hover:border-status-success/30',
                        },
                        {
                            label: 'Due Soon (60 days)',
                            value: stats.dueSoonCount,
                            icon: Clock,
                            color: 'amber',
                            gradient:
                                'from-status-warning/10 to-status-warning/5',
                            iconBg: 'bg-status-warning-bg',
                            iconColor: 'text-status-warning',
                            borderColor: 'hover:border-status-warning/30',
                        },
                        {
                            label: 'Expired',
                            value: stats.expiredCount,
                            icon: AlertTriangle,
                            color: 'red',
                            gradient:
                                'from-status-critical/10 to-status-critical/5',
                            iconBg: 'bg-status-critical-bg',
                            iconColor: 'text-status-critical',
                            borderColor: 'hover:border-status-critical/30',
                        },
                    ].map((kpi) => {
                        const Icon = kpi.icon;
                        return (
                            <Card
                                key={kpi.label}
                                className={`group overflow-hidden bg-gradient-to-br ${kpi.gradient} transition-all ${kpi.borderColor} hover:shadow-md`}
                            >
                                <CardContent className="pt-5">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <p className="text-[11px] font-medium tracking-wider text-muted-foreground uppercase">
                                                {kpi.label}
                                            </p>
                                            <p className="mt-1 text-3xl font-bold tracking-tight">
                                                {kpi.value}
                                            </p>
                                        </div>
                                        <div
                                            className={`flex h-10 w-10 items-center justify-center rounded-xl ${kpi.iconBg} transition-transform group-hover:scale-110`}
                                        >
                                            <Icon
                                                className={`h-5 w-5 ${kpi.iconColor}`}
                                            />
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Overdue & Due Soon */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Overdue */}
                    <Card className="overflow-hidden border-status-critical/30">
                        <CardHeader className="border-b bg-gradient-to-r from-status-critical-bg to-transparent pb-3">
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-status-critical-bg">
                                        <AlertTriangle className="h-4 w-4 text-status-critical" />
                                    </div>
                                    Overdue Training
                                    {overdue.length > 0 && (
                                        <Badge
                                            variant="destructive"
                                            className="ml-1 text-[10px]"
                                        >
                                            {overdue.length}
                                        </Badge>
                                    )}
                                </CardTitle>
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            {overdue.length === 0 ? (
                                <div className="flex flex-col items-center gap-2 py-12">
                                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-status-success-bg">
                                        <CheckCircle2 className="h-6 w-6 text-status-success" />
                                    </div>
                                    <p className="text-sm font-medium text-status-success">
                                        All clear!
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        No overdue training records
                                    </p>
                                </div>
                            ) : (
                                <div className="divide-y">
                                    {overdue.map((r) => (
                                        <div
                                            key={r.id}
                                            className="flex items-center justify-between px-4 py-3 transition-colors hover:bg-status-critical-bg"
                                        >
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-status-critical-bg text-xs font-bold text-status-critical">
                                                    {(r.user?.name ?? '?')[0]}
                                                </div>
                                                <div>
                                                    <p className="text-sm font-medium">
                                                        {r.user?.name ??
                                                            'Unknown'}
                                                    </p>
                                                    <p className="text-[10px] text-muted-foreground">
                                                        {r.training_course
                                                            ?.name ?? '\u2014'}
                                                    </p>
                                                </div>
                                            </div>
                                            <span className="text-xs font-medium text-status-critical">
                                                {formatDate(r.expires_at)}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Due Soon */}
                    <Card className="overflow-hidden border-status-warning/30">
                        <CardHeader className="border-b bg-gradient-to-r from-status-warning-bg to-transparent pb-3">
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-status-warning-bg">
                                        <Clock className="h-4 w-4 text-status-warning" />
                                    </div>
                                    Due Soon
                                    {dueSoon.length > 0 && (
                                        <Badge className="ml-1 border-0 bg-status-warning-bg text-[10px] text-status-warning">
                                            {dueSoon.length}
                                        </Badge>
                                    )}
                                </CardTitle>
                                <span className="text-[10px] text-muted-foreground">
                                    Next 60 days
                                </span>
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            {dueSoon.length === 0 ? (
                                <div className="flex flex-col items-center gap-2 py-12">
                                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                                        <Calendar className="h-6 w-6 text-muted-foreground" />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        No records expiring soon
                                    </p>
                                </div>
                            ) : (
                                <div className="divide-y">
                                    {dueSoon.map((r) => {
                                        const days = daysUntil(r.expires_at);
                                        return (
                                            <div
                                                key={r.id}
                                                className="flex items-center justify-between px-4 py-3 transition-colors hover:bg-status-warning-bg"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-status-warning-bg text-xs font-bold text-status-warning">
                                                        {
                                                            (r.user?.name ??
                                                                '?')[0]
                                                        }
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-medium">
                                                            {r.user?.name ??
                                                                'Unknown'}
                                                        </p>
                                                        <p className="text-[10px] text-muted-foreground">
                                                            {r.training_course
                                                                ?.name ??
                                                                '\u2014'}
                                                        </p>
                                                    </div>
                                                </div>
                                                <div className="text-right">
                                                    <span
                                                        className={`text-xs font-medium ${days !== null && days <= 14 ? 'text-status-warning' : 'text-muted-foreground'}`}
                                                    >
                                                        {formatDate(
                                                            r.expires_at,
                                                        )}
                                                    </span>
                                                    {days !== null &&
                                                        days > 0 && (
                                                            <p className="text-[10px] text-muted-foreground">
                                                                {days} days
                                                            </p>
                                                        )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Course Renewal Pressure */}
                <Card className="overflow-hidden">
                    <CardHeader className="border-b bg-gradient-to-r from-primary/10 to-transparent pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
                                    <TrendingUp className="h-4 w-4 text-primary" />
                                </div>
                                Course Renewal Pressure
                            </CardTitle>
                            <Select
                                value={filters.site_id || '__all__'}
                                onValueChange={(v) =>
                                    applyFilter(
                                        'site_id',
                                        v === '__all__' ? null : v,
                                    )
                                }
                            >
                                <SelectTrigger className="h-8 w-48 text-xs">
                                    <SelectValue placeholder="All sites" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__all__">
                                        All sites
                                    </SelectItem>
                                    {bySite.map((s) => (
                                        <SelectItem
                                            key={s.site_id}
                                            value={String(s.site_id)}
                                        >
                                            {s.site_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        {matrix.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 py-12">
                                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10">
                                    <GraduationCap className="h-6 w-6 text-primary/70" />
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    No courses currently under renewal pressure
                                </p>
                            </div>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="bg-muted/80">
                                    <tr>
                                        <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">
                                            Course
                                        </th>
                                        <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">
                                            Category
                                        </th>
                                        <th className="px-4 py-2.5 text-center text-xs font-medium text-muted-foreground">
                                            Expiring Soon
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {matrix.map((e) => (
                                        <tr
                                            key={e.course_id}
                                            className="group hover:bg-primary/10/30 transition-colors"
                                        >
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2.5">
                                                    <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10">
                                                        <BookOpen className="h-3.5 w-3.5 text-primary" />
                                                    </div>
                                                    <span className="font-medium">
                                                        {e.course_name}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {e.category ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px] capitalize"
                                                    >
                                                        {e.category}
                                                    </Badge>
                                                ) : (
                                                    '\u2014'
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <Badge
                                                    variant={
                                                        e.count > 5
                                                            ? 'destructive'
                                                            : e.count > 2
                                                              ? 'default'
                                                              : 'secondary'
                                                    }
                                                    className="min-w-[28px] justify-center text-xs"
                                                >
                                                    {e.count}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>

                {/* By Site & Courses Needing Renewal */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-gradient-to-r from-status-info-bg to-transparent pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-status-info-bg">
                                    <MapPin className="h-4 w-4 text-status-info" />
                                </div>
                                By Site
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 pt-4">
                            {bySite.length === 0 ? (
                                <p className="py-6 text-center text-sm text-muted-foreground">
                                    No site breakdown available.
                                </p>
                            ) : (
                                bySite.map((site) => {
                                    const pct =
                                        site.total > 0
                                            ? Math.round(
                                                  ((site.total - site.expired) /
                                                      site.total) *
                                                      100,
                                              )
                                            : 100;
                                    return (
                                        <div
                                            key={site.site_id}
                                            className="group rounded-xl border p-3 transition-all hover:border-status-info/30 hover:shadow-sm"
                                        >
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <p className="text-sm font-medium">
                                                        {site.site_name}
                                                    </p>
                                                    <p className="text-[10px] text-muted-foreground">
                                                        {site.total} records
                                                    </p>
                                                </div>
                                                <Badge
                                                    variant={
                                                        site.expired > 0
                                                            ? 'destructive'
                                                            : 'secondary'
                                                    }
                                                    className="text-xs"
                                                >
                                                    {site.expired} expired
                                                </Badge>
                                            </div>
                                            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className={`h-full rounded-full transition-all duration-700 ${site.expired > 0 ? 'bg-status-warning' : 'bg-status-success'}`}
                                                    style={{ width: `${pct}%` }}
                                                />
                                            </div>
                                        </div>
                                    );
                                })
                            )}
                        </CardContent>
                    </Card>

                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-gradient-to-r from-status-warning-bg to-transparent pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-status-warning-bg">
                                    <GraduationCap className="h-4 w-4 text-status-warning" />
                                </div>
                                Courses Needing Renewal
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 pt-4">
                            {renewalNeeded.length === 0 ? (
                                <div className="flex flex-col items-center gap-2 py-6">
                                    <CheckCircle2 className="h-8 w-8 text-status-success" />
                                    <p className="text-sm text-muted-foreground">
                                        No courses currently need renewal
                                    </p>
                                </div>
                            ) : (
                                renewalNeeded.map((course) => (
                                    <div
                                        key={course.id}
                                        className="group flex items-center justify-between rounded-xl border p-3 transition-all hover:border-status-warning/30 hover:shadow-sm"
                                    >
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-status-warning-bg">
                                                <BookOpen className="h-4 w-4 text-status-warning" />
                                            </div>
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {course.name}
                                                </p>
                                                <p className="text-[10px] text-muted-foreground">
                                                    {course.category ||
                                                        '\u2014'}
                                                    {course.code
                                                        ? ` \u00b7 ${course.code}`
                                                        : ''}
                                                </p>
                                            </div>
                                        </div>
                                        <Badge
                                            variant="outline"
                                            className="border-status-warning/30 bg-status-warning-bg text-xs text-status-warning"
                                        >
                                            {course.training_records_count ?? 0}{' '}
                                            expired
                                        </Badge>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
