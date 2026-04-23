import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowLeft,
    Users,
    Search,
    UserCheck,
    Pencil,
    User,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
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
            u.primary_role.toLowerCase().includes(searchQuery.toLowerCase())
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
        if (level >= 70) return 'bg-blue-100 text-blue-800 border-blue-200';
        if (level >= 50) return 'bg-green-100 text-green-800 border-green-200';
        if (level >= 30) return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        return 'bg-muted text-foreground border-border';
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User Assignments" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2 mb-2">
                            <Link href="/system/access">
                                <Button variant="ghost" size="sm">
                                    <ArrowLeft className="h-4 w-4 mr-1" />
                                    Back
                                </Button>
                            </Link>
                        </div>
                        <h1 className="text-2xl font-semibold tracking-tight">User Assignments</h1>
                        <p className="text-muted-foreground">
                            Manage role assignments for organization members.
                        </p>
                    </div>
                    <Link href="/system/users/create">
                        <Button>
                            <UserCheck className="h-4 w-4 mr-2" />
                            Invite Member
                        </Button>
                    </Link>
                </div>

                {/* Members Card */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Users className="h-5 w-5 text-primary" />
                            <CardTitle>Organization Members</CardTitle>
                        </div>
                        <CardDescription>
                            {users.length} member{users.length !== 1 ? 's' : ''} in the organization.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {/* Search */}
                        <div className="relative mb-4">
                            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search by name, email, or role..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="pl-9"
                            />
                        </div>

                        {/* Table */}
                        <div className="border rounded-md">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>User</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Current Roles</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Joined</TableHead>
                                        <TableHead className="w-[100px]">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {filteredUsers.map((user) => (
                                        <TableRow key={user.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                                        <User className="h-4 w-4 text-primary" />
                                                    </div>
                                                    <span className="font-medium">{user.name}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {user.email}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {user.roles.length > 0 ? (
                                                        user.roles
                                                            .sort((a, b) => b.level - a.level)
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
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={user.is_staff ? 'default' : 'secondary'}>
                                                    {user.is_staff ? 'Staff' : 'Portal User'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-sm">
                                                {new Date(user.created_at).toLocaleDateString('en-NZ', {
                                                    year: 'numeric',
                                                    month: 'short',
                                                    day: 'numeric',
                                                })}
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => openEditDialog(user)}
                                                >
                                                    <Pencil className="h-4 w-4 mr-1" />
                                                    Edit
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {filteredUsers.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="text-center py-8 text-muted-foreground"
                                            >
                                                No users found matching your search.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Edit Dialog */}
            <Dialog open={!!editingUser} onOpenChange={() => setEditingUser(null)}>
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
                            <p className="text-sm text-muted-foreground mb-3">
                                User will have all permissions from the selected roles.
                            </p>
                            <div className="space-y-2 max-h-60 overflow-y-auto border rounded-md p-3">
                                {roles
                                    .sort((a, b) => b.level - a.level)
                                    .map((role) => (
                                        <label
                                            key={role.id}
                                            className="flex items-center gap-3 p-2 rounded hover:bg-muted cursor-pointer"
                                        >
                                            <Checkbox
                                                checked={editForm.data.role_ids.includes(role.id)}
                                                onCheckedChange={(checked) => {
                                                    const ids = editForm.data.role_ids;
                                                    if (checked) {
                                                        editForm.setData('role_ids', [...ids, role.id]);
                                                    } else {
                                                        editForm.setData(
                                                            'role_ids',
                                                            ids.filter((id) => id !== role.id)
                                                        );
                                                    }
                                                }}
                                            />
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">{role.label}</span>
                                                    <Badge
                                                        variant="outline"
                                                        className={getRoleBadgeColor(role.level)}
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
                            <Button type="submit" disabled={editForm.processing}>
                                Save Changes
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
