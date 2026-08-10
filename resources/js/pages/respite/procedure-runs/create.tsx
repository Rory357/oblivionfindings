import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
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
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';

type Template = {
    id: number;
    name: string;
    domain?: string | null;
    description?: string | null;
    steps_json?: Array<Record<string, unknown>>;
};

type Props = {
    templates: Record<string, Template[]>;
    subjectType?: string;
    subjectId?: string;
};

const SUBJECT_TYPES = [
    { value: 'App\\Models\\RespiteBooking', label: 'Booking' },
    { value: 'App\\Models\\RespiteStay', label: 'Stay' },
    { value: 'App\\Models\\RespiteBookingRequest', label: 'Booking Request' },
    { value: 'App\\Models\\RespiteReferral', label: 'Referral' },
];

export default function ProcedureRunCreate({
    templates,
    subjectType,
    subjectId,
}: Props) {
    const templateOptions = Object.entries(templates ?? {}).flatMap(
        ([domain, items]) =>
            (items ?? []).map((item) => ({
                ...item,
                domain,
            })),
    );
    const hasTemplates = templateOptions.length > 0;

    const { data, setData, post, processing, errors } = useForm({
        procedure_template_id: '',
        subject_type: subjectType || SUBJECT_TYPES[0].value,
        subject_id: subjectId || '',
    });

    const selectedTemplate = templateOptions.find(
        (template) => String(template.id) === data.procedure_template_id,
    );
    const stepCount = Array.isArray(selectedTemplate?.steps_json)
        ? selectedTemplate.steps_json.length
        : 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                { title: 'Procedure Runs', href: '/respite/procedure-runs' },
                { title: 'New Run', href: '/respite/procedure-runs/create' },
            ]}
        >
            <Head title="New Procedure Run" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/procedure-runs"
                        title="New Procedure Run"
                        description="Start a procedure from an active template and attach it to a respite subject."
                    />
                }
            >
                <RespiteSubnav />

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        post('/respite/procedure-runs');
                    }}
                    className="space-y-4"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Run Setup
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            {!hasTemplates && (
                                <div className="rounded-md border border-status-warning/30 bg-status-warning-bg px-3 py-2 text-sm text-status-warning sm:col-span-2">
                                    No active procedure templates are available
                                    yet. Create or activate a template before
                                    starting a procedure run.
                                </div>
                            )}
                            <div className="sm:col-span-2">
                                <Label>Procedure Template *</Label>
                                <Select
                                    value={data.procedure_template_id}
                                    onValueChange={(value) =>
                                        setData('procedure_template_id', value)
                                    }
                                    disabled={!hasTemplates}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a template" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {templateOptions.map((template) => (
                                            <SelectItem
                                                key={template.id}
                                                value={String(template.id)}
                                            >
                                                {template.name}
                                                {template.domain
                                                    ? ` (${template.domain})`
                                                    : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.procedure_template_id && (
                                    <div className="mt-1 text-xs text-status-critical">
                                        {errors.procedure_template_id}
                                    </div>
                                )}
                            </div>

                            <div>
                                <Label>Subject Type *</Label>
                                <Select
                                    value={data.subject_type}
                                    onValueChange={(value) =>
                                        setData('subject_type', value)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select subject type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {SUBJECT_TYPES.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.subject_type && (
                                    <div className="mt-1 text-xs text-status-critical">
                                        {errors.subject_type}
                                    </div>
                                )}
                            </div>

                            <div>
                                <Label>Subject ID *</Label>
                                <Input
                                    type="number"
                                    min="1"
                                    value={data.subject_id}
                                    onChange={(event) =>
                                        setData(
                                            'subject_id',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Enter the related record ID"
                                />
                                {errors.subject_id && (
                                    <div className="mt-1 text-xs text-status-critical">
                                        {errors.subject_id}
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {selectedTemplate && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Template Preview
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm text-muted-foreground">
                                <div className="font-medium text-foreground">
                                    {selectedTemplate.name}
                                </div>
                                {selectedTemplate.description && (
                                    <p>{selectedTemplate.description}</p>
                                )}
                                <div>
                                    {stepCount} step{stepCount === 1 ? '' : 's'}{' '}
                                    will be created for this run.
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={processing || !hasTemplates}
                        >
                            {processing ? 'Starting...' : 'Start Procedure Run'}
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
