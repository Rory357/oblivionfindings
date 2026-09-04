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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil, Search, User, UserCheck, Users } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Overview', href: '/dashboard' },
    { title: 'Access Control', href: '/system/access' },
    { title: 'User Assignments', href: '/system/access/assignments' },
];

type Role = {
    id: number;
    name: string;
    label: string;
    level: number;
};

type UserItem = {
    id: number;
    name: string;
    email: string;
    roles: Role[];
    primary_role: string;
    is_staff: boolean;
    created_at: string;
};

type Props = {
    users: UserItem[];
    roles: Role[];
};

export default function UserAssignments({ users, roles }: Props) {
    const [searchQuery, setSearchQuery] = useState('');
    const [editingUser, setEditingUser] = useState<UserItem | null>(null);

    const editForm = useForm({
        role_ids: [] as number[],
    });

    const filteredUsers = users.filter(
        (u) =>
            u.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
            u.email.toLowerCase().includes(searchQuery.toLowerCase()) ||
            u.primary_role.toLowerCase().includes(searchQuery.toLowerCase()),
    );

    const openEditDialog = (user: UserItem) => {
        setEditingUser(user);
        editForm.setData({
            role_ids: user.roles.map((r) => r.id),
        });
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingUser) return;
        editForm.put(`/system/access/assignments/${editingUser.id}`, {
            onSuccess: () => setEditingUser(null),
        });
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

    const staffCount = users.filter((u) => u.is_staff).length;
    const portalCount = users.length - staffCount;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User Assignments" />

            <PageLayout
                hero={
                    <PageHero
                        icon={UserCheck}
                        title="User Assignments"
                        description="Manage role assignments for organization members."
                        stats={[
                            { label: 'Total', value: users.length },
                            { label: 'Staff', value: staffCount },
                            { label: 'Portal', value: portalCount },
                            { label: 'Roles', value: roles.length },
                        ]}
                        actions={
                            <Link href="/system/users/create">
                                <Button size="sm">
                                    <UserCheck className="mr-2 h-4 w-4" />
                                    Invite Member
                                </Button>
                            </Link>
                        }
                    />
                }
            >
                {/* Members Card */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Users className="h-5 w-5 text-primary" />
                            <CardTitle>Organization Members</CardTitle>
                        </div>
                        <CardDescription>
                            {users.length} member{users.length !== 1 ? 's' : ''}{' '}
                            in the organization.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {/* Search */}
                        <div className="relative mb-4">
                            <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search by name, email, or role..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="pl-9"
                            />
                        </div>

                        {/* Table */}
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>User</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Current Roles</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Joined</TableHead>
                                        <TableHead className="w-[100px]">
                                            Actions
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {filteredUsers.map((user) => (
                                        <TableRow key={user.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10">
                                                        <User className="h-4 w-4 text-primary" />
                                                    </div>
                                                    <span className="font-medium">
                                                        {user.name}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {user.email}
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
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        user.is_staff
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {user.is_staff
                                                        ? 'Staff'
                                                        : 'Portal User'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {new Date(
                                                    user.created_at,
                                                ).toLocaleDateString('en-NZ', {
                                                    year: 'numeric',
                                                    month: 'short',
                                                    day: 'numeric',
                                                })}
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        openEditDialog(user)
                                                    }
                                                >
                                                    <Pencil className="mr-1 h-4 w-4" />
                                                    Edit
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {filteredUsers.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="py-8 text-center text-muted-foreground"
                                            >
                                                No users found matching your
                                                search.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>

            {/* Edit Dialog */}
            <Dialog
                open={!!editingUser}
                onOpenChange={() => setEditingUser(null)}
            >
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Change Role Assignment</DialogTitle>
                        <DialogDescription>
                            Update roles for {editingUser?.name}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div>
                            <Label className="text-base">Select Roles</Label>
                            <p className="mb-3 text-sm text-muted-foreground">
                                User will have all permissions from the selected
                                roles.
                            </p>
                            <div className="max-h-60 space-y-2 overflow-y-auto rounded-md border p-3">
                                {roles
                                    .sort((a, b) => b.level - a.level)
                                    .map((role) => (
                                        <label
                                            key={role.id}
                                            className="flex cursor-pointer items-center gap-3 rounded p-2 hover:bg-muted"
                                        >
                                            <Checkbox
                                                checked={editForm.data.role_ids.includes(
                                                    role.id,
                                                )}
                                                onCheckedChange={(checked) => {
                                                    const ids =
                                                        editForm.data.role_ids;
                                                    if (checked) {
                                                        editForm.setData(
                                                            'role_ids',
                                                            [...ids, role.id],
                                                        );
                                                    } else {
                                                        editForm.setData(
                                                            'role_ids',
                                                            ids.filter(
                                                                (id) =>
                                                                    id !==
                                                                    role.id,
                                                            ),
                                                        );
                                                    }
                                                }}
                                            />
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">
                                                        {role.label}
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        className={getRoleBadgeColor(
                                                            role.level,
                                                        )}
                                                    >
                                                        L{role.level}
                                                    </Badge>
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {role.name}
                                                </div>
                                            </div>
                                        </label>
                                    ))}
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditingUser(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={editForm.processing}
                            >
                                Save Changes
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
