import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';

type FormField = {
    label: string;
    type: string;
    required: boolean;
    options?: string[];
};
type Form = {
    id: number;
    name: string;
    description?: string | null;
    form_type: string;
    is_active: boolean;
    schema: FormField[];
};

type Props = {
    form: Form;
};

export default function CustomFormEdit({ form }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name: form.name,
        description: form.description ?? '',
        form_type: form.form_type ?? 'general',
        schema: (form.schema ?? []).map((field) => ({
            label: field.label,
            type: field.type,
            required: !!field.required,
            options: field.options ?? [],
        })),
        is_active: !!form.is_active,
    });

    const addField = () => {
        setData('schema', [
            ...data.schema,
            { label: '', type: 'text', required: false, options: [] },
        ]);
    };

    const removeField = (index: number) => {
        setData(
            'schema',
            data.schema.filter((_, i) => i !== index),
        );
    };

    const updateField = (
        index: number,
        key: string,
        value: string | boolean | string[],
    ) => {
        const updated = [...data.schema];
        updated[index] = {
            ...updated[index],
            [key]: value,
        } as (typeof data.schema)[number];
        setData('schema', updated);
    };

    return (
        <AppLayout>
            <Head title={`Edit ${form.name}`} />
            <PageHero
                variant="compact"
                title={`Edit ${form.name}`}
                description="Update the form structure and workflow type."
                backHref={`/operations/forms/${form.id}`}
            />
            <PageShell>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        put(`/operations/forms/${form.id}`);
                    }}
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Form details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="name">Form name *</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                />
                                {errors.name ? (
                                    <p className="text-xs text-destructive">
                                        {errors.name}
                                    </p>
                                ) : null}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    rows={2}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="form_type">Form type *</Label>
                                <select
                                    id="form_type"
                                    value={data.form_type}
                                    onChange={(e) =>
                                        setData('form_type', e.target.value)
                                    }
                                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm"
                                >
                                    <option value="general">General</option>
                                    <option value="shift">Shift</option>
                                    <option value="care_delivery">
                                        Care delivery
                                    </option>
                                    <option value="handover">Handover</option>
                                    <option value="incident">Incident</option>
                                    <option value="medication">
                                        Medication
                                    </option>
                                </select>
                                {errors.form_type ? (
                                    <p className="text-xs text-destructive">
                                        {errors.form_type}
                                    </p>
                                ) : null}
                            </div>

                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <Label>Form fields *</Label>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addField}
                                    >
                                        Add field
                                    </Button>
                                </div>
                                {data.schema.map((field, index) => (
                                    <div
                                        key={index}
                                        className="flex items-end gap-2 rounded-md border p-3"
                                    >
                                        <div className="flex-1 space-y-1.5">
                                            <Label>Label</Label>
                                            <Input
                                                value={field.label}
                                                onChange={(e) =>
                                                    updateField(
                                                        index,
                                                        'label',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="w-32 space-y-1.5">
                                            <Label>Type</Label>
                                            <select
                                                value={field.type}
                                                onChange={(e) =>
                                                    updateField(
                                                        index,
                                                        'type',
                                                        e.target.value,
                                                    )
                                                }
                                                className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm"
                                            >
                                                <option value="text">
                                                    Text
                                                </option>
                                                <option value="textarea">
                                                    Textarea
                                                </option>
                                                <option value="number">
                                                    Number
                                                </option>
                                                <option value="checkbox">
                                                    Checkbox
                                                </option>
                                                <option value="select">
                                                    Select
                                                </option>
                                                <option value="date">
                                                    Date
                                                </option>
                                                <option value="email">
                                                    Email
                                                </option>
                                            </select>
                                        </div>
                                        {field.type === 'select' ? (
                                            <div className="flex-1 space-y-1.5">
                                                <Label>Options</Label>
                                                <Input
                                                    value={(
                                                        field.options ?? []
                                                    ).join(', ')}
                                                    onChange={(e) =>
                                                        updateField(
                                                            index,
                                                            'options',
                                                            e.target.value
                                                                .split(',')
                                                                .map((option) =>
                                                                    option.trim(),
                                                                )
                                                                .filter(
                                                                    Boolean,
                                                                ),
                                                        )
                                                    }
                                                />
                                            </div>
                                        ) : null}
                                        <div className="flex items-center gap-1 pb-1">
                                            <input
                                                type="checkbox"
                                                checked={field.required}
                                                onChange={(e) =>
                                                    updateField(
                                                        index,
                                                        'required',
                                                        e.target.checked,
                                                    )
                                                }
                                                className="h-4 w-4 rounded border-border"
                                            />
                                            <Label className="text-xs">
                                                Req
                                            </Label>
                                        </div>
                                        {data.schema.length > 1 ? (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    removeField(index)
                                                }
                                            >
                                                Remove
                                            </Button>
                                        ) : null}
                                    </div>
                                ))}
                            </div>

                            <div className="flex items-center gap-2">
                                <input
                                    id="is_active"
                                    type="checkbox"
                                    checked={data.is_active}
                                    onChange={(e) =>
                                        setData('is_active', e.target.checked)
                                    }
                                    className="h-4 w-4 rounded border-border"
                                />
                                <Label
                                    htmlFor="is_active"
                                    className="cursor-pointer"
                                >
                                    Active
                                </Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                router.get(`/operations/forms/${form.id}`)
                            }
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Save changes
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
