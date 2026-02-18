import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { useState, FormEventHandler } from 'react';
import { Plus, Pencil, Trash2, Shield, CheckSquare, Square, Settings } from 'lucide-react';

interface ComplianceRequirement {
    id: number;
    name: string;
    type: string;
    description: string | null;
    renewal_period_months: number | null;
    is_mandatory: boolean;
    is_active: boolean;
}

interface MatrixEntry {
    id: number;
    requirement_id: number;
    role: string;
    site_type: string | null;
    is_mandatory: boolean;
}

interface Props {
    requirements: ComplianceRequirement[];
    roles: string[];
    matrixEntries: MatrixEntry[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'Compliance', href: '/hr/compliance' },
    { title: 'Matrix', href: '/hr/compliance/matrix' },
];

const requirementTypeOptions = [
    { value: 'certification', label: 'Certification' },
    { value: 'training', label: 'Training' },
    { value: 'document', label: 'Document' },
    { value: 'check', label: 'Background Check' },
    { value: 'license', label: 'License' },
    { value: 'other', label: 'Other' },
];

export default function ComplianceMatrix({ requirements, roles, matrixEntries, can }: Props) {
    const [showAddForm, setShowAddForm] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    const addForm = useForm({
        name: '',
        type: '',
        description: '',
        renewal_period_months: '',
        is_mandatory: true,
    });

    const editForm = useForm({
        name: '',
        type: '',
        description: '',
        renewal_period_months: '',
        is_mandatory: true,
    });

    const handleAddSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        addForm.post('/hr/compliance/requirements', {
            preserveScroll: true,
            onSuccess: () => {
                addForm.reset();
                setShowAddForm(false);
            },
        });
    };

    const handleEditSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!editingId) return;
        editForm.put(`/hr/compliance/requirements/${editingId}`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingId(null);
                editForm.reset();
            },
        });
    };

    function startEdit(req: ComplianceRequirement) {
        setEditingId(req.id);
        editForm.setData({
            name: req.name,
            type: req.type,
            description: req.description || '',
            renewal_period_months: req.renewal_period_months ? String(req.renewal_period_months) : '',
            is_mandatory: req.is_mandatory,
        });
    }

    function deleteRequirement(id: number) {
        if (confirm('Are you sure you want to delete this requirement?')) {
            router.delete(`/hr/compliance/requirements/${id}`, { preserveScroll: true });
        }
    }

    function toggleMatrixEntry(requirementId: number, role: string, currentlyEnabled: boolean) {
        if (!can.manage) return;
        const action = currentlyEnabled ? 'unassign' : 'assign';
        const mandatory = currentlyEnabled ? isEntryMandatory(requirementId, role) : true;

        router.post('/hr/compliance/matrix', {
            requirement_id: requirementId,
            role,
            is_mandatory: mandatory,
            action,
        }, { preserveScroll: true });
    }

    function isEntryEnabled(requirementId: number, role: string): boolean {
        return matrixEntries.some((e) => e.requirement_id === requirementId && e.role === role);
    }

    function isEntryMandatory(requirementId: number, role: string): boolean {
        return matrixEntries.some((e) => e.requirement_id === requirementId && e.role === role && e.is_mandatory);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Compliance Matrix" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Compliance Matrix</h1>
                        <p className="text-muted-foreground">Configure compliance requirements and role assignments</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/hr/compliance">Back to Dashboard</Link>
                        </Button>
                        {can.manage && (
                            <Button onClick={() => setShowAddForm(!showAddForm)}>
                                <Plus className="mr-1 h-4 w-4" />
                                Add Requirement
                            </Button>
                        )}
                    </div>
                </div>

                {/* Add Requirement Form */}
                {showAddForm && can.manage && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Add New Requirement</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleAddSubmit} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <Label htmlFor="add_name">Name *</Label>
                                        <Input
                                            id="add_name"
                                            value={addForm.data.name}
                                            onChange={(e) => addForm.setData('name', e.target.value)}
                                            required
                                        />
                                        {addForm.errors.name && <p className="mt-1 text-sm text-destructive">{addForm.errors.name}</p>}
                                    </div>
                                    <div>
                                        <Label>Type *</Label>
                                        <Select value={addForm.data.type || '__none__'} onValueChange={(v) => addForm.setData('type', v === '__none__' ? '' : v)}>
                                            <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">Select type</SelectItem>
                                                {requirementTypeOptions.map((t) => (
                                                    <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {addForm.errors.type && <p className="mt-1 text-sm text-destructive">{addForm.errors.type}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="add_renewal">Renewal Period (months)</Label>
                                        <Input
                                            id="add_renewal"
                                            type="number"
                                            value={addForm.data.renewal_period_months}
                                            onChange={(e) => addForm.setData('renewal_period_months', e.target.value)}
                                            placeholder="e.g. 12"
                                        />
                                    </div>
                                    <div className="flex items-end gap-3">
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                id="add_mandatory"
                                                checked={addForm.data.is_mandatory}
                                                onChange={(e) => addForm.setData('is_mandatory', e.target.checked)}
                                                className="h-4 w-4 rounded"
                                            />
                                            <Label htmlFor="add_mandatory">Mandatory</Label>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <Label htmlFor="add_description">Description</Label>
                                    <Input
                                        id="add_description"
                                        value={addForm.data.description}
                                        onChange={(e) => addForm.setData('description', e.target.value)}
                                    />
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button type="submit" disabled={addForm.processing}>
                                        {addForm.processing ? 'Adding...' : 'Add Requirement'}
                                    </Button>
                                    <Button type="button" variant="outline" onClick={() => setShowAddForm(false)}>Cancel</Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Requirements List */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Settings className="h-5 w-5" />
                            Requirements ({requirements.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Requirement</th>
                                    <th className="px-4 py-3 text-left font-medium">Type</th>
                                    <th className="px-4 py-3 text-center font-medium">Renewal</th>
                                    <th className="px-4 py-3 text-center font-medium">Mandatory</th>
                                    <th className="px-4 py-3 text-center font-medium">Status</th>
                                    {can.manage && <th className="px-4 py-3" />}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {requirements.map((req) => (
                                    editingId === req.id ? (
                                        <tr key={req.id} className="bg-muted/20">
                                            <td colSpan={can.manage ? 6 : 5} className="px-4 py-3">
                                                <form onSubmit={handleEditSubmit} className="space-y-3">
                                                    <div className="grid gap-3 sm:grid-cols-4">
                                                        <div>
                                                            <Input
                                                                value={editForm.data.name}
                                                                onChange={(e) => editForm.setData('name', e.target.value)}
                                                                placeholder="Name"
                                                            />
                                                        </div>
                                                        <div>
                                                            <Select value={editForm.data.type || '__none__'} onValueChange={(v) => editForm.setData('type', v === '__none__' ? '' : v)}>
                                                                <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="__none__">Select type</SelectItem>
                                                                    {requirementTypeOptions.map((t) => (
                                                                        <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                                                    ))}
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                        <div>
                                                            <Input
                                                                type="number"
                                                                value={editForm.data.renewal_period_months}
                                                                onChange={(e) => editForm.setData('renewal_period_months', e.target.value)}
                                                                placeholder="Renewal months"
                                                            />
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                checked={editForm.data.is_mandatory}
                                                                onChange={(e) => editForm.setData('is_mandatory', e.target.checked)}
                                                                className="h-4 w-4 rounded"
                                                            />
                                                            <Label>Mandatory</Label>
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Button type="submit" size="sm" disabled={editForm.processing}>Save</Button>
                                                        <Button type="button" size="sm" variant="outline" onClick={() => setEditingId(null)}>Cancel</Button>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    ) : (
                                        <tr key={req.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <div className="font-medium">{req.name}</div>
                                                {req.description && <div className="text-xs text-muted-foreground">{req.description}</div>}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className="capitalize">{req.type.replace('_', ' ')}</Badge>
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {req.renewal_period_months ? `${req.renewal_period_months} months` : '\u2014'}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {req.is_mandatory ? (
                                                    <Badge variant="default">Required</Badge>
                                                ) : (
                                                    <Badge variant="secondary">Optional</Badge>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <Badge variant={req.is_active ? 'default' : 'secondary'}>
                                                    {req.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </td>
                                            {can.manage && (
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button variant="ghost" size="sm" onClick={() => startEdit(req)}>
                                                            <Pencil className="h-3.5 w-3.5" />
                                                        </Button>
                                                        <Button variant="ghost" size="sm" onClick={() => deleteRequirement(req.id)}>
                                                            <Trash2 className="h-3.5 w-3.5 text-destructive" />
                                                        </Button>
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    )
                                ))}
                                {requirements.length === 0 && (
                                    <tr>
                                        <td colSpan={can.manage ? 6 : 5} className="px-4 py-8 text-center text-muted-foreground">
                                            <Shield className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                            <p>No compliance requirements configured yet.</p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Role Matrix */}
                {requirements.length > 0 && roles.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Shield className="h-5 w-5" />
                                Role Assignment Matrix
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium sticky left-0 bg-muted/50">Requirement</th>
                                        {roles.map((role) => (
                                            <th key={role} className="px-3 py-3 text-center font-medium capitalize whitespace-nowrap">
                                                {role.replace('_', ' ')}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {requirements.filter((r) => r.is_active).map((req) => (
                                        <tr key={req.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium sticky left-0 bg-background">{req.name}</td>
                                            {roles.map((role) => {
                                                const enabled = isEntryEnabled(req.id, role);
                                                const mandatory = isEntryMandatory(req.id, role);
                                                return (
                                                    <td key={role} className="px-3 py-3 text-center">
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleMatrixEntry(req.id, role, enabled)}
                                                            disabled={!can.manage}
                                                            className={`inline-flex items-center justify-center rounded p-1 transition-colors ${
                                                                can.manage ? 'hover:bg-muted cursor-pointer' : 'cursor-default'
                                                            }`}
                                                            title={enabled ? (mandatory ? 'Mandatory' : 'Optional') : 'Not required'}
                                                        >
                                                            {enabled ? (
                                                                <CheckSquare className={`h-5 w-5 ${mandatory ? 'text-primary' : 'text-muted-foreground'}`} />
                                                            ) : (
                                                                <Square className="h-5 w-5 text-muted-foreground/30" />
                                                            )}
                                                        </button>
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
