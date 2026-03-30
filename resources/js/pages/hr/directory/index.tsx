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
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
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
    departments: string[];
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
    'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    'bg-violet-500/15 text-violet-700 dark:text-violet-300',
    'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    'bg-pink-500/15 text-pink-700 dark:text-pink-300',
    'bg-cyan-500/15 text-cyan-700 dark:text-cyan-300',
    'bg-rose-500/15 text-rose-700 dark:text-rose-300',
    'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
];

function getAvatarColor(id: number): string {
    return AVATAR_COLORS[id % AVATAR_COLORS.length];
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function DirectoryIndex({ employees, departments, sites, filters }: Props) {
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
        router.get('/hr/directory', newFilters, { preserveState: true, replace: true });
    }

    function clearAll() {
        setSearchValue('');
        router.get('/hr/directory', {}, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employee Directory" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                            <Users className="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h1 className="text-xl font-bold md:text-2xl">Employee Directory</h1>
                            <p className="text-sm text-muted-foreground">
                                {employees.total} team member{employees.total !== 1 ? 's' : ''} across the organisation
                            </p>
                        </div>
                    </div>

                    {/* View toggle */}
                    <div className="flex items-center gap-1 rounded-lg border bg-muted/30 p-0.5">
                        <button
                            onClick={() => setViewMode('grid')}
                            className={`rounded-md p-1.5 transition-all ${
                                viewMode === 'grid'
                                    ? 'bg-background shadow-sm text-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                            title="Grid view"
                        >
                            <Grid3X3 className="h-4 w-4" />
                        </button>
                        <button
                            onClick={() => setViewMode('list')}
                            className={`rounded-md p-1.5 transition-all ${
                                viewMode === 'list'
                                    ? 'bg-background shadow-sm text-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                            title="List view"
                        >
                            <LayoutList className="h-4 w-4" />
                        </button>
                    </div>
                </div>

                {/* Search & Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={handleSearch} className="relative flex-1 min-w-[250px]">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search by name, position, or email..."
                            value={searchValue}
                            onChange={(e) => setSearchValue(e.target.value)}
                            className="pl-9 pr-9"
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch(e)}
                        />
                        {searchValue && (
                            <button
                                type="button"
                                onClick={() => { setSearchValue(''); updateFilter('q', null); }}
                                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                            >
                                <X className="h-3.5 w-3.5" />
                            </button>
                        )}
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
                                <SelectItem key={dept} value={dept}>{dept}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {sites.length > 0 && (
                        <Select
                            value={filters.site ?? 'all'}
                            onValueChange={(v) => updateFilter('site', v === 'all' ? null : v)}
                        >
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All Sites" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Sites</SelectItem>
                                {sites.map((site) => (
                                    <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={clearAll} className="text-muted-foreground">
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
                                <button onClick={() => { setSearchValue(''); updateFilter('q', null); }}>
                                    <X className="h-3 w-3" />
                                </button>
                            </Badge>
                        )}
                        {filters.department && (
                            <Badge variant="secondary" className="gap-1">
                                Dept: {filters.department}
                                <button onClick={() => updateFilter('department', null)}>
                                    <X className="h-3 w-3" />
                                </button>
                            </Badge>
                        )}
                        {filters.site && (
                            <Badge variant="secondary" className="gap-1">
                                Site: {sites.find((s) => String(s.id) === filters.site)?.name ?? filters.site}
                                <button onClick={() => updateFilter('site', null)}>
                                    <X className="h-3 w-3" />
                                </button>
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
                                <p className="text-lg font-medium">No employees found</p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {hasFilters
                                        ? 'Try adjusting your search or filters'
                                        : 'Employees will appear here once profiles are created'}
                                </p>
                            </div>
                            {hasFilters && (
                                <Button variant="outline" size="sm" onClick={clearAll}>
                                    Clear all filters
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : viewMode === 'grid' ? (
                    /* ---- GRID VIEW ---- */
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {employees.data.map((emp) => (
                            <Link key={emp.id} href={`/hr/directory/${emp.id}`} className="group block">
                                <Card className="h-full overflow-hidden transition-all group-hover:shadow-lg group-hover:border-primary/40 group-hover:-translate-y-0.5">
                                    {/* Coloured header strip */}
                                    <div className="h-16 bg-gradient-to-r from-primary/20 via-primary/10 to-transparent" />

                                    <CardContent className="-mt-10 flex flex-col items-center px-5 pb-5 text-center">
                                        {/* Avatar */}
                                        <Avatar className="h-20 w-20 border-4 border-background shadow-md">
                                            <AvatarImage src={emp.profile_photo_path ? `/storage/${emp.profile_photo_path}` : undefined} />
                                            <AvatarFallback className={`text-xl font-bold ${getAvatarColor(emp.id)}`}>
                                                {getInitials(emp.name)}
                                            </AvatarFallback>
                                        </Avatar>

                                        {/* Info */}
                                        <h3 className="mt-3 font-semibold text-sm group-hover:text-primary transition-colors">
                                            {emp.name}
                                        </h3>
                                        {emp.position_title && (
                                            <p className="mt-0.5 text-xs text-muted-foreground line-clamp-1">
                                                {emp.position_title}
                                            </p>
                                        )}

                                        {/* Tags */}
                                        <div className="mt-2.5 flex flex-wrap items-center justify-center gap-1.5">
                                            {emp.department && (
                                                <Badge variant="secondary" className="text-[10px] px-2 py-0">
                                                    {emp.department}
                                                </Badge>
                                            )}
                                            {emp.site && (
                                                <Badge variant="outline" className="text-[10px] px-2 py-0 gap-0.5">
                                                    <MapPin className="h-2.5 w-2.5" />
                                                    {emp.site}
                                                </Badge>
                                            )}
                                        </div>

                                        {/* Contact icons */}
                                        <div className="mt-3 flex items-center gap-3">
                                            {emp.email && (
                                                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-muted/60 text-muted-foreground transition-colors group-hover:bg-primary/10 group-hover:text-primary" title={emp.email}>
                                                    <Mail className="h-3.5 w-3.5" />
                                                </span>
                                            )}
                                            {emp.phone && (
                                                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-muted/60 text-muted-foreground transition-colors group-hover:bg-primary/10 group-hover:text-primary" title={emp.phone}>
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
                        <CardContent className="p-0 divide-y">
                            {employees.data.map((emp) => (
                                <Link
                                    key={emp.id}
                                    href={`/hr/directory/${emp.id}`}
                                    className="flex items-center gap-4 p-4 transition-colors hover:bg-muted/30"
                                >
                                    <Avatar className="h-12 w-12 shrink-0">
                                        <AvatarImage src={emp.profile_photo_path ? `/storage/${emp.profile_photo_path}` : undefined} />
                                        <AvatarFallback className={`font-semibold ${getAvatarColor(emp.id)}`}>
                                            {getInitials(emp.name)}
                                        </AvatarFallback>
                                    </Avatar>

                                    <div className="min-w-0 flex-1">
                                        <p className="font-semibold text-sm">{emp.name}</p>
                                        {emp.position_title && (
                                            <p className="text-xs text-muted-foreground">{emp.position_title}</p>
                                        )}
                                    </div>

                                    <div className="hidden sm:flex items-center gap-4">
                                        {emp.department && (
                                            <Badge variant="secondary" className="text-[10px]">{emp.department}</Badge>
                                        )}
                                        {emp.site && (
                                            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                <MapPin className="h-3 w-3" />
                                                {emp.site}
                                            </span>
                                        )}
                                    </div>

                                    <div className="hidden md:flex items-center gap-3 text-xs text-muted-foreground">
                                        {emp.email && (
                                            <span className="flex items-center gap-1">
                                                <Mail className="h-3 w-3" />
                                                <span className="max-w-[180px] truncate">{emp.email}</span>
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
                            Showing {(employees.current_page - 1) * employees.per_page + 1} to{' '}
                            {Math.min(employees.current_page * employees.per_page, employees.total)} of{' '}
                            {employees.total} employee{employees.total !== 1 ? 's' : ''}
                        </p>
                        {employees.last_page > 1 && <LaravelPagination links={employees.links} />}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
