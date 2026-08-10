import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';

type SummaryDto = {
    id: number;
    scope_type: 'staff' | 'client' | 'site';
    scope_id: number;
    period_start: string;
    period_end: string;
    model?: string | null;
    summary_text: string;
    generated_at?: string | null;
};

type Props = {
    scope: { type: 'staff' | 'client' | 'site'; id: number; name: string };
    range: { from: string; to: string };
    summary: SummaryDto | null;
};

export default function SummariesIndex(props: Props) {
    const { auth } = usePage().props as any;
    const canGenerate = !!auth?.can?.summaries?.generate;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Summaries', href: '/summaries' },
    ];

    const form = useForm({
        scope_type: props.scope.type,
        scope_id: props.scope.id,
        from: props.range.from,
        to: props.range.to,
    });

    const generate = () => {
        form.post('/summaries/generate', { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Summaries" />

            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <div className="text-sm font-semibold">
                            {props.scope.name}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {new Date(props.range.from).toLocaleDateString()} →{' '}
                            {new Date(props.range.to).toLocaleDateString()}
                        </div>
                    </div>

                    {canGenerate ? (
                        <Button
                            size="sm"
                            onClick={generate}
                            disabled={form.processing}
                        >
                            Generate summary
                        </Button>
                    ) : null}
                </div>

                <Card className="rounded-2xl">
                    <CardHeader>
                        <CardTitle className="text-base">Summary</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {props.summary ? (
                            <div className="space-y-2">
                                <div className="text-xs text-muted-foreground">
                                    {props.summary.model
                                        ? `Model: ${props.summary.model}`
                                        : null}
                                    {props.summary.generated_at
                                        ? ` • Generated: ${new Date(props.summary.generated_at).toLocaleString()}`
                                        : null}
                                </div>
                                <pre className="rounded-xl border p-3 text-sm whitespace-pre-wrap">
                                    {props.summary.summary_text}
                                </pre>
                            </div>
                        ) : (
                            <div className="text-sm text-muted-foreground">
                                No summary generated for this period yet.
                            </div>
                        )}
                        {form.errors.scope_type ||
                        form.errors.scope_id ||
                        form.errors.from ||
                        form.errors.to ? (
                            <div className="mt-2 text-xs text-destructive">
                                {form.errors.scope_type ||
                                    form.errors.scope_id ||
                                    form.errors.from ||
                                    form.errors.to}
                            </div>
                        ) : null}
                    </CardContent>
                </Card>

                <Card className="rounded-2xl">
                    <CardHeader>
                        <CardTitle className="text-base">
                            How this works (MVP)
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-muted-foreground">
                        This is using your internal timeline events to build a
                        deterministic summary. When you’re ready, we can swap
                        the job to call a real LLM (OpenAI, Azure OpenAI, etc.)
                        and keep the same UI and storage.
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
