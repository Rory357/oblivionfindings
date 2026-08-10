import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    BookOpen,
    Building2,
    Car,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Circle,
    ClipboardList,
    FileText,
    Landmark,
    Search,
    Settings,
    Shield,
    ShieldAlert,
    Users,
    Wrench,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type Permission = { id: number; key: string; description?: string | null };

type RolePayload = {
    id: number;
    name: string;
    label: string;
    description?: string | null;
    users_count?: number;
    permission_keys: string[];
    landing_route?: string | null;
};

type LandingRouteOption = { key: string; label: string };

type Props = {
    mode: 'create' | 'edit';
    role: RolePayload | null;
    permissions: Permission[];
    landingRoutes: LandingRouteOption[];
};

/**
 * Module definitions: map permission prefixes to logical modules.
 * Order here determines display order.
 */
const MODULE_DEFINITIONS: {
    key: string;
    label: string;
    icon: React.ElementType;
    prefixes: string[];
}[] = [
    {
        key: 'operations',
        label: 'Operations',
        icon: ClipboardList,
        prefixes: [
            'clients',
            'shifts',
            'timesheets',
            'care_plans',
            'care_notes',
            'medications',
            'service_agreements',
            'funding',
            'rosters',
            'appointments',
            'goals',
            'progress_notes',
            'support_plans',
            'contacts',
            'documents',
            'portal',
        ],
    },
    {
        key: 'sites',
        label: 'Sites & Locations',
        icon: Building2,
        prefixes: [
            'sites',
            'hazards',
            'checklists',
            'rooms',
            'inspections',
            'locations',
            'maintenance',
        ],
    },
    {
        key: 'hr',
        label: 'HR & People',
        icon: Users,
        prefixes: [
            'staff',
            'leave',
            'training',
            'qualifications',
            'certifications',
            'payroll',
            'onboarding',
            'competencies',
        ],
    },
    {
        key: 'fleet',
        label: 'Fleet & Assets',
        icon: Car,
        prefixes: ['assets', 'fleet', 'vehicles', 'equipment', 'consumables'],
    },
    {
        key: 'governance',
        label: 'Governance',
        icon: Landmark,
        prefixes: ['governance', 'board', 'policies', 'compliance', 'meetings'],
    },
    {
        key: 'incidents',
        label: 'Incidents & Safety',
        icon: ShieldAlert,
        prefixes: [
            'incidents',
            'risks',
            'investigations',
            'notifications',
            'safety',
        ],
    },
    {
        key: 'settings',
        label: 'Settings',
        icon: Settings,
        prefixes: [
            'settings',
            'integrations',
            'roles',
            'permissions',
            'billing',
            'organisation',
        ],
    },
    {
        key: 'system',
        label: 'System',
        icon: Wrench,
        prefixes: ['audit', 'reports', 'exports', 'imports', 'logs', 'system'],
    },
];

/** Format a permission key suffix to a friendly label */
function friendlyLabel(key: string): string {
    // Remove prefix (e.g. "clients.view_any" -> "view_any")
    const parts = key.split('.');
    const suffix = parts.length > 1 ? parts.slice(1).join('.') : key;
    return suffix
        .split('_')
        .filter(Boolean)
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
}

export default function RoleEdit(props: Props) {
    const { auth, labels } = usePage().props as any;
    const can = auth?.can;
    const clientPlural = labels?.['client.plural'] ?? 'Clients';

    const [filter, setFilter] = useState('');
    const [expandedModules, setExpandedModules] = useState<Set<string>>(
        new Set(),
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Settings', href: '/settings/profile' },
        { title: 'Roles', href: '/settings/roles' },
        {
            title:
                props.mode === 'create'
                    ? 'New Role'
                    : `Edit: ${props.role?.label ?? 'Role'}`,
            href: '#',
        },
    ];

    const form = useForm<{
        name: string;
        label: string;
        description: string;
        permission_keys: string[];
        landing_route: string | null;
    }>({
        name: props.role?.name ?? '',
        label: props.role?.label ?? '',
        description: props.role?.description ?? '',
        permission_keys: props.role?.permission_keys ?? [],
        landing_route: props.role?.landing_route ?? null,
    });

    const filteredPermissions = useMemo(() => {
        const q = filter.trim().toLowerCase();
        if (!q) return props.permissions;
        return props.permissions.filter(
            (p) =>
                p.key.toLowerCase().includes(q) ||
                (p.description ?? '').toLowerCase().includes(q) ||
                friendlyLabel(p.key).toLowerCase().includes(q),
        );
    }, [filter, props.permissions]);

    /** Group permissions into modules */
    const moduleGroups = useMemo(() => {
        // Build a set of claimed prefixes
        const claimedPrefixes = new Set<string>();
        for (const mod of MODULE_DEFINITIONS) {
            for (const prefix of mod.prefixes) {
                claimedPrefixes.add(prefix);
            }
        }

        const result: {
            key: string;
            label: string;
            icon: React.ElementType;
            permissions: Permission[];
        }[] = [];

        // For each module, collect matching permissions
        for (const mod of MODULE_DEFINITIONS) {
            const prefixSet = new Set(mod.prefixes);
            const matching = filteredPermissions.filter((p) => {
                const prefix = p.key.split('.')[0] ?? '';
                return prefixSet.has(prefix);
            });
            if (matching.length > 0) {
                result.push({
                    key: mod.key,
                    label: mod.key === 'operations' ? mod.label : mod.label,
                    icon: mod.icon,
                    permissions: matching,
                });
            }
        }

        // Catch unclaimed permissions in an "Other" group
        const unclaimed = filteredPermissions.filter((p) => {
            const prefix = p.key.split('.')[0] ?? '';
            return !claimedPrefixes.has(prefix);
        });
        if (unclaimed.length > 0) {
            result.push({
                key: 'other',
                label: 'Other',
                icon: FileText,
                permissions: unclaimed,
            });
        }

        return result;
    }, [filteredPermissions]);

    const toggleModule = (key: string) => {
        setExpandedModules((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });
    };

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

    // When filter is active, expand all modules
    const isFiltering = filter.trim().length > 0;

    if (!can?.settings?.manageAccess) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Roles" />
                <SettingsLayout>
                    <HeadingSmall title="Roles" description="" />
                    <div className="rounded-md border p-4 text-sm">
                        You don't have permission to manage roles.
                    </div>
                </SettingsLayout>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={
                    props.mode === 'create'
                        ? 'New Role'
                        : `Edit: ${props.role?.label ?? 'Role'}`
                }
            />

            <SettingsLayout>
                {/* Header */}
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 w-8 p-0"
                            asChild
                        >
                            <Link href="/settings/roles">
                                <ArrowLeft className="h-4 w-4" />
                            </Link>
                        </Button>
                        <HeadingSmall
                            title={
                                props.mode === 'create'
                                    ? 'Create New Role'
                                    : `Edit Role: ${props.role?.label ?? ''}`
                            }
                            description="Configure role details and permission sets"
                        />
                    </div>
                </div>

                {/* Two-column layout */}
                <div className="grid gap-6 lg:grid-cols-[minmax(0,30%),minmax(0,70%)]">
                    {/* Left column: Role details */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader className="pb-4">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Shield className="h-4 w-4 text-primary" />
                                    Role Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Role Key</Label>
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                        placeholder="e.g. quality_coordinator"
                                        readOnly={props.mode === 'edit'}
                                        className={
                                            props.mode === 'edit'
                                                ? 'bg-muted font-mono text-sm'
                                                : 'font-mono text-sm'
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Lowercase letters, numbers, and
                                        underscores only.
                                        {props.mode === 'edit' &&
                                            ' Cannot be changed after creation.'}
                                    </p>
                                    <InputError message={form.errors.name} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="label">Display Label</Label>
                                    <Input
                                        id="label"
                                        value={form.data.label}
                                        onChange={(e) =>
                                            form.setData(
                                                'label',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. Quality Coordinator"
                                    />
                                    <InputError message={form.errors.label} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="description">
                                        Description
                                    </Label>
                                    <Textarea
                                        id="description"
                                        value={form.data.description}
                                        onChange={(e) =>
                                            form.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Brief description of this role's purpose..."
                                        rows={3}
                                    />
                                    <InputError
                                        message={
                                            (form.errors as any).description
                                        }
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="landing_route">
                                        Default Landing Page
                                    </Label>
                                    <select
                                        id="landing_route"
                                        value={form.data.landing_route ?? ''}
                                        onChange={(e) =>
                                            form.setData(
                                                'landing_route',
                                                e.target.value === ''
                                                    ? null
                                                    : e.target.value,
                                            )
                                        }
                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <option value="">
                                            System default (Dashboard)
                                        </option>
                                        {props.landingRoutes.map((opt) => (
                                            <option
                                                key={opt.key}
                                                value={opt.key}
                                            >
                                                {opt.label}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="text-xs text-muted-foreground">
                                        Where users with this role land after
                                        login. Users with multiple roles can
                                        pick which one wins on their profile.
                                    </p>
                                    <InputError
                                        message={
                                            (form.errors as any).landing_route
                                        }
                                    />
                                </div>

                                {props.mode === 'edit' &&
                                    props.role?.users_count != null && (
                                        <div className="rounded-lg border bg-muted/50 p-3">
                                            <div className="flex items-center gap-2">
                                                <Users className="h-4 w-4 text-muted-foreground" />
                                                <span className="text-sm font-medium">
                                                    {props.role.users_count}{' '}
                                                    user
                                                    {props.role.users_count !==
                                                    1
                                                        ? 's'
                                                        : ''}{' '}
                                                    assigned
                                                </span>
                                            </div>
                                        </div>
                                    )}

                                {/* Permission summary */}
                                <div className="rounded-lg border bg-muted/50 p-3">
                                    <div className="flex items-center gap-2">
                                        <BookOpen className="h-4 w-4 text-muted-foreground" />
                                        <span className="text-sm font-medium">
                                            {form.data.permission_keys.length}{' '}
                                            of {props.permissions.length}{' '}
                                            permissions selected
                                        </span>
                                    </div>
                                </div>

                                <Button
                                    onClick={submit}
                                    disabled={form.processing}
                                    className="w-full"
                                >
                                    <Check className="mr-1.5 h-4 w-4" />
                                    {props.mode === 'create'
                                        ? 'Create Role'
                                        : 'Save Changes'}
                                </Button>

                                <Button
                                    variant="outline"
                                    className="w-full"
                                    asChild
                                >
                                    <Link href="/settings/roles">Cancel</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right column: Permissions */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader className="pb-4">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Shield className="h-4 w-4 text-primary" />
                                    Permissions
                                </CardTitle>
                                <p className="text-sm text-muted-foreground">
                                    Select the permissions this role should
                                    have. Users inherit permissions from all
                                    assigned roles.
                                </p>
                                <div className="relative">
                                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        value={filter}
                                        onChange={(e) =>
                                            setFilter(e.target.value)
                                        }
                                        placeholder="Search permissions..."
                                        className="pl-9"
                                    />
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {moduleGroups.map((mod) => {
                                    const keys = mod.permissions.map(
                                        (p) => p.key,
                                    );
                                    const selected = keys.filter((k) =>
                                        form.data.permission_keys.includes(k),
                                    );
                                    const allSelected =
                                        selected.length === keys.length &&
                                        keys.length > 0;
                                    const noneSelected = selected.length === 0;
                                    const isExpanded =
                                        isFiltering ||
                                        expandedModules.has(mod.key);
                                    const Icon = mod.icon;

                                    return (
                                        <div
                                            key={mod.key}
                                            className="overflow-hidden rounded-lg border"
                                        >
                                            {/* Module header */}
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() =>
                                                    toggleModule(mod.key)
                                                }
                                                className="h-auto w-full justify-start gap-3 px-4 py-3 text-left whitespace-normal hover:bg-muted/50"
                                            >
                                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary/10 dark:bg-primary/30">
                                                    <Icon className="h-4 w-4 text-primary dark:text-primary" />
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-sm font-semibold">
                                                        {mod.label}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {selected.length} /{' '}
                                                        {keys.length} selected
                                                    </div>
                                                </div>
                                                <Badge
                                                    variant={
                                                        allSelected
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                    className={`text-xs ${allSelected ? 'bg-primary' : ''}`}
                                                >
                                                    {keys.length}
                                                </Badge>
                                                {isExpanded ? (
                                                    <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                ) : (
                                                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                )}
                                            </Button>

                                            {/* Module body */}
                                            {isExpanded && (
                                                <div className="border-t bg-muted/20 px-4 pt-3 pb-4">
                                                    {/* Select all / clear buttons */}
                                                    <div className="mb-3 flex items-center gap-2">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            type="button"
                                                            className="h-7 text-xs"
                                                            onClick={() =>
                                                                setGroup(
                                                                    keys,
                                                                    true,
                                                                )
                                                            }
                                                            disabled={
                                                                allSelected
                                                            }
                                                        >
                                                            Select All
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            type="button"
                                                            className="h-7 text-xs"
                                                            onClick={() =>
                                                                setGroup(
                                                                    keys,
                                                                    false,
                                                                )
                                                            }
                                                            disabled={
                                                                noneSelected
                                                            }
                                                        >
                                                            Deselect All
                                                        </Button>
                                                    </div>

                                                    {/* Permission checkboxes */}
                                                    <div className="space-y-1">
                                                        {mod.permissions.map(
                                                            (p) => {
                                                                const isChecked =
                                                                    form.data.permission_keys.includes(
                                                                        p.key,
                                                                    );

                                                                return (
                                                                    <label
                                                                        key={
                                                                            p.key
                                                                        }
                                                                        className="flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-background"
                                                                    >
                                                                        <Checkbox
                                                                            checked={
                                                                                isChecked
                                                                            }
                                                                            onCheckedChange={() =>
                                                                                toggle(
                                                                                    p.key,
                                                                                )
                                                                            }
                                                                        />
                                                                        <div className="flex min-w-0 flex-1 items-center gap-2">
                                                                            {isChecked ? (
                                                                                <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-status-success" />
                                                                            ) : (
                                                                                <Circle className="h-3.5 w-3.5 shrink-0 text-muted-foreground/40" />
                                                                            )}
                                                                            <div className="min-w-0">
                                                                                <span className="text-sm">
                                                                                    {friendlyLabel(
                                                                                        p.key,
                                                                                    )}
                                                                                </span>
                                                                                <span className="ml-2 font-mono text-xs text-muted-foreground">
                                                                                    {
                                                                                        p.key
                                                                                    }
                                                                                </span>
                                                                                {p.description && (
                                                                                    <p className="text-xs text-muted-foreground">
                                                                                        {
                                                                                            p.description
                                                                                        }
                                                                                    </p>
                                                                                )}
                                                                            </div>
                                                                        </div>
                                                                    </label>
                                                                );
                                                            },
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}

                                {filteredPermissions.length === 0 && (
                                    <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-8 text-center">
                                        <Search className="h-8 w-8 text-muted-foreground/40" />
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            No permissions match your search.
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
