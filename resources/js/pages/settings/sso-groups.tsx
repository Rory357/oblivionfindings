import PageHeader from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Switch } from '@/components/ui/switch';
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
import { Head, router, useForm } from '@inertiajs/react';
import { Loader2, Plus, RefreshCw, Shield, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'SSO Group Mapping', href: '/settings/sso-groups' },
];

type Role = {
    id: number;
    name: string;
    label: string | null;
};

type Mapping = {
    id: number;
    provider: string;
    external_group_id: string;
    external_group_name: string;
    role_id: number;
    auto_assign: boolean;
    auto_remove: boolean;
    last_synced_at: string | null;
    role?: Role;
};

type Props = {
    mappings: Mapping[];
    roles: Role[];
    stats: { total: number; microsoft: number; google: number };
};

export default function SsoGroups({ mappings = [], roles = [], stats = { total: 0, microsoft: 0, google: 0 } }: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [fetching, setFetching] = useState(false);

    const form = useForm({
        provider: 'microsoft',
        external_group_id: '',
        external_group_name: '',
        role_id: '',
        auto_assign: true,
        auto_remove: false,
    });

    function handleStore(e: React.FormEvent) {
        e.preventDefault();
        form.post('/settings/sso-groups', {
            onSuccess: () => {
                setDialogOpen(false);
                form.reset();
            },
        });
    }

    function handleDelete(id: number) {
        if (!confirm('Remove this group mapping?')) return;
        router.delete(`/settings/sso-groups/${id}`);
    }

    function handleFetchGroups() {
        setFetching(true);
        router.post('/settings/sso-groups/fetch', {}, {
            onFinish: () => setFetching(false),
        });
    }

    function handleUpdateMapping(id: number, field: string, value: any) {
        const mapping = mappings.find((m) => m.id === id);
        if (!mapping) return;

        router.put(`/settings/sso-groups/${id}`, {
            role_id: field === 'role_id' ? value : mapping.role_id,
            auto_assign: field === 'auto_assign' ? value : mapping.auto_assign,
            auto_remove: field === 'auto_remove' ? value : mapping.auto_remove,
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SSO Group Mapping" />
            <SettingsLayout>
                <PageHeader title="SSO Group Mapping" />

                {/* Stats */}
                <div className="grid grid-cols-3 gap-4">
                    <Card>
                        <CardContent className="pt-4">
                            <div className="text-2xl font-bold">{stats.total}</div>
                            <div className="text-muted-foreground text-xs">Total Mappings</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4">
                            <div className="text-2xl font-bold">{stats.microsoft}</div>
                            <div className="text-muted-foreground text-xs">Microsoft</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4">
                            <div className="text-2xl font-bold">{stats.google}</div>
                            <div className="text-muted-foreground text-xs">Google</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Main Card */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle className="flex items-center gap-2">
                                    <ShieldCheck className="h-5 w-5 text-primary" />
                                    SSO Group Mapping
                                </CardTitle>
                                <CardDescription>
                                    Map security groups from Microsoft Entra ID or Google Workspace to application roles
                                </CardDescription>
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={handleFetchGroups}
                                    disabled={fetching}
                                >
                                    {fetching ? (
                                        <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                                    ) : (
                                        <RefreshCw className="mr-1 h-4 w-4" />
                                    )}
                                    Fetch Groups
                                </Button>
                                <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                                    <DialogTrigger asChild>
                                        <Button size="sm" className="bg-primary hover:bg-primary">
                                            <Plus className="mr-1 h-4 w-4" />
                                            Add Mapping
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>Add Group Mapping</DialogTitle>
                                            <DialogDescription>
                                                Map an external security group to an application role.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <form onSubmit={handleStore} className="space-y-4">
                                            <div className="space-y-2">
                                                <Label>Provider</Label>
                                                <Select
                                                    value={form.data.provider}
                                                    onValueChange={(v) => form.setData('provider', v)}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="microsoft">Microsoft</SelectItem>
                                                        <SelectItem value="google">Google</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label>External Group ID</Label>
                                                <Input
                                                    value={form.data.external_group_id}
                                                    onChange={(e) => form.setData('external_group_id', e.target.value)}
                                                    placeholder="e.g. 00000000-0000-0000-0000-000000000000"
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Group Name</Label>
                                                <Input
                                                    value={form.data.external_group_name}
                                                    onChange={(e) => form.setData('external_group_name', e.target.value)}
                                                    placeholder="e.g. Support Workers"
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Mapped Role</Label>
                                                <Select
                                                    value={form.data.role_id.toString()}
                                                    onValueChange={(v) => form.setData('role_id', v)}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select a role" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {roles.map((r) => (
                                                            <SelectItem key={r.id} value={r.id.toString()}>
                                                                {r.label || r.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <Label>Auto-Assign</Label>
                                                <Switch
                                                    checked={form.data.auto_assign}
                                                    onCheckedChange={(v) => form.setData('auto_assign', v)}
                                                />
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <Label>Auto-Remove</Label>
                                                <Switch
                                                    checked={form.data.auto_remove}
                                                    onCheckedChange={(v) => form.setData('auto_remove', v)}
                                                />
                                            </div>
                                            <DialogFooter>
                                                <Button type="submit" disabled={form.processing} className="bg-primary hover:bg-primary">
                                                    Create Mapping
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {mappings.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <Shield className="text-muted-foreground mb-3 h-10 w-10" />
                                <h3 className="text-sm font-medium">No group mappings</h3>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    Add a mapping to automatically sync roles from your identity provider.
                                </p>
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Provider</TableHead>
                                        <TableHead>Group Name</TableHead>
                                        <TableHead className="hidden md:table-cell">External ID</TableHead>
                                        <TableHead>Mapped Role</TableHead>
                                        <TableHead>Auto-Assign</TableHead>
                                        <TableHead>Auto-Remove</TableHead>
                                        <TableHead className="hidden lg:table-cell">Last Synced</TableHead>
                                        <TableHead className="w-10" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {mappings.map((m) => (
                                        <TableRow key={m.id}>
                                            <TableCell>
                                                <Badge variant={m.provider === 'microsoft' ? 'default' : 'secondary'}>
                                                    {m.provider === 'microsoft' ? 'Microsoft' : 'Google'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="font-medium">{m.external_group_name}</TableCell>
                                            <TableCell className="text-muted-foreground hidden max-w-[200px] truncate font-mono text-xs md:table-cell">
                                                {m.external_group_id}
                                            </TableCell>
                                            <TableCell>
                                                <Select
                                                    value={m.role_id.toString()}
                                                    onValueChange={(v) => handleUpdateMapping(m.id, 'role_id', parseInt(v))}
                                                >
                                                    <SelectTrigger className="h-8 w-[140px]">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {roles.map((r) => (
                                                            <SelectItem key={r.id} value={r.id.toString()}>
                                                                {r.label || r.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </TableCell>
                                            <TableCell>
                                                <Switch
                                                    checked={m.auto_assign}
                                                    onCheckedChange={(v) => handleUpdateMapping(m.id, 'auto_assign', v)}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <Switch
                                                    checked={m.auto_remove}
                                                    onCheckedChange={(v) => handleUpdateMapping(m.id, 'auto_remove', v)}
                                                />
                                            </TableCell>
                                            <TableCell className="text-muted-foreground hidden text-xs lg:table-cell">
                                                {m.last_synced_at
                                                    ? new Date(m.last_synced_at).toLocaleDateString()
                                                    : 'Never'}
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-8 w-8 text-red-500 hover:text-red-700"
                                                    onClick={() => handleDelete(m.id)}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </SettingsLayout>
        </AppLayout>
    );
}
