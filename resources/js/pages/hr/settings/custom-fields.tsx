import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
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
    DialogHeader,
    DialogTitle,
    DialogTrigger,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Plus, Trash2, Pencil, Settings2 } from 'lucide-react';
import { useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

interface FieldDefinition {
    id: number;
    name: string;
    field_key: string;
    field_type: string;
    options: string[] | null;
    is_required: boolean;
    is_active: boolean;
    sort_order: number;
    creator: { id: number; name: string } | null;
    created_at: string;
}

interface Props {
    definitions: FieldDefinition[];
    fieldTypes: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Settings', href: '/hr/settings/webhooks' },
    { title: 'Custom Fields', href: '/hr/settings/custom-fields' },
];

const fieldTypeLabels: Record<string, string> = {
    text: 'Text',
    number: 'Number',
    date: 'Date',
    select: 'Select',
    checkbox: 'Checkbox',
    textarea: 'Text Area',
};

export default function CustomFieldsIndex({ definitions, fieldTypes }: Props) {
    const [open, setOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [optionInput, setOptionInput] = useState('');

    const form = useForm({
        name: '',
        field_type: 'text',
        options: [] as string[],
        is_required: false,
        is_active: true,
        sort_order: 0,
    });

    const openCreate = () => {
        form.reset();
        form.setData({ name: '', field_type: 'text', options: [], is_required: false, is_active: true, sort_order: 0 });
        setEditingId(null);
        setOptionInput('');
        setOpen(true);
    };

    const openEdit = (def: FieldDefinition) => {
        form.setData({
            name: def.name,
            field_type: def.field_type,
            options: def.options ?? [],
            is_required: def.is_required,
            is_active: def.is_active,
            sort_order: def.sort_order,
        });
        setEditingId(def.id);
        setOptionInput('');
        setOpen(true);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingId) {
            form.put(`/hr/settings/custom-fields/${editingId}`, {
                onSuccess: () => setOpen(false),
            });
        } else {
            form.post('/hr/settings/custom-fields', {
                onSuccess: () => setOpen(false),
            });
        }
    };

    const addOption = () => {
        if (optionInput.trim()) {
            form.setData('options', [...form.data.options, optionInput.trim()]);
            setOptionInput('');
        }
    };

    const removeOption = (index: number) => {
        form.setData('options', form.data.options.filter((_, i) => i !== index));
    };

    const deleteDefinition = (id: number) => {
        if (confirm('Are you sure? This will also delete all custom field values for employees.')) {
            router.delete(`/hr/settings/custom-fields/${id}`);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Custom Fields - HR Settings" />
            <PageShell>
                <PageHeader title="Custom Fields" description="Define custom fields for employee profiles.">
                    <Dialog open={open} onOpenChange={setOpen}>
                        <DialogTrigger asChild>
                            <Button onClick={openCreate}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Field
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="sm:max-w-lg">
                            <DialogHeader>
                                <DialogTitle>{editingId ? 'Edit Field' : 'Create Field'}</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submit} className="space-y-4">
                                <div>
                                    <Label htmlFor="name">Field Name</Label>
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        placeholder="e.g. T-Shirt Size"
                                        required
                                    />
                                    {form.errors.name && <p className="text-sm text-status-critical mt-1">{form.errors.name}</p>}
                                </div>

                                <div>
                                    <Label>Field Type</Label>
                                    <Select value={form.data.field_type} onValueChange={(v) => form.setData('field_type', v)}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {fieldTypes.map((type) => (
                                                <SelectItem key={type} value={type}>
                                                    {fieldTypeLabels[type] ?? type}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {form.data.field_type === 'select' && (
                                    <div>
                                        <Label>Options</Label>
                                        <div className="flex gap-2 mt-1">
                                            <Input
                                                value={optionInput}
                                                onChange={(e) => setOptionInput(e.target.value)}
                                                placeholder="Add an option"
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter') {
                                                        e.preventDefault();
                                                        addOption();
                                                    }
                                                }}
                                            />
                                            <Button type="button" variant="outline" onClick={addOption}>Add</Button>
                                        </div>
                                        <div className="flex flex-wrap gap-1 mt-2">
                                            {form.data.options.map((opt, i) => (
                                                <Badge key={i} variant="secondary" className="cursor-pointer" onClick={() => removeOption(i)}>
                                                    {opt} &times;
                                                </Badge>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                <div className="flex items-center gap-4">
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.is_required}
                                            onCheckedChange={(checked) => form.setData('is_required', !!checked)}
                                        />
                                        Required
                                    </label>
                                    {editingId && (
                                        <label className="flex items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={form.data.is_active}
                                                onCheckedChange={(checked) => form.setData('is_active', !!checked)}
                                            />
                                            Active
                                        </label>
                                    )}
                                </div>

                                <div>
                                    <Label htmlFor="sort_order">Sort Order</Label>
                                    <Input
                                        id="sort_order"
                                        type="number"
                                        min={0}
                                        value={form.data.sort_order}
                                        onChange={(e) => form.setData('sort_order', parseInt(e.target.value) || 0)}
                                    />
                                </div>

                                <DialogFooter>
                                    <Button type="submit" disabled={form.processing}>
                                        {editingId ? 'Update' : 'Create'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </PageHeader>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Settings2 className="h-5 w-5" />
                            Field Definitions
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {definitions.length === 0 ? (
                            <p className="text-muted-foreground text-center py-8">No custom fields defined yet.</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Key</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Required</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Order</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {definitions.map((def) => (
                                        <TableRow key={def.id}>
                                            <TableCell className="font-medium">{def.name}</TableCell>
                                            <TableCell className="font-mono text-xs text-muted-foreground">{def.field_key}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{fieldTypeLabels[def.field_type] ?? def.field_type}</Badge>
                                            </TableCell>
                                            <TableCell>
                                                {def.is_required ? (
                                                    <Badge variant="default">Required</Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">Optional</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={def.is_active ? 'default' : 'secondary'}>
                                                    {def.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>{def.sort_order}</TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-1">
                                                    <Button variant="ghost" size="sm" onClick={() => openEdit(def)} title="Edit">
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                    <Button variant="ghost" size="sm" onClick={() => deleteDefinition(def.id)} title="Delete">
                                                        <Trash2 className="h-4 w-4 text-status-critical" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
