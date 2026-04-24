import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    Building2,
    ChevronDown,
    ChevronRight,
    Filter,
    Home,
    Layers,
    Pencil,
    Plus,
    Search,
    Users,
    Sun,
    Clock,
    Shield,
    Baby,
    Puzzle,
    Archive,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type ServiceTypeInfo = {
    code: string;
    label: string;
    description: string;
    category: string;
    colour: string;
};

type Context = {
    id: number;
    type: string | null;
    name: string;
    description?: string | null;
    site_id?: number | null;
    site?: { id: number; name: string } | null;
    is_active: boolean;
};

type Props = {
    defaultContextId?: number | null;
    defaultContextName?: string | null;
    contexts: Context[];
    types: ServiceTypeInfo[];
    sites: Array<{ id: number; name: string; is_active: boolean }>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Service contexts', href: '/settings/service-contexts' },
];

const CATEGORY_ORDER = [
    'Residential',
    'Community',
    'Day Services',
    'Respite',
    'Specialist',
    'Children & Youth',
    'Flexible / Other',
];

const CATEGORY_COLOURS: Record<string, { bg: string; text: string; border: string; badge: string; icon: string }> = {
    Residential: {
        bg: 'bg-primary/10',
        text: 'text-primary',
        border: 'border-l-violet-500',
        badge: 'bg-primary/20 text-primary/70',
        icon: 'text-primary',
    },
    Community: {
        bg: 'bg-status-info',
        text: 'text-status-info',
        border: 'border-l-blue-500',
        badge: 'bg-status-info-bg text-status-info',
        icon: 'text-status-info',
    },
    'Day Services': {
        bg: 'bg-status-success',
        text: 'text-status-success',
        border: 'border-l-emerald-500',
        badge: 'bg-status-success-bg text-status-success',
        icon: 'text-status-success',
    },
    Respite: {
        bg: 'bg-status-warning',
        text: 'text-status-warning',
        border: 'border-l-amber-500',
        badge: 'bg-status-warning-bg text-status-warning',
        icon: 'text-status-warning',
    },
    Specialist: {
        bg: 'bg-status-critical',
        text: 'text-status-critical',
        border: 'border-l-rose-500',
        badge: 'bg-status-critical-bg text-status-critical',
        icon: 'text-status-critical',
    },
    'Children & Youth': {
        bg: 'bg-status-info',
        text: 'text-status-info',
        border: 'border-l-teal-500',
        badge: 'bg-status-info-bg text-status-info',
        icon: 'text-status-info',
    },
    'Flexible / Other': {
        bg: 'bg-muted-foreground/80/10',
        text: 'text-muted-foreground',
        border: 'border-l-slate-500',
        badge: 'bg-muted-foreground/80/20 text-muted-foreground',
        icon: 'text-muted-foreground',
    },
};

const CATEGORY_ICONS: Record<string, React.ComponentType<{ className?: string }>> = {
    Residential: Home,
    Community: Users,
    'Day Services': Sun,
    Respite: Clock,
    Specialist: Shield,
    'Children & Youth': Baby,
    'Flexible / Other': Puzzle,
};

function getCategoryForType(code: string, types: ServiceTypeInfo[]): string {
    return types.find((t) => t.code === code)?.category ?? 'Flexible / Other';
}

function getColourForType(code: string, types: ServiceTypeInfo[]): string {
    return types.find((t) => t.code === code)?.colour ?? 'slate';
}

export default function ServiceContextsPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const [searchQuery, setSearchQuery] = useState('');
    const [filterCategory, setFilterCategory] = useState<string>('all');
    const [filterStatus, setFilterStatus] = useState<string>('all');
    const [collapsedCategories, setCollapsedCategories] = useState<Set<string>>(new Set());
    const [createOpen, setCreateOpen] = useState(false);

    const typeMap = useMemo(() => {
        const m = new Map<string, ServiceTypeInfo>();
        props.types.forEach((t) => m.set(t.code, t));
        return m;
    }, [props.types]);

    const typeLabel = (code?: string | null) => (code ? typeMap.get(code)?.label ?? code : '--');

    // Group types by category for the select dropdown
    const typesByCategory = useMemo(() => {
        const groups: Record<string, ServiceTypeInfo[]> = {};
        for (const t of props.types) {
            const cat = t.category;
            if (!groups[cat]) groups[cat] = [];
            groups[cat].push(t);
        }
        return groups;
    }, [props.types]);

    // Filtered contexts
    const filteredContexts = useMemo(() => {
        return props.contexts.filter((c) => {
            if (searchQuery) {
                const q = searchQuery.toLowerCase();
                if (
                    !c.name.toLowerCase().includes(q) &&
                    !typeLabel(c.type).toLowerCase().includes(q) &&
                    !(c.site?.name ?? '').toLowerCase().includes(q) &&
                    !(c.description ?? '').toLowerCase().includes(q)
                ) {
                    return false;
                }
            }
            if (filterCategory !== 'all') {
                const cat = c.type ? getCategoryForType(c.type, props.types) : 'Flexible / Other';
                if (cat !== filterCategory) return false;
            }
            if (filterStatus === 'active' && !c.is_active) return false;
            if (filterStatus === 'inactive' && c.is_active) return false;
            return true;
        });
    }, [props.contexts, searchQuery, filterCategory, filterStatus, props.types]);

    // Group filtered contexts by category
    const contextsByCategory = useMemo(() => {
        const groups: Record<string, Context[]> = {};
        for (const c of filteredContexts) {
            const cat = c.type ? getCategoryForType(c.type, props.types) : 'Flexible / Other';
            if (!groups[cat]) groups[cat] = [];
            groups[cat].push(c);
        }
        return groups;
    }, [filteredContexts, props.types]);

    // Stats
    const totalContexts = props.contexts.length;
    const activeContexts = props.contexts.filter((c) => c.is_active).length;
    const linkedToSites = props.contexts.filter((c) => c.site_id).length;
    const categoryStats = useMemo(() => {
        const counts: Record<string, number> = {};
        for (const c of props.contexts) {
            const cat = c.type ? getCategoryForType(c.type, props.types) : 'Flexible / Other';
            counts[cat] = (counts[cat] ?? 0) + 1;
        }
        return counts;
    }, [props.contexts, props.types]);

    const toggleCategory = (cat: string) => {
        setCollapsedCategories((prev) => {
            const next = new Set(prev);
            if (next.has(cat)) {
                next.delete(cat);
            } else {
                next.add(cat);
            }
            return next;
        });
    };

    const createForm = useForm({
        type: props.types?.[0]?.code ?? 'residential',
        name: '',
        description: '',
        site_id: '' as any,
        is_active: true,
        funding_body: '',
        whaikaha_reference: '',
        max_capacity: '' as any,
    });

    const [editing, setEditing] = useState<null | Context>(null);
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

    const selectedCreateType = typeMap.get(createForm.data.type);

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
            <Head title="Service contexts" />
            <SettingsLayout>
                <div className="space-y-6">
                    {/* Stats Row */}
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-xl border bg-primary/5 p-4">
                            <div className="flex items-center gap-2">
                                <Layers className="h-4 w-4 text-primary" />
                                <span className="text-xs font-medium text-muted-foreground">Total Contexts</span>
                            </div>
                            <div className="mt-2 text-2xl font-bold text-primary">{totalContexts}</div>
                        </div>
                        <div className="rounded-xl border bg-status-success p-4">
                            <div className="flex items-center gap-2">
                                <div className="h-2 w-2 rounded-full bg-status-success" />
                                <span className="text-xs font-medium text-muted-foreground">Active</span>
                            </div>
                            <div className="mt-2 text-2xl font-bold text-status-success">{activeContexts}</div>
                        </div>
                        <div className="rounded-xl border p-4">
                            <div className="flex items-center gap-2">
                                <Filter className="h-4 w-4 text-muted-foreground" />
                                <span className="text-xs font-medium text-muted-foreground">By Category</span>
                            </div>
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {CATEGORY_ORDER.filter((cat) => categoryStats[cat]).map((cat) => (
                                    <span
                                        key={cat}
                                        className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${CATEGORY_COLOURS[cat]?.badge ?? 'bg-muted-foreground/80/20 text-muted-foreground'}`}
                                    >
                                        {cat}: {categoryStats[cat]}
                                    </span>
                                ))}
                                {Object.keys(categoryStats).length === 0 && (
                                    <span className="text-xs text-muted-foreground">None</span>
                                )}
                            </div>
                        </div>
                        <div className="rounded-xl border bg-status-info p-4">
                            <div className="flex items-center gap-2">
                                <Building2 className="h-4 w-4 text-status-info" />
                                <span className="text-xs font-medium text-muted-foreground">Linked to Sites</span>
                            </div>
                            <div className="mt-2 text-2xl font-bold text-status-info">{linkedToSites}</div>
                        </div>
                    </div>

                    {/* Default Context */}
                    <div className="rounded-xl border p-4">
                        <div className="text-sm font-medium">Default service context</div>
                        <div className="mt-1 text-xs text-muted-foreground">
                            Used when a client or shift doesn't have a specific service context selected.
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
                                    <option value="">-- None --</option>
                                    {props.contexts
                                        .filter((c) => c.is_active)
                                        .map((c) => {
                                            const cat = c.type
                                                ? getCategoryForType(c.type, props.types)
                                                : '';
                                            return (
                                                <option key={c.id} value={c.id}>
                                                    {c.name} {'\u2022'} {typeLabel(c.type)}
                                                    {cat ? ` [${cat}]` : ''}
                                                    {c.site ? ` \u2022 ${c.site.name}` : ''}
                                                </option>
                                            );
                                        })}
                                </select>
                                {defaultForm.errors.default_id && (
                                    <div className="text-xs text-status-critical">{defaultForm.errors.default_id}</div>
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

                    {/* Heading + Create button */}
                    <div className="flex items-center justify-between">
                        <HeadingSmall
                            title="Service contexts"
                            description="Define how services are delivered across residential, community, day, respite, and specialist settings."
                        />
                        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                            <DialogTrigger asChild>
                                <Button size="sm" className="gap-1.5">
                                    <Plus className="h-4 w-4" />
                                    New Context
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-w-lg">
                                <DialogHeader>
                                    <DialogTitle>Create service context</DialogTitle>
                                    <DialogDescription>
                                        Add a service context for residential, respite, or other delivery settings.
                                    </DialogDescription>
                                </DialogHeader>
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        createForm.post('/settings/service-contexts', {
                                            onSuccess: () => {
                                                createForm.reset();
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
                                                    {CATEGORY_ORDER.map((cat) => {
                                                        const catTypes = typesByCategory[cat];
                                                        if (!catTypes?.length) return null;
                                                        return (
                                                            <SelectGroup key={cat}>
                                                                <SelectLabel className={`text-xs font-semibold ${CATEGORY_COLOURS[cat]?.text ?? ''}`}>
                                                                    {cat}
                                                                </SelectLabel>
                                                                {catTypes.map((t) => (
                                                                    <SelectItem key={t.code} value={t.code}>
                                                                        {t.label}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectGroup>
                                                        );
                                                    })}
                                                </SelectContent>
                                            </Select>
                                            {createForm.errors.type && (
                                                <div className="text-xs text-status-critical">{createForm.errors.type}</div>
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
                                                <div className="text-xs text-status-critical">{createForm.errors.name}</div>
                                            )}
                                        </div>
                                    </div>

                                    {/* Type description hint */}
                                    {selectedCreateType && (
                                        <div className={`rounded-lg p-3 text-xs ${CATEGORY_COLOURS[selectedCreateType.category]?.bg ?? 'bg-muted-foreground/80/10'} ${CATEGORY_COLOURS[selectedCreateType.category]?.text ?? 'text-muted-foreground'}`}>
                                            <span className="font-medium">{selectedCreateType.label}:</span>{' '}
                                            {selectedCreateType.description}
                                        </div>
                                    )}

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Site (optional)</Label>
                                            <select
                                                className="mt-1 w-full rounded-md border bg-transparent p-2 text-sm"
                                                value={createForm.data.site_id ?? ''}
                                                onChange={(e) =>
                                                    createForm.setData(
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
                                            {createForm.errors.site_id && (
                                                <div className="text-xs text-status-critical">{createForm.errors.site_id}</div>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Max Capacity (optional)</Label>
                                            <Input
                                                type="number"
                                                min={0}
                                                value={createForm.data.max_capacity}
                                                onChange={(e) => createForm.setData('max_capacity', e.target.value)}
                                                placeholder="e.g. 6"
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Funding Body (optional)</Label>
                                            <Input
                                                value={createForm.data.funding_body}
                                                onChange={(e) => createForm.setData('funding_body', e.target.value)}
                                                placeholder="e.g. Whaikaha, DSS"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Whaikaha Reference (optional)</Label>
                                            <Input
                                                value={createForm.data.whaikaha_reference}
                                                onChange={(e) => createForm.setData('whaikaha_reference', e.target.value)}
                                                placeholder="e.g. WH-2024-1234"
                                            />
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <Label>Description (optional)</Label>
                                        <Textarea
                                            value={createForm.data.description}
                                            onChange={(e) => createForm.setData('description', e.target.value)}
                                            placeholder="What does this context represent?"
                                            rows={3}
                                        />
                                        {createForm.errors.description && (
                                            <div className="text-xs text-status-critical">{createForm.errors.description}</div>
                                        )}
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            checked={!!createForm.data.is_active}
                                            onCheckedChange={(v) => createForm.setData('is_active', !!v)}
                                        />
                                        <span className="text-sm">Active</span>
                                    </div>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setCreateOpen(false)}
                                        >
                                            Cancel
                                        </Button>
                                        <Button type="submit" disabled={createForm.processing}>
                                            Create Context
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>

                    {/* Filter Bar */}
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="relative flex-1 min-w-[200px]">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                className="pl-9"
                                placeholder="Search contexts..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                            />
                        </div>
                        <Select value={filterCategory} onValueChange={setFilterCategory}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All categories" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Categories</SelectItem>
                                {CATEGORY_ORDER.map((cat) => (
                                    <SelectItem key={cat} value={cat}>
                                        {cat}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={filterStatus} onValueChange={setFilterStatus}>
                            <SelectTrigger className="w-[140px]">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Statuses</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="inactive">Inactive</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Contexts grouped by category */}
                    {filteredContexts.length === 0 ? (
                        <div className="rounded-xl border p-8 text-center text-sm text-muted-foreground">
                            {props.contexts.length === 0
                                ? 'No service contexts yet. Create one to get started.'
                                : 'No contexts match your filters.'}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {CATEGORY_ORDER.filter((cat) => contextsByCategory[cat]?.length).map((cat) => {
                                const contexts = contextsByCategory[cat];
                                const isCollapsed = collapsedCategories.has(cat);
                                const colours = CATEGORY_COLOURS[cat] ?? CATEGORY_COLOURS['Flexible / Other'];
                                const IconComp = CATEGORY_ICONS[cat] ?? Puzzle;

                                return (
                                    <div key={cat} className="rounded-xl border">
                                        <button
                                            type="button"
                                            className={`flex w-full items-center gap-3 rounded-t-xl px-4 py-3 text-left transition-colors hover:bg-accent/50 ${colours.bg}`}
                                            onClick={() => toggleCategory(cat)}
                                        >
                                            {isCollapsed ? (
                                                <ChevronRight className={`h-4 w-4 ${colours.icon}`} />
                                            ) : (
                                                <ChevronDown className={`h-4 w-4 ${colours.icon}`} />
                                            )}
                                            <IconComp className={`h-4 w-4 ${colours.icon}`} />
                                            <span className={`text-sm font-semibold ${colours.text}`}>
                                                {cat}
                                            </span>
                                            <Badge
                                                variant="secondary"
                                                className={`ml-auto text-[10px] ${colours.badge} border-0`}
                                            >
                                                {contexts.length}
                                            </Badge>
                                        </button>

                                        {!isCollapsed && (
                                            <div className="grid gap-3 p-4 sm:grid-cols-2">
                                                {contexts.map((c) => (
                                                    <ContextCard
                                                        key={c.id}
                                                        context={c}
                                                        typeLabel={typeLabel(c.type)}
                                                        category={cat}
                                                        colours={colours}
                                                        types={props.types}
                                                        typesByCategory={typesByCategory}
                                                        sites={props.sites}
                                                        editing={editing}
                                                        setEditing={setEditing}
                                                        editForm={editForm}
                                                    />
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function ContextCard({
    context: c,
    typeLabel: label,
    category,
    colours,
    types,
    typesByCategory,
    sites,
    editing,
    setEditing,
    editForm,
}: {
    context: Context;
    typeLabel: string;
    category: string;
    colours: { bg: string; text: string; border: string; badge: string; icon: string };
    types: ServiceTypeInfo[];
    typesByCategory: Record<string, ServiceTypeInfo[]>;
    sites: Array<{ id: number; name: string; is_active: boolean }>;
    editing: Context | null;
    setEditing: (c: Context | null) => void;
    editForm: ReturnType<typeof useForm<{ type: string; name: string; description: string; site_id: any; is_active: boolean }>>;
}) {
    return (
        <div className={`rounded-lg border border-l-4 ${colours.border} p-3`}>
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-semibold">{c.name}</span>
                        <Badge
                            variant="secondary"
                            className={`text-[10px] ${colours.badge} border-0`}
                        >
                            {category}
                        </Badge>
                        {c.is_active ? (
                            <Badge variant="secondary" className="border-0 bg-status-success-bg text-[10px] text-status-success">
                                Active
                            </Badge>
                        ) : (
                            <Badge variant="secondary" className="border-0 bg-muted-foreground/80/20 text-[10px] text-muted-foreground">
                                Inactive
                            </Badge>
                        )}
                    </div>
                    <div className="mt-1 text-xs text-muted-foreground">{label}</div>
                    {c.description && (
                        <div className="mt-1.5 line-clamp-2 text-xs text-muted-foreground">
                            {c.description}
                        </div>
                    )}
                    {c.site && (
                        <div className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Building2 className="h-3 w-3" />
                            {c.site.name}
                        </div>
                    )}
                </div>

                <div className="flex shrink-0 gap-1">
                    <Dialog
                        open={editing?.id === c.id}
                        onOpenChange={(open) => {
                            if (!open) {
                                setEditing(null);
                                return;
                            }
                            setEditing(c);
                            editForm.setData({
                                type: c.type ?? (types?.[0]?.code ?? 'residential'),
                                name: c.name ?? '',
                                description: c.description ?? '',
                                site_id: c.site_id ?? '',
                                is_active: !!c.is_active,
                            });
                        }}
                    >
                        <DialogTrigger asChild>
                            <Button size="icon" variant="ghost" className="h-7 w-7">
                                <Pencil className="h-3.5 w-3.5" />
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="sm:max-w-2xl max-h-[85vh] overflow-y-auto">
                            <DialogHeader>
                                <DialogTitle>Edit service context</DialogTitle>
                                <DialogDescription>
                                    Update service details, funding, staffing, and contact information.
                                </DialogDescription>
                            </DialogHeader>

                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    editForm.put(`/settings/service-contexts/${c.id}`, {
                                        onSuccess: () => setEditing(null),
                                    });
                                }}
                            >
                              <Tabs defaultValue="details" className="w-full">
                                <TabsList className="mb-4 grid w-full grid-cols-4">
                                    <TabsTrigger value="details">Details</TabsTrigger>
                                    <TabsTrigger value="service">Service</TabsTrigger>
                                    <TabsTrigger value="funding">Funding</TabsTrigger>
                                    <TabsTrigger value="contact">Contact</TabsTrigger>
                                </TabsList>

                                <TabsContent value="details" className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Type</Label>
                                        <Select value={editForm.data.type} onValueChange={(v) => editForm.setData('type', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                            <SelectContent>
                                                {CATEGORY_ORDER.map((cat) => {
                                                    const catTypes = typesByCategory[cat];
                                                    if (!catTypes?.length) return null;
                                                    return (
                                                        <SelectGroup key={cat}>
                                                            <SelectLabel className={`text-xs font-semibold ${CATEGORY_COLOURS[cat]?.text ?? ''}`}>{cat}</SelectLabel>
                                                            {catTypes.map((t) => (<SelectItem key={t.code} value={t.code}>{t.label}</SelectItem>))}
                                                        </SelectGroup>
                                                    );
                                                })}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Name</Label>
                                        <Input value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} />
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <Label>Description</Label>
                                    <Textarea value={editForm.data.description} onChange={(e) => editForm.setData('description', e.target.value)} rows={2} />
                                </div>
                                <div className="flex items-center gap-2">
                                    <Checkbox checked={!!editForm.data.is_active} onCheckedChange={(v) => editForm.setData('is_active', !!v)} />
                                    <span className="text-sm">Active</span>
                                </div>

                                </TabsContent>

                                <TabsContent value="service" className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Site</Label>
                                        <select className="w-full rounded-md border bg-transparent p-2 text-sm" value={editForm.data.site_id ?? ''} onChange={(e) => editForm.setData('site_id', e.target.value === '' ? '' : Number(e.target.value))}>
                                            <option value="">-- None --</option>
                                            {sites.map((s) => (<option key={s.id} value={s.id}>{s.name}{s.is_active === false ? ' (inactive)' : ''}</option>))}
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Operating Model</Label>
                                        <select className="w-full rounded-md border bg-transparent p-2 text-sm" value={(editForm.data as any).operating_model ?? ''} onChange={(e) => (editForm as any).setData('operating_model', e.target.value)}>
                                            <option value="">-- Select --</option>
                                            <option value="24_7_residential">24/7 Residential</option>
                                            <option value="day_programme">Day Programme (Business Hours)</option>
                                            <option value="after_hours">After Hours</option>
                                            <option value="on_call">On-Call</option>
                                            <option value="flexible">Flexible</option>
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Max Capacity</Label>
                                        <Input type="number" min={0} value={(editForm.data as any).max_capacity ?? ''} onChange={(e) => (editForm as any).setData('max_capacity', e.target.value)} placeholder="e.g. 6" />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Staff Ratio</Label>
                                        <Input value={(editForm.data as any).staff_ratio ?? ''} onChange={(e) => (editForm as any).setData('staff_ratio', e.target.value)} placeholder="e.g. 1:3" />
                                    </div>
                                </div>

                                </TabsContent>

                                <TabsContent value="funding" className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Funding Body</Label>
                                        <select className="w-full rounded-md border bg-transparent p-2 text-sm" value={(editForm.data as any).funding_body ?? ''} onChange={(e) => (editForm as any).setData('funding_body', e.target.value)}>
                                            <option value="">-- Select --</option>
                                            <option value="whaikaha">Whaikaha</option>
                                            <option value="msd">MSD</option>
                                            <option value="acc">ACC</option>
                                            <option value="health_nz">Health NZ</option>
                                            <option value="oranga_tamariki">Oranga Tamariki</option>
                                            <option value="private">Private</option>
                                            <option value="self_funded">Self-funded</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Funding Type</Label>
                                        <select className="w-full rounded-md border bg-transparent p-2 text-sm" value={(editForm.data as any).funding_type ?? ''} onChange={(e) => (editForm as any).setData('funding_type', e.target.value)}>
                                            <option value="">-- Select --</option>
                                            <option value="if">Individualised Funding (IF)</option>
                                            <option value="eif">Enhanced IF (EIF)</option>
                                            <option value="flexible">Flexible</option>
                                            <option value="contract">Contract</option>
                                            <option value="fee_for_service">Fee for Service</option>
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Whaikaha Reference</Label>
                                        <Input value={(editForm.data as any).whaikaha_reference ?? ''} onChange={(e) => (editForm as any).setData('whaikaha_reference', e.target.value)} placeholder="e.g. WHK-2026-001" />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Contract Reference</Label>
                                        <Input value={(editForm.data as any).contract_reference ?? ''} onChange={(e) => (editForm as any).setData('contract_reference', e.target.value)} placeholder="e.g. CON-2026-001" />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Audit Status</Label>
                                        <select className="w-full rounded-md border bg-transparent p-2 text-sm" value={(editForm.data as any).audit_status ?? ''} onChange={(e) => (editForm as any).setData('audit_status', e.target.value)}>
                                            <option value="">-- Select --</option>
                                            <option value="current">Current</option>
                                            <option value="due">Due</option>
                                            <option value="overdue">Overdue</option>
                                            <option value="exempt">Exempt</option>
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Next Audit Date</Label>
                                        <Input type="date" value={(editForm.data as any).next_audit_date ?? ''} onChange={(e) => (editForm as any).setData('next_audit_date', e.target.value)} />
                                    </div>
                                </div>

                                </TabsContent>

                                <TabsContent value="contact" className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label>Name</Label>
                                        <Input value={(editForm.data as any).coordinator_name ?? ''} onChange={(e) => (editForm as any).setData('coordinator_name', e.target.value)} placeholder="Full name" />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Email</Label>
                                        <Input type="email" value={(editForm.data as any).coordinator_email ?? ''} onChange={(e) => (editForm as any).setData('coordinator_email', e.target.value)} placeholder="email@example.com" />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Phone</Label>
                                        <Input value={(editForm.data as any).coordinator_phone ?? ''} onChange={(e) => (editForm as any).setData('coordinator_phone', e.target.value)} placeholder="021 XXX XXXX" />
                                    </div>
                                </div>

                                </TabsContent>
                              </Tabs>

                                <DialogFooter className="mt-4">
                                    <Button type="button" variant="outline" onClick={() => setEditing(null)}>Cancel</Button>
                                    <Button type="submit" disabled={editForm.processing}>Save</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                    <Button
                        size="icon"
                        variant="ghost"
                        className="h-7 w-7 text-muted-foreground hover:text-status-warning"
                        onClick={() => {
                            // Toggle active/inactive as archive action
                            editForm.setData({
                                type: c.type ?? (types?.[0]?.code ?? 'residential'),
                                name: c.name ?? '',
                                description: c.description ?? '',
                                site_id: c.site_id ?? '',
                                is_active: !c.is_active,
                            });
                            editForm.put(`/settings/service-contexts/${c.id}`);
                        }}
                        title={c.is_active ? 'Archive' : 'Restore'}
                    >
                        <Archive className="h-3.5 w-3.5" />
                    </Button>
                </div>
            </div>
        </div>
    );
}
