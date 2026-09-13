import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { FileText, Inbox, Pencil } from 'lucide-react';
import { useMemo, useState } from 'react';

type Form = {
    id: number;
    name: string;
    description?: string | null;
    form_type: string;
    is_active: boolean;
    submissions_count?: number;
    schema: Array<{
        key?: string | null;
        label: string;
        type: string;
        required?: boolean;
        options?: string[];
    }>;
};

type Props = {
    form: Form;
};

function sentenceCase(value: string) {
    return value
        .split('_')
        .join(' ')
        .replace(/^\w/, (match) => match.toUpperCase());
}

export default function CustomFormShow({ form }: Props) {
    const [search, setSearch] = useState('');

    const requiredCount = form.schema.filter((f) => f.required).length;
    const submissionsCount = form.submissions_count ?? 0;

    const shownFields = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return form.schema;
        return form.schema.filter((f) =>
            `${f.label} ${f.type}`.toLowerCase().includes(q),
        );
    }, [form.schema, search]);

    const header = (
        <PageHeader
            variant="profile"
            backHref="/operations/forms"
            icon={FileText}
            title={form.name}
            titleChip={
                <PageHeaderStatusChip
                    variant={form.is_active ? 'success' : 'neutral'}
                >
                    {form.is_active ? 'Active' : 'Inactive'}
                </PageHeaderStatusChip>
            }
            subline={`${sentenceCase(form.form_type)} form · ${
                form.schema.length
            } ${form.schema.length === 1 ? 'field' : 'fields'} · ${
                submissionsCount
            } ${submissionsCount === 1 ? 'submission' : 'submissions'}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search fields…"
                    />
                    <PageHeaderGlassButton
                        icon={Inbox}
                        onClick={() =>
                            router.visit(
                                `/operations/forms/${form.id}/submissions`,
                            )
                        }
                    >
                        Submissions
                    </PageHeaderGlassButton>
                    <PageHeaderPrimaryButton
                        icon={Pencil}
                        onClick={() =>
                            router.visit(`/operations/forms/${form.id}/edit`)
                        }
                    >
                        Edit form
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Submissions"
                        ariaLabel="View this form's submissions"
                        href={`/operations/forms/${form.id}/submissions`}
                    >
                        <PageHeaderMeterBig>
                            {submissionsCount}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            recorded so far
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Fields"
                        ariaLabel="Edit this form's fields"
                        href={`/operations/forms/${form.id}/edit`}
                    >
                        <PageHeaderMeterBig>
                            {form.schema.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {requiredCount} required
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Forms', href: '/operations/forms' },
                { title: form.name, href: `/operations/forms/${form.id}` },
            ]}
        >
            <Head title={form.name} />

            <PageLayout hero={header}>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Form details
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="rounded-md border p-3">
                            <div className="text-sm font-medium">
                                Description
                            </div>
                            <div className="mt-2 text-sm text-muted-foreground">
                                {form.description || 'No description provided.'}
                            </div>
                        </div>

                        <div className="rounded-md border p-3">
                            <div className="flex items-baseline justify-between gap-2">
                                <div className="text-sm font-medium">
                                    Fields
                                </div>
                                {search.trim() !== '' && (
                                    <div className="text-xs text-muted-foreground">
                                        {shownFields.length} of{' '}
                                        {form.schema.length} match
                                    </div>
                                )}
                            </div>
                            <div className="mt-3 space-y-2">
                                {shownFields.length === 0 ? (
                                    <div className="rounded-md border border-dashed p-3 text-center text-xs text-muted-foreground">
                                        No fields match your search.
                                    </div>
                                ) : (
                                    shownFields.map((field, index) => (
                                        <div
                                            key={`${field.label}-${index}`}
                                            className="rounded-md border p-3"
                                        >
                                            <div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                                                <div className="text-sm font-medium">
                                                    {field.label}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {sentenceCase(field.type)}
                                                    {field.required
                                                        ? ' | Required'
                                                        : ' | Optional'}
                                                </div>
                                            </div>
                                            {field.options &&
                                            field.options.length > 0 ? (
                                                <div className="mt-2 text-xs text-muted-foreground">
                                                    Options:{' '}
                                                    {field.options.join(', ')}
                                                </div>
                                            ) : null}
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
