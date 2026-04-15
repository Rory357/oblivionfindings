import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { useInitials } from '@/hooks/use-initials';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Plus, Search, X, Users, UserCheck, Briefcase, MoreVertical, Eye, Pencil, UserCog } from 'lucide-react';

type StaffUser = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    profile_photo_url?: string | null;
    role?: string | null;
    roles?: { id: number; name: string; label: string }[];
    staff_profile?: {
        phone?: string | null;
        job_title?: string | null;
        is_active?: boolean;
    } | null;
    assigned_clients_count?: number;
};

type Props = {
    users: {
        data: StaffUser[];
        links: any[];
        meta: any;
        last_page?: number;
    };
    filters: { q?: string };
};

/* ------------------------------------------------------------------ */
/*  Stat Card                                                          */
/* ------------------------------------------------------------------ */

const STAT_COLORS = {
    blue: { bg: 'bg-blue-50 dark:bg-blue-500/10', icon: 'text-blue-600 dark:text-blue-400', ring: 'ring-blue-100 dark:ring-blue-500/20' },
    emerald: { bg: 'bg-emerald-50 dark:bg-emerald-500/10', icon: 'text-emerald-600 dark:text-emerald-400', ring: 'ring-emerald-100 dark:ring-emerald-500/20' },
    amber: { bg: 'bg-amber-50 dark:bg-amber-500/10', icon: 'text-amber-600 dark:text-amber-400', ring: 'ring-amber-100 dark:ring-amber-500/20' },
};

function StatCard({ label, value, icon: Icon, color }: { label: string; value: number; icon: React.ElementType; color: keyof typeof STAT_COLORS }) {
    const c = STAT_COLORS[color];
    return (
        <div className={`relative flex items-center gap-4 rounded-xl p-4 ring-1 ${c.bg} ${c.ring} transition-shadow hover:shadow-md`}>
            <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${c.bg} ${c.icon}`}>
                <Icon className="h-5 w-5" />
            </div>
            <div className="min-w-0">
                <p className="text-2xl font-bold tracking-tight">{value}</p>
                <p className="truncate text-xs font-medium text-muted-foreground">{label}</p>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function StaffIndex({ users, filters }: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;
    const getInitials = useInitials();

    const hasFilters = !!filters?.q;
    const data = users.data;
    const activeCount = data.filter((u) => u.staff_profile?.is_active !== false).length;
    const withClients = data.filter((u) => (u.assigned_clients_count ?? 0) > 0).length;

    return (
        <AppLayout breadcrumbs={[{ title: 'Staff', href: '/staff' }]}>
            <Head title="Staff" />

            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Staff</h1>
                        <p className="text-sm text-muted-foreground">
                            Manage staff profiles, assignments, availability and access
                        </p>
                    </div>
                    {can?.staff?.create && (
                        <Link href="/system/users/create?type=staff">
                            <Button size="sm">
                                <Plus className="mr-1.5 h-4 w-4" />
                                Add staff
                            </Button>
                        </Link>
                    )}
                </div>

                {/* Stats */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
                    <StatCard label="Total Staff" value={data.length} icon={Users} color="blue" />
                    <StatCard label="Active" value={activeCount} icon={UserCheck} color="emerald" />
                    <StatCard label="With Assignments" value={withClients} icon={Briefcase} color="amber" />
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            defaultValue={filters?.q ?? ''}
                            placeholder="Search name or email..."
                            className="w-64 pl-9"
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    router.get('/staff', { q: (e.target as HTMLInputElement).value }, { preserveState: true, replace: true });
                                }
                            }}
                        />
                    </div>
                    {hasFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.get('/staff', {}, { preserveState: true, replace: true })}
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
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Name</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">Email</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Role(s)</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground lg:table-cell">Assigned Clients</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {data.map((u) => (
                                        <tr
                                            key={u.id}
                                            className="group cursor-pointer transition-colors hover:bg-muted/40"
                                            onClick={() => router.visit(`/staff/${u.id}`)}
                                        >
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-3">
                                                    <Avatar className="h-9 w-9">
                                                        <AvatarImage src={u.avatar ?? u.profile_photo_url ?? undefined} alt={u.name} />
                                                        <AvatarFallback className="text-xs font-semibold">{getInitials(u.name)}</AvatarFallback>
                                                    </Avatar>
                                                    <div className="min-w-0">
                                                        <Link
                                                            href={`/staff/${u.id}`}
                                                            className="font-medium text-foreground group-hover:text-primary"
                                                            onClick={(e) => e.stopPropagation()}
                                                        >
                                                            {u.name}
                                                        </Link>
                                                        {u.staff_profile?.job_title && (
                                                            <div className="truncate text-xs text-muted-foreground">
                                                                {u.staff_profile.job_title}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">{u.email}</td>
                                            <td className="hidden px-4 py-3 sm:table-cell">
                                                {u.roles?.length
                                                    ? u.roles.map((r) => (
                                                        <Badge key={r.id} variant="outline" className="mr-1 text-[11px]">
                                                            {r.label}
                                                        </Badge>
                                                    ))
                                                    : <span className="text-muted-foreground">{u.role ?? '—'}</span>
                                                }
                                            </td>
                                            <td className="hidden px-4 py-3 text-muted-foreground lg:table-cell">
                                                {u.assigned_clients_count ?? 0}
                                            </td>
                                            <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <button className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors">
                                                            <MoreVertical className="h-4 w-4" />
                                                        </button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end" className="w-48">
                                                        <DropdownMenuItem onClick={() => router.visit(`/staff/${u.id}`)}>
                                                            <Eye className="mr-2 h-4 w-4" />
                                                            View profile
                                                        </DropdownMenuItem>
                                                        {can?.staff?.update && (
                                                            <DropdownMenuItem onClick={() => router.visit(`/staff/${u.id}/edit`)}>
                                                                <Pencil className="mr-2 h-4 w-4" />
                                                                Edit
                                                            </DropdownMenuItem>
                                                        )}
                                                        {can?.staff?.assignmentsUpdate && (
                                                            <DropdownMenuItem onClick={() => router.visit(`/staff/${u.id}/assignments`)}>
                                                                <UserCog className="mr-2 h-4 w-4" />
                                                                Assignments
                                                            </DropdownMenuItem>
                                                        )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </td>
                                        </tr>
                                    ))}

                                    {data.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="px-4 py-16 text-center">
                                                <Users className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                                <p className="font-medium text-muted-foreground">No staff found</p>
                                                <p className="mt-1 text-sm text-muted-foreground/70">
                                                    {hasFilters ? 'Try adjusting your search' : 'Add staff members to get started'}
                                                </p>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {(users.last_page ?? 1) > 1 && (
                    <LaravelPagination links={users.links} />
                )}
            </div>
        </AppLayout>
    );
}
