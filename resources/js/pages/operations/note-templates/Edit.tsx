import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';

type TemplateField = {
    label: string;
    type: string;
    required: boolean;
};

type NoteTemplate = {
    id: number;
    name: string;
    description: string | null;
    fields: TemplateField[] | null;
    is_active: boolean;
};

type Props = {
    template: NoteTemplate;
};

const emptyField = (): TemplateField => ({ label: '', type: 'text', required: false });

export default function NoteTemplateEdit({ template }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name: template.name ?? '',
        description: template.description ?? '',
        fields: Array.isArray(template.fields) && template.fields.length > 0 ? template.fields : [emptyField()],
        is_active: Boolean(template.is_active),
    });

    const addField = () => setData('fields', [...data.fields, emptyField()]);
    const removeField = (index: number) => setData('fields', data.fields.filter((_, i) => i !== index));
    const updateField = (index: number, key: keyof TemplateField, value: string | boolean) => {
        const updated = [...data.fields];
        updated[index] = { ...updated[index], [key]: value };
        setData('fields', updated);
    };

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        put(`/operations/note-templates/${template.id}`);
    };

    return (
        <AppLayout>
            <Head title={`Edit ${template.name}`} />
            <PageHeader title="Edit Note Template" description="Update reusable fields for care notes." backHref="/operations/note-templates" />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Template Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="name">Template Name *</Label>
                                <Input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} />
                                {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(event) => setData('description', event.target.value)}
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
                                            <Input value={field.label} onChange={(event) => updateField(index, 'label', event.target.value)} />
                                        </div>
                                        <div className="w-36 space-y-1.5">
                                            <Label>Type</Label>
                                            <select
                                                value={field.type}
                                                onChange={(event) => updateField(index, 'type', event.target.value)}
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
                                                checked={Boolean(field.required)}
                                                onChange={(event) => updateField(index, 'required', event.target.checked)}
                                                className="h-4 w-4 rounded border-gray-300"
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
                                    onChange={(event) => setData('is_active', event.target.checked)}
                                    className="h-4 w-4 rounded border-gray-300"
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
                            Save Template
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
