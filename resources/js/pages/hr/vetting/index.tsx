import { PageHero, PageLayout } from '@/components/page';
import { ComplianceTabs } from '@/components/hr';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle, Clock, Plus, Search, ShieldCheck } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type VettingCheck = {
    id: number;
    user: { id: number; name: string; email?: string };
    check_type: string;
    status: string;
    check_date: string | null;
    issue_date: string | null;
    expires_at: string | null;
    reference_number: string | null;
};

type Props = {
    checks: {
        data: VettingCheck[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    summary: {
        total: number;
        clear: number;
        expiring: number;
        expired: number;
        pending: number;
        flagged: number;
    };
    filters: {
        status: string | null;
        q: string | null;
    };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Vetting', href: '/hr/compliance/vetting' },
];

const formatDate = (value?: string | null) => {
    if (!value) return '--';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'clear':
            return 'bg-status-success-bg text-status-success border-status-success/30';
        case 'pending':
        case 'requested':
            return 'bg-status-info-bg text-status-info border-status-info/30';
        case 'flagged':
        case 'adverse':
            return 'bg-status-critical-bg text-status-critical border-status-critical/30';
        case 'renewal_due':
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        default:
            return 'bg-muted text-foreground border-border';
    }
};

const isExpiringSoon = (expiresAt: string | null) => {
    if (!expiresAt) return false;
    const d = new Date(expiresAt);
    const now = new Date();
    const thirtyDays = 30 * 24 * 60 * 60 * 1000;
    return (
        d.getTime() - now.getTime() < thirtyDays && d.getTime() > now.getTime()
    );
};

const isExpired = (expiresAt: string | null) => {
    if (!expiresAt) return false;
    return new Date(expiresAt) < new Date();
};

const statuses = [
    'clear',
    'pending',
    'requested',
    'flagged',
    'adverse',
    'renewal_due',
    'expired',
    'expiring',
    'action',
];

export default function VettingIndex({ checks, summary, filters, can }: Props) {
    const NONE = '__none__';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/compliance/vetting',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vetting Register" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={ShieldCheck}
                        title="Vetting Register"
                        description="Staff background checks, DBS, and vetting records."
                        stats={[
                            { label: 'Total', value: summary.total },
                            { label: 'Clear', value: summary.clear },
                            { label: 'Expiring', value: summary.expiring },
                            { label: 'Expired', value: summary.expired },
                        ]}
                        actions={
                            can.manage ? (
                                <Link href="/hr/compliance/vetting/create">
                                    <Button size="sm">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Add Check
                                    </Button>
                                </Link>
                            ) : undefined
                        }
                    />
                }
            >
                <ComplianceTabs active="vetting" />

                <div className="grid gap-4 sm:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Total Records
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {summary.total}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Clear
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2">
                                <CheckCircle className="h-5 w-5 text-status-success" />
                                <div className="text-2xl font-bold text-status-success">
                                    {summary.clear}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Expiring Soon
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2">
                                <Clock className="h-5 w-5 text-status-warning" />
                                <div
                                    className={`text-2xl font-bold ${summary.expiring > 0 ? 'text-status-warning' : ''}`}
                                >
                                    {summary.expiring}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Expired
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2">
                                {summary.expired > 0 && (
                                    <AlertTriangle className="h-5 w-5 text-status-critical" />
                                )}
                                <div
                                    className={`text-2xl font-bold ${summary.expired > 0 ? 'text-status-critical' : ''}`}
                                >
                                    {summary.expired}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Pending
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {summary.pending}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Flagged
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-status-critical">
                                {summary.flagged}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="sm:col-span-2">
                            <Label className="text-xs text-muted-foreground">
                                Search
                            </Label>
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search by staff name or reference..."
                                    value={filters.q || ''}
                                    onChange={(e) =>
                                        onFilter({ q: e.target.value })
                                    }
                                    className="pl-9"
                                />
                            </div>
                        </div>

                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Status
                            </Label>
                            <Select
                                value={filters.status ?? NONE}
                                onValueChange={(v) =>
                                    onFilter({ status: v === NONE ? null : v })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Statuses
                                    </SelectItem>
                                    {statuses.map((s) => (
                                        <SelectItem
                                            key={s}
                                            value={s}
                                            className="capitalize"
                                        >
                                            {s.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Staff Member</TableHead>
                                    <TableHead>Check Type</TableHead>
                                    <TableHead>Reference</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Issued</TableHead>
                                    <TableHead>Expires</TableHead>
                                    <TableHead className="w-20"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {checks.data.map((check) => (
                                    <TableRow key={check.id}>
                                        <TableCell>
                                            <div className="font-medium">
                                                {check.user.name}
                                            </div>
                                            {check.user.email && (
                                                <div className="text-xs text-muted-foreground">
                                                    {check.user.email}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="capitalize">
                                            {check.check_type.replace(
                                                /_/g,
                                                ' ',
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {check.reference_number || '--'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                className={getStatusColor(
                                                    check.status,
                                                )}
                                            >
                                                {check.status.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(
                                                check.issue_date ||
                                                    check.check_date,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={
                                                    isExpired(check.expires_at)
                                                        ? 'font-semibold text-status-critical'
                                                        : isExpiringSoon(
                                                                check.expires_at,
                                                            )
                                                          ? 'font-medium text-status-warning'
                                                          : ''
                                                }
                                            >
                                                {formatDate(check.expires_at)}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                href={`/hr/compliance/vetting/${check.id}`}
                                                className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                            >
                                                View
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!checks.data.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No vetting records found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {checks?.links?.length ? (
                    <LaravelPagination links={checks.links} />
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
