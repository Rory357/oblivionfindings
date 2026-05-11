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
    Briefcase,
    Clock,
    Download,
    Search,
    ShieldAlert,
    UserPlus,
    Users,
    X,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface EmployeeRow {
    id: number;
    profile_id: number | null;
    employee_number: string | null;
    position_title: string | null;
    employment_type: string | null;
    department: string | null;
    is_active: boolean;
    start_date: string | null;
    user: { id: number; name: string; email: string };
    primary_site: { id: number; name: string } | null;
}

interface Props {
    profiles: {
        data: EmployeeRow[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    sites: Array<{ id: number; name: string }>;
    departments: Array<{ id: number; name: string }>;
    filters: {
        q: string;
        status: string | null;
        site_id: string | null;
        department: string | null;
        employment_type: string | null;
    };
    summary: {
        active: number;
        inactive: number;
        new_hires: number;
        on_probation: number;
        compliance_alerts: number;
        type_counts: Record<string, number>;
    };
    can: { manage: boolean };
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'People', href: '/hr/people' },
];

const NONE = '__none__';

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

const AVATAR_COLORS = [
    'bg-status-info-bg text-status-info dark:text-status-info',
    'bg-primary/15 text-primary dark:text-primary/70',
    'bg-status-success-bg text-status-success dark:text-status-success',
    'bg-status-warning-bg text-status-warning dark:text-status-warning',
    'bg-status-critical-bg text-status-critical dark:text-status-critical',
    'bg-status-info-bg text-status-info dark:text-status-info',
    'bg-status-critical-bg text-status-critical dark:text-status-critical',
    'bg-primary/15 text-primary dark:text-primary/70',
];

function getAvatarColor(id: number): string {
    return AVATAR_COLORS[id % AVATAR_COLORS.length];
}

const TYPE_STYLES: Record<string, string> = {
    full_time:
        'bg-status-info-bg text-status-info border-status-info/30 dark:bg-status-info-bg dark:text-status-info dark:border-status-info/30',
    part_time:
        'bg-status-warning-bg text-status-warning border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning dark:border-status-warning/30',
    casual: 'bg-primary/10 text-primary border-primary dark:bg-primary/10 dark:text-primary/70 dark:border-primary/30',
    fixed_term:
        'bg-status-info-bg text-status-info border-status-info/30 dark:bg-status-info-bg dark:text-status-info dark:border-status-info/30',
    contractor:
        'bg-status-warning-bg text-status-warning border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning dark:border-status-warning/30',
};

const TYPE_BAR_COLORS: Record<string, string> = {
    full_time: 'bg-status-info',
    part_time: 'bg-status-warning',
    casual: 'bg-primary',
    fixed_term: 'bg-status-info',
    contractor: 'bg-status-warning',
};

function formatLabel(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

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

/* ------------------------------------------------------------------ */
/*  Stat Card                                                          */
/* ------------------------------------------------------------------ */

interface StatCardProps {
    label: string;
    value: number;
    icon: React.ElementType;
    color: 'blue' | 'emerald' | 'amber' | 'red';
    href?: string;
}

const STAT_COLORS = {
    blue: {
        bg: 'bg-status-info-bg',
        icon: 'text-status-info dark:text-status-info',
        ring: 'ring-status-info dark:ring-status-info/20',
    },
    emerald: {
        bg: 'bg-status-success-bg',
        icon: 'text-status-success dark:text-status-success',
        ring: 'ring-status-success dark:ring-status-success/20',
    },
    amber: {
        bg: 'bg-status-warning-bg',
        icon: 'text-status-warning dark:text-status-warning',
        ring: 'ring-status-warning dark:ring-status-warning/20',
    },
    red: {
        bg: 'bg-status-critical-bg',
        icon: 'text-status-critical dark:text-status-critical',
        ring: 'ring-status-critical dark:ring-status-critical/20',
    },
};

function StatCard({ label, value, icon: Icon, color, href }: StatCardProps) {
    const c = STAT_COLORS[color];
    const inner = (
        <div
            className={`relative flex items-center gap-4 rounded-xl p-4 ring-1 ${c.bg} ${c.ring} transition-shadow hover:shadow-md`}
        >
            <div
                className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${c.bg} ${c.icon}`}
            >
                <Icon className="h-5 w-5" />
            </div>
            <div className="min-w-0">
                <p className="text-2xl font-bold tracking-tight">{value}</p>
                <p className="truncate text-xs font-medium text-muted-foreground">
                    {label}
                </p>
            </div>
        </div>
    );

    if (href) {
        return (
            <Link href={href} className="block">
                {inner}
            </Link>
        );
    }
    return inner;
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function EmployeesIndex({
    profiles,
    sites,
    departments,
    filters,
    summary,
    can,
}: Props) {
    function applyFilter(key: string, value: string | null) {
        router.get(
            '/hr/people',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    function clearFilters() {
        router.get('/hr/people', {}, { preserveState: true, replace: true });
    }

    const hasFilters = !!(
        filters.q ||
        filters.status ||
        filters.site_id ||
        filters.department ||
        filters.employment_type
    );

    const typeTotal =
        Object.values(summary.type_counts).reduce((a, b) => a + b, 0) || 1;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="People" />

            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            People
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Manage your workforce &mdash; {profiles.total}{' '}
                            {profiles.total === 1 ? 'person' : 'people'} total
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" className="gap-1.5">
                            <Download className="h-4 w-4" />
                            Export
                        </Button>
                    </div>
                </div>

                {/* Stats Cards */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <StatCard
                        label="Active Employees"
                        value={summary.active}
                        icon={Users}
                        color="blue"
                    />
                    <StatCard
                        label="New Hires (30 days)"
                        value={summary.new_hires}
                        icon={UserPlus}
                        color="emerald"
                    />
                    <StatCard
                        label="On Probation"
                        value={summary.on_probation}
                        icon={Clock}
                        color="amber"
                    />
                    <StatCard
                        label="Compliance Alerts"
                        value={summary.compliance_alerts}
                        icon={ShieldAlert}
                        color="red"
                        href="/hr/compliance"
                    />
                </div>

                {/* Employment Type Bar */}
                {typeTotal > 0 && (
                    <Card>
                        <CardContent className="py-3">
                            <div className="mb-2 flex items-center gap-2">
                                <Briefcase className="h-4 w-4 text-muted-foreground" />
                                <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Employment Type Breakdown
                                </span>
                            </div>
                            <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-muted">
                                {Object.entries(summary.type_counts).map(
                                    ([type, count]) => (
                                        <div
                                            key={type}
                                            className={`${TYPE_BAR_COLORS[type] || 'bg-muted'} transition-all`}
                                            style={{
                                                width: `${(count / typeTotal) * 100}%`,
                                            }}
                                            title={`${formatLabel(type)}: ${count}`}
                                        />
                                    ),
                                )}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                {Object.entries(summary.type_counts).map(
                                    ([type, count]) => (
                                        <div
                                            key={type}
                                            className="flex items-center gap-1.5 text-xs text-muted-foreground"
                                        >
                                            <span
                                                className={`inline-block h-2.5 w-2.5 rounded-full ${TYPE_BAR_COLORS[type] || 'bg-muted'}`}
                                            />
                                            {formatLabel(type)}{' '}
                                            <span className="font-medium text-foreground">
                                                {count}
                                            </span>
                                        </div>
                                    ),
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search by name or email..."
                            defaultValue={filters.q}
                            className="w-64 pl-9"
                            onKeyDown={(e) => {
                                if (e.key === 'Enter')
                                    applyFilter(
                                        'q',
                                        (e.target as HTMLInputElement).value,
                                    );
                            }}
                        />
                    </div>

                    <Select
                        value={filters.status || NONE}
                        onValueChange={(v) =>
                            applyFilter('status', v === NONE ? null : v)
                        }
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.site_id || NONE}
                        onValueChange={(v) =>
                            applyFilter('site_id', v === NONE ? null : v)
                        }
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Site" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All Sites</SelectItem>
                            {sites.map((s) => (
                                <SelectItem key={s.id} value={String(s.id)}>
                                    {s.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {departments.length > 0 && (
                        <Select
                            value={filters.department || NONE}
                            onValueChange={(v) =>
                                applyFilter('department', v === NONE ? null : v)
                            }
                        >
                            <SelectTrigger className="w-44">
                                <SelectValue placeholder="Department" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>
                                    All Departments
                                </SelectItem>
                                {departments.map((d) => (
                                    <SelectItem key={d.id} value={String(d.id)}>
                                        {d.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}

                    <Select
                        value={filters.employment_type || NONE}
                        onValueChange={(v) =>
                            applyFilter(
                                'employment_type',
                                v === NONE ? null : v,
                            )
                        }
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All Types</SelectItem>
                            <SelectItem value="full_time">Full Time</SelectItem>
                            <SelectItem value="part_time">Part Time</SelectItem>
                            <SelectItem value="casual">Casual</SelectItem>
                            <SelectItem value="fixed_term">
                                Fixed Term
                            </SelectItem>
                            <SelectItem value="contractor">
                                Contractor
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={clearFilters}
                            className="gap-1.5 text-muted-foreground"
                        >
                            <X className="h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                            Employee
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase lg:table-cell">
                                            Employee #
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                            Position
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase md:table-cell">
                                            Department
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:table-cell">
                                            Type
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase xl:table-cell">
                                            Site
                                        </th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase xl:table-cell">
                                            Start Date
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {profiles.data.map((p) => (
                                        <tr
                                            key={p.id}
                                            className="group cursor-pointer transition-colors hover:bg-muted/40"
                                            onClick={() => {
                                                if (p.profile_id)
                                                    router.visit(
                                                        `/hr/people/${p.profile_id}`,
                                                    );
                                            }}
                                        >
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-3">
                                                    <div
                                                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${getAvatarColor(p.id)}`}
                                                    >
                                                        {getInitials(
                                                            p.user.name,
                                                        )}
                                                    </div>
                                                    <div className="min-w-0">
                                                        {p.profile_id ? (
                                                            <Link
                                                                href={`/hr/people/${p.profile_id}`}
                                                                className="font-medium text-foreground group-hover:text-primary"
                                                                onClick={(e) =>
                                                                    e.stopPropagation()
                                                                }
                                                            >
                                                                {p.user.name}
                                                            </Link>
                                                        ) : (
                                                            <span className="font-medium">
                                                                {p.user.name}
                                                            </span>
                                                        )}
                                                        <div className="truncate text-xs text-muted-foreground">
                                                            {p.user.email}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="hidden px-4 py-3 font-mono text-xs text-muted-foreground lg:table-cell">
                                                {p.employee_number || '\u2014'}
                                            </td>
                                            <td className="px-4 py-3 text-sm">
                                                {p.position_title || '\u2014'}
                                            </td>
                                            <td className="hidden px-4 py-3 text-sm text-muted-foreground md:table-cell">
                                                {p.department || '\u2014'}
                                            </td>
                                            <td className="hidden px-4 py-3 sm:table-cell">
                                                {p.employment_type ? (
                                                    <Badge
                                                        variant="outline"
                                                        className={`text-[11px] ${TYPE_STYLES[p.employment_type] || ''}`}
                                                    >
                                                        {formatLabel(
                                                            p.employment_type,
                                                        )}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        {'\u2014'}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="hidden px-4 py-3 text-sm text-muted-foreground xl:table-cell">
                                                {p.primary_site?.name ||
                                                    '\u2014'}
                                            </td>
                                            <td className="hidden px-4 py-3 text-sm text-muted-foreground xl:table-cell">
                                                {formatDate(p.start_date)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        p.is_active
                                                            ? 'border-status-success/30 bg-status-success-bg text-status-success dark:border-status-success/30 dark:bg-status-success-bg dark:text-status-success'
                                                            : 'dark:bg-muted-foreground/80/10 border-border bg-muted text-muted-foreground dark:border-border/30 dark:text-muted-foreground'
                                                    }
                                                >
                                                    {p.is_active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                    {profiles.data.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={8}
                                                className="px-4 py-16 text-center"
                                            >
                                                <Users className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                                <p className="font-medium text-muted-foreground">
                                                    No employees found
                                                </p>
                                                <p className="mt-1 text-sm text-muted-foreground/70">
                                                    {hasFilters
                                                        ? 'Try adjusting your filters'
                                                        : 'Add employees to get started'}
                                                </p>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {profiles.last_page > 1 && (
                    <LaravelPagination links={profiles.links} />
                )}
            </div>
        </AppLayout>
    );
}
