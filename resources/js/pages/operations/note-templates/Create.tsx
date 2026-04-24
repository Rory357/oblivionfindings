import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function NoteTemplateCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        fields: [{ label: '', type: 'text', required: false }] as Array<{ label: string; type: string; required: boolean }>,
        is_active: true,
    });

    const addField = () => {
        setData('fields', [...data.fields, { label: '', type: 'text', required: false }]);
    };

    const removeField = (index: number) => {
        setData('fields', data.fields.filter((_, i) => i !== index));
    };

    const updateField = (index: number, key: string, value: string | boolean) => {
        const updated = [...data.fields];
        updated[index] = { ...updated[index], [key]: value };
        setData('fields', updated);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/operations/note-templates');
    };

    return (
        <AppLayout>
            <Head title="Create Note Template" />
            <PageHeader title="Create Note Template" description="Design a reusable template for care notes." backHref="/operations/note-templates" />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Template Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="name">Template Name *</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="e.g. Daily Shift Notes"
                                />
                                {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Describe when this template should be used..."
                                    rows={2}
                                />
                            </div>

                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <Label>Template Fields *</Label>
                                    <Button type="button" variant="outline" size="sm" onClick={addField}>
                                        Add Field
                                    </Button>
                                </div>
                                {data.fields.map((field, index) => (
                                    <div key={index} className="flex items-end gap-2 rounded-md border p-3">
                                        <div className="flex-1 space-y-1.5">
                                            <Label>Label</Label>
                                            <Input
                                                value={field.label}
                                                onChange={(e) => updateField(index, 'label', e.target.value)}
                                                placeholder="Field label"
                                            />
                                        </div>
                                        <div className="w-32 space-y-1.5">
                                            <Label>Type</Label>
                                            <select
                                                value={field.type}
                                                onChange={(e) => updateField(index, 'type', e.target.value)}
                                                className="border-input bg-background flex h-9 w-full rounded-md border px-3 py-1 text-sm"
                                            >
                                                <option value="text">Text</option>
                                                <option value="textarea">Textarea</option>
                                                <option value="number">Number</option>
                                                <option value="checkbox">Checkbox</option>
                                                <option value="select">Select</option>
                                            </select>
                                        </div>
                                        <div className="flex items-center gap-1 pb-1">
                                            <input
                                                type="checkbox"
                                                checked={field.required}
                                                onChange={(e) => updateField(index, 'required', e.target.checked)}
                                                className="h-4 w-4 rounded border-border"
                                            />
                                            <Label className="text-xs">Req</Label>
                                        </div>
                                        {data.fields.length > 1 && (
                                            <Button type="button" variant="ghost" size="sm" onClick={() => removeField(index)}>
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                ))}
                                {errors.fields && <p className="text-xs text-destructive">{errors.fields}</p>}
                            </div>

                            <div className="flex items-center gap-2">
                                <input
                                    id="is_active"
                                    type="checkbox"
                                    checked={data.is_active}
                                    onChange={(e) => setData('is_active', e.target.checked)}
                                    className="h-4 w-4 rounded border-border"
                                />
                                <Label htmlFor="is_active" className="cursor-pointer">Active</Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/operations/note-templates')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Create Template
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
