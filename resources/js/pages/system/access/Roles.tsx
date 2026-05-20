import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    Shield,
    Plus,
    Copy,
    Pencil,
    Trash2,
    ShieldCheck,
    Users,
    Lock,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/input-error';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Access Control', href: '/system/access' },
    { title: 'Roles', href: '/system/access/roles' },
];

type Role = {
    id: number;
    name: string;
    label: string;
    level: number;
    level_display: string;
    type: 'system' | 'custom';
    description: string | null;
    users_count: number;
    permissions_count: number;
    permission_keys: string[];
};

type Permission = {
    id: number;
    key: string;
    description: string | null;
    group: string;
    module: string | null;
};

type Props = {
    systemRoles: Role[];
    customRoles: Role[];
    permissions: Permission[];
    permissionGroups: string[];
};

export default function RolesManagement({ systemRoles, customRoles, permissions, permissionGroups }: Props) {
    const page = usePage();
    const [createDialogOpen, setCreateDialogOpen] = useState(false);
    const [editingRole, setEditingRole] = useState<Role | null>(null);
    const [cloneRole, setCloneRole] = useState<Role | null>(null);
    const [deletingRole, setDeletingRole] = useState<Role | null>(null);
    const [permissionSearch, setPermissionSearch] = useState('');

    const createForm = useForm({
        name: '',
        label: '',
        description: '',
        level: 50,
        permission_keys: [] as string[],
    });

    const editForm = useForm({
        label: '',
        description: '',
        permission_keys: [] as string[],
    });

    const cloneForm = useForm({
        name: '',
        label: '',
    });

    const handleCreateSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post('/system/access/roles', {
            onSuccess: () => {
                setCreateDialogOpen(false);
                createForm.reset();
            },
        });
    };

    const handleEditSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingRole) return;
        editForm.put(`/system/access/roles/${editingRole.id}`, {
            onSuccess: () => setEditingRole(null),
        });
    };

    const handleCloneSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!cloneRole) return;
        cloneForm.post(`/system/access/roles/${cloneRole.id}/clone`, {
            onSuccess: () => {
                setCloneRole(null);
                cloneForm.reset();
            },
        });
    };

    const handleDelete = () => {
        if (!deletingRole) return;
        router.delete(`/system/access/roles/${deletingRole.id}`, {
            onSuccess: () => setDeletingRole(null),
        });
    };

    const openEditDialog = (role: Role) => {
        setEditingRole(role);
        editForm.setData({
            label: role.label,
            description: role.description || '',
            permission_keys: role.permission_keys,
        });
    };

    const openCloneDialog = (role: Role) => {
        setCloneRole(role);
        cloneForm.setData({
            name: `${role.name}_copy`,
            label: `${role.label} (Copy)`,
        });
    };

    useEffect(() => {
        const editRoleId = new URLSearchParams(page.url.split('?')[1] ?? '').get('edit');

        if (!editRoleId) {
            return;
        }

        const roleId = Number.parseInt(editRoleId, 10);
        const role = [...systemRoles, ...customRoles].find((candidate) => candidate.id === roleId);

        if (!role) {
            return;
        }

        openEditDialog(role);
        window.history.replaceState(window.history.state, '', '/system/access/roles');
    // eslint-disable-next-line react-hooks/exhaustive-deps -- URL deep-link handling intentionally hydrates the Inertia edit form once per matching page URL.
    }, [customRoles, page.url, systemRoles]);

    const groupedPermissions = permissionGroups.map((group) => ({
        group,
        permissions: permissions.filter(
            (p) =>
                p.group === group &&
                (permissionSearch === '' ||
                    p.key.toLowerCase().includes(permissionSearch.toLowerCase()) ||
                    (p.description && p.description.toLowerCase().includes(permissionSearch.toLowerCase())))
        ),
    })).filter((g) => g.permissions.length > 0);

    const RoleCard = ({ role, isSystem }: { role: Role; isSystem: boolean }) => (
        <Card className="hover:shadow-sm transition-shadow">
            <CardContent className="p-4">
                <div className="flex items-start justify-between">
                    <div className="flex items-start gap-4">
                        <div className="p-2 bg-primary/10 rounded-full">
                            <Shield className="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2 mb-1">
                                <span className="font-medium text-lg">{role.label}</span>
                                <Badge variant={isSystem ? 'default' : 'secondary'}>
                                    {isSystem ? 'System' : 'Custom'}
                                </Badge>
                                <Badge variant="outline">{role.level_display}</Badge>
                            </div>
                            <p className="text-sm text-muted-foreground mb-2">
                                {role.description || 'No description'}
                            </p>
                            <div className="flex items-center gap-4 text-sm text-muted-foreground">
                                <span className="flex items-center gap-1">
                                    <Lock className="h-3 w-3" />
                                    {role.permissions_count} permissions
                                </span>
                                <span className="flex items-center gap-1">
                                    <Users className="h-3 w-3" />
                                    {role.users_count} users
                                </span>
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={() => openCloneDialog(role)}>
                            <Copy className="h-4 w-4 mr-1" />
                            Clone
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => openEditDialog(role)}>
                            <Pencil className="h-4 w-4 mr-1" />
                            Customize
                        </Button>
                        {!isSystem && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                onClick={() => setDeletingRole(role)}
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Roles Management" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Shield}
                        title="Roles Management"
                        description="Manage roles and their permissions for your organization."
                        stats={[
                            { label: 'System', value: systemRoles.length },
                            { label: 'Custom', value: customRoles.length },
                            { label: 'Permissions', value: permissions.length },
                        ]}
                        actions={
                            <Dialog open={createDialogOpen} onOpenChange={setCreateDialogOpen}>
                                <DialogTrigger asChild>
                                    <Button size="sm">
                                        <Plus className="h-4 w-4 mr-2" />
                                        Create Custom Role
                                    </Button>
                                </DialogTrigger>
                                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                            <DialogHeader>
                                <DialogTitle>Create Custom Role</DialogTitle>
                                <DialogDescription>
                                    Create a new custom role with specific permissions.
                                </DialogDescription>
                            </DialogHeader>
                            <form onSubmit={handleCreateSubmit} className="space-y-4">
                                <div>
                                    <Label htmlFor="name">Role Key</Label>
                                    <Input
                                        id="name"
                                        value={createForm.data.name}
                                        onChange={(e) => createForm.setData('name', e.target.value)}
                                        placeholder="e.g., senior_nurse"
                                    />
                                    <p className="text-xs text-muted-foreground mt-1">
                                        Lowercase letters, numbers, and underscores only.
                                    </p>
                                    <InputError message={createForm.errors.name} />
                                </div>
                                <div>
                                    <Label htmlFor="label">Display Name</Label>
                                    <Input
                                        id="label"
                                        value={createForm.data.label}
                                        onChange={(e) => createForm.setData('label', e.target.value)}
                                        placeholder="e.g., Senior Nurse"
                                    />
                                    <InputError message={createForm.errors.label} />
                                </div>
                                <div>
                                    <Label htmlFor="description">Description</Label>
                                    <Textarea
                                        id="description"
                                        value={createForm.data.description}
                                        onChange={(e) => createForm.setData('description', e.target.value)}
                                        placeholder="Describe the role's responsibilities..."
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="level">Level (1-100)</Label>
                                    <Input
                                        id="level"
                                        type="number"
                                        min={1}
                                        max={100}
                                        value={createForm.data.level}
                                        onChange={(e) => createForm.setData('level', parseInt(e.target.value))}
                                    />
                                    <p className="text-xs text-muted-foreground mt-1">
                                        Higher level = more authority. Admins are 100, standard staff 40.
                                    </p>
                                </div>
                                <div>
                                    <Label>Permissions</Label>
                                    <Input
                                        placeholder="Search permissions..."
                                        value={permissionSearch}
                                        onChange={(e) => setPermissionSearch(e.target.value)}
                                        className="mb-2"
                                    />
                                    <div className="border rounded-md p-2 max-h-60 overflow-y-auto">
                                        <Accordion type="multiple" className="w-full">
                                            {groupedPermissions.map(({ group, permissions: groupPerms }) => (
                                                <AccordionItem key={group} value={group}>
                                                    <AccordionTrigger className="text-sm py-2">
                                                        {group.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())}
                                                        <span className="ml-2 text-xs text-muted-foreground">
                                                            ({groupPerms.length})
                                                        </span>
                                                    </AccordionTrigger>
                                                    <AccordionContent>
                                                        <div className="space-y-2">
                                                            {groupPerms.map((perm) => (
                                                                <label
                                                                    key={perm.id}
                                                                    className="flex items-start gap-2 text-sm"
                                                                >
                                                                    <Checkbox
                                                                        checked={createForm.data.permission_keys.includes(perm.key)}
                                                                        onCheckedChange={(checked) => {
                                                                            const keys = createForm.data.permission_keys;
                                                                            if (checked) {
                                                                                createForm.setData('permission_keys', [...keys, perm.key]);
                                                                            } else {
                                                                                createForm.setData(
                                                                                    'permission_keys',
                                                                                    keys.filter((k) => k !== perm.key)
                                                                                );
                                                                            }
                                                                        }}
                                                                    />
                                                                    <div className="leading-tight">
                                                                        <div className="font-mono text-xs text-muted-foreground">
                                                                            {perm.key}
                                                                        </div>
                                                                        {perm.description && (
                                                                            <div className="text-xs">{perm.description}</div>
                                                                        )}
                                                                    </div>
                                                                </label>
                                                            ))}
                                                        </div>
                                                    </AccordionContent>
                                                </AccordionItem>
                                            ))}
                                        </Accordion>
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={() => setCreateDialogOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={createForm.processing}>
                                        Create Role
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                        }
                    />
                }
            >

                {/* System Roles */}
                <div>
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <ShieldCheck className="h-5 w-5 text-primary" />
                                <CardTitle>System Roles</CardTitle>
                            </div>
                            <CardDescription>
                                Pre-defined roles available to all organizations. Permissions can be customized per role.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {systemRoles.map((role) => (
                                <RoleCard key={role.id} role={role} isSystem={true} />
                            ))}
                        </CardContent>
                    </Card>
                </div>

                {/* Custom Roles */}
                <div>
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <Users className="h-5 w-5 text-primary" />
                                <CardTitle>Custom Roles</CardTitle>
                            </div>
                            <CardDescription>
                                Organization-specific roles created for your needs.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {customRoles.length > 0 ? (
                                <div className="space-y-3">
                                    {customRoles.map((role) => (
                                        <RoleCard key={role.id} role={role} isSystem={false} />
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center py-8 border-2 border-dashed rounded-lg">
                                    <Shield className="h-8 w-8 text-muted-foreground mx-auto mb-2" />
                                    <h3 className="font-medium">No Custom Roles</h3>
                                    <p className="text-sm text-muted-foreground mb-4">
                                        Custom roles let you define specific permission sets for unique positions.
                                    </p>
                                    <Button onClick={() => setCreateDialogOpen(true)}>
                                        <Plus className="h-4 w-4 mr-2" />
                                        Create Your First Custom Role
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>

            {/* Edit Dialog */}
            <Dialog open={!!editingRole} onOpenChange={() => setEditingRole(null)}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Customize Role: {editingRole?.label}</DialogTitle>
                        <DialogDescription>
                            {editingRole?.type === 'system'
                                ? 'System roles can only have permissions modified.'
                                : 'Modify role details and permissions.'}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleEditSubmit} className="space-y-4">
                        {editingRole?.type === 'custom' && (
                            <>
                                <div>
                                    <Label htmlFor="edit-label">Display Name</Label>
                                    <Input
                                        id="edit-label"
                                        value={editForm.data.label}
                                        onChange={(e) => editForm.setData('label', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="edit-description">Description</Label>
                                    <Textarea
                                        id="edit-description"
                                        value={editForm.data.description}
                                        onChange={(e) => editForm.setData('description', e.target.value)}
                                    />
                                </div>
                            </>
                        )}
                        <div>
                            <Label>Permissions</Label>
                            <Input
                                placeholder="Search permissions..."
                                value={permissionSearch}
                                onChange={(e) => setPermissionSearch(e.target.value)}
                                className="mb-2"
                            />
                            <div className="border rounded-md p-2 max-h-60 overflow-y-auto">
                                <Accordion type="multiple" className="w-full">
                                    {groupedPermissions.map(({ group, permissions: groupPerms }) => (
                                        <AccordionItem key={group} value={group}>
                                            <AccordionTrigger className="text-sm py-2">
                                                {group.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())}
                                            </AccordionTrigger>
                                            <AccordionContent>
                                                <div className="space-y-2">
                                                    {groupPerms.map((perm) => (
                                                        <label
                                                            key={perm.id}
                                                            className="flex items-start gap-2 text-sm"
                                                        >
                                                            <Checkbox
                                                                checked={editForm.data.permission_keys.includes(perm.key)}
                                                                onCheckedChange={(checked) => {
                                                                    const keys = editForm.data.permission_keys;
                                                                    if (checked) {
                                                                        editForm.setData('permission_keys', [...keys, perm.key]);
                                                                    } else {
                                                                        editForm.setData(
                                                                            'permission_keys',
                                                                            keys.filter((k) => k !== perm.key)
                                                                        );
                                                                    }
                                                                }}
                                                            />
                                                            <div className="leading-tight">
                                                                <div className="font-mono text-xs text-muted-foreground">
                                                                    {perm.key}
                                                                </div>
                                                                {perm.description && (
                                                                    <div className="text-xs">{perm.description}</div>
                                                                )}
                                                            </div>
                                                        </label>
                                                    ))}
                                                </div>
                                            </AccordionContent>
                                        </AccordionItem>
                                    ))}
                                </Accordion>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEditingRole(null)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={editForm.processing}>
                                Save Changes
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Clone Dialog */}
            <Dialog open={!!cloneRole} onOpenChange={() => setCloneRole(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Clone Role: {cloneRole?.label}</DialogTitle>
                        <DialogDescription>
                            Create a copy of this role with a new name.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleCloneSubmit} className="space-y-4">
                        <div>
                            <Label htmlFor="clone-name">New Role Key</Label>
                            <Input
                                id="clone-name"
                                value={cloneForm.data.name}
                                onChange={(e) => cloneForm.setData('name', e.target.value)}
                            />
                            <InputError message={cloneForm.errors.name} />
                        </div>
                        <div>
                            <Label htmlFor="clone-label">New Display Name</Label>
                            <Input
                                id="clone-label"
                                value={cloneForm.data.label}
                                onChange={(e) => cloneForm.setData('label', e.target.value)}
                            />
                            <InputError message={cloneForm.errors.label} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setCloneRole(null)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={cloneForm.processing}>
                                Clone Role
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Delete Dialog */}
            <Dialog open={!!deletingRole} onOpenChange={() => setDeletingRole(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Role</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete the role &quot;{deletingRole?.label}&quot;? This action cannot be undone.
                            {deletingRole && deletingRole.users_count > 0 && (
                                <p className="mt-2 text-destructive">
                                    Warning: This role is assigned to {deletingRole.users_count} user(s).
                                </p>
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setDeletingRole(null)}>
                            Cancel
                        </Button>
                        <Button type="button" variant="destructive" onClick={handleDelete}>
                            Delete Role
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
