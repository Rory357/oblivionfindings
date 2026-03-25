import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';

export default function CustomFormCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        schema: [{ label: '', type: 'text', required: false }] as Array<{ label: string; type: string; required: boolean }>,
        is_active: true,
    });

    const addField = () => {
        setData('schema', [...data.schema, { label: '', type: 'text', required: false }]);
    };

    const removeField = (index: number) => {
        setData('schema', data.schema.filter((_, i) => i !== index));
    };

    const updateField = (index: number, key: string, value: string | boolean) => {
        const updated = [...data.schema];
        updated[index] = { ...updated[index], [key]: value };
        setData('schema', updated);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/operations/forms');
    };

    return (
        <AppLayout>
            <Head title="Create Form" />
            <PageHeader title="Create Form" description="Design a new custom form for data collection." backHref="/operations/forms" />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Form Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="name">Form Name *</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="e.g. Client Satisfaction Survey"
                                />
                                {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Describe the purpose of this form..."
                                    rows={2}
                                />
                            </div>

                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <Label>Form Fields *</Label>
                                    <Button type="button" variant="outline" size="sm" onClick={addField}>
                                        Add Field
                                    </Button>
                                </div>
                                {data.schema.map((field, index) => (
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
                                                <option value="date">Date</option>
                                                <option value="email">Email</option>
                                            </select>
                                        </div>
                                        <div className="flex items-center gap-1 pb-1">
                                            <input
                                                type="checkbox"
                                                checked={field.required}
                                                onChange={(e) => updateField(index, 'required', e.target.checked)}
                                                className="h-4 w-4 rounded border-gray-300"
                                            />
                                            <Label className="text-xs">Req</Label>
                                        </div>
                                        {data.schema.length > 1 && (
                                            <Button type="button" variant="ghost" size="sm" onClick={() => removeField(index)}>
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                ))}
                                {errors.schema && <p className="text-xs text-destructive">{errors.schema}</p>}
                            </div>

                            <div className="flex items-center gap-2">
                                <input
                                    id="is_active"
                                    type="checkbox"
                                    checked={data.is_active}
                                    onChange={(e) => setData('is_active', e.target.checked)}
                                    className="h-4 w-4 rounded border-gray-300"
                                />
                                <Label htmlFor="is_active" className="cursor-pointer">Active</Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/operations/forms')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Create Form
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
