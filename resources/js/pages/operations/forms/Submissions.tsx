import PageShell from '@/components/page-shell';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

type Props = {
    form: {
        id: number;
        name: string;
    };
    submissions: {
        data: Array<{
            id: number;
            data: Record<string, unknown>;
            created_at?: string | null;
            submitter?: { id: number; name: string } | null;
        }>;
    };
};

export default function CustomFormSubmissions({ form, submissions }: Props) {
    return (
        <AppLayout>
            <Head title={`${form.name} submissions`} />
            <PageHero variant="compact"
                title={`${form.name} submissions`}
                description="Review recent submissions captured against this form."
                backHref={`/operations/forms/${form.id}`}
            />
            <PageShell>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Recent submissions
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {submissions.data.length === 0 ? (
                            <div className="rounded-md border p-3 text-sm text-muted-foreground">
                                No submissions have been captured for this form
                                yet.
                            </div>
                        ) : (
                            submissions.data.map((submission) => (
                                <div
                                    key={submission.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                                        <div className="text-sm font-medium">
                                            Submission #{submission.id}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {submission.submitter?.name ||
                                                'Unknown submitter'}
                                            {submission.created_at
                                                ? ` | ${new Date(submission.created_at).toLocaleString()}`
                                                : ''}
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
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
