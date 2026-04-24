import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { BedDouble, Plus, ArrowLeft, User, Trash2, History } from 'lucide-react';
import { useState } from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

type Site = {
    id: number;
    name: string;
};

type Client = {
    id: number;
    first_name: string;
    last_name: string;
};

type Room = {
    id: number;
    name: string;
    notes?: string;
    is_active: boolean;
    assigned_client?: Client | null;
    history?: RoomHistory[];
};

type RoomHistory = {
    id: number;
    client_id?: number;
    client?: Client;
    assigned_from?: string;
    assigned_until?: string;
    notes?: string;
    created_at?: string;
};

type Props = {
    site: Site;
    rooms: Room[];
    clients: Client[];
};

export default function SiteRooms({ site, rooms, clients }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [editingRoom, setEditingRoom] = useState<Room | null>(null);
    const [showHistory, setShowHistory] = useState<Room | null>(null);

    const form = useForm({
        name: '',
        notes: '',
        assigned_client_id: '',
    });

    const deleteForm = useForm({});

    const startEdit = (room: Room) => {
        setEditingRoom(room);
        form.setData({
            name: room.name,
            notes: room.notes || '',
            assigned_client_id: room.assigned_client?.id?.toString() || '',
        });
        setShowForm(true);
    };

    const resetForm = () => {
        setEditingRoom(null);
        setShowForm(false);
        form.reset();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingRoom) {
            form.put(`/sites/${site.id}/rooms/${editingRoom.id}`, {
                onSuccess: resetForm,
            });
        } else {
            form.post(`/sites/${site.id}/rooms`, {
                onSuccess: resetForm,
            });
        }
    };

    const handleDeactivate = (room: Room) => {
        if (confirm(`Are you sure you want to deactivate "${room.name}"?`)) {
            deleteForm.delete(`/sites/${site.id}/rooms/${room.id}`, {
                onSuccess: () => setShowHistory(null),
            });
        }
    };

    const activeRooms = rooms.filter(r => r.is_active);

    return (
        <AppLayout breadcrumbs={[
            { title: 'Sites', href: '/sites' },
            { title: site.name, href: `/sites/${site.id}` },
            { title: 'Rooms', href: `#` },
        ]}>
            <Head title={`${site.name} - Bedrooms`} />

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
                            <BedDouble className="w-5 h-5" />
                            Bedrooms
                        </h1>
                        <p className="text-sm text-muted-foreground">{site.name}</p>
                    </div>
                    <Button onClick={() => setShowForm(true)}>
                        <Plus className="w-4 h-4 mr-1" />
                        Add Bedroom
                    </Button>
                </div>

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{rooms.length}</div>
                            <div className="text-sm text-muted-foreground">Total Bedrooms</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-status-success border-status-success/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-status-success">{activeRooms.length}</div>
                            <div className="text-sm text-muted-foreground">Active</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-primary/5 border-primary/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-primary">
                                {activeRooms.filter(r => r.assigned_client).length}
                            </div>
                            <div className="text-sm text-muted-foreground">Occupied</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Add/Edit Form */}
                {showForm && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>{editingRoom ? 'Edit Bedroom' : 'Add Bedroom'}</CardTitle>
                            <Button variant="ghost" size="sm" onClick={resetForm}>Cancel</Button>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <Label>Room Name *</Label>
                                    <Input
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        placeholder="e.g., Bedroom 1, Master Room"
                                        required
                                    />
                                </div>
                                <div>
                                    <Label>Assign Client</Label>
                                    <select
                                        value={form.data.assigned_client_id}
                                        onChange={(e) => form.setData('assigned_client_id', e.target.value)}
                                        className="w-full rounded-md border bg-background px-3 py-2"
                                    >
                                        <option value="">-- Unassigned --</option>
                                        {clients.map(client => (
                                            <option key={client.id} value={client.id}>
                                                {client.first_name} {client.last_name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <Label>Notes</Label>
                                    <Textarea
                                        value={form.data.notes}
                                        onChange={(e) => form.setData('notes', e.target.value)}
                                        placeholder="Room notes, special requirements, etc."
                                        rows={3}
                                    />
                                </div>
                                <Button type="submit" disabled={form.processing}>
                                    {editingRoom ? 'Save Changes' : 'Add Bedroom'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Rooms Grid */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Bedrooms ({activeRooms.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {activeRooms.length === 0 ? (
                            <div className="text-center py-8 text-muted-foreground">
                                <BedDouble className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No bedrooms configured yet</p>
                            </div>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {activeRooms.map(room => (
                                    <Card key={room.id} className="hover:bg-muted/50 transition-colors">
                                        <CardContent className="p-4">
                                            <div className="flex items-start justify-between">
                                                <div className="flex-1">
                                                    <div className="font-medium">{room.name}</div>
                                                    {room.assigned_client ? (
                                                        <Badge className="mt-2 bg-primary/20 text-primary/70 border-primary/30">
                                                            <User className="w-3 h-3 mr-1" />
                                                            {room.assigned_client.first_name} {room.assigned_client.last_name}
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="mt-2 text-muted-foreground">
                                                            Available
                                                        </Badge>
                                                    )}
                                                    {room.notes && (
                                                        <div className="text-sm text-muted-foreground mt-2">{room.notes}</div>
                                                    )}
                                                </div>
                                                <div className="flex gap-1 ml-2">
                                                    {room.history && room.history.length > 0 && (
                                                        <Dialog open={showHistory?.id === room.id} onOpenChange={(open) => setShowHistory(open ? room : null)}>
                                                            <DialogTrigger asChild>
                                                                <Button variant="ghost" size="sm" title="View assignment history">
                                                                    <History className="w-4 h-4" />
                                                                </Button>
                                                            </DialogTrigger>
                                                            <DialogContent>
                                                                <DialogHeader>
                                                                    <DialogTitle>Assignment History - {room.name}</DialogTitle>
                                                                </DialogHeader>
                                                                <div className="space-y-3 max-h-96 overflow-y-auto">
                                                                    {room.history?.map((entry, idx) => (
                                                                        <Card key={idx} className="bg-muted/50">
                                                                            <CardContent className="p-3 text-sm">
                                                                                {entry.client ? (
                                                                                    <div>
                                                                                        <div className="font-medium">
                                                                                            {entry.client.first_name} {entry.client.last_name}
                                                                                        </div>
                                                                                        <div className="text-xs text-muted-foreground mt-1">
                                                                                            {entry.assigned_from && `From: ${entry.assigned_from}`}
                                                                                            {entry.assigned_until && ` • To: ${entry.assigned_until}`}
                                                                                        </div>
                                                                                        {entry.notes && <div className="text-xs mt-1 text-muted-foreground">{entry.notes}</div>}
                                                                                    </div>
                                                                                ) : (
                                                                                    <div className="text-muted-foreground">No assignment data</div>
                                                                                )}
                                                                            </CardContent>
                                                                        </Card>
                                                                    ))}
                                                                </div>
                                                            </DialogContent>
                                                        </Dialog>
                                                    )}
                                                    <Button variant="ghost" size="sm" onClick={() => startEdit(room)}>
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-status-critical hover:text-status-critical hover:bg-status-critical"
                                                        onClick={() => handleDeactivate(room)}
                                                        disabled={deleteForm.processing}
                                                    >
                                                        <Trash2 className="w-4 h-4" />
                                                    </Button>
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
