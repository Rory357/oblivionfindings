import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    HardHat,
    Package,
    ClipboardCheck,
    Ban,
    Search,
    Plus,
    RotateCcw,
    CheckCircle2,
    XCircle,
    ShieldCheck,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type Staff = { id: number; name: string };
type Site = { id: number; name: string };

type PpeType = {
    id: number;
    name: string;
    category: string;
    description: string | null;
    hazards_addressed: string | null;
    standards_reference: string | null;
    inspection_frequency: string | null;
    typical_lifespan_months: number | null;
};

type InventoryItem = {
    id: number;
    ppe_type: PpeType | null;
    site: Site | null;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    purchase_date: string | null;
    expiry_date: string | null;
    quantity: number;
    location: string | null;
    condition: string;
    status: string;
    next_inspection_due: string | null;
};

type Allocation = {
    id: number;
    user: Staff | null;
    inventory_item: InventoryItem | null;
    ppe_type_name: string | null;
    allocated_date: string;
    fit_test_completed: boolean;
    training_completed: boolean;
    acknowledged: boolean;
    returned_at: string | null;
};

type Props = {
    types: PpeType[];
    inventory: {
        data: InventoryItem[];
        links: Array<{ label: string; url: string | null; active: boolean }>;
    };
    allocations?: {
        data: Allocation[];
        links: Array<{ label: string; url: string | null; active: boolean }>;
    };
    stats: {
        total_items: number;
        allocated: number;
        inspections_due: number;
        condemned: number;
    };
    sites: Site[];
    staff: Staff[];
    can_manage: boolean;
    filters?: {
        site_id: string | null;
        ppe_type_id: string | null;
        condition: string | null;
        status: string | null;
    };
};

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const ANY = '__any__';

const fmtDate = (v: string | null) =>
    v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

const conditionColor = (c: string) => {
    switch (c) {
        case 'new':
            return 'bg-green-100 text-green-800';
        case 'good':
            return 'bg-blue-100 text-blue-800';
        case 'fair':
            return 'bg-amber-100 text-amber-800';
        case 'poor':
            return 'bg-orange-100 text-orange-800';
        case 'condemned':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-muted text-foreground';
    }
};

const statusColor = (s: string) => {
    switch (s) {
        case 'available':
            return 'bg-green-100 text-green-800';
        case 'allocated':
            return 'bg-blue-100 text-blue-800';
        case 'in_repair':
            return 'bg-amber-100 text-amber-800';
        case 'condemned':
            return 'bg-red-100 text-red-800';
        case 'retired':
            return 'bg-muted text-foreground';
        default:
            return 'bg-muted text-foreground';
    }
};

const categoryColor = (c: string) => {
    switch (c) {
        case 'head':
            return 'bg-blue-100 text-blue-800';
        case 'eye':
            return 'bg-cyan-100 text-cyan-800';
        case 'ear':
            return 'bg-teal-100 text-teal-800';
        case 'respiratory':
            return 'bg-primary/10 text-primary';
        case 'hand':
            return 'bg-amber-100 text-amber-800';
        case 'foot':
            return 'bg-orange-100 text-orange-800';
        case 'body':
            return 'bg-primary/10 text-primary';
        case 'fall_protection':
            return 'bg-red-100 text-red-800';
        case 'high_visibility':
            return 'bg-yellow-100 text-yellow-800';
        default:
            return 'bg-muted text-foreground';
    }
};

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function PpeIndex({ types, inventory, allocations, stats, sites, staff, filters, can_manage }: Props) {
    const currentFilters = filters ?? { site_id: null, ppe_type_id: null, condition: null, status: null };

    /* Dialog states */
    const [addItemOpen, setAddItemOpen] = useState(false);
    const [addTypeOpen, setAddTypeOpen] = useState(false);
    const [allocateOpen, setAllocateOpen] = useState(false);
    const [allocateItemId, setAllocateItemId] = useState<number | null>(null);
    const [inspectOpen, setInspectOpen] = useState(false);
    const [inspectItemId, setInspectItemId] = useState<number | null>(null);

    /* Forms */
    const addItemForm = useForm({
        ppe_type_id: '',
        site_id: '',
        brand: '',
        model: '',
        serial_number: '',
        purchase_date: '',
        expiry_date: '',
        quantity: '1',
        location: '',
    });

    const addTypeForm = useForm({
        name: '',
        category: '',
        description: '',
        hazards_addressed: '',
        standards_reference: '',
        inspection_frequency: '',
        typical_lifespan_months: '',
    });

    const allocateForm = useForm({
        user_id: '',
        fit_test_completed: false as boolean,
        fit_test_date: '',
        fit_test_result: '',
        training_completed: false as boolean,
        training_date: '',
    });

    const inspectForm = useForm({
        result: '',
        condition_after: '',
        findings: '',
        action_taken: '',
        next_inspection_due: '',
    });

    /* Filter handler */
    const onFilter = (next: Partial<typeof currentFilters>) => {
        router.get(
            '/health-safety/ppe',
            { ...currentFilters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    /* Submit handlers */
    const submitAddItem = () => {
        addItemForm.post('/health-safety/ppe/inventory', {
            onSuccess: () => {
                setAddItemOpen(false);
                addItemForm.reset();
            },
        });
    };

    const submitAddType = () => {
        addTypeForm.post('/health-safety/ppe/types', {
            onSuccess: () => {
                setAddTypeOpen(false);
                addTypeForm.reset();
            },
        });
    };

    const submitAllocate = () => {
        if (!allocateItemId) return;
        allocateForm.post(`/health-safety/ppe/inventory/${allocateItemId}/allocate`, {
            onSuccess: () => {
                setAllocateOpen(false);
                allocateForm.reset();
            },
        });
    };

    const submitInspection = () => {
        if (!inspectItemId) return;
        inspectForm.post(`/health-safety/ppe/inventory/${inspectItemId}/inspections`, {
            onSuccess: () => {
                setInspectOpen(false);
                inspectForm.reset();
            },
        });
    };

    const submitReturn = (allocationId: number) => {
        router.post(`/health-safety/ppe/allocations/${allocationId}/return`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'PPE Management', href: '/health-safety/ppe' },
            ]}
        >
            <Head title="PPE Management" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="PPE Management"
                    description="Manage personal protective equipment inventory, allocations, and inspections"
                    icon={<Package className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Total Items', value: stats.total_items },
                        { label: 'Allocated', value: stats.allocated },
                        { label: 'Inspections Due', value: stats.inspections_due },
                        { label: 'Condemned', value: stats.condemned },
                    ]}
                />

                {/* Tabs */}
                <Tabs defaultValue="inventory">
                    <TabsList>
                        <TabsTrigger value="inventory">Inventory</TabsTrigger>
                        <TabsTrigger value="types">PPE Types</TabsTrigger>
                        <TabsTrigger value="allocations">Allocations</TabsTrigger>
                    </TabsList>

                    {/* ========== INVENTORY TAB ========== */}
                    <TabsContent value="inventory" className="space-y-4">
                        {/* Filters */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center justify-between text-base">
                                    <span className="flex items-center gap-2">
                                        <Search className="h-4 w-4" />
                                        Filters
                                    </span>
                                    {can_manage && (
                                        <Button size="sm" onClick={() => setAddItemOpen(true)}>
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Add Item
                                        </Button>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                                <div>
                                    <Label className="text-xs text-muted-foreground">Site</Label>
                                    <Select
                                        value={currentFilters.site_id ?? ANY}
                                        onValueChange={(v) => onFilter({ site_id: v === ANY ? null : v })}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Site" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ANY}>All Sites</SelectItem>
                                            {sites.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label className="text-xs text-muted-foreground">PPE Type</Label>
                                    <Select
                                        value={currentFilters.ppe_type_id ?? ANY}
                                        onValueChange={(v) => onFilter({ ppe_type_id: v === ANY ? null : v })}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ANY}>All Types</SelectItem>
                                            {types.map((t) => (
                                                <SelectItem key={t.id} value={String(t.id)}>
                                                    {t.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label className="text-xs text-muted-foreground">Condition</Label>
                                    <Select
                                        value={currentFilters.condition ?? ANY}
                                        onValueChange={(v) => onFilter({ condition: v === ANY ? null : v })}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Condition" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ANY}>Any</SelectItem>
                                            {['new', 'good', 'fair', 'poor', 'condemned'].map((c) => (
                                                <SelectItem key={c} value={c}>
                                                    {c.charAt(0).toUpperCase() + c.slice(1)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label className="text-xs text-muted-foreground">Status</Label>
                                    <Select
                                        value={currentFilters.status ?? ANY}
                                        onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Status" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ANY}>Any</SelectItem>
                                            {['available', 'allocated', 'in_repair', 'condemned', 'retired'].map(
                                                (s) => (
                                                    <SelectItem key={s} value={s}>
                                                        {s.replace(/_/g, ' ')}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Inventory Table */}
                        <Card>
                            <CardContent className="pt-6">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-xs text-muted-foreground">
                                                <th className="pb-2 pr-4 font-medium">Type</th>
                                                <th className="pb-2 pr-4 font-medium">Brand / Model</th>
                                                <th className="pb-2 pr-4 font-medium">Serial #</th>
                                                <th className="pb-2 pr-4 font-medium">Site</th>
                                                <th className="pb-2 pr-4 font-medium">Location</th>
                                                <th className="pb-2 pr-4 font-medium">Condition</th>
                                                <th className="pb-2 pr-4 font-medium">Status</th>
                                                <th className="pb-2 pr-4 font-medium">Next Inspection</th>
                                                <th className="pb-2 font-medium">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {inventory.data.map((item) => (
                                                <tr key={item.id} className="border-b last:border-0">
                                                    <td className="py-2.5 pr-4 font-medium">
                                                        {item.ppe_type?.name ?? '-'}
                                                    </td>
                                                    <td className="py-2.5 pr-4 text-xs">
                                                        {[item.brand, item.model].filter(Boolean).join(' ') || '-'}
                                                    </td>
                                                    <td className="py-2.5 pr-4 text-xs font-mono">
                                                        {item.serial_number ?? '-'}
                                                    </td>
                                                    <td className="py-2.5 pr-4 text-xs">{item.site?.name ?? '-'}</td>
                                                    <td className="py-2.5 pr-4 text-xs">{item.location ?? '-'}</td>
                                                    <td className="py-2.5 pr-4">
                                                        <Badge className={conditionColor(item.condition)}>
                                                            {item.condition}
                                                        </Badge>
                                                    </td>
                                                    <td className="py-2.5 pr-4">
                                                        <Badge className={statusColor(item.status)}>
                                                            {item.status.replace(/_/g, ' ')}
                                                        </Badge>
                                                    </td>
                                                    <td className="py-2.5 pr-4 text-xs">
                                                        {item.next_inspection_due ? (
                                                            <span
                                                                className={
                                                                    new Date(item.next_inspection_due) < new Date()
                                                                        ? 'font-medium text-red-600'
                                                                        : ''
                                                                }
                                                            >
                                                                {fmtDate(item.next_inspection_due)}
                                                            </span>
                                                        ) : (
                                                            '-'
                                                        )}
                                                    </td>
                                                    <td className="py-2.5">
                                                        <div className="flex flex-wrap gap-1.5">
                                                            {can_manage && item.status === 'available' && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="h-7 text-xs"
                                                                    onClick={() => {
                                                                        setAllocateItemId(item.id);
                                                                        setAllocateOpen(true);
                                                                    }}
                                                                >
                                                                    Allocate
                                                                </Button>
                                                            )}
                                                            {can_manage &&
                                                                item.status !== 'condemned' &&
                                                                item.status !== 'retired' && (
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        className="h-7 text-xs"
                                                                        onClick={() => {
                                                                            setInspectItemId(item.id);
                                                                            setInspectOpen(true);
                                                                        }}
                                                                    >
                                                                        Inspect
                                                                    </Button>
                                                                )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {!inventory.data.length && (
                                        <div className="py-6 text-center text-sm text-muted-foreground">
                                            No PPE inventory items found.
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Inventory Pagination */}
                        {inventory?.links?.length ? (
                            <div className="flex flex-wrap gap-2">
                                {inventory.links.map((l) => (
                                    <button
                                        key={l.label}
                                        disabled={!l.url}
                                        className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                        onClick={() =>
                                            l.url &&
                                            router.get(l.url, {}, { preserveState: true, preserveScroll: true })
                                        }
                                        dangerouslySetInnerHTML={{ __html: l.label }}
                                    />
                                ))}
                            </div>
                        ) : null}
                    </TabsContent>

                    {/* ========== PPE TYPES TAB ========== */}
                    <TabsContent value="types" className="space-y-4">
                        {can_manage && (
                            <div className="flex justify-end">
                                <Button size="sm" onClick={() => setAddTypeOpen(true)}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add Type
                                </Button>
                            </div>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {types.map((t) => (
                                <Card key={t.id}>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center justify-between text-sm">
                                            <span className="font-semibold">{t.name}</span>
                                            <Badge className={categoryColor(t.category)}>
                                                {t.category.replace(/_/g, ' ')}
                                            </Badge>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-2 text-xs text-muted-foreground">
                                        {t.description && <p>{t.description}</p>}
                                        {t.hazards_addressed && (
                                            <div>
                                                <span className="font-medium text-foreground">Hazards: </span>
                                                {t.hazards_addressed}
                                            </div>
                                        )}
                                        {t.standards_reference && (
                                            <div>
                                                <span className="font-medium text-foreground">Standards: </span>
                                                {t.standards_reference}
                                            </div>
                                        )}
                                        {t.inspection_frequency && (
                                            <div>
                                                <span className="font-medium text-foreground">Inspection: </span>
                                                {t.inspection_frequency.replace(/_/g, ' ')}
                                            </div>
                                        )}
                                        {t.typical_lifespan_months && (
                                            <div>
                                                <span className="font-medium text-foreground">Lifespan: </span>
                                                {t.typical_lifespan_months} months
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                            {!types.length && (
                                <div className="col-span-full py-8 text-center text-sm text-muted-foreground">
                                    No PPE types defined yet.
                                </div>
                            )}
                        </div>
                    </TabsContent>

                    {/* ========== ALLOCATIONS TAB ========== */}
                    <TabsContent value="allocations" className="space-y-4">
                        <Card>
                            <CardContent className="pt-6">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-xs text-muted-foreground">
                                                <th className="pb-2 pr-4 font-medium">Worker</th>
                                                <th className="pb-2 pr-4 font-medium">PPE Type</th>
                                                <th className="pb-2 pr-4 font-medium">Item</th>
                                                <th className="pb-2 pr-4 font-medium">Allocated</th>
                                                <th className="pb-2 pr-4 font-medium">Fit Test</th>
                                                <th className="pb-2 pr-4 font-medium">Training</th>
                                                <th className="pb-2 pr-4 font-medium">Acknowledged</th>
                                                <th className="pb-2 font-medium">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(allocations?.data ?? []).map((a) => (
                                                <tr key={a.id} className="border-b last:border-0">
                                                    <td className="py-2.5 pr-4 font-medium">
                                                        {a.user?.name ?? '-'}
                                                    </td>
                                                    <td className="py-2.5 pr-4 text-xs">
                                                        {a.ppe_type_name ?? a.inventory_item?.ppe_type?.name ?? '-'}
                                                    </td>
                                                    <td className="py-2.5 pr-4 text-xs font-mono">
                                                        {a.inventory_item?.serial_number ?? '-'}
                                                    </td>
                                                    <td className="py-2.5 pr-4 text-xs">
                                                        {fmtDate(a.allocated_date)}
                                                    </td>
                                                    <td className="py-2.5 pr-4">
                                                        {a.fit_test_completed ? (
                                                            <CheckCircle2 className="h-4 w-4 text-green-600" />
                                                        ) : (
                                                            <XCircle className="h-4 w-4 text-red-400" />
                                                        )}
                                                    </td>
                                                    <td className="py-2.5 pr-4">
                                                        {a.training_completed ? (
                                                            <CheckCircle2 className="h-4 w-4 text-green-600" />
                                                        ) : (
                                                            <XCircle className="h-4 w-4 text-red-400" />
                                                        )}
                                                    </td>
                                                    <td className="py-2.5 pr-4">
                                                        {a.acknowledged ? (
                                                            <Badge className="bg-green-100 text-green-800">Yes</Badge>
                                                        ) : (
                                                            <Badge className="bg-muted text-muted-foreground">No</Badge>
                                                        )}
                                                    </td>
                                                    <td className="py-2.5">
                                                        {can_manage && !a.returned_at && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                className="h-7 text-xs"
                                                                onClick={() => submitReturn(a.id)}
                                                            >
                                                                <RotateCcw className="mr-1 h-3 w-3" />
                                                                Return
                                                            </Button>
                                                        )}
                                                        {a.returned_at && (
                                                            <span className="text-xs text-muted-foreground">
                                                                Returned {fmtDate(a.returned_at)}
                                                            </span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {!(allocations?.data ?? []).length && (
                                        <div className="py-6 text-center text-sm text-muted-foreground">
                                            No PPE allocations found.
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Allocations Pagination */}
                        {allocations?.links?.length ? (
                            <div className="flex flex-wrap gap-2">
                                {allocations.links.map((l) => (
                                    <button
                                        key={l.label}
                                        disabled={!l.url}
                                        className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                        onClick={() =>
                                            l.url &&
                                            router.get(l.url, {}, { preserveState: true, preserveScroll: true })
                                        }
                                        dangerouslySetInnerHTML={{ __html: l.label }}
                                    />
                                ))}
                            </div>
                        ) : null}
                    </TabsContent>
                </Tabs>
            </div>

            {/* ============================================================ */}
            {/*  Dialogs                                                      */}
            {/* ============================================================ */}

            {/* Add Item Dialog */}
            <Dialog open={addItemOpen} onOpenChange={setAddItemOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Add PPE Item</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>PPE Type</Label>
                                <Select
                                    value={addItemForm.data.ppe_type_id}
                                    onValueChange={(v) => addItemForm.setData('ppe_type_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {types.map((t) => (
                                            <SelectItem key={t.id} value={String(t.id)}>
                                                {t.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {addItemForm.errors.ppe_type_id && (
                                    <p className="mt-1 text-xs text-red-600">{addItemForm.errors.ppe_type_id}</p>
                                )}
                            </div>
                            <div>
                                <Label>Site</Label>
                                <Select
                                    value={addItemForm.data.site_id}
                                    onValueChange={(v) => addItemForm.setData('site_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select site" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sites.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {addItemForm.errors.site_id && (
                                    <p className="mt-1 text-xs text-red-600">{addItemForm.errors.site_id}</p>
                                )}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Brand</Label>
                                <Input
                                    value={addItemForm.data.brand}
                                    onChange={(e) => addItemForm.setData('brand', e.target.value)}
                                    placeholder="e.g. 3M"
                                />
                            </div>
                            <div>
                                <Label>Model</Label>
                                <Input
                                    value={addItemForm.data.model}
                                    onChange={(e) => addItemForm.setData('model', e.target.value)}
                                    placeholder="e.g. SecureFit 400"
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Serial Number</Label>
                                <Input
                                    value={addItemForm.data.serial_number}
                                    onChange={(e) => addItemForm.setData('serial_number', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label>Quantity</Label>
                                <Input
                                    type="number"
                                    min="1"
                                    value={addItemForm.data.quantity}
                                    onChange={(e) => addItemForm.setData('quantity', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Purchase Date</Label>
                                <Input
                                    type="date"
                                    value={addItemForm.data.purchase_date}
                                    onChange={(e) => addItemForm.setData('purchase_date', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label>Expiry Date</Label>
                                <Input
                                    type="date"
                                    value={addItemForm.data.expiry_date}
                                    onChange={(e) => addItemForm.setData('expiry_date', e.target.value)}
                                />
                            </div>
                        </div>

                        <div>
                            <Label>Storage Location</Label>
                            <Input
                                value={addItemForm.data.location}
                                onChange={(e) => addItemForm.setData('location', e.target.value)}
                                placeholder="e.g. Main store room, Shelf B3"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAddItemOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submitAddItem} disabled={addItemForm.processing}>
                            Add Item
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Add PPE Type Dialog */}
            <Dialog open={addTypeOpen} onOpenChange={setAddTypeOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Add PPE Type</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Name</Label>
                                <Input
                                    value={addTypeForm.data.name}
                                    onChange={(e) => addTypeForm.setData('name', e.target.value)}
                                    placeholder="e.g. Safety Glasses"
                                />
                                {addTypeForm.errors.name && (
                                    <p className="mt-1 text-xs text-red-600">{addTypeForm.errors.name}</p>
                                )}
                            </div>
                            <div>
                                <Label>Category</Label>
                                <Select
                                    value={addTypeForm.data.category}
                                    onValueChange={(v) => addTypeForm.setData('category', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select category" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {[
                                            'head',
                                            'eye',
                                            'ear',
                                            'respiratory',
                                            'hand',
                                            'foot',
                                            'body',
                                            'fall_protection',
                                            'high_visibility',
                                            'other',
                                        ].map((c) => (
                                            <SelectItem key={c} value={c}>
                                                {c.replace(/_/g, ' ')}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {addTypeForm.errors.category && (
                                    <p className="mt-1 text-xs text-red-600">{addTypeForm.errors.category}</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <Label>Description</Label>
                            <Textarea
                                value={addTypeForm.data.description}
                                onChange={(e) => addTypeForm.setData('description', e.target.value)}
                                placeholder="Describe the PPE type"
                                rows={2}
                            />
                        </div>

                        <div>
                            <Label>Hazards Addressed</Label>
                            <Textarea
                                value={addTypeForm.data.hazards_addressed}
                                onChange={(e) => addTypeForm.setData('hazards_addressed', e.target.value)}
                                placeholder="e.g. Chemical splash, impact hazards"
                                rows={2}
                            />
                        </div>

                        <div>
                            <Label>Standards Reference</Label>
                            <Input
                                value={addTypeForm.data.standards_reference}
                                onChange={(e) => addTypeForm.setData('standards_reference', e.target.value)}
                                placeholder="e.g. AS/NZS 1337.1:2010"
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <Label>Inspection Frequency</Label>
                                <Select
                                    value={addTypeForm.data.inspection_frequency}
                                    onValueChange={(v) => addTypeForm.setData('inspection_frequency', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select frequency" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {['before_each_use', 'weekly', 'monthly', 'quarterly', 'six_monthly', 'annually'].map(
                                            (f) => (
                                                <SelectItem key={f} value={f}>
                                                    {f.replace(/_/g, ' ')}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Typical Lifespan (months)</Label>
                                <Input
                                    type="number"
                                    min="1"
                                    value={addTypeForm.data.typical_lifespan_months}
                                    onChange={(e) => addTypeForm.setData('typical_lifespan_months', e.target.value)}
                                    placeholder="e.g. 24"
                                />
                            </div>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAddTypeOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submitAddType} disabled={addTypeForm.processing}>
                            Add Type
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Allocate Dialog */}
            <Dialog open={allocateOpen} onOpenChange={setAllocateOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Allocate PPE</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <Label>Worker</Label>
                            <Select
                                value={allocateForm.data.user_id}
                                onValueChange={(v) => allocateForm.setData('user_id', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select staff member" />
                                </SelectTrigger>
                                <SelectContent>
                                    {staff.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {allocateForm.errors.user_id && (
                                <p className="mt-1 text-xs text-red-600">{allocateForm.errors.user_id}</p>
                            )}
                        </div>

                        <div className="space-y-3 rounded-lg border p-3">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="fit_test"
                                    checked={allocateForm.data.fit_test_completed}
                                    onCheckedChange={(checked) =>
                                        allocateForm.setData('fit_test_completed', checked === true)
                                    }
                                />
                                <Label htmlFor="fit_test" className="text-sm font-normal">
                                    Fit test completed
                                </Label>
                            </div>
                            {allocateForm.data.fit_test_completed && (
                                <div className="grid grid-cols-2 gap-3 pl-6">
                                    <div>
                                        <Label className="text-xs">Fit Test Date</Label>
                                        <Input
                                            type="date"
                                            value={allocateForm.data.fit_test_date}
                                            onChange={(e) => allocateForm.setData('fit_test_date', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <Label className="text-xs">Result</Label>
                                        <Select
                                            value={allocateForm.data.fit_test_result}
                                            onValueChange={(v) => allocateForm.setData('fit_test_result', v)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Result" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="pass">Pass</SelectItem>
                                                <SelectItem value="fail">Fail</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="space-y-3 rounded-lg border p-3">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="training"
                                    checked={allocateForm.data.training_completed}
                                    onCheckedChange={(checked) =>
                                        allocateForm.setData('training_completed', checked === true)
                                    }
                                />
                                <Label htmlFor="training" className="text-sm font-normal">
                                    Training completed
                                </Label>
                            </div>
                            {allocateForm.data.training_completed && (
                                <div className="pl-6">
                                    <Label className="text-xs">Training Date</Label>
                                    <Input
                                        type="date"
                                        value={allocateForm.data.training_date}
                                        onChange={(e) => allocateForm.setData('training_date', e.target.value)}
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAllocateOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submitAllocate} disabled={allocateForm.processing}>
                            <ShieldCheck className="mr-1.5 h-4 w-4" />
                            Allocate
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Inspection Dialog */}
            <Dialog open={inspectOpen} onOpenChange={setInspectOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>PPE Inspection</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div>
                            <Label>Result</Label>
                            <Select
                                value={inspectForm.data.result}
                                onValueChange={(v) => inspectForm.setData('result', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select result" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="pass">Pass</SelectItem>
                                    <SelectItem value="fail">Fail</SelectItem>
                                    <SelectItem value="needs_repair">Needs Repair</SelectItem>
                                    <SelectItem value="condemned">Condemned</SelectItem>
                                </SelectContent>
                            </Select>
                            {inspectForm.errors.result && (
                                <p className="mt-1 text-xs text-red-600">{inspectForm.errors.result}</p>
                            )}
                        </div>

                        <div>
                            <Label>Condition After Inspection</Label>
                            <Select
                                value={inspectForm.data.condition_after}
                                onValueChange={(v) => inspectForm.setData('condition_after', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select condition" />
                                </SelectTrigger>
                                <SelectContent>
                                    {['new', 'good', 'fair', 'poor', 'condemned'].map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c.charAt(0).toUpperCase() + c.slice(1)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label>Findings</Label>
                            <Textarea
                                value={inspectForm.data.findings}
                                onChange={(e) => inspectForm.setData('findings', e.target.value)}
                                placeholder="Describe any issues or observations"
                                rows={3}
                            />
                        </div>

                        <div>
                            <Label>Action Taken</Label>
                            <Textarea
                                value={inspectForm.data.action_taken}
                                onChange={(e) => inspectForm.setData('action_taken', e.target.value)}
                                placeholder="Describe any corrective actions"
                                rows={2}
                            />
                        </div>

                        <div>
                            <Label>Next Inspection Due</Label>
                            <Input
                                type="date"
                                value={inspectForm.data.next_inspection_due}
                                onChange={(e) => inspectForm.setData('next_inspection_due', e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setInspectOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submitInspection} disabled={inspectForm.processing}>
                            <ClipboardCheck className="mr-1.5 h-4 w-4" />
                            Save Inspection
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
