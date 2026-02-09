import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { DoorOpen, Plus, ArrowLeft, Users, Calendar } from 'lucide-react';
import { useState } from 'react';

type Site = {
    id: number;
    name: string;
};

type Resource = {
    id: number;
    name: string;
    resource_type: 'boardroom' | 'training_room' | 'meeting_room' | 'other';
    capacity?: number;
    amenities?: string[];
    calendar_email?: string;
    is_bookable: boolean;
    is_active: boolean;
};

type Props = {
    site: Site;
    resources: Resource[];
};

const typeLabels: Record<string, string> = {
    boardroom: 'Boardroom',
    training_room: 'Training Room',
    meeting_room: 'Meeting Room',
    other: 'Other',
};

const typeColors: Record<string, string> = {
    boardroom: 'bg-purple-500/20 text-purple-300 border-purple-500/30',
    training_room: 'bg-blue-500/20 text-blue-300 border-blue-500/30',
    meeting_room: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    other: 'bg-slate-500/20 text-slate-300 border-slate-500/30',
};

export default function SiteResources({ site, resources }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [editingResource, setEditingResource] = useState<Resource | null>(null);

    const form = useForm({
        name: '',
        resource_type: 'meeting_room' as const,
        capacity: '',
        calendar_email: '',
        amenities: '',
    });

    const startEdit = (resource: Resource) => {
        setEditingResource(resource);
        form.setData({
            name: resource.name,
            resource_type: resource.resource_type,
            capacity: resource.capacity?.toString() || '',
            calendar_email: resource.calendar_email || '',
            amenities: resource.amenities?.join(', ') || '',
        });
        setShowForm(true);
    };

    const resetForm = () => {
        setEditingResource(null);
        setShowForm(false);
        form.reset();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const data = {
            ...form.data,
            capacity: form.data.capacity ? parseInt(form.data.capacity) : null,
            amenities: form.data.amenities.split(',').map(s => s.trim()).filter(Boolean),
        };
        if (editingResource) {
            form.put(`/sites/${site.id}/resources/${editingResource.id}`, {
                onSuccess: resetForm,
            });
        } else {
            form.post(`/sites/${site.id}/resources`, {
                onSuccess: resetForm,
            });
        }
    };

    const activeResources = resources.filter(r => r.is_active);

    return (
        <AppLayout breadcrumbs={[
            { title: 'Sites', href: '/sites' },
            { title: site.name, href: `/sites/${site.id}` },
            { title: 'Resources', href: `#` },
        ]}>>
            <Head title={`${site.name} - Resources`} />

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
                            <DoorOpen className="w-5 h-5" />
                            Rooms & Resources
                        </h1>
                        <p className="text-sm text-slate-400">{site.name}</p>
                    </div>
                    <Button onClick={() => setShowForm(true)}>
                        <Plus className="w-4 h-4 mr-1" />
                        Add Resource
                    </Button>
                </div>

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card className="bg-slate-800/30">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{resources.length}</div>
                            <div className="text-sm text-slate-400">Total Resources</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-emerald-500/5 border-emerald-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-emerald-400">{activeResources.length}</div>
                            <div className="text-sm text-slate-400">Active</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-blue-500/5 border-blue-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-blue-400">
                                {activeResources.filter(r => r.is_bookable).length}
                            </div>
                            <div className="text-sm text-slate-400">Bookable</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Add/Edit Form */}
                {showForm && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>{editingResource ? 'Edit Resource' : 'Add Resource'}</CardTitle>
                            <Button variant="ghost" size="sm" onClick={resetForm}>Cancel</Button>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Name *</Label>
                                        <Input
                                            value={form.data.name}
                                            onChange={(e) => form.setData('name', e.target.value)}
                                            placeholder="e.g., Main Boardroom"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Type *</Label>
                                        <select
                                            value={form.data.resource_type}
                                            onChange={(e) => form.setData('resource_type', e.target.value as any)}
                                            className="w-full rounded-md border border-slate-700 bg-slate-900 px-3 py-2"
                                            required
                                        >
                                            <option value="boardroom">Boardroom</option>
                                            <option value="training_room">Training Room</option>
                                            <option value="meeting_room">Meeting Room</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Capacity</Label>
                                        <Input
                                            type="number"
                                            value={form.data.capacity}
                                            onChange={(e) => form.setData('capacity', e.target.value)}
                                            placeholder="e.g., 10"
                                        />
                                    </div>
                                    <div>
                                        <Label>Calendar Email</Label>
                                        <Input
                                            type="email"
                                            value={form.data.calendar_email}
                                            onChange={(e) => form.setData('calendar_email', e.target.value)}
                                            placeholder="room@company.com"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <Label>Amenities (comma separated)</Label>
                                    <Input
                                        value={form.data.amenities}
                                        onChange={(e) => form.setData('amenities', e.target.value)}
                                        placeholder="Projector, Whiteboard, Video conferencing"
                                    />
                                </div>
                                <Button type="submit" disabled={form.processing}>
                                    {editingResource ? 'Save Changes' : 'Add Resource'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Resources Grid */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Resources ({activeResources.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {activeResources.length === 0 ? (
                            <div className="text-center py-8 text-slate-400">
                                <DoorOpen className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No resources configured yet</p>
                            </div>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {activeResources.map(resource => (
                                    <Card key={resource.id} className="bg-slate-800/30">
                                        <CardContent className="p-4">
                                            <div className="flex items-start justify-between">
                                                <div>
                                                    <div className="font-medium">{resource.name}</div>
                                                    <Badge className={`mt-2 ${typeColors[resource.resource_type]}`}>
                                                        {typeLabels[resource.resource_type]}
                                                    </Badge>
                                                    <div className="flex items-center gap-3 mt-2 text-sm text-slate-400">
                                                        {resource.capacity && (
                                                            <span className="flex items-center gap-1">
                                                                <Users className="w-3.5 h-3.5" />
                                                                {resource.capacity}
                                                            </span>
                                                        )}
                                                        {resource.calendar_email && (
                                                            <span className="flex items-center gap-1">
                                                                <Calendar className="w-3.5 h-3.5" />
                                                                Calendar
                                                            </span>
                                                        )}
                                                    </div>
                                                    {resource.amenities && resource.amenities.length > 0 && (
                                                        <div className="flex flex-wrap gap-1 mt-2">
                                                            {resource.amenities.map((amenity, i) => (
                                                                <span key={i} className="text-xs bg-slate-700 px-2 py-0.5 rounded">
                                                                    {amenity}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    )}
                                                </div>
                                                <Button variant="ghost" size="sm" onClick={() => startEdit(resource)}>
                                                    Edit
                                                </Button>
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
