import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';

type Form = {
    id: number;
    name: string;
    description?: string | null;
    form_type: string;
    is_active: boolean;
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
    return (
        <AppLayout>
            <Head title={form.name} />
            <PageHero variant="compact"
                title={form.name}
                description="Review this custom form's structure and current workflow type."
                backHref="/operations/forms"
            >
                <div className="flex items-center gap-2">
                    <Button asChild size="sm" variant="outline">
                        <Link href={`/operations/forms/${form.id}/submissions`}>
                            Submissions
                        </Link>
                    </Button>
                    <Button asChild size="sm">
                        <Link href={`/operations/forms/${form.id}/edit`}>
                            Edit
                        </Link>
                    </Button>
                </div>
            </PageHero>
            <PageShell>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Form details
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 md:grid-cols-3">
                            <div className="rounded-md border p-3">
                                <div className="text-xs text-muted-foreground uppercase">
                                    Type
                                </div>
                                <div className="mt-1 text-sm font-medium">
                                    {sentenceCase(form.form_type)}
                                </div>
                            </div>
                            <div className="rounded-md border p-3">
                                <div className="text-xs text-muted-foreground uppercase">
                                    Status
                                </div>
                                <div className="mt-1 text-sm font-medium">
                                    {form.is_active ? 'Active' : 'Inactive'}
                                </div>
                            </div>
                            <div className="rounded-md border p-3">
                                <div className="text-xs text-muted-foreground uppercase">
                                    Fields
                                </div>
                                <div className="mt-1 text-sm font-medium">
                                    {form.schema.length}
                                </div>
                            </div>
                        </div>

                        <div className="rounded-md border p-3">
                            <div className="text-sm font-medium">
                                Description
                            </div>
                            <div className="mt-2 text-sm text-muted-foreground">
                                {form.description || 'No description provided.'}
                            </div>
                        </div>

                        <div className="rounded-md border p-3">
                            <div className="text-sm font-medium">Fields</div>
                            <div className="mt-3 space-y-2">
                                {form.schema.map((field, index) => (
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
                                ))}
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
