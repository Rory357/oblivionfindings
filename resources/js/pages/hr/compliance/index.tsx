import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { Users, CheckCircle2, AlertTriangle, Clock, Shield, Search } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

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
    filters: { q: string; status: string | null; requirement_id: string | null };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'Compliance', href: '/hr/compliance' },
];

export default function ComplianceIndex({ staffStatuses, summary, requirements, filters, can }: Props) {
    const [searchTerm, setSearchTerm] = useState(filters.q || '');
    const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    function applyFilter(key: string, value: string | null) {
        router.get('/hr/compliance', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    const handleSearchChange = useCallback((value: string) => {
        setSearchTerm(value);
        if (debounceTimer.current) clearTimeout(debounceTimer.current);
        debounceTimer.current = setTimeout(() => {
            applyFilter('q', value || null);
        }, 300);
    }, [filters]);

    useEffect(() => {
        return () => {
            if (debounceTimer.current) clearTimeout(debounceTimer.current);
        };
    }, []);

    const complianceRate = summary.total_staff > 0
        ? Math.round((summary.fully_compliant / summary.total_staff) * 100)
        : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Staff Compliance" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Staff Compliance</h1>
                    <div className="flex items-center gap-2">
                        {can.manage && (
                            <Button asChild variant="outline">
                                <Link href="/hr/compliance/matrix">Manage Requirements</Link>
                            </Button>
                        )}
                    </div>
                </div>

                {/* Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Total Staff</p>
                                    <p className="text-3xl font-bold">{summary.total_staff}</p>
                                </div>
                                <Users className="h-8 w-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Fully Compliant</p>
                                    <p className="text-3xl font-bold text-green-600">{summary.fully_compliant}</p>
                                    <p className="text-xs text-muted-foreground">{complianceRate}% of staff</p>
                                </div>
                                <CheckCircle2 className="h-8 w-8 text-green-500" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Have Expired</p>
                                    <p className="text-3xl font-bold text-destructive">{summary.has_expired}</p>
                                </div>
                                <AlertTriangle className="h-8 w-8 text-destructive" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Have Expiring</p>
                                    <p className="text-3xl font-bold text-yellow-600">{summary.has_expiring}</p>
                                </div>
                                <Clock className="h-8 w-8 text-yellow-500" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Search by name or email..."
                            value={searchTerm}
                            className="w-64 pl-9"
                            onChange={(e) => handleSearchChange(e.target.value)}
                        />
                    </div>
                    <Select value={filters.status || '__none__'} onValueChange={(v) => applyFilter('status', v === '__none__' ? null : v)}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="Compliance Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Staff</SelectItem>
                            <SelectItem value="fully_compliant">Fully Compliant</SelectItem>
                            <SelectItem value="has_expired">Has Expired Items</SelectItem>
                            <SelectItem value="has_expiring">Has Expiring Items</SelectItem>
                            <SelectItem value="incomplete">Incomplete</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters.requirement_id || '__none__'} onValueChange={(v) => applyFilter('requirement_id', v === '__none__' ? null : v)}>
                        <SelectTrigger className="w-56"><SelectValue placeholder="Requirement" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Requirements</SelectItem>
                            {requirements.map((r) => (
                                <SelectItem key={r.id} value={String(r.id)}>{r.name}</SelectItem>
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
                                    <th className="px-4 py-3 text-left font-medium">Staff Member</th>
                                    <th className="px-4 py-3 text-center font-medium">Compliance</th>
                                    <th className="px-4 py-3 text-center font-medium">Compliant</th>
                                    <th className="px-4 py-3 text-center font-medium">Expired</th>
                                    <th className="px-4 py-3 text-center font-medium">Expiring</th>
                                    <th className="px-4 py-3 text-center font-medium">Not Started</th>
                                    <th className="px-4 py-3 text-center font-medium">Shifts Affected</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {staffStatuses.data.map((staff) => (
                                    <tr key={staff.user_id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <Link href={`/hr/compliance/staff/${staff.user_id}`} className="font-medium text-primary hover:underline">
                                                {staff.user_name}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">{staff.user_email}</div>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <div className="flex items-center justify-center gap-2">
                                                <div className="h-2 w-16 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className={`h-full rounded-full transition-all ${
                                                            staff.compliance_percent === 100
                                                                ? 'bg-green-500'
                                                                : staff.compliance_percent >= 70
                                                                    ? 'bg-yellow-500'
                                                                    : 'bg-destructive'
                                                        }`}
                                                        style={{ width: `${staff.compliance_percent}%` }}
                                                    />
                                                </div>
                                                <span className="text-xs font-medium">{staff.compliance_percent}%</span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-400">
                                                {staff.compliant_count}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {staff.expired_count > 0 ? (
                                                <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-400">
                                                    {staff.expired_count}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground">0</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {staff.expiring_soon_count > 0 ? (
                                                <Badge variant="outline" className="border-yellow-200 bg-yellow-50 text-yellow-700 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-400">
                                                    {staff.expiring_soon_count}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground">0</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {staff.not_started_count > 0 ? (
                                                <Badge variant="outline" className="border-border bg-muted text-foreground dark:border-border dark:bg-muted dark:text-muted-foreground">
                                                    {staff.not_started_count}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground">0</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {staff.future_shifts_affected && staff.future_shifts_affected > 0 ? (
                                                <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-400">
                                                    {staff.future_shifts_affected} shift{staff.future_shifts_affected !== 1 ? 's' : ''}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground">&mdash;</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={`/hr/compliance/staff/${staff.user_id}`}>View</Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                                {staffStatuses.data.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-12 text-center text-muted-foreground">
                                            <Shield className="mx-auto mb-3 h-12 w-12 opacity-30" />
                                            <p className="text-base font-medium">No compliance records found</p>
                                            <p className="mt-1 text-sm">
                                                {filters.q || filters.status || filters.requirement_id
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
                            Showing {staffStatuses.from}–{staffStatuses.to} of {staffStatuses.total} results
                        </p>
                        {staffStatuses.last_page > 1 && (
                            <LaravelPagination links={staffStatuses.links} />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
