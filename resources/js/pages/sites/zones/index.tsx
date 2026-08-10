import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { LayoutGrid, Map, MapPin, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
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

export default function SiteZones({ site, zones }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [editingZone, setEditingZone] = useState<Zone | null>(null);

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

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Zones', href: `#` },
            ]}
        >
            <Head title={`${site.name} - Zones`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={Map}
                        title="Areas & Zones"
                        description={site.name}
                        backHref={`/sites/${site.id}`}
                        stats={[
                            { label: 'Total', value: zones.length },
                            { label: 'Active', value: activeZones.length },
                        ]}
                        actions={
                            <Button size="sm" onClick={() => setShowForm(true)}>
                                <Plus className="mr-1 h-4 w-4" />
                                Add Zone
                            </Button>
                        }
                    />
                }
            >
                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">
                                {zones.length}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Total Zones
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-success/20 bg-status-success">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-status-success">
                                {activeZones.length}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Active
                            </div>
                        </CardContent>
                    </Card>
                </div>

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
                            Zones ({activeZones.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {activeZones.length === 0 ? (
                            <div className="py-8 text-center text-muted-foreground">
                                <LayoutGrid className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>No zones configured yet</p>
                            </div>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {activeZones.map((zone) => (
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
