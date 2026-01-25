import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
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
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
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

export default function ServiceContextsPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const typeLabel = useMemo(() => {
        const m = new Map(props.types.map((t) => [t.code, t.label]));
        return (code?: string | null) => (code ? m.get(code) ?? code : '—');
    }, [props.types]);

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


    if (!can?.settings?.manageServiceContexts) {
        return (
            <SettingsLayout>
                <HeadingSmall title="Service contexts" description="" />
                <div className="rounded-md border p-4 text-sm">
                    You don’t have permission to manage service contexts.
                </div>
            </SettingsLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Service contexts" />
            <SettingsLayout>
                <div className="space-y-6">
                    
                    <div className="rounded-xl border p-4">
                        <div className="text-sm font-medium">Default service context</div>
                        <div className="mt-1 text-xs text-muted-foreground">
                            Used when a client or shift doesn’t have a specific service context selected.
                        </div>

                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-3 sm:items-end"
                            onSubmit={(e) => {
                                e.preventDefault();
                                defaultForm.post('/settings/service-contexts/default');
                            }}
                        >
                            <div className="space-y-2 sm:col-span-2">
                                <Label>Default</Label>
                                <select
                                    className="mt-1 w-full rounded-md border bg-transparent p-2"
                                    value={defaultForm.data.default_id ?? ''}
                                    onChange={(e) =>
                                        defaultForm.setData(
                                            'default_id',
                                            e.target.value === '' ? '' : Number(e.target.value),
                                        )
                                    }
                                >
                                    <option value="">— None —</option>
                                    {props.contexts
                                        .filter((c) => c.is_active)
                                        .map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.name} • {typeLabel(c.type)}
                                                {c.site ? ` • ${c.site.name}` : ''}
                                            </option>
                                        ))}
                                </select>
                                {defaultForm.errors.default_id && (
                                    <div className="text-xs text-red-400">{defaultForm.errors.default_id}</div>
                                )}
                            </div>
                            <div className="flex gap-2">
                                <Button type="submit" disabled={defaultForm.processing}>
                                    Save
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
                    </div>

                    <HeadingSmall
                        title="Service contexts"
                        description="Define how services are delivered (residential, home support, respite) for audit and reporting."
                    />

                    <div className="rounded-xl border p-4">
                        <div className="text-sm font-medium">Create service context</div>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                createForm.post('/settings/service-contexts', {
                                    onSuccess: () => {
                                        createForm.reset('name', 'description');
                                    },
                                });
                            }}
                            className="mt-4 space-y-4"
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
                                        <div className="text-xs text-red-400">{createForm.errors.type}</div>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label>Name</Label>
                                    <Input
                                        value={createForm.data.name}
                                        onChange={(e) => createForm.setData('name', e.target.value)}
                                        placeholder="e.g. Residential – Albany House"
                                    />
                                    {createForm.errors.name && (
                                        <div className="text-xs text-red-400">{createForm.errors.name}</div>
                                    )}
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Site (optional)</Label>
                                    <select
                                        className="mt-1 w-full rounded-md border bg-transparent p-2"
                                        value={createForm.data.site_id ?? ''}
                                        onChange={(e) =>
                                            createForm.setData('site_id', e.target.value === '' ? '' : Number(e.target.value))
                                        }
                                    >
                                        <option value="">—</option>
                                        {props.sites.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                                {s.is_active === false ? ' (inactive)' : ''}
                                            </option>
                                        ))}
                                    </select>
                                    {createForm.errors.site_id && (
                                        <div className="text-xs text-red-400">{createForm.errors.site_id}</div>
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
                                    <div className="text-xs text-red-400">{createForm.errors.description}</div>
                                )}
                            </div>

                            <div className="flex items-center gap-2">
                                <Button type="submit" disabled={createForm.processing}>
                                    Create
                                </Button>
                            </div>
                        </form>
                    </div>

                    <div className="rounded-xl border">
                        <div className="border-b p-4 text-sm font-medium">Existing contexts</div>
                        <div className="p-4">
                            {props.contexts.length === 0 ? (
                                <div className="text-sm text-muted-foreground">No service contexts yet.</div>
                            ) : (
                                <div className="space-y-3">
                                    {props.contexts.map((c) => (
                                        <div key={c.id} className="rounded-lg border p-3">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <div className="text-sm font-medium">
                                                        {c.name}{' '}
                                                        {!c.is_active && (
                                                            <span className="text-xs text-muted-foreground">(inactive)</span>
                                                        )}
                                                    </div>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {typeLabel(c.type)}
                                                        {c.site ? ` • ${c.site.name}` : ''}
                                                    </div>
                                                    {c.description ? (
                                                        <div className="mt-2 text-sm text-slate-300">{c.description}</div>
                                                    ) : null}
                                                </div>

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
                                                        <Button size="sm" variant="outline">
                                                            Edit
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
                                                                    <div className="text-xs text-red-400">{editForm.errors.type}</div>
                                                                )}
                                                            </div>

                                                            <div className="space-y-2">
                                                                <Label>Name</Label>
                                                                <Input
                                                                    value={editForm.data.name}
                                                                    onChange={(e) => editForm.setData('name', e.target.value)}
                                                                />
                                                                {editForm.errors.name && (
                                                                    <div className="text-xs text-red-400">{editForm.errors.name}</div>
                                                                )}
                                                            </div>

                                                            <div className="space-y-2">
                                                                <Label>Site (optional)</Label>
                                                                <select
                                                                    className="mt-1 w-full rounded-md border bg-transparent p-2"
                                                                    value={editForm.data.site_id ?? ''}
                                                                    onChange={(e) =>
                                                                        editForm.setData(
                                                                            'site_id',
                                                                            e.target.value === '' ? '' : Number(e.target.value),
                                                                        )
                                                                    }
                                                                >
                                                                    <option value="">—</option>
                                                                    {props.sites.map((s) => (
                                                                        <option key={s.id} value={s.id}>
                                                                            {s.name}
                                                                            {s.is_active === false ? ' (inactive)' : ''}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                                {editForm.errors.site_id && (
                                                                    <div className="text-xs text-red-400">{editForm.errors.site_id}</div>
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
                                                                    <div className="text-xs text-red-400">{editForm.errors.description}</div>
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
                                                                <Button type="submit" disabled={editForm.processing}>
                                                                    Save
                                                                </Button>
                                                            </DialogFooter>
                                                        </form>
                                                    </DialogContent>
                                                </Dialog>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
