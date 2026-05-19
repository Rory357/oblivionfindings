import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Briefcase, Plus, Search, Users } from 'lucide-react';
import { useState } from 'react';

type Position = {
    id: number;
    title: string;
    code: string;
    department: string | null;
    team: string | null;
    employment_type: string;
    fte: number;
    headcount_budget: number;
    current_headcount: number;
    vacancies: number;
    is_active: boolean;
};

type PaginatedPositions = {
    data: Position[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

type Props = {
    positions: PaginatedPositions;
    departments: Array<{ id: number; name: string }>;
    filters: {
        q?: string;
        department?: string;
        status?: string;
    };
    can: {
        manage?: boolean;
    };
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Positions', href: '/hr/positions' },
];

const employmentTypeLabels: Record<string, string> = {
    full_time: 'Full Time',
    part_time: 'Part Time',
    casual: 'Casual',
    fixed_term: 'Fixed Term',
};

export default function PositionsIndex({
    positions,
    departments,
    filters,
    can,
}: Props) {
    const [searchValue, setSearchValue] = useState(filters.q ?? '');

    const updateFilter = (key: string, value: string | null) => {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all') {
            delete newFilters[key as keyof typeof newFilters];
        }
        router.get('/hr/positions', newFilters, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        updateFilter('q', searchValue || null);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Positions" />

            <PageShell>
                <PageHero
                    icon={Briefcase}
                    title="Positions"
                    description="Manage job positions, headcount budgets and organisational structure."
                    stats={[
                        { label: 'Positions', value: positions.total },
                        {
                            label: 'Vacancies',
                            value: positions.data.reduce((sum, p) => sum + p.vacancies, 0),
                        },
                        {
                            label: 'Headcount',
                            value: positions.data.reduce(
                                (sum, p) => sum + p.current_headcount,
                                0,
                            ),
                        },
                    ]}
                    actions={
                        can.manage ? (
                            <Button asChild>
                                <Link href="/hr/positions/create">
                                    <Plus className="mr-2 h-4 w-4" />
                                    New Position
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {/* Filters */}
                <Card className="flex flex-wrap items-center gap-2 p-3">
                    <form
                        onSubmit={handleSearch}
                        className="flex items-center gap-2"
                    >
                        <div className="relative">
                            <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search positions..."
                                value={searchValue}
                                onChange={(e) => setSearchValue(e.target.value)}
                                className="w-[200px] pl-8"
                            />
                        </div>
                        <Button type="submit" variant="outline" size="sm">
                            Search
                        </Button>
                    </form>

                    <Select
                        value={filters.department ?? 'all'}
                        onValueChange={(v) =>
                            updateFilter('department', v === 'all' ? null : v)
                        }
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All Departments" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Departments</SelectItem>
                            {departments.map((dept) => (
                                <SelectItem
                                    key={dept.id}
                                    value={String(dept.id)}
                                >
                                    {dept.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(v) =>
                            updateFilter('status', v === 'all' ? null : v)
                        }
                    >
                        <SelectTrigger className="w-[140px]">
                            <SelectValue placeholder="All Statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>

                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setSearchValue('');
                            router.get(
                                '/hr/positions',
                                {},
                                { preserveState: true },
                            );
                        }}
                    >
                        Clear Filters
                    </Button>
                </Card>

                {/* Positions Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Briefcase className="h-5 w-5" />
                            All Positions ({positions.total})
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {positions.data.length === 0 ? (
                            <div className="py-12 text-center text-muted-foreground">
                                <Briefcase className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>No positions found.</p>
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/5">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Title
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Code
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Department
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Type
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                FTE
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Headcount
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Vacancies
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Status
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {positions.data.map((position) => (
                                            <tr
                                                key={position.id}
                                                className="border-b last:border-b-0 hover:bg-muted/50"
                                            >
                                                <td className="px-4 py-3 font-medium">
                                                    {position.title}
                                                </td>
                                                <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                                    {position.code}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {position.department ?? '-'}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {employmentTypeLabels[
                                                        position.employment_type
                                                    ] ??
                                                        position.employment_type}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {position.fte}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-1">
                                                        <Users className="h-3.5 w-3.5 text-muted-foreground" />
                                                        <span>
                                                            {
                                                                position.current_headcount
                                                            }
                                                            /
                                                            {
                                                                position.headcount_budget
                                                            }
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    {position.vacancies > 0 ? (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-status-info/30 bg-status-info text-status-info"
                                                        >
                                                            {position.vacancies}{' '}
                                                            open
                                                        </Badge>
                                                    ) : (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-border/30 text-muted-foreground"
                                                        >
                                                            Filled
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {position.is_active ? (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-status-success/30 bg-status-success text-status-success"
                                                        >
                                                            Active
                                                        </Badge>
                                                    ) : (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-status-critical/30 bg-status-critical text-status-critical"
                                                        >
                                                            Inactive
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/hr/positions/${position.id}`}
                                                            >
                                                                View
                                                            </Link>
                                                        </Button>
                                                        {can.manage && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={`/hr/positions/${position.id}/edit`}
                                                                >
                                                                    Edit
                                                                </Link>
                                                            </Button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {positions.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(positions.current_page - 1) * positions.per_page +
                                1}{' '}
                            to{' '}
                            {Math.min(
                                positions.current_page * positions.per_page,
                                positions.total,
                            )}{' '}
                            of {positions.total} results
                        </p>
                        <LaravelPagination links={positions.links} />
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
