import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { LayoutGrid, Plus, ArrowLeft, MapPin, Trash2 } from 'lucide-react';
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

    const activeZones = zones.filter(z => z.is_active);

    return (
        <AppLayout breadcrumbs={[
            { title: 'Sites', href: '/sites' },
            { title: site.name, href: `/sites/${site.id}` },
            { title: 'Zones', href: `#` },
        ]}>
            <Head title={`${site.name} - Zones`} />

            <div className="m-4 max-w-4xl mx-auto space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <Button asChild variant="ghost" size="sm" className="mb-2">
                            <Link href={`/sites/${site.id}`}>
                                <ArrowLeft className="w-4 h-4 mr-1" />
                                Back
                            </Link>
                        </Button>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <LayoutGrid className="w-5 h-5" />
                            Areas & Zones
                        </h1>
                        <p className="text-sm text-muted-foreground">{site.name}</p>
                    </div>
                    <Button onClick={() => setShowForm(true)}>
                        <Plus className="w-4 h-4 mr-1" />
                        Add Zone
                    </Button>
                </div>

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{zones.length}</div>
                            <div className="text-sm text-muted-foreground">Total Zones</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-status-success border-status-success/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-status-success">{activeZones.length}</div>
                            <div className="text-sm text-muted-foreground">Active</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Add/Edit Form */}
                {showForm && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>{editingZone ? 'Edit Zone' : 'Add Zone'}</CardTitle>
                            <Button variant="ghost" size="sm" onClick={resetForm}>Cancel</Button>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Zone Name *</Label>
                                        <Input
                                            value={form.data.name}
                                            onChange={(e) => form.setData('name', e.target.value)}
                                            placeholder="e.g., Workshop Area A"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Zone Type</Label>
                                        <Input
                                            value={form.data.zone_type}
                                            onChange={(e) => form.setData('zone_type', e.target.value)}
                                            placeholder="e.g., Workshop, Café, Storage"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <Label>Description</Label>
                                    <Textarea
                                        value={form.data.description}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                        placeholder="Description of the zone, equipment, or purpose"
                                        rows={3}
                                    />
                                </div>
                                <Button type="submit" disabled={form.processing}>
                                    {editingZone ? 'Save Changes' : 'Add Zone'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Zones Grid */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Zones ({activeZones.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {activeZones.length === 0 ? (
                            <div className="text-center py-8 text-muted-foreground">
                                <LayoutGrid className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No zones configured yet</p>
                            </div>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {activeZones.map(zone => (
                                    <Card key={zone.id} className="hover:bg-muted/50 transition-colors">
                                        <CardContent className="p-4">
                                            <div className="flex items-start justify-between">
                                                <div className="flex-1">
                                                    <div className="font-medium">{zone.name}</div>
                                                    {zone.zone_type && (
                                                        <Badge variant="outline" className="mt-2">
                                                            <MapPin className="w-3 h-3 mr-1" />
                                                            {zone.zone_type}
                                                        </Badge>
                                                    )}
                                                    {zone.description && (
                                                        <div className="text-sm text-muted-foreground mt-2">{zone.description}</div>
                                                    )}
                                                </div>
                                                <div className="flex gap-1 ml-2">
                                                    <Button variant="ghost" size="sm" onClick={() => startEdit(zone)}>
                                                        Edit
                                                    </Button>
                                                    <ConfirmAction
                                                        title="Deactivate zone?"
                                                        description={`Deactivate "${zone.name}" for this site?`}
                                                        confirmLabel="Deactivate"
                                                        onConfirm={() =>
                                                            handleDeactivate(zone)
                                                        }
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-status-critical hover:text-status-critical hover:bg-status-critical"
                                                            disabled={deleteForm.processing}
                                                        >
                                                            <Trash2 className="w-4 h-4" />
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
            </div>
        </AppLayout>
    );
}
