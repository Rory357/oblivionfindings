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
import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

type FormField = {
    key?: string | null;
    label: string;
    type: string;
    required?: boolean;
    options?: string[];
};

type ShiftForm = {
    id: number;
    name: string;
    description?: string | null;
    form_type: string;
    schema: FormField[];
};

type ShiftFormSubmission = {
    id: number;
    status: string;
    submitted_at?: string | null;
    data: Record<string, unknown>;
    submitter?: { id: number; name: string } | null;
    form?: { id: number; name: string; form_type: string } | null;
};

type Props = {
    shiftId: number;
    canSubmit: boolean;
    forms: ShiftForm[];
    submissions: ShiftFormSubmission[];
};

type SubmissionValue = string | boolean | null;

type SubmissionFormData = {
    data: Record<string, SubmissionValue>;
    shift_id: number;
};

function fieldKey(field: FormField, index: number) {
    if (field.key && field.key.trim() !== '') return field.key;

    const slug = field.label
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    return slug || `field_${index + 1}`;
}

function buildInitialValues(form: ShiftForm | undefined) {
    if (!form) return {};

    return form.schema.reduce<Record<string, SubmissionValue>>(
        (carry, field, index) => {
            carry[fieldKey(field, index)] =
                field.type === 'checkbox' ? false : '';
            return carry;
        },
        {},
    );
}

function sentenceCase(value: string) {
    return value
        .split('_')
        .join(' ')
        .replace(/^\w/, (match) => match.toUpperCase());
}

export default function ShiftFormsCard({
    shiftId,
    canSubmit,
    forms,
    submissions,
}: Props) {
    const [selectedFormId, setSelectedFormId] = useState<string>(() =>
        forms[0] ? String(forms[0].id) : '',
    );

    const selectedForm = useMemo(
        () => forms.find((form) => String(form.id) === String(selectedFormId)),
        [forms, selectedFormId],
    );

    const submissionForm = useForm<SubmissionFormData>({
        data: buildInitialValues(forms[0]),
        shift_id: shiftId,
    });

    useEffect(() => {
        if (!forms.length) return;
        const firstForm = forms[0];
        if (!firstForm) return;

        if (
            !selectedFormId ||
            !forms.some((form) => String(form.id) === String(selectedFormId))
        ) {
            setSelectedFormId(String(firstForm.id));
        }
    }, [forms, selectedFormId]);

    useEffect(() => {
        submissionForm.setData('data', buildInitialValues(selectedForm));
        submissionForm.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- The Inertia form helper is intentionally stable for this reset; including it would retrigger on setData.
    }, [selectedForm]);

    const submit = () => {
        if (!selectedForm) return;

        submissionForm.post(`/operations/forms/${selectedForm.id}/submit`, {
            preserveScroll: true,
            onSuccess: () => {
                submissionForm.reset();
                submissionForm.setData(
                    'data',
                    buildInitialValues(selectedForm),
                );
            },
        });
    };

    const renderField = (field: FormField, index: number) => {
        const key = fieldKey(field, index);
        const value = submissionForm.data.data[key];
        const errors = submissionForm.errors as Record<
            string,
            string | undefined
        >;
        const error = errors[`data.${key}`];

        if (field.type === 'textarea') {
            return (
                <div key={key} className="space-y-1">
                    <Label>
                        {field.label}
                        {field.required ? ' *' : ''}
                    </Label>
                    <Textarea
                        disabled={!canSubmit}
                        value={String(value ?? '')}
                        onChange={(event) =>
                            submissionForm.setData('data', {
                                ...submissionForm.data.data,
                                [key]: event.target.value,
                            })
                        }
                    />
                    {error ? (
                        <div className="text-xs text-status-critical">
                            {error}
                        </div>
                    ) : null}
                </div>
            );
        }

        if (field.type === 'checkbox') {
            return (
                <div
                    key={key}
                    className="flex items-center justify-between rounded-md border p-3"
                >
                    <div>
                        <div className="text-sm font-medium">{field.label}</div>
                        {field.required ? (
                            <div className="text-xs text-muted-foreground">
                                Required
                            </div>
                        ) : null}
                    </div>
                    <Checkbox
                        checked={Boolean(value)}
                        disabled={!canSubmit}
                        onCheckedChange={(checked) =>
                            submissionForm.setData('data', {
                                ...submissionForm.data.data,
                                [key]: Boolean(checked),
                            })
                        }
                    />
                </div>
            );
        }

        if (field.type === 'select') {
            const options = field.options ?? [];

            return (
                <div key={key} className="space-y-1">
                    <Label>
                        {field.label}
                        {field.required ? ' *' : ''}
                    </Label>
                    <Select
                        value={String(value ?? '__none__')}
                        disabled={!canSubmit}
                        onValueChange={(nextValue) =>
                            submissionForm.setData('data', {
                                ...submissionForm.data.data,
                                [key]:
                                    nextValue === '__none__' ? '' : nextValue,
                            })
                        }
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Select an option" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">
                                Select an option
                            </SelectItem>
                            {options.map((option) => (
                                <SelectItem key={option} value={option}>
                                    {option}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {error ? (
                        <div className="text-xs text-status-critical">
                            {error}
                        </div>
                    ) : null}
                </div>
            );
        }

        const inputType =
            field.type === 'number'
                ? 'number'
                : field.type === 'date'
                  ? 'date'
                  : field.type === 'email'
                    ? 'email'
                    : 'text';

        return (
            <div key={key} className="space-y-1">
                <Label>
                    {field.label}
                    {field.required ? ' *' : ''}
                </Label>
                <Input
                    type={inputType}
                    disabled={!canSubmit}
                    value={String(value ?? '')}
                    onChange={(event) =>
                        submissionForm.setData('data', {
                            ...submissionForm.data.data,
                            [key]: event.target.value,
                        })
                    }
                />
                {error ? (
                    <div className="text-xs text-status-critical">{error}</div>
                ) : null}
            </div>
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Shift forms</CardTitle>
                <div className="text-sm text-muted-foreground">
                    Submit shift-linked forms without leaving the live workflow.
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid gap-3 md:grid-cols-3">
                    <div className="rounded-md border p-3">
                        <div className="text-xs text-muted-foreground uppercase">
                            Active forms
                        </div>
                        <div className="mt-1 text-2xl font-semibold">
                            {forms.length}
                        </div>
                    </div>
                    <div className="rounded-md border p-3">
                        <div className="text-xs text-muted-foreground uppercase">
                            Submitted on shift
                        </div>
                        <div className="mt-1 text-2xl font-semibold">
                            {submissions.length}
                        </div>
                    </div>
                    <div className="rounded-md border p-3">
                        <div className="text-xs text-muted-foreground uppercase">
                            Submission access
                        </div>
                        <div className="mt-1 text-sm font-medium">
                            {canSubmit ? 'Available' : 'View only'}
                        </div>
                    </div>
                </div>

                {forms.length === 0 ? (
                    <div className="rounded-md border p-3 text-sm text-muted-foreground">
                        No active shift forms are configured for this workflow
                        yet.
                    </div>
                ) : (
                    <div className="space-y-3 rounded-md border p-3">
                        <div className="space-y-1">
                            <Label>Form</Label>
                            <Select
                                value={selectedFormId}
                                onValueChange={setSelectedFormId}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a form" />
                                </SelectTrigger>
                                <SelectContent>
                                    {forms.map((form) => (
                                        <SelectItem
                                            key={form.id}
                                            value={String(form.id)}
                                        >
                                            {form.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {selectedForm ? (
                            <>
                                <div className="rounded-md bg-muted/40 p-3 text-sm">
                                    <div className="font-medium">
                                        {selectedForm.name}
                                    </div>
                                    <div className="mt-1 text-muted-foreground">
                                        {selectedForm.description ||
                                            'No description provided.'}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        Type:{' '}
                                        {sentenceCase(selectedForm.form_type)}
                                    </div>
                                </div>

                                <div className="grid gap-3 md:grid-cols-2">
                                    {selectedForm.schema.map((field, index) =>
                                        renderField(field, index),
                                    )}
                                </div>

                                {canSubmit ? (
                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            disabled={submissionForm.processing}
                                            onClick={submit}
                                        >
                                            Submit form
                                        </Button>
                                    </div>
                                ) : null}
                            </>
                        ) : null}
                    </div>
                )}

                <div className="rounded-md border p-3">
                    <div className="text-sm font-medium">
                        Recent shift submissions
                    </div>
                    <div className="mt-3 space-y-2">
                        {submissions.length === 0 ? (
                            <div className="text-sm text-muted-foreground">
                                No forms have been submitted on this shift yet.
                            </div>
                        ) : (
                            submissions.map((submission) => (
                                <div
                                    key={submission.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                                        <div>
                                            <div className="text-sm font-medium">
                                                {submission.form?.name ||
                                                    'Shift form'}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {submission.submitter?.name ||
                                                    'Unknown submitter'}
                                                {submission.submitted_at
                                                    ? ` | ${new Date(submission.submitted_at).toLocaleString()}`
                                                    : ''}
                                            </div>
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {sentenceCase(
                                                submission.status ||
                                                    'submitted',
                                            )}
                                        </div>
                                    </div>

                                    <div className="mt-3 grid gap-2 md:grid-cols-2">
                                        {Object.entries(
                                            submission.data || {},
                                        ).map(([key, value]) => (
                                            <div
                                                key={`${submission.id}-${key}`}
                                                className="rounded-md bg-muted/40 p-2 text-sm"
                                            >
                                                <div className="text-xs text-muted-foreground uppercase">
                                                    {key.split('_').join(' ')}
                                                </div>
                                                <div className="mt-1 font-medium">
                                                    {typeof value === 'boolean'
                                                        ? value
                                                            ? 'Yes'
                                                            : 'No'
                                                        : String(value || '-')}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
