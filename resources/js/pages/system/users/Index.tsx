import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Eye,
    Filter,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    Trash2,
    UserCheck,
    Users,
    UserX,
    X,
} from 'lucide-react';
import { useState } from 'react';

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
            { preserveState: true },
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
        if (level >= 70)
            return 'bg-status-info-bg text-status-info border-status-info/30';
        if (level >= 50)
            return 'bg-status-success-bg text-status-success border-status-success/30';
        if (level >= 30)
            return 'bg-status-warning-bg text-status-warning border-status-warning/30';
        return 'bg-muted text-foreground border-border';
    };

    const getUserTypeBadge = (type: string) => {
        const variants: Record<string, string> = {
            staff: 'bg-status-info-bg text-status-info border-status-info/30',
            client: 'bg-status-success-bg text-status-success border-status-success/30',
            next_of_kin:
                'bg-status-warning-bg text-status-warning border-status-warning/30',
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
            <Badge
                variant="outline"
                className={variants[type] || variants.user}
            >
                {labels[type] || type}
            </Badge>
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User Management" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Users}
                        title="User Management"
                        description="Manage system users and their organization assignments."
                        stats={[
                            { label: 'Total', value: stats.total },
                            { label: 'Active', value: stats.active },
                            { label: 'Pending', value: stats.pending },
                            { label: 'Staff', value: stats.staff },
                        ]}
                        actions={
                            <Link href="/system/users/create">
                                <Button size="sm">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create User
                                </Button>
                            </Link>
                        }
                    />
                }
            >
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
                                <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search by name or email..."
                                    value={searchQuery}
                                    onChange={(e) =>
                                        setSearchQuery(e.target.value)
                                    }
                                    onKeyDown={(e) =>
                                        e.key === 'Enter' && applyFilters()
                                    }
                                    className="pl-9"
                                />
                            </div>

                            {/* Filter Row */}
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="flex items-center gap-2">
                                    <Filter className="h-4 w-4 text-muted-foreground" />
                                    <span className="text-sm font-medium">
                                        Filters:
                                    </span>
                                </div>

                                {/* Status Filter */}
                                <Select
                                    value={statusFilter}
                                    onValueChange={setStatusFilter}
                                >
                                    <SelectTrigger className="h-8 w-[130px]">
                                        <SelectValue placeholder="Status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All Status
                                        </SelectItem>
                                        <SelectItem value="active">
                                            Active
                                        </SelectItem>
                                        <SelectItem value="pending">
                                            Pending
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                {/* Type Filter */}
                                <Select
                                    value={typeFilter}
                                    onValueChange={setTypeFilter}
                                >
                                    <SelectTrigger className="h-8 w-[140px]">
                                        <SelectValue placeholder="User Type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All Types
                                        </SelectItem>
                                        <SelectItem value="staff">
                                            Staff
                                        </SelectItem>
                                        <SelectItem value="client">
                                            Client
                                        </SelectItem>
                                        <SelectItem value="next_of_kin">
                                            Next of Kin
                                        </SelectItem>
                                        <SelectItem value="board">
                                            Board
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                {/* Role Filter */}
                                <Select
                                    value={roleFilter}
                                    onValueChange={setRoleFilter}
                                >
                                    <SelectTrigger className="h-8 w-[160px]">
                                        <SelectValue placeholder="Role" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All Roles
                                        </SelectItem>
                                        <SelectItem
                                            disabled
                                            value="system-header"
                                            className="font-semibold text-muted-foreground"
                                        >
                                            System Roles
                                        </SelectItem>
                                        {roles
                                            .filter((r) => r.type === 'system')
                                            .map((role) => (
                                                <SelectItem
                                                    key={role.id}
                                                    value={String(role.id)}
                                                >
                                                    {role.label} (L{role.level})
                                                </SelectItem>
                                            ))}
                                        {roles.some(
                                            (r) => r.type === 'custom',
                                        ) && (
                                            <>
                                                <SelectItem
                                                    disabled
                                                    value="custom-header"
                                                    className="font-semibold text-muted-foreground"
                                                >
                                                    Custom Roles
                                                </SelectItem>
                                                {roles
                                                    .filter(
                                                        (r) =>
                                                            r.type === 'custom',
                                                    )
                                                    .map((role) => (
                                                        <SelectItem
                                                            key={role.id}
                                                            value={String(
                                                                role.id,
                                                            )}
                                                        >
                                                            {role.label} (L
                                                            {role.level})
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
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={clearFilters}
                                    >
                                        <X className="mr-1 h-4 w-4" />
                                        Clear
                                    </Button>
                                )}
                            </div>

                            {/* Active Filter Badges */}
                            {hasActiveFilters && (
                                <div className="flex flex-wrap gap-2 pt-1">
                                    {statusFilter !== 'all' && (
                                        <Badge
                                            variant="secondary"
                                            className="gap-1"
                                        >
                                            Status: {statusFilter}
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-5"
                                                onClick={() => {
                                                    setStatusFilter('all');
                                                    applyFilters();
                                                }}
                                            >
                                                <X className="h-3 w-3" />
                                            </Button>
                                        </Badge>
                                    )}
                                    {typeFilter !== 'all' && (
                                        <Badge
                                            variant="secondary"
                                            className="gap-1"
                                        >
                                            Type: {typeFilter}
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-5"
                                                onClick={() => {
                                                    setTypeFilter('all');
                                                    applyFilters();
                                                }}
                                            >
                                                <X className="h-3 w-3" />
                                            </Button>
                                        </Badge>
                                    )}
                                    {roleFilter !== 'all' && (
                                        <Badge
                                            variant="secondary"
                                            className="gap-1"
                                        >
                                            Role:{' '}
                                            {
                                                roles.find(
                                                    (r) =>
                                                        String(r.id) ===
                                                        roleFilter,
                                                )?.label
                                            }
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-5"
                                                onClick={() => {
                                                    setRoleFilter('all');
                                                    applyFilters();
                                                }}
                                            >
                                                <X className="h-3 w-3" />
                                            </Button>
                                        </Badge>
                                    )}
                                </div>
                            )}
                        </div>

                        {/* Table */}
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>User</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Roles</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-[100px]">
                                            Actions
                                        </TableHead>
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
                                                            className="h-8 w-8 rounded-full object-cover"
                                                        />
                                                    ) : (
                                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-sm font-medium">
                                                            {user.name
                                                                .charAt(0)
                                                                .toUpperCase()}
                                                        </div>
                                                    )}
                                                    <div>
                                                        <div className="font-medium">
                                                            {user.name}
                                                        </div>
                                                        {user.staff_profile
                                                            ?.job_title && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {
                                                                    user
                                                                        .staff_profile
                                                                        .job_title
                                                                }
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {user.email}
                                            </TableCell>
                                            <TableCell>
                                                {getUserTypeBadge(
                                                    user.user_type,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {user.roles.length > 0 ? (
                                                        user.roles
                                                            .sort(
                                                                (a, b) =>
                                                                    b.level -
                                                                    a.level,
                                                            )
                                                            .slice(0, 2)
                                                            .map((role) => (
                                                                <Badge
                                                                    key={
                                                                        role.id
                                                                    }
                                                                    variant="outline"
                                                                    className={getRoleBadgeColor(
                                                                        role.level,
                                                                    )}
                                                                >
                                                                    {role.label}
                                                                </Badge>
                                                            ))
                                                    ) : (
                                                        <span className="text-sm text-muted-foreground">
                                                            No roles
                                                        </span>
                                                    )}
                                                    {user.roles.length > 2 && (
                                                        <Badge variant="outline">
                                                            +
                                                            {user.roles.length -
                                                                2}
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
                                                    <Badge variant="secondary">
                                                        Pending
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                        >
                                                            <MoreHorizontal className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/system/users/${user.id}`}
                                                                className="flex cursor-pointer items-center"
                                                            >
                                                                <Eye className="mr-2 h-4 w-4" />
                                                                View
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/system/users/${user.id}/edit`}
                                                                className="flex cursor-pointer items-center"
                                                            >
                                                                <Pencil className="mr-2 h-4 w-4" />
                                                                Edit
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        {!user.is_active ? (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    e,
                                                                ) => {
                                                                    e.preventDefault();
                                                                    handleApprove(
                                                                        user.id,
                                                                    );
                                                                }}
                                                            >
                                                                <UserCheck className="mr-2 h-4 w-4" />
                                                                Approve
                                                            </DropdownMenuItem>
                                                        ) : (
                                                            <DropdownMenuItem
                                                                onSelect={(
                                                                    e,
                                                                ) => {
                                                                    e.preventDefault();
                                                                    handleSuspend(
                                                                        user.id,
                                                                    );
                                                                }}
                                                            >
                                                                <UserX className="mr-2 h-4 w-4" />
                                                                Suspend
                                                            </DropdownMenuItem>
                                                        )}
                                                        <DropdownMenuItem
                                                            className="text-destructive"
                                                            onSelect={(e) => {
                                                                e.preventDefault();
                                                                setDeletingUser(
                                                                    user,
                                                                );
                                                            }}
                                                        >
                                                            <Trash2 className="mr-2 h-4 w-4" />
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
                                                className="py-8 text-center text-muted-foreground"
                                            >
                                                No users found matching your
                                                filters.
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
                                    Showing page {users.current_page} of{' '}
                                    {users.last_page}
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
                                        disabled={
                                            users.current_page ===
                                            users.last_page
                                        }
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
            </PageLayout>

            {/* Delete Dialog */}
            <Dialog
                open={!!deletingUser}
                onOpenChange={() => setDeletingUser(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete User</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete &quot;
                            {deletingUser?.name}&quot;? This action cannot be
                            undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeletingUser(null)}
                        >
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
