import { Badge } from '@/components/ui/badge';
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
import { Head, router } from '@inertiajs/react';
import { Search } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type LeaveBalance = {
    id: number;
    user: { id: number; name: string; email: string };
    leave_type: string;
    year: number;
    entitlement_hours: number;
    taken_hours: number;
    pending_hours: number;
    remaining_hours: number;
};

type Props = {
    balances: {
        data: LeaveBalance[];
        links: any[];
    };
    year: number;
    leaveTypes: string[];
    filters: {
        year: string | number | null;
        q: string | null;
    };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Leave Balances', href: '/hr/leave/balances' },
];

const formatHours = (hours: number) => {
    if (hours === 0) return '0h';
    const h = Math.floor(hours);
    const m = Math.round((hours - h) * 60);
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
};

const getUsageColor = (remaining: number, entitlement: number) => {
    if (entitlement === 0) return 'text-muted-foreground';
    const pct = (remaining / entitlement) * 100;
    if (pct <= 10) return 'text-status-critical font-semibold';
    if (pct <= 25) return 'text-status-warning';
    return 'text-status-success';
};

export default function LeaveBalances({
    balances,
    year,
    leaveTypes,
    filters,
    can,
}: Props) {
    const NONE = '__none__';
    const currentYear = new Date().getFullYear();
    const years = Array.from({ length: 5 }, (_, i) => currentYear - 2 + i);

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/leave/balances',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave Balances" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            Leave Balances
                        </h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            Staff leave entitlements and usage for {year}
                        </div>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Year
                            </Label>
                            <Select
                                value={String(filters.year || year)}
                                onValueChange={(v) => onFilter({ year: v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Year" />
                                </SelectTrigger>
                                <SelectContent>
                                    {years.map((y) => (
                                        <SelectItem key={y} value={String(y)}>
                                            {y}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="sm:col-span-2">
                            <Label className="text-xs text-muted-foreground">
                                Search
                            </Label>
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search by staff name or email..."
                                    value={filters.q || ''}
                                    onChange={(e) =>
                                        onFilter({ q: e.target.value })
                                    }
                                    className="pl-9"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Staff Member</TableHead>
                                    <TableHead>Leave Type</TableHead>
                                    <TableHead className="text-right">
                                        Entitlement
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Taken
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Pending
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Remaining
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {balances.data.map((balance) => (
                                    <TableRow key={balance.id}>
                                        <TableCell>
                                            <div className="font-medium">
                                                {balance.user.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {balance.user.email}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant="outline"
                                                className="capitalize"
                                            >
                                                {balance.leave_type.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            {formatHours(
                                                balance.entitlement_hours,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatHours(balance.taken_hours)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {balance.pending_hours > 0 ? (
                                                <span className="text-status-warning">
                                                    {formatHours(
                                                        balance.pending_hours,
                                                    )}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    0h
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={`text-right ${getUsageColor(balance.remaining_hours, balance.entitlement_hours)}`}
                                        >
                                            {formatHours(
                                                balance.remaining_hours,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!balances.data.length && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-8 text-center text-sm text-muted-foreground"
                                        >
                                            No leave balances found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {balances?.links?.length ? (
                    <LaravelPagination links={balances.links} />
                ) : null}
            </div>
        </AppLayout>
    );
}
