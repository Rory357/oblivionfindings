import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import { useMemo, useState } from 'react';

type Submission = {
    id: number;
    data: Record<string, unknown>;
    created_at?: string | null;
    submitter?: { id: number; name: string } | null;
};

type Props = {
    form: {
        id: number;
        name: string;
    };
    submissions: {
        data: Array<Submission>;
        links?: { url: string | null; label: string; active: boolean }[];
        last_page?: number;
        total?: number;
    };
};

export default function CustomFormSubmissions({ form, submissions }: Props) {
    const [search, setSearch] = useState('');
    const total = submissions.total ?? submissions.data.length;

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return submissions.data;
        return submissions.data.filter((s) => {
            const hay = `${s.submitter?.name ?? ''} ${Object.values(
                s.data || {},
            ).join(' ')}`.toLowerCase();
            return hay.includes(q);
        });
    }, [submissions.data, search]);

    const header = (
        <PageHeader
            variant="profile"
            backHref={`/operations/forms/${form.id}`}
            icon={Inbox}
            title={form.name}
            titleChip={
                <PageHeaderStatusChip variant={total > 0 ? 'info' : 'neutral'}>
                    {total} {total === 1 ? 'submission' : 'submissions'}
                </PageHeaderStatusChip>
            }
            subline="Form submissions · newest first"
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search submissions…"
                />
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
                {
                    title: 'Submissions',
                    href: `/operations/forms/${form.id}/submissions`,
                },
            ]}
        >
            <Head title={`${form.name} submissions`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Recent submissions"
                        caption={`${shown.length} of ${total} shown`}
                    />

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recent submissions
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {shown.length === 0 ? (
                                <EmptyState
                                    icon={Inbox}
                                    title="No submissions"
                                    description={
                                        search.trim() !== ''
                                            ? 'No submissions match your search.'
                                            : 'No submissions have been captured for this form yet.'
                                    }
                                />
                            ) : (
                                shown.map((submission) => (
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
                                                        {key
                                                            .split('_')
                                                            .join(' ')}
                                                    </div>
                                                    <div className="mt-1 font-medium">
                                                        {typeof value ===
                                                        'boolean'
                                                            ? value
                                                                ? 'Yes'
                                                                : 'No'
                                                            : String(
                                                                  value || '-',
                                                              )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    {(submissions.last_page ?? 1) > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {(submissions.links ?? []).map((link, i) => (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    className="h-7 min-w-[28px] px-2 text-xs"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
