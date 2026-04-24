import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, ClipboardCheck, Plus, GripVertical, Trash2, Save, FileQuestion } from 'lucide-react';
import { useState } from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Item = {
    id: number;
    sort_order: number;
    question: string;
    response_type: string;
    is_required: boolean;
    guidance?: string;
    failure_creates_hazard: boolean;
};

type Template = {
    id: number;
    key: string;
    name: string;
    description?: string;
    applicable_to_type: string;
    frequency: string;
    is_active: boolean;
    items: Item[];
};

type Props = {
    template: Template;
};

const frequencyLabels: Record<string, string> = {
    once: 'One-time',
    daily: 'Daily',
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
};

const responseTypeLabels: Record<string, string> = {
    yes_no: 'Yes / No',
    yes_no_na: 'Yes / No / N/A',
    pass_fail: 'Pass / Fail',
    numeric: 'Number',
    text: 'Text',
    photo: 'Photo Required',
};

export default function EditTemplate({ template }: Props) {
    const form = useForm({
        name: template.name,
        description: template.description || '',
        applicable_to_type: template.applicable_to_type,
        frequency: template.frequency,
        is_active: template.is_active,
    });

    const itemForm = useForm({
        question: '',
        response_type: 'yes_no',
        is_required: true,
        guidance: '',
        failure_creates_hazard: false,
    });

    const [showAddItem, setShowAddItem] = useState(false);
    const [editingItem, setEditingItem] = useState<Item | null>(null);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(`/sites/checklists/templates/${template.id}`);
    };

    const handleAddItem = (e: React.FormEvent) => {
        e.preventDefault();
        itemForm.post(`/sites/checklists/templates/${template.id}/items`, {
            onSuccess: () => {
                itemForm.reset();
                setShowAddItem(false);
            },
        });
    };

    const handleUpdateItem = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingItem) return;
        
        itemForm.put(`/sites/checklists/templates/items/${editingItem.id}`, {
            onSuccess: () => {
                itemForm.reset();
                setEditingItem(null);
            },
        });
    };

    const startEditItem = (item: Item) => {
        setEditingItem(item);
        itemForm.setData({
            question: item.question,
            response_type: item.response_type,
            is_required: item.is_required,
            guidance: item.guidance || '',
            failure_creates_hazard: item.failure_creates_hazard,
        });
        setShowAddItem(true);
    };

    const cancelEdit = () => {
        setEditingItem(null);
        setShowAddItem(false);
        itemForm.reset();
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: 'Checklist Templates', href: '/sites/checklists/templates' }, { title: template.name, href: '#' }]}>
            <Head title={`Edit ${template.name}`} />

            <div className="m-4 max-w-4xl mx-auto space-y-6">
                <Button asChild variant="ghost" size="sm">
                    <Link href="/sites/checklists/templates">
                        <ArrowLeft className="w-4 h-4 mr-1" />
                        Back to Templates
                    </Link>
                </Button>

                {/* Template Details */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <ClipboardCheck className="w-5 h-5" />
                            Template Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Template Key</Label>
                                    <Input value={template.key} disabled className="bg-muted" />
                                    <p className="text-xs text-muted-foreground mt-1">Key cannot be changed</p>
                                </div>
                                <div>
                                    <Label>Template Name *</Label>
                                    <Input
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        required
                                    />
                                </div>
                            </div>

                            <div>
                                <Label>Description</Label>
                                <Textarea
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    rows={2}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Applies To</Label>
                                    <Select
                                        value={form.data.applicable_to_type}
                                        onValueChange={(v) => form.setData('applicable_to_type', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Types</SelectItem>
                                            <SelectItem value="house">Houses</SelectItem>
                                            <SelectItem value="head_office">Head Offices</SelectItem>
                                            <SelectItem value="facility">Facilities</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Default Frequency</Label>
                                    <Select
                                        value={form.data.frequency}
                                        onValueChange={(v) => form.setData('frequency', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="once">One-time</SelectItem>
                                            <SelectItem value="daily">Daily</SelectItem>
                                            <SelectItem value="weekly">Weekly</SelectItem>
                                            <SelectItem value="fortnightly">Fortnightly</SelectItem>
                                            <SelectItem value="monthly">Monthly</SelectItem>
                                            <SelectItem value="quarterly">Quarterly</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="flex items-center gap-2 pt-6">
                                    <Checkbox
                                        id="is_active"
                                        checked={form.data.is_active}
                                        onCheckedChange={(checked) => form.setData('is_active', checked as boolean)}
                                    />
                                    <Label htmlFor="is_active" className="font-normal cursor-pointer">
                                        Active
                                    </Label>
                                </div>
                            </div>

                            <div className="flex justify-end">
                                <Button type="submit" disabled={form.processing} size="sm">
                                    <Save className="w-4 h-4 mr-1" />
                                    Save Changes
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {/* Checklist Items */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2">
                            <FileQuestion className="w-5 h-5" />
                            Checklist Items ({template.items.length})
                        </CardTitle>
                        {!showAddItem && (
                            <Button onClick={() => setShowAddItem(true)} size="sm">
                                <Plus className="w-4 h-4 mr-1" />
                                Add Item
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        {/* Add/Edit Item Form */}
                        {showAddItem && (
                            <form onSubmit={editingItem ? handleUpdateItem : handleAddItem} className="mb-6 p-4 rounded-lg border bg-muted/30">
                                <h4 className="font-medium mb-4">
                                    {editingItem ? 'Edit Item' : 'Add New Item'}
                                </h4>
                                <div className="space-y-4">
                                    <div>
                                        <Label>Question / Item Text *</Label>
                                        <Textarea
                                            value={itemForm.data.question}
                                            onChange={(e) => itemForm.setData('question', e.target.value)}
                                            placeholder="e.g., Are all fire exits clear and accessible?"
                                            rows={2}
                                            required
                                        />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <Label>Response Type</Label>
                                            <Select
                                                value={itemForm.data.response_type}
                                                onValueChange={(v) => itemForm.setData('response_type', v)}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="yes_no">Yes / No</SelectItem>
                                                    <SelectItem value="yes_no_na">Yes / No / N/A</SelectItem>
                                                    <SelectItem value="pass_fail">Pass / Fail</SelectItem>
                                                    <SelectItem value="numeric">Number</SelectItem>
                                                    <SelectItem value="text">Text</SelectItem>
                                                    <SelectItem value="photo">Photo Required</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div>
                                            <Label>Guidance (optional)</Label>
                                            <Input
                                                value={itemForm.data.guidance}
                                                onChange={(e) => itemForm.setData('guidance', e.target.value)}
                                                placeholder="Help text for the inspector"
                                            />
                                        </div>
                                    </div>
                                    <div className="flex gap-6">
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id="is_required"
                                                checked={itemForm.data.is_required}
                                                onCheckedChange={(checked) => itemForm.setData('is_required', checked as boolean)}
                                            />
                                            <Label htmlFor="is_required" className="font-normal cursor-pointer">
                                                Required item
                                            </Label>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id="failure_creates_hazard"
                                                checked={itemForm.data.failure_creates_hazard}
                                                onCheckedChange={(checked) => itemForm.setData('failure_creates_hazard', checked as boolean)}
                                            />
                                            <Label htmlFor="failure_creates_hazard" className="font-normal cursor-pointer">
                                                Failure creates hazard
                                            </Label>
                                        </div>
                                    </div>
                                    <div className="flex justify-end gap-2">
                                        <Button type="button" variant="outline" size="sm" onClick={cancelEdit}>
                                            Cancel
                                        </Button>
                                        <Button type="submit" size="sm" disabled={itemForm.processing}>
                                            {editingItem ? 'Update Item' : 'Add Item'}
                                        </Button>
                                    </div>
                                </div>
                            </form>
                        )}

                        {/* Items List */}
                        <div className="space-y-2">
                            {template.items.length === 0 ? (
                                <div className="text-center py-8 text-muted-foreground">
                                    <FileQuestion className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                    <p>No items in this checklist yet</p>
                                    <p className="text-sm mt-1">Add questions or items to check</p>
                                </div>
                            ) : (
                                template.items
                                    .sort((a, b) => a.sort_order - b.sort_order)
                                    .map((item, index) => (
                                        <div
                                            key={item.id}
                                            className="flex items-start gap-3 p-3 rounded-lg border hover:bg-muted/50"
                                        >
                                            <div className="mt-1 text-muted-foreground">
                                                <GripVertical className="w-4 h-4" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-start gap-2">
                                                    <span className="text-sm font-mono text-muted-foreground">{index + 1}.</span>
                                                    <div className="flex-1">
                                                        <p className="font-medium">{item.question}</p>
                                                        <div className="flex flex-wrap gap-2 mt-1">
                                                            <Badge variant="outline" className="text-xs">
                                                                {responseTypeLabels[item.response_type]}
                                                            </Badge>
                                                            {item.is_required && (
                                                                <Badge className="text-xs bg-primary/20 text-primary/70 border-primary/30">
                                                                    Required
                                                                </Badge>
                                                            )}
                                                            {item.failure_creates_hazard && (
                                                                <Badge className="text-xs bg-status-critical-bg text-status-critical border-status-critical/30">
                                                                    Hazard on Fail
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        {item.guidance && (
                                                            <p className="text-sm text-muted-foreground mt-1">{item.guidance}</p>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="flex gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => startEditItem(item)}
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-status-critical hover:text-status-critical"
                                                    onClick={() => {
                                                        if (confirm('Remove this item?')) {
                                                            itemForm.delete(`/sites/checklists/templates/items/${item.id}`);
                                                        }
                                                    }}
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </Button>
                                            </div>
                                        </div>
                                    ))
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
