import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { Calendar, DoorOpen, Folder, Plus, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '../_confirm-action';

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

type ResourceFormData = {
    name: string;
    resource_type: Resource['resource_type'];
    capacity: string;
    calendar_email: string;
    amenities: string;
};

const typeLabels: Record<string, string> = {
    boardroom: 'Boardroom',
    training_room: 'Training Room',
    meeting_room: 'Meeting Room',
    other: 'Other',
};

const typeColors: Record<string, string> = {
    boardroom: 'bg-primary/20 text-primary/70 border-primary/30',
    training_room: 'bg-status-info-bg text-status-info border-status-info/30',
    meeting_room:
        'bg-status-success-bg text-status-success border-status-success/30',
    other: 'bg-muted-foreground/20 text-muted-foreground border-border/30',
};

export default function SiteResources({ site, resources }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [editingResource, setEditingResource] = useState<Resource | null>(
        null,
    );

    const form = useForm<ResourceFormData>({
        name: '',
        resource_type: 'meeting_room',
        capacity: '',
        calendar_email: '',
        amenities: '',
    });

    const deleteForm = useForm({});

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

    const handleDeactivate = (resource: Resource) => {
        deleteForm.delete(`/sites/${site.id}/resources/${resource.id}`);
    };

    const activeResources = resources.filter((r) => r.is_active);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Resources', href: `#` },
            ]}
        >
            <Head title={`${site.name} - Resources`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={Folder}
                        title="Rooms & Resources"
                        description={site.name}
                        backHref={`/sites/${site.id}`}
                        stats={[
                            { label: 'Total', value: resources.length },
                            { label: 'Active', value: activeResources.length },
                            {
                                label: 'Bookable',
                                value: activeResources.filter(
                                    (r) => r.is_bookable,
                                ).length,
                            },
                        ]}
                        actions={
                            <Button size="sm" onClick={() => setShowForm(true)}>
                                <Plus className="mr-1 h-4 w-4" />
                                Add Resource
                            </Button>
                        }
                    />
                }
            >
                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">
                                {resources.length}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Total Resources
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-success/20 bg-status-success">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-status-success">
                                {activeResources.length}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Active
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-info/20 bg-status-info">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-status-info">
                                {
                                    activeResources.filter((r) => r.is_bookable)
                                        .length
                                }
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Bookable
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Add/Edit Form */}
                {showForm && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>
                                {editingResource
                                    ? 'Edit Resource'
                                    : 'Add Resource'}
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
                                        <Label>Name *</Label>
                                        <Input
                                            value={form.data.name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g., Main Boardroom"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Type *</Label>
                                        <select
                                            value={form.data.resource_type}
                                            onChange={(e) =>
                                                form.setData(
                                                    'resource_type',
                                                    e.target
                                                        .value as Resource['resource_type'],
                                                )
                                            }
                                            className="w-full rounded-md border bg-background px-3 py-2"
                                            required
                                        >
                                            <option value="boardroom">
                                                Boardroom
                                            </option>
                                            <option value="training_room">
                                                Training Room
                                            </option>
                                            <option value="meeting_room">
                                                Meeting Room
                                            </option>
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
                                            onChange={(e) =>
                                                form.setData(
                                                    'capacity',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g., 10"
                                        />
                                    </div>
                                    <div>
                                        <Label>Calendar Email</Label>
                                        <Input
                                            type="email"
                                            value={form.data.calendar_email}
                                            onChange={(e) =>
                                                form.setData(
                                                    'calendar_email',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="room@company.com"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <Label>Amenities (comma separated)</Label>
                                    <Input
                                        value={form.data.amenities}
                                        onChange={(e) =>
                                            form.setData(
                                                'amenities',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Projector, Whiteboard, Video conferencing"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {editingResource
                                        ? 'Save Changes'
                                        : 'Add Resource'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Resources Grid */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Resources ({activeResources.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {activeResources.length === 0 ? (
                            <div className="py-8 text-center text-muted-foreground">
                                <DoorOpen className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>No resources configured yet</p>
                            </div>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {activeResources.map((resource) => (
                                    <Card
                                        key={resource.id}
                                        className="transition-colors hover:bg-muted/50"
                                    >
                                        <CardContent className="p-4">
                                            <div className="flex items-start justify-between">
                                                <div className="flex-1">
                                                    <div className="font-medium">
                                                        {resource.name}
                                                    </div>
                                                    <Badge
                                                        className={`mt-2 ${typeColors[resource.resource_type]}`}
                                                    >
                                                        {
                                                            typeLabels[
                                                                resource
                                                                    .resource_type
                                                            ]
                                                        }
                                                    </Badge>
                                                    <div className="mt-2 flex items-center gap-3 text-sm text-muted-foreground">
                                                        {resource.capacity && (
                                                            <span className="flex items-center gap-1">
                                                                <Users className="h-3.5 w-3.5" />
                                                                {
                                                                    resource.capacity
                                                                }
                                                            </span>
                                                        )}
                                                        {resource.calendar_email && (
                                                            <span className="flex items-center gap-1">
                                                                <Calendar className="h-3.5 w-3.5" />
                                                                Calendar
                                                            </span>
                                                        )}
                                                    </div>
                                                    {resource.amenities &&
                                                        resource.amenities
                                                            .length > 0 && (
                                                            <div className="mt-2 flex flex-wrap gap-1">
                                                                {resource.amenities.map(
                                                                    (
                                                                        amenity,
                                                                        i,
                                                                    ) => (
                                                                        <span
                                                                            key={
                                                                                i
                                                                            }
                                                                            className="rounded bg-muted px-2 py-0.5 text-xs"
                                                                        >
                                                                            {
                                                                                amenity
                                                                            }
                                                                        </span>
                                                                    ),
                                                                )}
                                                            </div>
                                                        )}
                                                </div>
                                                <div className="ml-2 flex gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            startEdit(resource)
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                    <ConfirmAction
                                                        title="Deactivate resource?"
                                                        description={`Deactivate "${resource.name}" for this site?`}
                                                        confirmLabel="Deactivate"
                                                        onConfirm={() =>
                                                            handleDeactivate(
                                                                resource,
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
