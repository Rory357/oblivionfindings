import { OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    CheckCircle,
    Clock,
    Download,
    Mail,
    MoreHorizontal,
    Pencil,
    Search,
    Shield,
    ShieldCheck,
    ShieldAlert,
    UserMinus,
    UserPlus,
    Users,
    Users2,
} from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Users', href: '/settings/users' },
];

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
        return <Badge variant="outline" className="border-amber-300 bg-amber-50 text-amber-700">Pending</Badge>;
    }
    if (user.staff_profile?.status === 'suspended') {
        return <Badge variant="outline" className="border-red-300 bg-red-50 text-red-700">Suspended</Badge>;
    }
    return <Badge variant="outline" className="border-emerald-300 bg-emerald-50 text-emerald-700">Active</Badge>;
}

function userTypeBadge(type: string) {
    const map: Record<string, { label: string; className: string }> = {
        staff: { label: 'Staff', className: 'border-violet-300 bg-violet-50 text-violet-700' },
        client: { label: 'Client', className: 'border-blue-300 bg-blue-50 text-blue-700' },
        next_of_kin: { label: 'Whanau', className: 'border-cyan-300 bg-cyan-50 text-cyan-700' },
        board: { label: 'Board', className: 'border-amber-300 bg-amber-50 text-amber-700' },
        user: { label: 'User', className: 'border-slate-300 bg-slate-50 text-slate-700' },
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
    const [search, setSearch] = useState(filters.search ?? '');
    const [statusFilter, setStatusFilter] = useState(filters.status ?? 'all');
    const [roleFilter, setRoleFilter] = useState(filters.role ?? 'all');
    const [twoFaFilter, setTwoFaFilter] = useState(filters.has_2fa ?? 'all');
    const [activityFilter, setActivityFilter] = useState(filters.activity ?? 'all');
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [inviteOpen, setInviteOpen] = useState(false);

    const inviteForm = useForm({
        name: '',
        email: '',
        role_ids: [] as number[],
    });

    const allData = users?.data ?? [];

    function applyFilters(overrides: Record<string, string> = {}) {
        router.get(
            '/settings/users',
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
        if (selectedIds.length === allData.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(allData.map((u) => u.id));
        }
    }

    function handleInvite(e: React.FormEvent) {
        e.preventDefault();
        inviteForm.post('/settings/users', {
            onSuccess: () => {
                setInviteOpen(false);
                inviteForm.reset();
            },
        });
    }

    function handleSuspend(userId: number) {
        router.post(`/settings/users/${userId}/suspend`, {}, { preserveScroll: true });
    }

    function handleApprove(userId: number) {
        router.post(`/settings/users/${userId}/approve`, {}, { preserveScroll: true });
    }

    const adminCount = allData.filter(
        (u) => u.roles?.some((r) => r.label?.toLowerCase().includes('admin')),
    ).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User Management" />
            <SettingsLayout>
                <div className="space-y-6">
                    <PageHeader
                        title="User Management"
                        description="Manage user accounts, roles, and access across your organisation"
                        actions={
                            <div className="flex items-center gap-2">
                                <Button variant="outline" onClick={handleExportCsv}>
                                    <Download className="mr-2 h-4 w-4" />
                                    Export CSV
                                </Button>
                                <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
                                    <DialogTrigger asChild>
                                        <Button className="bg-violet-600 hover:bg-violet-700">
                                            <UserPlus className="mr-2 h-4 w-4" />
                                            Invite User
                                        </Button>
                                    </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Invite New User</DialogTitle>
                                        <DialogDescription>
                                            Send an invitation to join your organisation.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <form onSubmit={handleInvite} className="space-y-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="invite-name">Full Name</Label>
                                            <Input
                                                id="invite-name"
                                                placeholder="e.g. Aroha Williams"
                                                value={inviteForm.data.name}
                                                onChange={(e) => inviteForm.setData('name', e.target.value)}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="invite-email">Email Address</Label>
                                            <Input
                                                id="invite-email"
                                                type="email"
                                                placeholder="aroha@example.co.nz"
                                                value={inviteForm.data.email}
                                                onChange={(e) => inviteForm.setData('email', e.target.value)}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Role</Label>
                                            <Select
                                                value={inviteForm.data.role_ids[0]?.toString() ?? ''}
                                                onValueChange={(val) =>
                                                    inviteForm.setData('role_ids', val ? [parseInt(val)] : [])
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select a role..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {(roles ?? []).map((role) => (
                                                        <SelectItem key={role.id} value={role.id.toString()}>
                                                            {role.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <DialogFooter>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => setInviteOpen(false)}
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                type="submit"
                                                className="bg-violet-600 hover:bg-violet-700"
                                                disabled={inviteForm.processing}
                                            >
                                                <Mail className="mr-2 h-4 w-4" />
                                                Send Invitation
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
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
                        <div className="flex items-center gap-3 rounded-lg border border-violet-200 bg-violet-50 px-4 py-2">
                            <span className="text-sm font-medium text-violet-700">
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
                                    className="border-red-200 text-red-600 hover:bg-red-50"
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
                                    <div className="mb-4 rounded-full bg-violet-100 p-4">
                                        <Users className="h-8 w-8 text-violet-600" />
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
                                                    checked={
                                                        allData.length > 0 &&
                                                        selectedIds.length === allData.length
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
                                            <TableHead className="w-20">Actions</TableHead>
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
                                                        checked={selectedIds.includes(user.id)}
                                                        onCheckedChange={() => toggleSelect(user.id)}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <Link
                                                        href={`/settings/users/${user.id}`}
                                                        className="flex items-center gap-3"
                                                    >
                                                        <Avatar className="h-8 w-8">
                                                            {user.avatar && <AvatarImage src={user.avatar} />}
                                                            <AvatarFallback className="bg-violet-100 text-xs text-violet-700">
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
                                                        <ShieldCheck className="h-4 w-4 text-emerald-600" />
                                                    ) : (
                                                        <ShieldAlert className="h-4 w-4 text-muted-foreground/30" />
                                                    )}
                                                </TableCell>
                                                <TableCell onClick={(e) => e.stopPropagation()}>
                                                    <div className="flex gap-1">
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            className="h-8 w-8"
                                                            asChild
                                                        >
                                                            <Link href={`/settings/users/${user.id}`}>
                                                                <Pencil className="h-3.5 w-3.5" />
                                                            </Link>
                                                        </Button>
                                                        {user.is_active ? (
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                className="h-8 w-8 text-red-600 hover:bg-red-50 hover:text-red-700"
                                                                onClick={() => handleSuspend(user.id)}
                                                            >
                                                                <UserMinus className="h-3.5 w-3.5" />
                                                            </Button>
                                                        ) : (
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                className="h-8 w-8 text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700"
                                                                onClick={() => handleApprove(user.id)}
                                                            >
                                                                <CheckCircle className="h-3.5 w-3.5" />
                                                            </Button>
                                                        )}
                                                    </div>
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
                                        className={link.active ? 'bg-violet-600 hover:bg-violet-700' : ''}
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
            </SettingsLayout>
        </AppLayout>
    );
}
