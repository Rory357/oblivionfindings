import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Permission = { id: number; key: string; description?: string | null };

type RolePayload = {
    id: number;
    name: string;
    label: string;
    permission_keys: string[];
};

const _GROUP_LABELS: Record<string, string> = {
    clients: 'Clients',
    shifts: 'Shifts',
    incidents: 'Incidents',
    timesheets: 'Timesheets',
    settings: 'Settings',
    staff: 'Staff',
    reports: 'Reports',
    portal: 'Portal',
    documents: 'Documents',
    medications: 'Medications',
    governance: 'Governance',
};

type Props = {
    mode: 'create' | 'edit';
    role: RolePayload | null;
    permissions: Permission[];
};

export default function RoleEdit(props: Props) {
    const { auth, labels } = usePage().props as any;
    const can = auth?.can;
    const clientPlural = labels?.['client.plural'] ?? 'Clients';

    const GROUP_LABELS: Record<string, string> = { ..._GROUP_LABELS, clients: clientPlural };

    function formatGroupName(group: string) {
        if (GROUP_LABELS[group]) return GROUP_LABELS[group];
        return group
            .split('_')
            .filter(Boolean)
            .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
            .join(' ');
    }

    const [filter, setFilter] = useState('');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Settings', href: '/settings/profile' },
        { title: 'Roles', href: '/settings/roles' },
        { title: props.mode === 'create' ? 'New role' : 'Edit role', href: '#' },
    ];

    const form = useForm<{
        name: string;
        label: string;
        permission_keys: string[];
    }>({
        name: props.role?.name ?? '',
        label: props.role?.label ?? '',
        permission_keys: props.role?.permission_keys ?? [],
    });

    const filteredPermissions = useMemo(() => {
        const q = filter.trim().toLowerCase();
        if (!q) return props.permissions;
        return props.permissions.filter(
            (p) =>
                p.key.toLowerCase().includes(q) ||
                (p.description ?? '').toLowerCase().includes(q),
        );
    }, [filter, props.permissions]);

    const groups = useMemo(() => {
        const map: Record<string, Permission[]> = {};
        for (const p of filteredPermissions) {
            const prefix = p.key.split('.')[0] ?? 'other';
            if (!map[prefix]) map[prefix] = [];
            map[prefix].push(p);
        }
        return Object.entries(map).sort((a, b) => a[0].localeCompare(b[0]));
    }, [filteredPermissions]);

    const toggle = (key: string) => {
        const set = new Set(form.data.permission_keys);
        if (set.has(key)) set.delete(key);
        else set.add(key);
        form.setData('permission_keys', Array.from(set).sort());
    };

    const setGroup = (keys: string[], enabled: boolean) => {
        const set = new Set(form.data.permission_keys);
        for (const k of keys) {
            if (enabled) set.add(k);
            else set.delete(k);
        }
        form.setData('permission_keys', Array.from(set).sort());
    };

    const submit = () => {
        if (props.mode === 'create') {
            form.post('/settings/roles');
        } else {
            form.put(`/settings/roles/${props.role?.id}`);
        }
    };

    if (!can?.settings?.manageAccess) {
        return (
            <SettingsLayout>
                <HeadingSmall title="Roles" description="" />
                <div className="rounded-md border p-4 text-sm">
                    You don’t have permission to manage roles.
                </div>
            </SettingsLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={props.mode === 'create' ? 'New role' : 'Edit role'} />

            <SettingsLayout>
                <div className="flex items-start justify-between gap-3">
                    <HeadingSmall
                        title={props.mode === 'create' ? 'New role' : 'Edit role'}
                        description="Roles are used across the system for RBAC. For safety, roles cannot be deleted."
                    />

                    <Button variant="outline" asChild>
                        <Link href="/settings/roles">Back</Link>
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Role details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="name">Role key</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. quality_coordinator"
                            />
                            <div className="text-xs text-muted-foreground">
                                Lowercase letters, numbers, and underscores only.
                            </div>
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="label">Label</Label>
                            <Input
                                id="label"
                                value={form.data.label}
                                onChange={(e) => form.setData('label', e.target.value)}
                                placeholder="e.g. Quality Coordinator"
                            />
                            <InputError message={form.errors.label} />
                        </div>
                    </CardContent>
                </Card>

                <Separator />

                <Card>
                    <CardHeader className="space-y-2">
                        <CardTitle className="text-base">Permissions</CardTitle>
                        <div className="text-sm text-muted-foreground">
                            Select the permissions this role should have. Users inherit permissions from all assigned roles.
                        </div>
                        <Input
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            placeholder="Filter permissions..."
                        />
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {groups.map(([group, perms]) => {
                            const keys = perms.map((p) => p.key);
                            const selected = keys.filter((k) => form.data.permission_keys.includes(k));
                            const allSelected = selected.length === keys.length && keys.length > 0;
                            const noneSelected = selected.length === 0;

                            const title = formatGroupName(group);

                            return (
                                <details
                                    key={group}
                                    className="rounded-md border"
                                    open={Boolean(filter)}
                                >
                                    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 hover:bg-muted">
                                        <div className="min-w-0">
                                            <div className="text-sm font-semibold">{title}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {allSelected ? 'All selected' : `${selected.length} / ${keys.length} selected`}
                                            </div>
                                        </div>

                                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                type="button"
                                                onClick={(e) => {
                                                    e.preventDefault();
                                                    setGroup(keys, true);
                                                }}
                                            >
                                                Select all
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                type="button"
                                                onClick={(e) => {
                                                    e.preventDefault();
                                                    setGroup(keys, false);
                                                }}
                                                disabled={noneSelected}
                                            >
                                                Clear
                                            </Button>
                                        </div>
                                    </summary>

                                    <div className="px-4 pb-4">
                                        <div className="mt-2 space-y-2">
                                            {perms.map((p) => {
                                                const shortKey = p.key.startsWith(group + '.')
                                                    ? p.key.slice(group.length + 1)
                                                    : p.key;

                                                return (
                                                    <label
                                                        key={p.key}
                                                        className="flex items-start gap-3 rounded-md px-2 py-2 hover:bg-muted"
                                                    >
                                                        <Checkbox
                                                            checked={form.data.permission_keys.includes(p.key)}
                                                            onCheckedChange={() => toggle(p.key)}
                                                        />
                                                        <div className="flex-1">
                                                            <div className="text-sm">
                                                                <span className="font-mono text-xs text-muted-foreground">{group}.</span>
                                                                <span className="font-mono">{shortKey}</span>
                                                            </div>
                                                            {p.description && (
                                                                <div className="text-xs text-muted-foreground">
                                                                    {p.description}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </label>
                                                );
                                                                                        })}
                                        </div>
                                    </div>
                                </details>
                            );
                        })}

                        {filteredPermissions.length === 0 && (
                            <div className="rounded-md border p-4 text-sm text-muted-foreground">
                                No permissions match your filter.
                            </div>
                        )}
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href="/settings/roles">Cancel</Link>
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {props.mode === 'create' ? 'Create role' : 'Save changes'}
                    </Button>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
