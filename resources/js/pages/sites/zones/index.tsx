import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
    type PageHeaderRailItem,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { LayoutGrid, Map, MapPin, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmAction } from '../_confirm-action';

type Site = {
    id: number;
    name: string;
};

type Zone = {
    id: number;
    name: string;
    description?: string;
    zone_type?: string;
    is_active: boolean;
};

type Props = {
    site: Site;
    zones: Zone[];
};

type View = 'active' | 'inactive';

export default function SiteZones({ site, zones }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [editingZone, setEditingZone] = useState<Zone | null>(null);
    const [view, setView] = useState<View>('active');
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('all');

    const form = useForm({
        name: '',
        zone_type: '',
        description: '',
    });

    const deleteForm = useForm({});

    const startEdit = (zone: Zone) => {
        setEditingZone(zone);
        form.setData({
            name: zone.name,
            zone_type: zone.zone_type || '',
            description: zone.description || '',
        });
        setShowForm(true);
    };

    const resetForm = () => {
        setEditingZone(null);
        setShowForm(false);
        form.reset();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingZone) {
            form.put(`/sites/${site.id}/zones/${editingZone.id}`, {
                onSuccess: resetForm,
            });
        } else {
            form.post(`/sites/${site.id}/zones`, {
                onSuccess: resetForm,
            });
        }
    };

    const handleDeactivate = (zone: Zone) => {
        deleteForm.delete(`/sites/${site.id}/zones/${zone.id}`);
    };

    const activeZones = zones.filter((z) => z.is_active);
    const inactiveCount = zones.length - activeZones.length;
    const zoneTypes = useMemo(
        () => [
            ...new Set(
                zones
                    .map((z) => z.zone_type)
                    .filter((t): t is string => !!t && t.length > 0),
            ),
        ],
        [zones],
    );

    const list = useMemo(() => {
        const q = search.trim().toLowerCase();
        return zones.filter(
            (z) =>
                (view === 'active' ? z.is_active : !z.is_active) &&
                (typeFilter === 'all' || z.zone_type === typeFilter) &&
                (q === '' ||
                    `${z.name} ${z.zone_type ?? ''} ${z.description ?? ''}`
                        .toLowerCase()
                        .includes(q)),
        );
    }, [zones, view, typeFilter, search]);

    const railItems: PageHeaderRailItem<View>[] = [
        {
            key: 'active',
            label: 'Active',
            icon: LayoutGrid,
            count: activeZones.length,
        },
        {
            key: 'inactive',
            label: 'Inactive',
            icon: Trash2,
            count: inactiveCount,
        },
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Zones', href: `/sites/${site.id}/zones` },
            ]}
        >
            <Head title={`${site.name} - Zones`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref={`/sites/${site.id}`}
                        icon={Map}
                        title={site.name}
                        titleChip={
                            activeZones.length > 0 ? (
                                <PageHeaderStatusChip variant="success">
                                    {activeZones.length} active
                                </PageHeaderStatusChip>
                            ) : (
                                <PageHeaderStatusChip variant="neutral">
                                    No zones yet
                                </PageHeaderStatusChip>
                            )
                        }
                        subline={`Areas & zones · ${zones.length} ${zones.length === 1 ? 'zone' : 'zones'} · ${zoneTypes.length} ${zoneTypes.length === 1 ? 'type' : 'types'}`}
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    placeholder="Search zones, types…"
                                />
                                <PageHeaderPrimaryButton
                                    icon={Plus}
                                    onClick={() => setShowForm(true)}
                                >
                                    Add zone
                                </PageHeaderPrimaryButton>
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Active zones"
                                    ariaLabel="View active zones"
                                    onClick={() => setView('active')}
                                >
                                    <PageHeaderMeterBig>
                                        {activeZones.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        in use across the site
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Zone types"
                                    ariaLabel="View active zones by type"
                                    onClick={() => setView('active')}
                                >
                                    <PageHeaderMeterBig>
                                        {zoneTypes.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        categories in use
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Inactive"
                                    ariaLabel="View deactivated zones"
                                    onClick={() => setView('inactive')}
                                >
                                    <PageHeaderMeterBig>
                                        {inactiveCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        deactivated zones
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <PageHeaderFilterSelect
                                icon={MapPin}
                                label="All types"
                                value={typeFilter}
                                options={[
                                    { value: 'all', label: 'All types' },
                                    ...zoneTypes.map((t) => ({
                                        value: t,
                                        label: t,
                                    })),
                                ]}
                                onChange={setTypeFilter}
                            />
                        }
                        rail={
                            <PageHeaderRail
                                items={railItems}
                                value={view}
                                onSelect={setView}
                                ariaLabel="Zone views"
                            />
                        }
                    />
                }
            >
                {/* Add/Edit Form */}
                {showForm && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>
                                {editingZone ? 'Edit Zone' : 'Add Zone'}
                            </CardTitle>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={resetForm}
                            >
                                Cancel
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Zone Name *</Label>
                                        <Input
                                            value={form.data.name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g., Workshop Area A"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Zone Type</Label>
                                        <Input
                                            value={form.data.zone_type}
                                            onChange={(e) =>
                                                form.setData(
                                                    'zone_type',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g., Workshop, Café, Storage"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <Label>Description</Label>
                                    <Textarea
                                        value={form.data.description}
                                        onChange={(e) =>
                                            form.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Description of the zone, equipment, or purpose"
                                        rows={3}
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {editingZone ? 'Save Changes' : 'Add Zone'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Zones Grid */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {view === 'active' ? 'Zones' : 'Inactive zones'} (
                            {list.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {list.length === 0 ? (
                            <div className="py-8 text-center text-muted-foreground">
                                <LayoutGrid className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>
                                    {view === 'active'
                                        ? search.trim() || typeFilter !== 'all'
                                            ? 'No zones match your filters.'
                                            : 'No zones configured yet'
                                        : 'No deactivated zones.'}
                                </p>
                            </div>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {list.map((zone) => (
                                    <Card
                                        key={zone.id}
                                        className="transition-colors hover:bg-muted/50"
                                    >
                                        <CardContent className="p-4">
                                            <div className="flex items-start justify-between">
                                                <div className="flex-1">
                                                    <div className="font-medium">
                                                        {zone.name}
                                                    </div>
                                                    {zone.zone_type && (
                                                        <Badge
                                                            variant="outline"
                                                            className="mt-2"
                                                        >
                                                            <MapPin className="mr-1 h-3 w-3" />
                                                            {zone.zone_type}
                                                        </Badge>
                                                    )}
                                                    {zone.description && (
                                                        <div className="mt-2 text-sm text-muted-foreground">
                                                            {zone.description}
                                                        </div>
                                                    )}
                                                </div>
                                                {zone.is_active ? (
                                                    <div className="ml-2 flex gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                startEdit(zone)
                                                            }
                                                        >
                                                            Edit
                                                        </Button>
                                                        <ConfirmAction
                                                            title="Deactivate zone?"
                                                            description={`Deactivate "${zone.name}" for this site?`}
                                                            confirmLabel="Deactivate"
                                                            onConfirm={() =>
                                                                handleDeactivate(
                                                                    zone,
                                                                )
                                                            }
                                                        >
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                className="text-status-critical hover:bg-status-critical hover:text-status-critical"
                                                                disabled={
                                                                    deleteForm.processing
                                                                }
                                                            >
                                                                <Trash2 className="h-4 w-4" />
                                                            </Button>
                                                        </ConfirmAction>
                                                    </div>
                                                ) : (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2 border-muted-foreground/30 text-muted-foreground"
                                                    >
                                                        Inactive
                                                    </Badge>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
