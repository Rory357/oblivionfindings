import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { FileText, Plus, Trash2 } from 'lucide-react';

const CATEGORIES = [
    { value: 'manual_handling', label: 'Manual Handling' },
    { value: 'fire_safety', label: 'Fire Safety' },
    { value: 'chemical_handling', label: 'Chemical Handling' },
    { value: 'electrical_safety', label: 'Electrical Safety' },
    { value: 'working_at_height', label: 'Working at Height' },
    { value: 'confined_spaces', label: 'Confined Spaces' },
    { value: 'infection_control', label: 'Infection Control' },
    { value: 'medication', label: 'Medication' },
    { value: 'vehicle_safety', label: 'Vehicle Safety' },
    { value: 'vehicle_operation', label: 'Vehicle Operation' },
    { value: 'personal_care', label: 'Personal Care' },
    { value: 'challenging_behaviour', label: 'Challenging Behaviour' },
    { value: 'lone_working', label: 'Lone Working' },
    { value: 'equipment_use', label: 'Equipment Use' },
    { value: 'emergency_procedures', label: 'Emergency Procedures' },
    { value: 'ppe', label: 'PPE' },
    { value: 'general', label: 'General' },
    { value: 'other', label: 'Other' },
];

const PPE_OPTIONS = [
    'Safety glasses',
    'Face shield',
    'Gloves (disposable)',
    'Gloves (heavy duty)',
    'Safety boots',
    'Hard hat',
    'Hi-vis vest',
    'Ear protection',
    'Dust mask',
    'Respirator',
    'Apron',
    'Full body harness',
    'Other',
];

type Step = {
    step_number: number;
    description: string;
    safety_notes: string;
};

type Props = {
    procedure: {
        id: number;
        title: string;
        reference_number: string;
        category: string;
        purpose: string;
        scope: string;
        steps: Step[];
        ppe_required: string[];
        emergency_procedures: string;
        applicable_roles: string[];
        applicable_sites: string[];
    };
};

export default function ProcedureEdit({ procedure }: Props) {
    const form = useForm<{
        title: string;
        reference_number: string;
        category: string;
        purpose: string;
        scope: string;
        steps: Step[];
        ppe_required: string[];
        emergency_procedures: string;
        applicable_roles: string[];
        applicable_sites: string[];
    }>({
        title: procedure.title,
        reference_number: procedure.reference_number,
        category: procedure.category,
        purpose: procedure.purpose,
        scope: procedure.scope,
        steps: procedure.steps,
        ppe_required: procedure.ppe_required,
        emergency_procedures: procedure.emergency_procedures,
        applicable_roles: procedure.applicable_roles,
        applicable_sites: procedure.applicable_sites,
    });

    const addStep = () => {
        form.setData('steps', [
            ...form.data.steps,
            {
                step_number: form.data.steps.length + 1,
                description: '',
                safety_notes: '',
            },
        ]);
    };

    const removeStep = (index: number) => {
        if (form.data.steps.length <= 1) return;
        const updated = form.data.steps
            .filter((_, i) => i !== index)
            .map((s, i) => ({ ...s, step_number: i + 1 }));
        form.setData('steps', updated);
    };

    const updateStep = (index: number, field: keyof Step, value: string) => {
        const updated = [...form.data.steps];
        updated[index] = { ...updated[index], [field]: value };
        form.setData('steps', updated);
    };

    const togglePpe = (item: string) => {
        const current = form.data.ppe_required;
        if (current.includes(item)) {
            form.setData(
                'ppe_required',
                current.filter((p) => p !== item),
            );
        } else {
            form.setData('ppe_required', [...current, item]);
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(`/health-safety/procedures/${procedure.id}`);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Procedures', href: '/health-safety/procedures' },
                {
                    title: procedure.title,
                    href: `/health-safety/procedures/${procedure.id}`,
                },
                {
                    title: 'Edit',
                    href: `/health-safety/procedures/${procedure.id}/edit`,
                },
            ]}
        >
            <Head title={`Edit ${procedure.title}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref={`/health-safety/procedures/${procedure.id}`}
                        icon={FileText}
                        title="Edit Safe Work Procedure"
                        description={procedure.title}
                    />
                }
            >
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Basic Information
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <Label>Title</Label>
                                    <Input
                                        value={form.data.title}
                                        onChange={(e) =>
                                            form.setData(
                                                'title',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Procedure title"
                                    />
                                    {form.errors.title && (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {form.errors.title}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Reference Number</Label>
                                    <Input
                                        value={form.data.reference_number}
                                        onChange={(e) =>
                                            form.setData(
                                                'reference_number',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. SWP-001"
                                    />
                                    {form.errors.reference_number && (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {form.errors.reference_number}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div>
                                <Label>Category</Label>
                                <Select
                                    value={form.data.category}
                                    onValueChange={(v) =>
                                        form.setData('category', v)
                                    }
                                >
                                    <SelectTrigger className="sm:max-w-xs">
                                        <SelectValue placeholder="Select category" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CATEGORIES.map((c) => (
                                            <SelectItem
                                                key={c.value}
                                                value={c.value}
                                            >
                                                {c.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.category && (
                                    <p className="mt-1 text-xs text-status-critical">
                                        {form.errors.category}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label>Purpose</Label>
                                <Textarea
                                    value={form.data.purpose}
                                    onChange={(e) =>
                                        form.setData('purpose', e.target.value)
                                    }
                                    rows={3}
                                    placeholder="What is the purpose of this procedure?"
                                />
                                {form.errors.purpose && (
                                    <p className="mt-1 text-xs text-status-critical">
                                        {form.errors.purpose}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label>Scope</Label>
                                <Textarea
                                    value={form.data.scope}
                                    onChange={(e) =>
                                        form.setData('scope', e.target.value)
                                    }
                                    rows={3}
                                    placeholder="What does this procedure cover?"
                                />
                                {form.errors.scope && (
                                    <p className="mt-1 text-xs text-status-critical">
                                        {form.errors.scope}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">
                                    Procedure Steps
                                </CardTitle>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={addStep}
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Add Step
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {form.data.steps.map((step, index) => (
                                <div
                                    key={index}
                                    className="rounded-lg border p-4"
                                >
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-sm font-semibold">
                                            Step {step.step_number}
                                        </span>
                                        {form.data.steps.length > 1 && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    removeStep(index)
                                                }
                                            >
                                                <Trash2 className="h-4 w-4 text-status-critical" />
                                            </Button>
                                        )}
                                    </div>
                                    <div className="space-y-3">
                                        <div>
                                            <Label>Description</Label>
                                            <Textarea
                                                value={step.description}
                                                onChange={(e) =>
                                                    updateStep(
                                                        index,
                                                        'description',
                                                        e.target.value,
                                                    )
                                                }
                                                rows={2}
                                                placeholder="Describe what must be done in this step"
                                            />
                                        </div>
                                        <div>
                                            <Label>Safety Notes</Label>
                                            <Textarea
                                                value={step.safety_notes}
                                                onChange={(e) =>
                                                    updateStep(
                                                        index,
                                                        'safety_notes',
                                                        e.target.value,
                                                    )
                                                }
                                                rows={2}
                                                placeholder="Any safety warnings or precautions for this step"
                                            />
                                        </div>
                                    </div>
                                </div>
                            ))}
                            {(form.errors as any).steps && (
                                <p className="text-xs text-status-critical">
                                    {(form.errors as any).steps}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Safety Requirements
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label className="mb-2 block">
                                    PPE Required
                                </Label>
                                <div className="flex flex-wrap gap-2">
                                    {PPE_OPTIONS.map((item) => (
                                        <Button
                                            key={item}
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => togglePpe(item)}
                                            className={`rounded-full px-3 text-xs ${
                                                form.data.ppe_required.includes(
                                                    item,
                                                )
                                                    ? 'border-status-info/30 bg-status-info-bg text-status-info'
                                                    : 'border-border bg-card text-muted-foreground hover:bg-muted'
                                            }`}
                                        >
                                            {item}
                                        </Button>
                                    ))}
                                </div>
                            </div>

                            <div>
                                <Label>Emergency Procedures</Label>
                                <Textarea
                                    value={form.data.emergency_procedures}
                                    onChange={(e) =>
                                        form.setData(
                                            'emergency_procedures',
                                            e.target.value,
                                        )
                                    }
                                    rows={3}
                                    placeholder="What to do in case of an emergency during this procedure"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Applicability
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label>Applicable Roles</Label>
                                <Input
                                    value={form.data.applicable_roles.join(
                                        ', ',
                                    )}
                                    onChange={(e) =>
                                        form.setData(
                                            'applicable_roles',
                                            e.target.value
                                                .split(',')
                                                .map((r) => r.trim())
                                                .filter(Boolean),
                                        )
                                    }
                                    placeholder="e.g. Support Worker, Team Leader, Nurse (comma-separated)"
                                />
                            </div>

                            <div>
                                <Label>Applicable Sites</Label>
                                <Input
                                    value={form.data.applicable_sites.join(
                                        ', ',
                                    )}
                                    onChange={(e) =>
                                        form.setData(
                                            'applicable_sites',
                                            e.target.value
                                                .split(',')
                                                .map((s) => s.trim())
                                                .filter(Boolean),
                                        )
                                    }
                                    placeholder="e.g. All sites, or specific site names (comma-separated)"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.history.back()}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save Procedure
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
