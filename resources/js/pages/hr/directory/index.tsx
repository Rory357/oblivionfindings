import { PageHero, PageLayout } from '@/components/page';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
    Grid3X3,
    LayoutList,
    Mail,
    MapPin,
    Phone,
    Search,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

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

interface SiteOption {
    id: number;
    name: string;
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
    departments: Array<{ id: number; name: string }>;
    sites: SiteOption[];
    filters: {
        q: string;
        department: string | null;
        site: string | null;
    };
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const breadcrumbs: BreadcrumbItem[] = [
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

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function DirectoryIndex({
    employees,
    departments,
    sites,
    filters,
}: Props) {
    const [searchValue, setSearchValue] = useState(filters.q ?? '');
    const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');

    const hasFilters = !!(filters.q || filters.department || filters.site);

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
        router.get('/hr/directory', newFilters, {
            preserveState: true,
            replace: true,
        });
    }

    function clearAll() {
        setSearchValue('');
        router.get('/hr/directory', {}, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employee Directory" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Users}
                        title="Employee Directory"
                        description={`${employees.total} team member${employees.total !== 1 ? 's' : ''} across the organisation.`}
                        stats={[
                            { label: 'Total', value: employees.total },
                            { label: 'Departments', value: departments.length },
                            { label: 'Sites', value: sites.length },
                        ]}
                        actions={
                            <div className="flex items-center gap-1 rounded-lg border border-primary-foreground/15 bg-primary-foreground/10 p-0.5 backdrop-blur-sm">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() => setViewMode('grid')}
                                    className={`h-auto w-auto rounded-md p-1.5 ${
                                        viewMode === 'grid'
                                            ? 'bg-primary-foreground/20 text-primary-foreground'
                                            : 'text-primary-foreground/70 hover:text-primary-foreground'
                                    }`}
                                    title="Grid view"
                                >
                                    <Grid3X3 className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() => setViewMode('list')}
                                    className={`h-auto w-auto rounded-md p-1.5 ${
                                        viewMode === 'list'
                                            ? 'bg-primary-foreground/20 text-primary-foreground'
                                            : 'text-primary-foreground/70 hover:text-primary-foreground'
                                    }`}
                                    title="List view"
                                >
                                    <LayoutList className="h-4 w-4" />
                                </Button>
                            </div>
                        }
                    />
                }
            >
                {/* Search & Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <form
                        onSubmit={handleSearch}
                        className="relative min-w-[250px] flex-1"
                    >
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search by name, position, or email..."
                            value={searchValue}
                            onChange={(e) => setSearchValue(e.target.value)}
                            className="pr-9 pl-9"
                            onKeyDown={(e) =>
                                e.key === 'Enter' && handleSearch(e)
                            }
                        />
                        {searchValue && (
                            <Button
                                type="button"
                                onClick={() => {
                                    setSearchValue('');
                                    updateFilter('q', null);
                                }}
                                variant="ghost"
                                size="icon"
                                className="absolute top-1/2 right-2 h-7 w-7 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                            >
                                <X className="h-3.5 w-3.5" />
                            </Button>
                        )}
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

                    {sites.length > 0 && (
                        <Select
                            value={filters.site ?? 'all'}
                            onValueChange={(v) =>
                                updateFilter('site', v === 'all' ? null : v)
                            }
                        >
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All Sites" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Sites</SelectItem>
                                {sites.map((site) => (
                                    <SelectItem
                                        key={site.id}
                                        value={String(site.id)}
                                    >
                                        {site.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={clearAll}
                            className="text-muted-foreground"
                        >
                            <X className="mr-1 h-3 w-3" />
                            Clear
                        </Button>
                    )}
                </div>

                {/* Active filter badges */}
                {hasFilters && (
                    <div className="flex flex-wrap gap-2">
                        {filters.q && (
                            <Badge variant="secondary" className="gap-1">
                                Search: "{filters.q}"
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-auto p-0 hover:bg-transparent"
                                    onClick={() => {
                                        setSearchValue('');
                                        updateFilter('q', null);
                                    }}
                                >
                                    <X className="h-3 w-3" />
                                </Button>
                            </Badge>
                        )}
                        {filters.department && (
                            <Badge variant="secondary" className="gap-1">
                                Dept: {filters.department}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-auto p-0 hover:bg-transparent"
                                    onClick={() =>
                                        updateFilter('department', null)
                                    }
                                >
                                    <X className="h-3 w-3" />
                                </Button>
                            </Badge>
                        )}
                        {filters.site && (
                            <Badge variant="secondary" className="gap-1">
                                Site:{' '}
                                {sites.find(
                                    (s) => String(s.id) === filters.site,
                                )?.name ?? filters.site}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-auto p-0 hover:bg-transparent"
                                    onClick={() => updateFilter('site', null)}
                                >
                                    <X className="h-3 w-3" />
                                </Button>
                            </Badge>
                        )}
                    </div>
                )}

                {/* Results */}
                {employees.data.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-16">
                            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                                <Users className="h-8 w-8 text-muted-foreground/40" />
                            </div>
                            <div className="text-center">
                                <p className="text-lg font-medium">
                                    No employees found
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {hasFilters
                                        ? 'Try adjusting your search or filters'
                                        : 'Employees will appear here once profiles are created'}
                                </p>
                            </div>
                            {hasFilters && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={clearAll}
                                >
                                    Clear all filters
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : viewMode === 'grid' ? (
                    /* ---- GRID VIEW ---- */
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {employees.data.map((emp) => (
                            <Link
                                key={emp.id}
                                href={`/hr/directory/${emp.id}`}
                                className="group block"
                            >
                                <Card className="h-full overflow-hidden transition-all group-hover:-translate-y-0.5 group-hover:border-primary/40 group-hover:shadow-lg">
                                    {/* Coloured header strip */}
                                    <div className="h-16 bg-gradient-to-r from-primary/20 via-primary/10 to-transparent" />

                                    <CardContent className="-mt-10 flex flex-col items-center px-5 pb-5 text-center">
                                        {/* Avatar */}
                                        <Avatar className="h-20 w-20 border-4 border-background shadow-md">
                                            <AvatarImage
                                                src={
                                                    emp.profile_photo_path
                                                        ? `/storage/${emp.profile_photo_path}`
                                                        : undefined
                                                }
                                            />
                                            <AvatarFallback
                                                className={`text-xl font-bold ${getAvatarColor(emp.id)}`}
                                            >
                                                {getInitials(emp.name)}
                                            </AvatarFallback>
                                        </Avatar>

                                        {/* Info */}
                                        <h3 className="mt-3 text-sm font-semibold transition-colors group-hover:text-primary">
                                            {emp.name}
                                        </h3>
                                        {emp.position_title && (
                                            <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                                {emp.position_title}
                                            </p>
                                        )}

                                        {/* Tags */}
                                        <div className="mt-2.5 flex flex-wrap items-center justify-center gap-1.5">
                                            {emp.department && (
                                                <Badge
                                                    variant="secondary"
                                                    className="px-2 py-0 text-[10px]"
                                                >
                                                    {emp.department}
                                                </Badge>
                                            )}
                                            {emp.site && (
                                                <Badge
                                                    variant="outline"
                                                    className="gap-0.5 px-2 py-0 text-[10px]"
                                                >
                                                    <MapPin className="h-2.5 w-2.5" />
                                                    {emp.site}
                                                </Badge>
                                            )}
                                        </div>

                                        {/* Contact icons */}
                                        <div className="mt-3 flex items-center gap-3">
                                            {emp.email && (
                                                <span
                                                    className="flex h-8 w-8 items-center justify-center rounded-full bg-muted/60 text-muted-foreground transition-colors group-hover:bg-primary/10 group-hover:text-primary"
                                                    title={emp.email}
                                                >
                                                    <Mail className="h-3.5 w-3.5" />
                                                </span>
                                            )}
                                            {emp.phone && (
                                                <span
                                                    className="flex h-8 w-8 items-center justify-center rounded-full bg-muted/60 text-muted-foreground transition-colors group-hover:bg-primary/10 group-hover:text-primary"
                                                    title={emp.phone}
                                                >
                                                    <Phone className="h-3.5 w-3.5" />
                                                </span>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </Link>
                        ))}
                    </div>
                ) : (
                    /* ---- LIST VIEW ---- */
                    <Card>
                        <CardContent className="divide-y p-0">
                            {employees.data.map((emp) => (
                                <Link
                                    key={emp.id}
                                    href={`/hr/directory/${emp.id}`}
                                    className="flex items-center gap-4 p-4 transition-colors hover:bg-muted/30"
                                >
                                    <Avatar className="h-12 w-12 shrink-0">
                                        <AvatarImage
                                            src={
                                                emp.profile_photo_path
                                                    ? `/storage/${emp.profile_photo_path}`
                                                    : undefined
                                            }
                                        />
                                        <AvatarFallback
                                            className={`font-semibold ${getAvatarColor(emp.id)}`}
                                        >
                                            {getInitials(emp.name)}
                                        </AvatarFallback>
                                    </Avatar>

                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold">
                                            {emp.name}
                                        </p>
                                        {emp.position_title && (
                                            <p className="text-xs text-muted-foreground">
                                                {emp.position_title}
                                            </p>
                                        )}
                                    </div>

                                    <div className="hidden items-center gap-4 sm:flex">
                                        {emp.department && (
                                            <Badge
                                                variant="secondary"
                                                className="text-[10px]"
                                            >
                                                {emp.department}
                                            </Badge>
                                        )}
                                        {emp.site && (
                                            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                <MapPin className="h-3 w-3" />
                                                {emp.site}
                                            </span>
                                        )}
                                    </div>

                                    <div className="hidden items-center gap-3 text-xs text-muted-foreground md:flex">
                                        {emp.email && (
                                            <span className="flex items-center gap-1">
                                                <Mail className="h-3 w-3" />
                                                <span className="max-w-[180px] truncate">
                                                    {emp.email}
                                                </span>
                                            </span>
                                        )}
                                        {emp.phone && (
                                            <span className="flex items-center gap-1">
                                                <Phone className="h-3 w-3" />
                                                {emp.phone}
                                            </span>
                                        )}
                                    </div>
                                </Link>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {/* Pagination + Count */}
                {employees.total > 0 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(employees.current_page - 1) * employees.per_page +
                                1}{' '}
                            to{' '}
                            {Math.min(
                                employees.current_page * employees.per_page,
                                employees.total,
                            )}{' '}
                            of {employees.total} employee
                            {employees.total !== 1 ? 's' : ''}
                        </p>
                        {employees.last_page > 1 && (
                            <LaravelPagination links={employees.links} />
                        )}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
