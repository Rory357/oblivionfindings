import { OpsStatCard } from '@/components/ops-stat-card';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
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
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CheckCircle,
    Clock,
    Download,
    MoreHorizontal,
    Pencil,
    Search,
    Shield,
    ShieldCheck,
    ShieldAlert,
    UserCog,
    UserMinus,
    UserPlus,
    Users,
    Users2,
} from 'lucide-react';
import { useState } from 'react';

const ANY = '__ANY__';

type Role = { id: number; name?: string; label: string; level?: number; type?: string };

type UserItem = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    is_active: boolean;
    approved_at?: string | null;
    created_at?: string;
    roles: { id: number; label: string; level?: number }[];
    user_type: string;
    staff_profile?: { job_title?: string | null; status?: string | null } | null;
    last_login_at?: string | null;
    last_login_ip?: string | null;
    login_count?: number;
    two_factor_confirmed_at?: string | null;
    session_count?: number;
};

type Props = {
    users: {
        data: UserItem[];
        links: any[];
        total: number;
        current_page?: number;
        last_page?: number;
    };
    roles: Role[];
    filters: {
        search?: string;
        status?: string;
        role?: string;
        type?: string;
        has_2fa?: string;
        activity?: string;
    };
    stats: {
        total: number;
        active: number;
        pending: number;
        staff: number;
    };
};

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function relativeTime(dateStr?: string | null): string {
    if (!dateStr) return 'Never';
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

function statusBadge(user: UserItem) {
    if (!user.approved_at) {
        return <Badge variant="outline" className="border-status-warning/30 bg-status-warning-bg text-status-warning">Pending</Badge>;
    }
    if (user.staff_profile?.status === 'suspended') {
        return <Badge variant="outline" className="border-status-critical/30 bg-status-critical-bg text-status-critical">Suspended</Badge>;
    }
    return <Badge variant="outline" className="border-status-success/30 bg-status-success-bg text-status-success">Active</Badge>;
}

function userTypeBadge(type: string) {
    const map: Record<string, { label: string; className: string }> = {
        staff: { label: 'Staff', className: 'border-primary bg-primary/10 text-primary' },
        client: { label: 'Client', className: 'border-status-info/30 bg-status-info-bg text-status-info' },
        next_of_kin: { label: 'Whanau', className: 'border-status-info/30 bg-status-info-bg text-status-info' },
        board: { label: 'Board', className: 'border-status-warning/30 bg-status-warning-bg text-status-warning' },
        user: { label: 'User', className: 'border-border bg-muted text-foreground' },
    };
    const info = map[type] ?? map.user;
    return <Badge variant="outline" className={info!.className}>{info!.label}</Badge>;
}

export default function UsersIndex({
    users = { data: [], links: [], total: 0 },
    roles = [],
    filters = {},
    stats = { total: 0, active: 0, pending: 0, staff: 0 },
}: Props) {
    const page = usePage<SharedData>();
    const [search, setSearch] = useState(filters.search ?? '');
    const [statusFilter, setStatusFilter] = useState(filters.status ?? 'all');
    const [roleFilter, setRoleFilter] = useState(filters.role ?? 'all');
    const [twoFaFilter, setTwoFaFilter] = useState(filters.has_2fa ?? 'all');
    const [activityFilter, setActivityFilter] = useState(filters.activity ?? 'all');
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const { auth } = page.props;
    const canImpersonate = (auth.can as any)?.settings?.impersonate;
    const currentUserId = auth.user.id;
    const usersBasePath = '/system/users';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'System Users', href: usersBasePath },
    ];

    const allData = users?.data ?? [];

    function applyFilters(overrides: Record<string, string> = {}) {
        router.get(
            usersBasePath,
            {
                search: overrides.search ?? search,
                status: overrides.status ?? statusFilter,
                role: overrides.role ?? roleFilter,
                has_2fa: overrides.has_2fa ?? twoFaFilter,
                activity: overrides.activity ?? activityFilter,
            },
            { preserveState: true, preserveScroll: true },
        );
    }

    function handleExportCsv() {
        const headers = ['Name', 'Email', 'Type', 'Status', 'Roles', 'Last Login', 'Sessions', '2FA', 'Created'];
        const rows = allData.map((u) => [
            u.name,
            u.email,
            u.user_type,
            u.is_active ? 'Active' : 'Inactive',
            (u.roles ?? []).map((r) => r.label).join('; '),
            u.last_login_at ? new Date(u.last_login_at).toLocaleDateString('en-NZ') : 'Never',
            u.session_count ?? 0,
            u.two_factor_confirmed_at ? 'Yes' : 'No',
            u.created_at ? new Date(u.created_at).toLocaleDateString('en-NZ') : '',
        ]);
        const csv = [headers, ...rows].map((row) => row.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `users-export-${new Date().toISOString().slice(0, 10)}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    }

    function handleSearch(e: React.FormEvent) {
        e.preventDefault();
        applyFilters();
    }

    function toggleSelect(id: number) {
        setSelectedIds((prev) =>
            prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id],
        );
    }

    function toggleAll() {
        const selectableIds = allData
            .filter((user) => user.id !== currentUserId)
            .map((user) => user.id);

        if (selectedIds.length === selectableIds.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(selectableIds);
        }
    }

    function handleImpersonate(userId: number) {
        router.post(`/system/users/${userId}/impersonate`);
    }

    function handleSuspend(userId: number) {
        if (userId === currentUserId) {
            return;
        }

        router.post(`${usersBasePath}/${userId}/suspend`, {}, { preserveScroll: true });
    }

    function handleApprove(userId: number) {
        router.post(`${usersBasePath}/${userId}/approve`, {}, { preserveScroll: true });
    }

    const adminCount = allData.filter(
        (u) => u.roles?.some((r) => r.label?.toLowerCase().includes('admin')),
    ).length;

    const content = (
        <div className="space-y-6">
                    <PageHero variant="compact"
                        title="System Users"
                        description="Manage user accounts, roles, and access across your organisation"
                        actions={
                            <div className="flex items-center gap-2">
                                <Button variant="outline" onClick={handleExportCsv}>
                                    <Download className="mr-2 h-4 w-4" />
                                    Export CSV
                                </Button>
                                <Button asChild className="bg-primary hover:bg-primary" dusk="users-create-link">
                                    <Link href="/system/users/create">
                                            <UserPlus className="mr-2 h-4 w-4" />
                                            Create User
                                    </Link>
                                </Button>
                            </div>
                        }
                    />

                    {/* Stats Row */}
                    <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <OpsStatCard
                            label="Total Users"
                            value={stats.total}
                            icon={Users2}
                            color="violet"
                        />
                        <OpsStatCard
                            label="Active"
                            value={stats.active}
                            icon={CheckCircle}
                            color="emerald"
                        />
                        <OpsStatCard
                            label="Pending Approval"
                            value={stats.pending}
                            icon={Clock}
                            color="amber"
                        />
                        <OpsStatCard
                            label="Staff Members"
                            value={stats.staff}
                            icon={ShieldCheck}
                            color="blue"
                        />
                    </div>

                    {/* Filters */}
                    <Card>
                        <CardContent className="pt-0">
                            <form onSubmit={handleSearch} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div className="relative flex-1">
                                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        placeholder="Search users by name or email..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        className="pl-9"
                                    />
                                </div>
                                <Select
                                    value={roleFilter}
                                    onValueChange={(val) => {
                                        setRoleFilter(val);
                                        applyFilters({ role: val });
                                    }}
                                >
                                    <SelectTrigger className="w-[180px]">
                                        <SelectValue placeholder="All Roles" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Roles</SelectItem>
                                        {(roles ?? []).map((role) => (
                                            <SelectItem key={role.id} value={role.id.toString()}>
                                                {role.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Select
                                    value={statusFilter}
                                    onValueChange={(val) => {
                                        setStatusFilter(val);
                                        applyFilters({ status: val });
                                    }}
                                >
                                    <SelectTrigger className="w-[180px]">
                                        <SelectValue placeholder="All Statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Statuses</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="pending">Pending</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Select
                                    value={twoFaFilter}
                                    onValueChange={(val) => {
                                        setTwoFaFilter(val);
                                        applyFilters({ has_2fa: val });
                                    }}
                                >
                                    <SelectTrigger className="w-[140px]">
                                        <SelectValue placeholder="2FA" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All 2FA</SelectItem>
                                        <SelectItem value="yes">2FA Enabled</SelectItem>
                                        <SelectItem value="no">2FA Disabled</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Select
                                    value={activityFilter}
                                    onValueChange={(val) => {
                                        setActivityFilter(val);
                                        applyFilters({ activity: val });
                                    }}
                                >
                                    <SelectTrigger className="w-[180px]">
                                        <SelectValue placeholder="Activity" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Activity</SelectItem>
                                        <SelectItem value="today">Active Today</SelectItem>
                                        <SelectItem value="week">This Week</SelectItem>
                                        <SelectItem value="inactive">Inactive 30+ Days</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Button type="submit" variant="outline">
                                    <Search className="mr-2 h-4 w-4" />
                                    Search
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Bulk Actions */}
                    {selectedIds.length > 0 && (
                        <div className="flex items-center gap-3 rounded-lg border border-primary bg-primary/10 px-4 py-2">
                            <span className="text-sm font-medium text-primary">
                                {selectedIds.length} user{selectedIds.length !== 1 ? 's' : ''} selected
                            </span>
                            <div className="ml-auto flex gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        selectedIds.forEach((id) => handleApprove(id));
                                        setSelectedIds([]);
                                    }}
                                >
                                    <CheckCircle className="mr-1 h-3 w-3" />
                                    Activate
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-status-critical/30 text-status-critical hover:bg-status-critical-bg"
                                    onClick={() => {
                                        selectedIds.forEach((id) => handleSuspend(id));
                                        setSelectedIds([]);
                                    }}
                                >
                                    <UserMinus className="mr-1 h-3 w-3" />
                                    Deactivate
                                </Button>
                            </div>
                        </div>
                    )}

                    {/* Users Table */}
                    <Card>
                        <CardContent className="p-0">
                            {allData.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-16 text-center">
                                    <div className="mb-4 rounded-full bg-primary/10 p-4">
                                        <Users className="h-8 w-8 text-primary" />
                                    </div>
                                    <h3 className="text-lg font-semibold">No users found</h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Try adjusting your search or filters, or invite a new user.
                                    </p>
                                </div>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-10">
                                                <Checkbox
                                                    dusk="users-select-all"
                                                    checked={
                                                        allData.length > 0 &&
                                                        selectedIds.length === allData.filter((user) => user.id !== currentUserId).length
                                                    }
                                                    onCheckedChange={toggleAll}
                                                />
                                            </TableHead>
                                            <TableHead>User</TableHead>
                                            <TableHead>Role(s)</TableHead>
                                            <TableHead>Type</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Created</TableHead>
                                            <TableHead>Last Login</TableHead>
                                            <TableHead className="w-20">Sessions</TableHead>
                                            <TableHead className="w-10">2FA</TableHead>
                                            <TableHead className="w-14">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {allData.map((user) => (
                                            <TableRow
                                                key={user.id}
                                                className="cursor-pointer hover:bg-muted/50"
                                            >
                                                <TableCell onClick={(e) => e.stopPropagation()}>
                                                    <Checkbox
                                                        dusk={`user-select-${user.id}`}
                                                        checked={selectedIds.includes(user.id)}
                                                        disabled={user.id === currentUserId}
                                                        onCheckedChange={() => toggleSelect(user.id)}
                                                    />
                                            </TableCell>
                                                <TableCell>
                                                    <Link
                                                        href={`${usersBasePath}/${user.id}`}
                                                        className="flex items-center gap-3"
                                                    >
                                                        <Avatar className="h-8 w-8">
                                                            {user.avatar && <AvatarImage src={user.avatar} />}
                                                            <AvatarFallback className="bg-primary/10 text-xs text-primary">
                                                                {getInitials(user.name)}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <div>
                                                            <p className="font-medium">{user.name}</p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {user.email}
                                                            </p>
                                                        </div>
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap gap-1">
                                                        {(user.roles ?? []).map((role) => (
                                                            <Badge
                                                                key={role.id}
                                                                variant="secondary"
                                                                className="text-xs"
                                                            >
                                                                {role.label}
                                                            </Badge>
                                                        ))}
                                                        {(!user.roles || user.roles.length === 0) && (
                                                            <span className="text-xs text-muted-foreground">
                                                                No role
                                                            </span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>{userTypeBadge(user.user_type)}</TableCell>
                                                <TableCell>{statusBadge(user)}</TableCell>
                                                <TableCell>
                                                    <span className="text-sm text-muted-foreground">
                                                        {relativeTime(user.created_at)}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <span className="text-sm text-muted-foreground" title={user.last_login_at ? new Date(user.last_login_at).toLocaleString('en-NZ') : undefined}>
                                                        {relativeTime(user.last_login_at)}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="secondary" className="text-xs">
                                                        {user.session_count ?? 0}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {user.two_factor_confirmed_at ? (
                                                        <ShieldCheck className="h-4 w-4 text-status-success" />
                                                    ) : (
                                                        <ShieldAlert className="h-4 w-4 text-muted-foreground/30" />
                                                    )}
                                                </TableCell>
                                                <TableCell onClick={(e) => e.stopPropagation()}>
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                className="h-8 w-8"
                                                                dusk={`user-actions-${user.id}`}
                                                            >
                                                                <MoreHorizontal className="h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem asChild>
                                                                <Link href={`${usersBasePath}/${user.id}`}>
                                                                    <Pencil className="mr-2 h-4 w-4" />
                                                                    Edit
                                                                </Link>
                                                            </DropdownMenuItem>
                                                            {canImpersonate &&
                                                             user.id !== auth.user.id &&
                                                             !user.roles?.some((r) => r.label === 'Administrator') && (
                                                                <DropdownMenuItem onClick={() => handleImpersonate(user.id)}>
                                                                    <UserCog className="mr-2 h-4 w-4" />
                                                                    Impersonate
                                                                </DropdownMenuItem>
                                                            )}
                                                            {user.id !== currentUserId && (
                                                                <>
                                                                    <DropdownMenuSeparator />
                                                                    {user.is_active ? (
                                                                        <DropdownMenuItem
                                                                            dusk={`user-suspend-${user.id}`}
                                                                            className="text-status-critical focus:text-status-critical"
                                                                            onClick={() => handleSuspend(user.id)}
                                                                        >
                                                                            <UserMinus className="mr-2 h-4 w-4" />
                                                                            Suspend
                                                                        </DropdownMenuItem>
                                                                    ) : (
                                                                        <DropdownMenuItem
                                                                            dusk={`user-approve-${user.id}`}
                                                                            className="text-status-success focus:text-status-success"
                                                                            onClick={() => handleApprove(user.id)}
                                                                        >
                                                                            <CheckCircle className="mr-2 h-4 w-4" />
                                                                            Approve
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                </>
                                                            )}
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>

                    {/* Pagination */}
                    {(users?.links ?? []).length > 3 && (
                        <div className="flex items-center justify-between">
                            <p className="text-sm text-muted-foreground">
                                Showing {allData.length} of {users.total} users
                            </p>
                            <div className="flex gap-1">
                                {(users.links ?? []).map((link: any, i: number) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        className={link.active ? 'bg-primary hover:bg-primary' : ''}
                                        asChild={!!link.url}
                                    >
                                        {link.url ? (
                                            <Link
                                                href={link.url}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        ) : (
                                            <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                        )}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    )}
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="System Users" />
            {content}
        </AppLayout>
    );
}
