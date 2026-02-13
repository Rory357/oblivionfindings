import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { Users, CheckCircle2, AlertTriangle, Clock, Shield } from 'lucide-react';

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
    function applyFilter(key: string, value: string | null) {
        router.get('/hr/compliance', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

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
                    <Input
                        placeholder="Search by name or email..."
                        defaultValue={filters.q}
                        className="w-64"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') applyFilter('q', (e.target as HTMLInputElement).value);
                        }}
                    />
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
                                            <Badge variant="default" className="bg-green-100 text-green-800 hover:bg-green-100">
                                                {staff.compliant_count}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {staff.expired_count > 0 ? (
                                                <Badge variant="destructive">{staff.expired_count}</Badge>
                                            ) : (
                                                <span className="text-muted-foreground">0</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {staff.expiring_soon_count > 0 ? (
                                                <Badge variant="outline" className="border-yellow-500 text-yellow-600">{staff.expiring_soon_count}</Badge>
                                            ) : (
                                                <span className="text-muted-foreground">0</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {staff.not_started_count > 0 ? (
                                                <Badge variant="secondary">{staff.not_started_count}</Badge>
                                            ) : (
                                                <span className="text-muted-foreground">0</span>
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
                                        <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">
                                            <Shield className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                            <p>No staff compliance records found.</p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {staffStatuses.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {staffStatuses.links.map((link: any, i: number) => (
                            <Button key={i} variant={link.active ? 'default' : 'outline'} size="sm" disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}>
                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
                            </Button>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
