import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Users,
    Search,
    Plus,
    Eye,
    Pencil,
    Trash2,
    UserCheck,
    UserX,
    MoreHorizontal,
    Filter,
    X,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System', href: '/system/access' },
    { title: 'Users', href: '/system/users' },
];

type Role = {
    id: number;
    name: string;
    label: string;
    level: number;
    type: 'system' | 'custom';
};

type UserItem = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    is_active: boolean;
    approved_at: string | null;
    created_at: string;
    roles: Role[];
    user_type: string;
    staff_profile: {
        job_title: string | null;
        status: string;
    } | null;
};

type Stats = {
    total: number;
    active: number;
    pending: number;
    staff: number;
};

type Props = {
    users: {
        data: UserItem[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        search: string;
        status: string;
        role: string;
        type: string;
    };
    roles: Role[];
    stats: Stats;
};

export default function UsersIndex({ users, filters, roles, stats }: Props) {
    const [searchQuery, setSearchQuery] = useState(filters.search);
    const [statusFilter, setStatusFilter] = useState(filters.status);
    const [roleFilter, setRoleFilter] = useState(filters.role);
    const [typeFilter, setTypeFilter] = useState(filters.type);
    const [deletingUser, setDeletingUser] = useState<UserItem | null>(null);

    const applyFilters = () => {
        router.get(
            '/system/users',
            {
                search: searchQuery,
                status: statusFilter,
                role: roleFilter,
                type: typeFilter,
            },
            { preserveState: true }
        );
    };

    const clearFilters = () => {
        setSearchQuery('');
        setStatusFilter('all');
        setRoleFilter('all');
        setTypeFilter('all');
        router.get('/system/users', {}, { preserveState: true });
    };

    const hasActiveFilters =
        searchQuery ||
        statusFilter !== 'all' ||
        roleFilter !== 'all' ||
        typeFilter !== 'all';

    const handleDelete = () => {
        if (!deletingUser) return;
        router.delete(`/system/users/${deletingUser.id}`, {
            onSuccess: () => setDeletingUser(null),
        });
    };

    const handleApprove = (userId: number) => {
        router.post(`/system/users/${userId}/approve`);
    };

    const handleSuspend = (userId: number) => {
        router.post(`/system/users/${userId}/suspend`);
    };

    const getRoleBadgeColor = (level: number): string => {
        if (level >= 90) return 'bg-primary/10 text-primary border-primary';
        if (level >= 70) return 'bg-status-info-bg text-status-info border-status-info/30';
        if (level >= 50) return 'bg-status-success-bg text-status-success border-status-success/30';
        if (level >= 30) return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        return 'bg-muted text-foreground border-border';
    };

    const getUserTypeBadge = (type: string) => {
        const variants: Record<string, string> = {
            staff: 'bg-status-info-bg text-status-info border-status-info/30',
            client: 'bg-status-success-bg text-status-success border-status-success/30',
            next_of_kin: 'bg-status-warning-bg text-status-warning border-status-warning/30',
            board: 'bg-primary/10 text-primary border-primary',
            user: 'bg-muted text-foreground border-border',
        };
        const labels: Record<string, string> = {
            staff: 'Staff',
            client: 'Client',
            next_of_kin: 'Next of Kin',
            board: 'Board',
            user: 'User',
        };
        return (
            <Badge variant="outline" className={variants[type] || variants.user}>
                {labels[type] || type}
            </Badge>
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User Management" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">User Management</h1>
                        <p className="text-muted-foreground">
                            Manage system users and their organization assignments.
                        </p>
                    </div>
                    <Link href="/system/users/create">
                        <Button>
                            <Plus className="h-4 w-4 mr-2" />
                            Create User
                        </Button>
                    </Link>
                </div>

                {/* Stats */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Users</CardTitle>
                            <Users className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.total}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Active</CardTitle>
                            <UserCheck className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.active}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Pending</CardTitle>
                            <UserX className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.pending}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Staff</CardTitle>
                            <Users className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.staff}</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Main Content */}
                <Card>
                    <CardHeader>
                        <CardTitle>All Users</CardTitle>
                        <CardDescription>
                            {users.total} users in the system
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {/* Filters */}
                        <div className="space-y-3">
                            {/* Search Row */}
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search by name or email..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                                    className="pl-9"
                                />
                            </div>

                            {/* Filter Row */}
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="flex items-center gap-2">
                                    <Filter className="h-4 w-4 text-muted-foreground" />
                                    <span className="text-sm font-medium">Filters:</span>
                                </div>

                                {/* Status Filter */}
                                <Select value={statusFilter} onValueChange={setStatusFilter}>
                                    <SelectTrigger className="w-[130px] h-8">
                                        <SelectValue placeholder="Status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Status</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="pending">Pending</SelectItem>
                                    </SelectContent>
                                </Select>

                                {/* Type Filter */}
                                <Select value={typeFilter} onValueChange={setTypeFilter}>
                                    <SelectTrigger className="w-[140px] h-8">
                                        <SelectValue placeholder="User Type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Types</SelectItem>
                                        <SelectItem value="staff">Staff</SelectItem>
                                        <SelectItem value="client">Client</SelectItem>
                                        <SelectItem value="next_of_kin">Next of Kin</SelectItem>
                                        <SelectItem value="board">Board</SelectItem>
                                    </SelectContent>
                                </Select>

                                {/* Role Filter */}
                                <Select value={roleFilter} onValueChange={setRoleFilter}>
                                    <SelectTrigger className="w-[160px] h-8">
                                        <SelectValue placeholder="Role" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Roles</SelectItem>
                                        <SelectItem disabled value="system-header" className="font-semibold text-muted-foreground">
                                            System Roles
                                        </SelectItem>
                                        {roles
                                            .filter((r) => r.type === 'system')
                                            .map((role) => (
                                                <SelectItem key={role.id} value={String(role.id)}>
                                                    {role.label} (L{role.level})
                                                </SelectItem>
                                            ))}
                                        {roles.some((r) => r.type === 'custom') && (
                                            <>
                                                <SelectItem disabled value="custom-header" className="font-semibold text-muted-foreground">
                                                    Custom Roles
                                                </SelectItem>
                                                {roles
                                                    .filter((r) => r.type === 'custom')
                                                    .map((role) => (
                                                        <SelectItem key={role.id} value={String(role.id)}>
                                                            {role.label} (L{role.level})
                                                        </SelectItem>
                                                    ))}
                                            </>
                                        )}
                                    </SelectContent>
                                </Select>

                                {/* Apply Button */}
                                <Button size="sm" onClick={applyFilters}>
                                    Apply
                                </Button>

                                {/* Clear Button */}
                                {hasActiveFilters && (
                                    <Button variant="ghost" size="sm" onClick={clearFilters}>
                                        <X className="h-4 w-4 mr-1" />
                                        Clear
                                    </Button>
                                )}
                            </div>

                            {/* Active Filter Badges */}
                            {hasActiveFilters && (
                                <div className="flex flex-wrap gap-2 pt-1">
                                    {statusFilter !== 'all' && (
                                        <Badge variant="secondary" className="gap-1">
                                            Status: {statusFilter}
                                            <Button variant="ghost" size="icon" className="size-5" onClick={() => { setStatusFilter('all'); applyFilters(); }}>
                                                <X className="h-3 w-3" />
                                            </Button>
                                        </Badge>
                                    )}
                                    {typeFilter !== 'all' && (
                                        <Badge variant="secondary" className="gap-1">
                                            Type: {typeFilter}
                                            <Button variant="ghost" size="icon" className="size-5" onClick={() => { setTypeFilter('all'); applyFilters(); }}>
                                                <X className="h-3 w-3" />
                                            </Button>
                                        </Badge>
                                    )}
                                    {roleFilter !== 'all' && (
                                        <Badge variant="secondary" className="gap-1">
                                            Role: {roles.find((r) => String(r.id) === roleFilter)?.label}
                                            <Button variant="ghost" size="icon" className="size-5" onClick={() => { setRoleFilter('all'); applyFilters(); }}>
                                                <X className="h-3 w-3" />
                                            </Button>
                                        </Badge>
                                    )}
                                </div>
                            )}
                        </div>

                        {/* Table */}
                        <div className="border rounded-md">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>User</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Roles</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-[100px]">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {users.data.map((user) => (
                                        <TableRow key={user.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-3">
                                                    {user.avatar ? (
                                                        <img
                                                            src={user.avatar}
                                                            alt={user.name}
                                                            className="w-8 h-8 rounded-full object-cover"
                                                        />
                                                    ) : (
                                                        <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-sm font-medium">
                                                            {user.name.charAt(0).toUpperCase()}
                                                        </div>
                                                    )}
                                                    <div>
                                                        <div className="font-medium">{user.name}</div>
                                                        {user.staff_profile?.job_title && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {user.staff_profile.job_title}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {user.email}
                                            </TableCell>
                                            <TableCell>{getUserTypeBadge(user.user_type)}</TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {user.roles.length > 0 ? (
                                                        user.roles
                                                            .sort((a, b) => b.level - a.level)
                                                            .slice(0, 2)
                                                            .map((role) => (
                                                                <Badge
                                                                    key={role.id}
                                                                    variant="outline"
                                                                    className={getRoleBadgeColor(role.level)}
                                                                >
                                                                    {role.label}
                                                                </Badge>
                                                            ))
                                                    ) : (
                                                        <span className="text-muted-foreground text-sm">
                                                            No roles
                                                        </span>
                                                    )}
                                                    {user.roles.length > 2 && (
                                                        <Badge variant="outline">
                                                            +{user.roles.length - 2}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {user.is_active ? (
                                                    <Badge className="bg-status-success-bg text-status-success hover:bg-status-success-bg">
                                                        Active
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary">Pending</Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button variant="ghost" size="sm">
                                                            <MoreHorizontal className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem asChild>
                                                            <Link 
                                                                href={`/system/users/${user.id}`}
                                                                className="cursor-pointer flex items-center"
                                                            >
                                                                <Eye className="h-4 w-4 mr-2" />
                                                                View
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem asChild>
                                                            <Link 
                                                                href={`/system/users/${user.id}/edit`}
                                                                className="cursor-pointer flex items-center"
                                                            >
                                                                <Pencil className="h-4 w-4 mr-2" />
                                                                Edit
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        {!user.is_active ? (
                                                            <DropdownMenuItem
                                                                onSelect={(e) => {
                                                                    e.preventDefault();
                                                                    handleApprove(user.id);
                                                                }}
                                                            >
                                                                <UserCheck className="h-4 w-4 mr-2" />
                                                                Approve
                                                            </DropdownMenuItem>
                                                        ) : (
                                                            <DropdownMenuItem
                                                                onSelect={(e) => {
                                                                    e.preventDefault();
                                                                    handleSuspend(user.id);
                                                                }}
                                                            >
                                                                <UserX className="h-4 w-4 mr-2" />
                                                                Suspend
                                                            </DropdownMenuItem>
                                                        )}
                                                        <DropdownMenuItem
                                                            className="text-destructive"
                                                            onSelect={(e) => {
                                                                e.preventDefault();
                                                                setDeletingUser(user);
                                                            }}
                                                        >
                                                            <Trash2 className="h-4 w-4 mr-2" />
                                                            Delete
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {users.data.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="text-center py-8 text-muted-foreground"
                                            >
                                                No users found matching your filters.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Pagination */}
                        {users.last_page > 1 && (
                            <div className="flex items-center justify-between">
                                <p className="text-sm text-muted-foreground">
                                    Showing page {users.current_page} of {users.last_page}
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={users.current_page === 1}
                                        onClick={() =>
                                            router.get('/system/users', {
                                                page: users.current_page - 1,
                                                search: searchQuery,
                                                status: statusFilter,
                                                role: roleFilter,
                                                type: typeFilter,
                                            })
                                        }
                                    >
                                        Previous
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={users.current_page === users.last_page}
                                        onClick={() =>
                                            router.get('/system/users', {
                                                page: users.current_page + 1,
                                                search: searchQuery,
                                                status: statusFilter,
                                                role: roleFilter,
                                                type: typeFilter,
                                            })
                                        }
                                    >
                                        Next
                                    </Button>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Delete Dialog */}
            <Dialog open={!!deletingUser} onOpenChange={() => setDeletingUser(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete User</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete &quot;{deletingUser?.name}&quot;? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeletingUser(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            Delete User
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
