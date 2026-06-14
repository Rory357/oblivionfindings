import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, X } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Documents', href: '/hr/documents' },
    { title: 'Templates', href: '/hr/documents/templates' },
    { title: 'Create', href: '/hr/documents/templates/create' },
];

const categories = [
    { value: 'contract', label: 'Contract' },
    { value: 'letter', label: 'Letter' },
    { value: 'policy', label: 'Policy' },
    { value: 'certificate', label: 'Certificate' },
    { value: 'offer', label: 'Offer Letter' },
    { value: 'other', label: 'Other' },
];

export default function CreateTemplate() {
    const [mergeFields, setMergeFields] = useState<string[]>(['']);

    const { data, setData, post, processing, errors } = useForm({
        name: '',
        category: '',
        content: '',
        merge_fields: [] as string[],
        approval_required: false,
    });

    const addMergeField = () => {
        setMergeFields([...mergeFields, '']);
    };

    const updateMergeField = (index: number, value: string) => {
        const newFields = [...mergeFields];
        newFields[index] = value;
        setMergeFields(newFields);
        setData(
            'merge_fields',
            newFields.filter((f) => f.trim() !== ''),
        );
    };

    const removeMergeField = (index: number) => {
        const newFields = mergeFields.filter((_, i) => i !== index);
        setMergeFields(newFields);
        setData(
            'merge_fields',
            newFields.filter((f) => f.trim() !== ''),
        );
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/hr/documents/templates');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Document Template" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/documents/templates"
                        title="Create Document Template"
                        description="Define a new HR document template with merge fields."
                    />
                }
            >
                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Template Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="name">
                                        Template Name{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) =>
                                            setData('name', e.target.value)
                                        }
                                        placeholder="e.g., Employment Contract"
                                        className={
                                            errors.name
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-status-critical">
                                            {errors.name}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="category">
                                        Category{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Select
                                        value={data.category}
                                        onValueChange={(value) =>
                                            setData('category', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="category"
                                            className={
                                                errors.category
                                                    ? 'border-status-critical/30'
                                                    : ''
                                            }
                                        >
                                            <SelectValue placeholder="Select category" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categories.map((cat) => (
                                                <SelectItem
                                                    key={cat.value}
                                                    value={cat.value}
                                                >
                                                    {cat.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.category && (
                                        <p className="text-sm text-status-critical">
                                            {errors.category}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="content">
                                    Template Content{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Textarea
                                    id="content"
                                    value={data.content}
                                    onChange={(e) =>
                                        setData('content', e.target.value)
                                    }
                                    placeholder="Enter the template content. Use {{field_name}} for merge fields..."
                                    rows={15}
                                    className={
                                        errors.content
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.content && (
                                    <p className="text-sm text-status-critical">
                                        {errors.content}
                                    </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    Use {'{{employee_name}}'}, {'{{date}}'},{' '}
                                    {'{{position_title}}'} etc. as placeholders
                                    for dynamic content.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Merge Fields</CardTitle>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addMergeField}
                            >
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
                                        onChange={(e) =>
                                            updateMergeField(
                                                index,
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {mergeFields.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Remove merge field"
                                            onClick={() =>
                                                removeMergeField(index)
                                            }
                                            className="text-status-critical hover:text-status-critical"
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    )}
                                </div>
                            ))}
                            <p className="text-xs text-muted-foreground">
                                Define the merge fields used in this template.
                                These will be replaced with actual values when
                                generating documents.
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Settings</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="approval_required"
                                    checked={data.approval_required}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'approval_required',
                                            checked as boolean,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor="approval_required"
                                    className="text-sm font-normal"
                                >
                                    Require approval before use
                                </Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-3">
                        <Link href="/hr/documents/templates">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Template'}
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}

