import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';

type TemplateRow = {
    id: number;
    name: string;
    description?: string | null;
    template_type: string;
    is_active: boolean;
    updated_at?: string | null;
    creator?: { id: number; name: string } | null;
    template_shifts_count?: number;
};

type Pagination<T> = {
    data: T[];
    links?: Array<{ url: string | null; label: string; active: boolean }>;
};

type Props = {
    templates: Pagination<TemplateRow>;
};

export default function TemplateIndex({ templates }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: 'Roster Templates',
                    href: '/operations/rostering/templates',
                },
            ]}
        >
            <Head title="Roster Templates" />
            <PageShell>
                <PageHero variant="compact"
                    title="Roster Templates"
                    description="Reusable roster patterns for regular houses, teams, and shift structures."
                    actions={
                        <Button asChild>
                            <Link href="/operations/rostering/templates/create">
                                Create Template
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-4">
                    {templates.data.length > 0 ? (
                        templates.data.map((template) => (
                            <Card key={template.id}>
                                <CardHeader className="flex flex-row items-start justify-between gap-3">
                                    <div>
                                        <CardTitle className="text-base">
                                            <Link
                                                href={`/operations/rostering/templates/${template.id}`}
                                                className="hover:underline"
                                            >
                                                {template.name}
                                            </Link>
                                        </CardTitle>
                                        {template.description && (
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {template.description}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant="outline">
                                            {template.template_type}
                                        </Badge>
                                        <Badge
                                            variant={
                                                template.is_active
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {template.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="flex flex-wrap items-center justify-between gap-3 text-sm">
                                    <div className="flex flex-wrap gap-4 text-muted-foreground">
                                        <span>
                                            Rows:{' '}
                                            <span className="font-medium text-foreground">
                                                {template.template_shifts_count ??
                                                    0}
                                            </span>
                                        </span>
                                        <span>
                                            Updated:{' '}
                                            <span className="font-medium text-foreground">
                                                {template.updated_at
                                                    ? new Date(
                                                          template.updated_at,
                                                      ).toLocaleDateString(
                                                          'en-NZ',
                                                      )
                                                    : '—'}
                                            </span>
                                        </span>
                                        <span>
                                            Created by:{' '}
                                            <span className="font-medium text-foreground">
                                                {template.creator?.name ??
                                                    'Unknown'}
                                            </span>
                                        </span>
                                    </div>

                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={`/operations/rostering/templates/${template.id}`}
                                            >
                                                View
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={`/operations/rostering/templates/${template.id}/edit`}
                                            >
                                                Edit
                                            </Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    ) : (
                        <Card>
                            <CardContent className="py-10 text-center text-sm text-muted-foreground">
                                No roster templates yet.
                            </CardContent>
                        </Card>
                    )}
                </div>

                {templates.links && templates.links.length > 3 && (
                    <div className="mt-4 flex flex-wrap gap-2">
                        {templates.links.map((link, index) => (
                            <Button
                                key={`template-link-${index}`}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                asChild={!!link.url}
                            >
                                {link.url ? (
                                    <Link
                                        href={link.url}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ) : (
                                    <span
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                )}
                            </Button>
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
