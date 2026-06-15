import { PageHero, PageLayout } from '@/components/page';
import { ComplianceTabs } from '@/components/hr';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
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
    CheckCircle2,
    Clock,
    Search,
    Shield,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface StaffStatus {
    user_id: number;
    user_name: string;
    user_email: string;
    total_requirements: number;
    compliant_count: number;
    expired_count: number;
    expiring_soon_count: number;
    not_started_count: number;
    compliance_percent: number;
    future_shifts_affected?: number;
}

interface Requirement {
    id: number;
    name: string;
    type: string;
}

interface Props {
    staffStatuses: {
        data: StaffStatus[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        per_page: number;
    };
    summary: {
        total_staff: number;
        fully_compliant: number;
        has_expired: number;
        has_expiring: number;
    };
    requirements: Requirement[];
    filters: {
        q: string;
        status: string | null;
        requirement_id: string | null;
    };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'Compliance', href: '/hr/compliance' },
];

export default function ComplianceIndex({
    staffStatuses,
    summary,
    requirements,
    filters,
    can,
}: Props) {
    const [searchTerm, setSearchTerm] = useState(filters.q || '');
    const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const applyFilter = useCallback(
        (key: string, value: string | null) => {
            router.get(
                '/hr/compliance',
                { ...filters, [key]: value || undefined },
                { preserveState: true, replace: true },
            );
        },
        [filters],
    );

    const handleSearchChange = useCallback(
        (value: string) => {
            setSearchTerm(value);
            if (debounceTimer.current) clearTimeout(debounceTimer.current);
            debounceTimer.current = setTimeout(() => {
                applyFilter('q', value || null);
            }, 300);
        },
        [applyFilter],
    );

    useEffect(() => {
        return () => {
            if (debounceTimer.current) clearTimeout(debounceTimer.current);
        };
    }, []);

    const complianceRate =
        summary.total_staff > 0
            ? Math.round((summary.fully_compliant / summary.total_staff) * 100)
            : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Staff Compliance" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={ShieldCheck}
                        title="Staff Compliance"
                        description="Monitor staff compliance with training and certification requirements."
                        stats={[
                            { label: 'Total staff', value: summary.total_staff },
                            { label: 'Fully compliant', value: summary.fully_compliant },
                            { label: 'Has expired', value: summary.has_expired },
                            { label: 'Compliance rate', value: `${complianceRate}%` },
                        ]}
                        actions={
                            can.manage ? (
                                <Button asChild variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                    <Link href="/hr/compliance/matrix">
                                        Manage Requirements
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                }
            >
                <ComplianceTabs active="overview" />

                {/* Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Total Staff
                                    </p>
                                    <p className="text-3xl font-bold">
                                        {summary.total_staff}
                                    </p>
                                </div>
                                <Users className="h-8 w-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Fully Compliant
                                    </p>
                                    <p className="text-3xl font-bold text-status-success">
                                        {summary.fully_compliant}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {complianceRate}% of staff
                                    </p>
                                </div>
                                <CheckCircle2 className="h-8 w-8 text-status-success" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Have Expired
                                    </p>
                                    <p className="text-3xl font-bold text-destructive">
                                        {summary.has_expired}
                                    </p>
                                </div>
                                <AlertTriangle className="h-8 w-8 text-destructive" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Have Expiring
                                    </p>
                                    <p className="text-3xl font-bold text-status-warning">
                                        {summary.has_expiring}
                                    </p>
                                </div>
                                <Clock className="h-8 w-8 text-status-warning" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Search by name or email..."
                            value={searchTerm}
                            className="w-64 pl-9"
                            onChange={(e) => handleSearchChange(e.target.value)}
                        />
                    </div>
                    <Select
                        value={filters.status || '__none__'}
                        onValueChange={(v) =>
                            applyFilter('status', v === '__none__' ? null : v)
                        }
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Compliance Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Staff</SelectItem>
                            <SelectItem value="fully_compliant">
                                Fully Compliant
                            </SelectItem>
                            <SelectItem value="has_expired">
                                Has Expired Items
                            </SelectItem>
                            <SelectItem value="has_expiring">
                                Has Expiring Items
                            </SelectItem>
                            <SelectItem value="incomplete">
                                Incomplete
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.requirement_id || '__none__'}
                        onValueChange={(v) =>
                            applyFilter(
                                'requirement_id',
                                v === '__none__' ? null : v,
                            )
                        }
                    >
                        <SelectTrigger className="w-56">
                            <SelectValue placeholder="Requirement" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">
                                All Requirements
                            </SelectItem>
                            {requirements.map((r) => (
                                <SelectItem key={r.id} value={String(r.id)}>
                                    {r.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Staff Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Staff Member
                                    </th>
                                    <th className="px-4 py-3 text-center font-medium">
                                        Compliance
                                    </th>
                                    <th className="px-4 py-3 text-center font-medium">
                                        Compliant
                                    </th>
                                    <th className="px-4 py-3 text-center font-medium">
                                        Expired
                                    </th>
                                    <th className="px-4 py-3 text-center font-medium">
                                        Expiring
                                    </th>
                                    <th className="px-4 py-3 text-center font-medium">
                                        Not Started
                                    </th>
                                    <th className="px-4 py-3 text-center font-medium">
                                        Shifts Affected
                                    </th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {staffStatuses.data.map((staff) => (
                                    <tr
                                        key={staff.user_id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/hr/compliance/staff/${staff.user_id}`}
                                                className="font-medium text-primary hover:underline"
                                            >
                                                {staff.user_name}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {staff.user_email}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <div className="flex items-center justify-center gap-2">
                                                <div className="h-2 w-16 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className={`h-full rounded-full transition-all ${
                                                            staff.compliance_percent ===
                                                            100
                                                                ? 'bg-status-success'
                                                                : staff.compliance_percent >=
                                                                    70
                                                                  ? 'bg-status-warning'
                                                                  : 'bg-destructive'
                                                        }`}
                                                        style={{
                                                            width: `${staff.compliance_percent}%`,
                                                        }}
                                                    />
                                                </div>
                                                <span className="text-xs font-medium">
                                                    {staff.compliance_percent}%
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Badge
                                                variant="outline"
                                                className="border-status-success/30 bg-status-success-bg text-status-success dark:border-status-success/30 dark:bg-status-success-bg dark:text-status-success"
                                            >
                                                {staff.compliant_count}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {staff.expired_count > 0 ? (
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-critical/30 bg-status-critical-bg text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical"
                                                >
                                                    {staff.expired_count}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    0
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {staff.expiring_soon_count > 0 ? (
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning"
                                                >
                                                    {staff.expiring_soon_count}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    0
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {staff.not_started_count > 0 ? (
                                                <Badge
                                                    variant="outline"
                                                    className="border-border bg-muted text-foreground dark:border-border dark:bg-muted dark:text-muted-foreground"
                                                >
                                                    {staff.not_started_count}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    0
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {staff.future_shifts_affected &&
                                            staff.future_shifts_affected > 0 ? (
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-critical/30 bg-status-critical-bg text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical"
                                                >
                                                    {
                                                        staff.future_shifts_affected
                                                    }{' '}
                                                    shift
                                                    {staff.future_shifts_affected !==
                                                    1
                                                        ? 's'
                                                        : ''}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    &mdash;
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/hr/compliance/staff/${staff.user_id}`}
                                                >
                                                    View
                                                </Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                                {staffStatuses.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-4 py-12 text-center text-muted-foreground"
                                        >
                                            <Shield className="mx-auto mb-3 h-12 w-12 opacity-30" />
                                            <p className="text-base font-medium">
                                                No compliance records found
                                            </p>
                                            <p className="mt-1 text-sm">
                                                {filters.q ||
                                                filters.status ||
                                                filters.requirement_id
                                                    ? 'Try adjusting your search or filters to find what you are looking for.'
                                                    : 'Compliance records will appear here once requirements are assigned to staff.'}
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {staffStatuses.total > 0 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {staffStatuses.from}–{staffStatuses.to} of{' '}
                            {staffStatuses.total} results
                        </p>
                        {staffStatuses.last_page > 1 && (
                            <LaravelPagination links={staffStatuses.links} />
                        )}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
