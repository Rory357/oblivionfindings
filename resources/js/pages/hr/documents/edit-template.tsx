import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft, Plus, X } from 'lucide-react';
import { useState } from 'react';

interface Template {
    id: number;
    name: string;
    category: string;
    content: string;
    merge_fields: string[] | null;
    is_active: boolean;
    approval_required: boolean;
    version: number;
}

interface Props {
    template: Template;
}

const categories = [
    { value: 'contract', label: 'Contract' },
    { value: 'letter', label: 'Letter' },
    { value: 'policy', label: 'Policy' },
    { value: 'certificate', label: 'Certificate' },
    { value: 'offer', label: 'Offer Letter' },
    { value: 'other', label: 'Other' },
];

export default function EditTemplate({ template }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Documents', href: '/hr/documents' },
        { title: 'Templates', href: '/hr/documents/templates' },
        { title: template.name, href: `/hr/documents/templates/${template.id}/edit` },
    ];

    const initialFields = template.merge_fields?.length ? template.merge_fields : [''];
    const [mergeFields, setMergeFields] = useState<string[]>(initialFields);

    const { data, setData, put, processing, errors } = useForm({
        name: template.name,
        category: template.category,
        content: template.content,
        merge_fields: template.merge_fields || [],
        is_active: template.is_active,
        approval_required: template.approval_required,
    });

    const addMergeField = () => {
        setMergeFields([...mergeFields, '']);
    };

    const updateMergeField = (index: number, value: string) => {
        const newFields = [...mergeFields];
        newFields[index] = value;
        setMergeFields(newFields);
        setData('merge_fields', newFields.filter(f => f.trim() !== ''));
    };

    const removeMergeField = (index: number) => {
        const newFields = mergeFields.filter((_, i) => i !== index);
        setMergeFields(newFields);
        setData('merge_fields', newFields.filter(f => f.trim() !== ''));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/hr/documents/templates/${template.id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${template.name}`} />
            <div className="flex flex-col gap-6 p-6 max-w-4xl">
                <div className="flex items-center gap-4">
                    <Link href="/hr/documents/templates">
                        <Button variant="outline" size="icon">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold">Edit Template</h1>
                        <p className="text-muted-foreground flex items-center gap-2">
                            {template.name}
                            <Badge variant="outline">v{template.version}</Badge>
                        </p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Template Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="name">
                                        Template Name <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g., Employment Contract"
                                        className={errors.name ? 'border-red-500' : ''}
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-red-500">{errors.name}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="category">
                                        Category <span className="text-red-500">*</span>
                                    </Label>
                                    <Select
                                        value={data.category}
                                        onValueChange={(value) => setData('category', value)}
                                    >
                                        <SelectTrigger id="category" className={errors.category ? 'border-red-500' : ''}>
                                            <SelectValue placeholder="Select category" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categories.map((cat) => (
                                                <SelectItem key={cat.value} value={cat.value}>
                                                    {cat.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.category && (
                                        <p className="text-sm text-red-500">{errors.category}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="content">
                                    Template Content <span className="text-red-500">*</span>
                                </Label>
                                <Textarea
                                    id="content"
                                    value={data.content}
                                    onChange={(e) => setData('content', e.target.value)}
                                    placeholder="Enter the template content. Use {{field_name}} for merge fields..."
                                    rows={15}
                                    className={errors.content ? 'border-red-500' : ''}
                                />
                                {errors.content && (
                                    <p className="text-sm text-red-500">{errors.content}</p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    Use {'{{employee_name}}'}, {'{{date}}'}, {'{{position_title}}'} etc. as placeholders for dynamic content.
                                    Changes to content will auto-increment the version number.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Merge Fields</CardTitle>
                            <Button type="button" variant="outline" size="sm" onClick={addMergeField}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Field
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {mergeFields.map((field, index) => (
                                <div key={index} className="flex gap-2">
                                    <Input
                                        placeholder={`Field name (e.g., employee_name)`}
                                        value={field}
                                        onChange={(e) => updateMergeField(index, e.target.value)}
                                    />
                                    {mergeFields.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => removeMergeField(index)}
                                            className="text-red-500 hover:text-red-600"
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    )}
                                </div>
                            ))}
                            <p className="text-xs text-muted-foreground">
                                Define the merge fields used in this template. These will be replaced with actual values when generating documents.
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Settings</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="is_active"
                                    checked={data.is_active}
                                    onCheckedChange={(checked) => setData('is_active', checked as boolean)}
                                />
                                <Label htmlFor="is_active" className="text-sm font-normal">
                                    Template is active and available for use
                                </Label>
                            </div>

                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="approval_required"
                                    checked={data.approval_required}
                                    onCheckedChange={(checked) => setData('approval_required', checked as boolean)}
                                />
                                <Label htmlFor="approval_required" className="text-sm font-normal">
                                    Require approval before use
                                </Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-3">
                        <Link href="/hr/documents/templates">
                            <Button type="button" variant="outline">Cancel</Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Update Template'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
