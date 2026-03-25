import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Archive, Building2, Edit2, Layers, Link2, Plus, Settings2 } from 'lucide-react';
import { useMemo, useState } from 'react';

type Props = {
    defaultContextId?: number | null;
    defaultContextName?: string | null;
    contexts: Array<{
        id: number;
        type: string | null;
        name: string;
        description?: string | null;
        site_id?: number | null;
        site?: { id: number; name: string } | null;
        is_active: boolean;
    }>;
    types: Array<{ code: string; label: string; description: string }>;
    sites: Array<{ id: number; name: string; is_active: boolean }>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Service contexts', href: '/settings/service-contexts' },
];

const TYPE_COLORS: Record<string, string> = {
    residential: 'border-l-violet-500',
    home_support: 'border-l-blue-500',
    respite: 'border-l-amber-500',
    community: 'border-l-emerald-500',
    day_programme: 'border-l-pink-500',
};

const TYPE_BADGE_COLORS: Record<string, string> = {
    residential: 'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300',
    home_support: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    respite: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    community: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    day_programme: 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-300',
};

export default function ServiceContextsPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const typeLabel = useMemo(() => {
        const m = new Map(props.types.map((t) => [t.code, t.label]));
        return (code?: string | null) => (code ? m.get(code) ?? code : '--');
    }, [props.types]);

    const [createOpen, setCreateOpen] = useState(false);
    const createForm = useForm({
        type: props.types?.[0]?.code ?? 'residential',
        name: '',
        description: '',
        site_id: '' as any,
        is_active: true,
    });

    const [editing, setEditing] = useState<null | Props['contexts'][number]>(null);
    const editForm = useForm({
        type: '',
        name: '',
        description: '',
        site_id: '' as any,
        is_active: true,
    });

    const defaultForm = useForm({
        default_id: props.defaultContextId ?? '',
    });

    // Stats
    const totalContexts = props.contexts.length;
    const activeContexts = props.contexts.filter((c) => c.is_active).length;
    const linkedToSites = props.contexts.filter((c) => c.site_id).length;

    if (!can?.settings?.manageServiceContexts) {
        return (
            <SettingsLayout>
                <HeadingSmall title="Service contexts" description="" />
                <div className="rounded-md border p-4 text-sm">
                    You don't have permission to manage service contexts.
                </div>
            </SettingsLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Service Contexts" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Service Contexts"
                        description="Define how services are delivered for audit and reporting."
                    />

                    {/* Stats row */}
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="rounded-lg border bg-indigo-50 p-4 dark:bg-indigo-900/20">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 dark:bg-indigo-800/50">
                                    <Layers className="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <p className="text-2xl font-bold text-indigo-700 dark:text-indigo-300">{totalContexts}</p>
                                    <p className="text-xs text-indigo-600/70 dark:text-indigo-400/70">Total Contexts</p>
                                </div>
                            </div>
                        </div>
                        <div className="rounded-lg border bg-emerald-50 p-4 dark:bg-emerald-900/20">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-800/50">
                                    <Settings2 className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <div>
                                    <p className="text-2xl font-bold text-emerald-700 dark:text-emerald-300">{activeContexts}</p>
                                    <p className="text-xs text-emerald-600/70 dark:text-emerald-400/70">Active</p>
                                </div>
                            </div>
                        </div>
                        <div className="rounded-lg border bg-blue-50 p-4 dark:bg-blue-900/20">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-800/50">
                                    <Link2 className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                                </div>
                                <div>
                                    <p className="text-2xl font-bold text-blue-700 dark:text-blue-300">{linkedToSites}</p>
                                    <p className="text-xs text-blue-600/70 dark:text-blue-400/70">Linked to Sites</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Default Context */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Default Context</CardTitle>
                            <CardDescription>
                                Set the default service context for new clients.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="flex items-end gap-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    defaultForm.post('/settings/service-contexts/default');
                                }}
                            >
                                <div className="flex-1 space-y-2">
                                    <Label>Default</Label>
                                    <select
                                        className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                        value={defaultForm.data.default_id ?? ''}
                                        onChange={(e) =>
                                            defaultForm.setData(
                                                'default_id',
                                                e.target.value === '' ? '' : Number(e.target.value),
                                            )
                                        }
                                    >
                                        <option value="">-- None --</option>
                                        {props.contexts
                                            .filter((c) => c.is_active)
                                            .map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name} - {typeLabel(c.type)}
                                                    {c.site ? ` - ${c.site.name}` : ''}
                                                </option>
                                            ))}
                                    </select>
                                    {defaultForm.errors.default_id && (
                                        <div className="text-xs text-red-500">{defaultForm.errors.default_id}</div>
                                    )}
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        type="submit"
                                        disabled={defaultForm.processing}
                                        className="bg-violet-600 hover:bg-violet-700"
                                    >
                                        Save Default
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            defaultForm.setData('default_id', '');
                                            defaultForm.post('/settings/service-contexts/default');
                                        }}
                                        disabled={defaultForm.processing}
                                    >
                                        Clear
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Service Contexts */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle>Service Contexts</CardTitle>
                                    <CardDescription>
                                        Configure the types of services your organisation provides.
                                    </CardDescription>
                                </div>
                                <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                                    <DialogTrigger asChild>
                                        <Button className="bg-violet-600 hover:bg-violet-700">
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            New Context
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>Create service context</DialogTitle>
                                        </DialogHeader>
                                        <form
                                            onSubmit={(e) => {
                                                e.preventDefault();
                                                createForm.post('/settings/service-contexts', {
                                                    onSuccess: () => {
                                                        createForm.reset('name', 'description');
                                                        setCreateOpen(false);
                                                    },
                                                });
                                            }}
                                            className="space-y-4"
                                        >
                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label>Type</Label>
                                                    <Select
                                                        value={createForm.data.type}
                                                        onValueChange={(v) => createForm.setData('type', v)}
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select type" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {props.types.map((t) => (
                                                                <SelectItem key={t.code} value={t.code}>
                                                                    {t.label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    {createForm.errors.type && (
                                                        <div className="text-xs text-red-500">{createForm.errors.type}</div>
                                                    )}
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Name</Label>
                                                    <Input
                                                        value={createForm.data.name}
                                                        onChange={(e) => createForm.setData('name', e.target.value)}
                                                        placeholder="e.g. Residential -- Albany House"
                                                    />
                                                    {createForm.errors.name && (
                                                        <div className="text-xs text-red-500">{createForm.errors.name}</div>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label>Site (optional)</Label>
                                                    <select
                                                        className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                                        value={createForm.data.site_id ?? ''}
                                                        onChange={(e) =>
                                                            createForm.setData('site_id', e.target.value === '' ? '' : Number(e.target.value))
                                                        }
                                                    >
                                                        <option value="">--</option>
                                                        {props.sites.map((s) => (
                                                            <option key={s.id} value={s.id}>
                                                                {s.name}
                                                                {s.is_active === false ? ' (inactive)' : ''}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    {createForm.errors.site_id && (
                                                        <div className="text-xs text-red-500">{createForm.errors.site_id}</div>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-2 pt-8">
                                                    <Checkbox
                                                        checked={!!createForm.data.is_active}
                                                        onCheckedChange={(v) => createForm.setData('is_active', !!v)}
                                                    />
                                                    <span className="text-sm">Active</span>
                                                </div>
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Description (optional)</Label>
                                                <Textarea
                                                    value={createForm.data.description}
                                                    onChange={(e) => createForm.setData('description', e.target.value)}
                                                    placeholder="What does this context represent?"
                                                />
                                                {createForm.errors.description && (
                                                    <div className="text-xs text-red-500">{createForm.errors.description}</div>
                                                )}
                                            </div>
                                            <DialogFooter>
                                                <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>
                                                    Cancel
                                                </Button>
                                                <Button type="submit" disabled={createForm.processing} className="bg-violet-600 hover:bg-violet-700">
                                                    Create
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {props.contexts.length === 0 ? (
                                <div className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed py-12 text-center">
                                    <Building2 className="mb-3 h-10 w-10 text-muted-foreground/50" />
                                    <p className="text-sm font-medium text-muted-foreground">No service contexts yet</p>
                                    <p className="mt-1 text-xs text-muted-foreground/70">
                                        Create your first context to get started.
                                    </p>
                                </div>
                            ) : (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {props.contexts.map((c) => (
                                        <div
                                            key={c.id}
                                            className={`rounded-lg border border-l-4 p-4 transition-colors hover:bg-muted/30 ${
                                                TYPE_COLORS[c.type ?? ''] ?? 'border-l-gray-400'
                                            }`}
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-sm font-semibold">{c.name}</span>
                                                    </div>
                                                    <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                                                        <span
                                                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${
                                                                TYPE_BADGE_COLORS[c.type ?? ''] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'
                                                            }`}
                                                        >
                                                            {typeLabel(c.type)}
                                                        </span>
                                                        {c.is_active ? (
                                                            <Badge variant="secondary" className="text-[10px] bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                                                                Active
                                                            </Badge>
                                                        ) : (
                                                            <Badge variant="secondary" className="text-[10px] bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                                                Inactive
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    {c.site && (
                                                        <div className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                            <Link2 className="h-3 w-3" />
                                                            {c.site.name}
                                                        </div>
                                                    )}
                                                    {c.description && (
                                                        <p className="mt-2 line-clamp-2 text-xs text-muted-foreground">
                                                            {c.description}
                                                        </p>
                                                    )}
                                                </div>

                                                <div className="flex flex-shrink-0 gap-1">
                                                    <Dialog
                                                        open={editing?.id === c.id}
                                                        onOpenChange={(open) => {
                                                            if (!open) {
                                                                setEditing(null);
                                                                return;
                                                            }
                                                            setEditing(c);
                                                            editForm.setData({
                                                                type: c.type ?? (props.types?.[0]?.code ?? 'residential'),
                                                                name: c.name ?? '',
                                                                description: c.description ?? '',
                                                                site_id: c.site_id ?? '',
                                                                is_active: !!c.is_active,
                                                            });
                                                        }}
                                                    >
                                                        <DialogTrigger asChild>
                                                            <Button size="icon" variant="ghost" className="h-8 w-8">
                                                                <Edit2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </DialogTrigger>
                                                        <DialogContent>
                                                            <DialogHeader>
                                                                <DialogTitle>Edit service context</DialogTitle>
                                                            </DialogHeader>
                                                            <form
                                                                onSubmit={(e) => {
                                                                    e.preventDefault();
                                                                    editForm.put(`/settings/service-contexts/${c.id}`, {
                                                                        onSuccess: () => setEditing(null),
                                                                    });
                                                                }}
                                                                className="space-y-4"
                                                            >
                                                                <div className="space-y-2">
                                                                    <Label>Type</Label>
                                                                    <Select
                                                                        value={editForm.data.type}
                                                                        onValueChange={(v) => editForm.setData('type', v)}
                                                                    >
                                                                        <SelectTrigger>
                                                                            <SelectValue placeholder="Select type" />
                                                                        </SelectTrigger>
                                                                        <SelectContent>
                                                                            {props.types.map((t) => (
                                                                                <SelectItem key={t.code} value={t.code}>
                                                                                    {t.label}
                                                                                </SelectItem>
                                                                            ))}
                                                                        </SelectContent>
                                                                    </Select>
                                                                    {editForm.errors.type && (
                                                                        <div className="text-xs text-red-500">{editForm.errors.type}</div>
                                                                    )}
                                                                </div>
                                                                <div className="space-y-2">
                                                                    <Label>Name</Label>
                                                                    <Input
                                                                        value={editForm.data.name}
                                                                        onChange={(e) => editForm.setData('name', e.target.value)}
                                                                    />
                                                                    {editForm.errors.name && (
                                                                        <div className="text-xs text-red-500">{editForm.errors.name}</div>
                                                                    )}
                                                                </div>
                                                                <div className="space-y-2">
                                                                    <Label>Site (optional)</Label>
                                                                    <select
                                                                        className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                                                        value={editForm.data.site_id ?? ''}
                                                                        onChange={(e) =>
                                                                            editForm.setData(
                                                                                'site_id',
                                                                                e.target.value === '' ? '' : Number(e.target.value),
                                                                            )
                                                                        }
                                                                    >
                                                                        <option value="">--</option>
                                                                        {props.sites.map((s) => (
                                                                            <option key={s.id} value={s.id}>
                                                                                {s.name}
                                                                                {s.is_active === false ? ' (inactive)' : ''}
                                                                            </option>
                                                                        ))}
                                                                    </select>
                                                                    {editForm.errors.site_id && (
                                                                        <div className="text-xs text-red-500">{editForm.errors.site_id}</div>
                                                                    )}
                                                                </div>
                                                                <div className="space-y-2">
                                                                    <Label>Description (optional)</Label>
                                                                    <Textarea
                                                                        value={editForm.data.description}
                                                                        onChange={(e) =>
                                                                            editForm.setData('description', e.target.value)
                                                                        }
                                                                    />
                                                                    {editForm.errors.description && (
                                                                        <div className="text-xs text-red-500">{editForm.errors.description}</div>
                                                                    )}
                                                                </div>
                                                                <div className="flex items-center gap-2">
                                                                    <Checkbox
                                                                        checked={!!editForm.data.is_active}
                                                                        onCheckedChange={(v) =>
                                                                            editForm.setData('is_active', !!v)
                                                                        }
                                                                    />
                                                                    <span className="text-sm">Active</span>
                                                                </div>
                                                                <DialogFooter>
                                                                    <Button
                                                                        type="button"
                                                                        variant="outline"
                                                                        onClick={() => setEditing(null)}
                                                                    >
                                                                        Cancel
                                                                    </Button>
                                                                    <Button type="submit" disabled={editForm.processing} className="bg-violet-600 hover:bg-violet-700">
                                                                        Save
                                                                    </Button>
                                                                </DialogFooter>
                                                            </form>
                                                        </DialogContent>
                                                    </Dialog>
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        className="h-8 w-8 text-muted-foreground hover:text-amber-600"
                                                        title="Archive"
                                                    >
                                                        <Archive className="h-3.5 w-3.5" />
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
