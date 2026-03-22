import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useState } from 'react';
import { Search, Mail, Phone, Users } from 'lucide-react';

interface Employee {
    id: number;
    name: string;
    full_name: string;
    email: string | null;
    phone: string | null;
    position_title: string | null;
    department: string | null;
    site: string | null;
    profile_photo_path: string | null;
    bio: string | null;
}

interface Props {
    employees: {
        data: Employee[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    departments: string[];
    filters: {
        q: string;
        department: string | null;
        site: string | null;
    };
}

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Directory', href: '/hr/directory' },
];

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

export default function DirectoryIndex({ employees, departments, filters }: Props) {
    const [searchValue, setSearchValue] = useState(filters.q ?? '');

    function handleSearch(e: React.FormEvent) {
        e.preventDefault();
        router.get(
            '/hr/directory',
            { ...filters, q: searchValue || undefined },
            { preserveState: true, replace: true },
        );
    }

    function updateFilter(key: string, value: string | null) {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all') {
            delete newFilters[key as keyof typeof newFilters];
        }
        router.get('/hr/directory', newFilters, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employee Directory" />

            <PageShell>
                <PageHeader
                    title="Employee Directory"
                    description="Find and connect with team members across the organisation."
                />

                {/* Search & Filters */}
                <div className="flex flex-wrap items-center gap-3 rounded-lg border bg-card p-3">
                    <form onSubmit={handleSearch} className="flex flex-1 items-center gap-2">
                        <div className="relative flex-1 min-w-[200px]">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Search by name..."
                                value={searchValue}
                                onChange={(e) => setSearchValue(e.target.value)}
                                className="pl-9"
                            />
                        </div>
                        <Button type="submit" variant="outline" size="sm">
                            Search
                        </Button>
                    </form>

                    <Select
                        value={filters.department ?? 'all'}
                        onValueChange={(v) => updateFilter('department', v === 'all' ? null : v)}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All Departments" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Departments</SelectItem>
                            {departments.map((dept) => (
                                <SelectItem key={dept} value={dept}>
                                    {dept}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setSearchValue('');
                            router.get('/hr/directory', {}, { preserveState: true });
                        }}
                    >
                        Clear
                    </Button>
                </div>

                {/* Employee Cards Grid */}
                {employees.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center">
                            <Users className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p className="text-muted-foreground">No employees found.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {employees.data.map((employee) => (
                            <Link
                                key={employee.id}
                                href={`/hr/directory/${employee.id}`}
                                className="block"
                            >
                                <Card className="h-full transition-colors hover:bg-muted/50">
                                    <CardContent className="flex flex-col items-center p-6 text-center">
                                        {/* Avatar */}
                                        <div className="mb-4">
                                            {employee.profile_photo_path ? (
                                                <img
                                                    src={`/storage/${employee.profile_photo_path}`}
                                                    alt={employee.name}
                                                    className="h-20 w-20 rounded-full object-cover"
                                                />
                                            ) : (
                                                <div className="flex h-20 w-20 items-center justify-center rounded-full bg-primary/10 text-xl font-semibold text-primary">
                                                    {getInitials(employee.name)}
                                                </div>
                                            )}
                                        </div>

                                        {/* Name & Position */}
                                        <h3 className="font-semibold">{employee.name}</h3>
                                        {employee.position_title && (
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {employee.position_title}
                                            </p>
                                        )}
                                        {employee.department && (
                                            <Badge variant="secondary" className="mt-2">
                                                {employee.department}
                                            </Badge>
                                        )}

                                        {/* Contact */}
                                        <div className="mt-4 space-y-1 text-xs text-muted-foreground">
                                            {employee.email && (
                                                <div className="flex items-center justify-center gap-1">
                                                    <Mail className="h-3 w-3" />
                                                    <span className="truncate">{employee.email}</span>
                                                </div>
                                            )}
                                            {employee.phone && (
                                                <div className="flex items-center justify-center gap-1">
                                                    <Phone className="h-3 w-3" />
                                                    <span>{employee.phone}</span>
                                                </div>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </Link>
                        ))}
                    </div>
                )}

                {/* Pagination */}
                {employees.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(employees.current_page - 1) * employees.per_page + 1} to{' '}
                            {Math.min(employees.current_page * employees.per_page, employees.total)} of{' '}
                            {employees.total} employees
                        </p>
                        <div className="flex items-center gap-1">
                            {employees.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url)}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
