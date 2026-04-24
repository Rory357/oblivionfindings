import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import {
    ArrowLeft,
    Check,
    X,
    Settings,
    Search,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
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
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Access Control', href: '/system/access' },
    { title: 'Permissions Matrix', href: '/system/access/matrix' },
];

type Role = {
    id: number;
    name: string;
    label: string;
    level: number;
    type: 'system' | 'custom';
};

type Permission = {
    id: number;
    key: string;
    description: string | null;
    group: string;
};

type Props = {
    roles: Role[];
    permissions: Permission[];
    permissionGroups: string[];
    rolePermissions: Record<number, number[]>; // role_id -> [permission_id, ...]
};

export default function PermissionsMatrix({ roles, permissions, permissionGroups, rolePermissions }: Props) {
    const [selectedRoles, setSelectedRoles] = useState<number[]>(roles.map((r) => r.id));
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedGroup, setSelectedGroup] = useState<string | null>(null);

    const filteredPermissions = useMemo(() => {
        return permissions.filter((perm) => {
            const matchesSearch =
                searchQuery === '' ||
                perm.key.toLowerCase().includes(searchQuery.toLowerCase()) ||
                (perm.description && perm.description.toLowerCase().includes(searchQuery.toLowerCase()));
            const matchesGroup = selectedGroup === null || perm.group === selectedGroup;
            return matchesSearch && matchesGroup;
        });
    }, [permissions, searchQuery, selectedGroup]);

    const groupedPermissions = useMemo(() => {
        const groups: Record<string, Permission[]> = {};
        filteredPermissions.forEach((perm) => {
            if (!groups[perm.group]) groups[perm.group] = [];
            groups[perm.group].push(perm);
        });
        return groups;
    }, [filteredPermissions]);

    const visibleRoles = roles.filter((r) => selectedRoles.includes(r.id));

    const hasPermission = (roleId: number, permissionId: number): boolean => {
        return rolePermissions[roleId]?.includes(permissionId) ?? false;
    };

    const toggleRole = (roleId: number) => {
        setSelectedRoles((prev) =>
            prev.includes(roleId) ? prev.filter((id) => id !== roleId) : [...prev, roleId]
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Permissions Matrix" />

            <div className="space-y-6">
                {/* Header */}
                <div>
                    <div className="flex items-center gap-2 mb-2">
                        <Link href="/system/access">
                            <Button variant="ghost" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-1" />
                                Back
                            </Button>
                        </Link>
                    </div>
                    <h1 className="text-2xl font-semibold tracking-tight">Permissions Matrix</h1>
                    <p className="text-muted-foreground">
                        Overview of permissions across all roles for your organization.
                    </p>
                </div>

                {/* Roles Selector */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Settings className="h-5 w-5 text-primary" />
                            <CardTitle>Roles</CardTitle>
                        </div>
                        <CardDescription>
                            Select roles to display in the matrix. Roles are ordered by level (highest to lowest).
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {roles.map((role) => (
                                <Button
                                    key={role.id}
                                    type="button"
                                    variant={selectedRoles.includes(role.id) ? 'default' : 'secondary'}
                                    onClick={() => toggleRole(role.id)}
                                    className="h-auto rounded-full px-3 py-1.5"
                                >
                                    {role.label}
                                    <Badge variant={selectedRoles.includes(role.id) ? 'secondary' : 'outline'} className="text-xs">
                                        L{role.level}
                                    </Badge>
                                </Button>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Filters */}
                <div className="flex flex-col sm:flex-row gap-4">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Search permissions..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="pl-9"
                        />
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant={selectedGroup === null ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setSelectedGroup(null)}
                        >
                            All
                        </Button>
                        {permissionGroups.slice(0, 5).map((group) => (
                            <Button
                                key={group}
                                variant={selectedGroup === group ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => setSelectedGroup(group === selectedGroup ? null : group)}
                            >
                                {group.replace(/_/g, ' ')}
                            </Button>
                        ))}
                    </div>
                </div>

                {/* Matrix Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Permission Matrix</CardTitle>
                        <CardDescription>
                            Shows which roles have which permissions.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-[300px] bg-muted/50">Permission</TableHead>
                                        {visibleRoles.map((role) => (
                                            <TableHead
                                                key={role.id}
                                                className="text-center min-w-[100px] bg-muted/50"
                                            >
                                                <div className="flex flex-col items-center">
                                                    <span className="text-xs">{role.label}</span>
                                                    <Badge variant="outline" className="text-[10px] mt-1">
                                                        L{role.level}
                                                    </Badge>
                                                </div>
                                            </TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {Object.entries(groupedPermissions).map(([group, perms]) => (
                                        <>
                                            <TableRow key={group} className="bg-muted/30">
                                                <TableCell
                                                    colSpan={visibleRoles.length + 1}
                                                    className="font-medium text-sm py-2"
                                                >
                                                    {group.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())}
                                                </TableCell>
                                            </TableRow>
                                            {perms.map((perm) => (
                                                <TableRow key={perm.id} className="hover:bg-muted/50">
                                                    <TableCell className="py-2">
                                                        <TooltipProvider>
                                                            <Tooltip>
                                                                <TooltipTrigger asChild>
                                                                    <div className="cursor-help">
                                                                        <div className="font-medium text-sm">
                                                                            {perm.key.split('.').pop()?.replace(/_/g, ' ')}
                                                                        </div>
                                                                        <div className="font-mono text-xs text-muted-foreground">
                                                                            {perm.key}
                                                                        </div>
                                                                    </div>
                                                                </TooltipTrigger>
                                                                <TooltipContent side="right" className="max-w-xs">
                                                                    <p>{perm.description || 'No description'}</p>
                                                                </TooltipContent>
                                                            </Tooltip>
                                                        </TooltipProvider>
                                                    </TableCell>
                                                    {visibleRoles.map((role) => (
                                                        <TableCell key={role.id} className="text-center py-2">
                                                            {hasPermission(role.id, perm.id) ? (
                                                                <div className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-status-success-bg text-status-success">
                                                                    <Check className="h-4 w-4" />
                                                                </div>
                                                            ) : (
                                                                <div className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-muted text-muted-foreground">
                                                                    <X className="h-4 w-4" />
                                                                </div>
                                                            )}
                                                        </TableCell>
                                                    ))}
                                                </TableRow>
                                            ))}
                                        </>
                                    ))}
                                    {filteredPermissions.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={visibleRoles.length + 1}
                                                className="text-center py-8 text-muted-foreground"
                                            >
                                                No permissions match your search.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
