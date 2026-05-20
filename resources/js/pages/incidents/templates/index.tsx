import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';

type Props = {
    auth: any;
    templates: Array<any>;
};

export default function IncidentTemplateIndex({ auth, templates }: Props) {
    const activeCount = templates.filter((t) => t.is_active).length;

    return (
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }, { title: 'Templates', href: '/incidents/templates' }]}>
            <Head title="Incident templates" />

            <PageLayout
                hero={
                    <PageHero
                        icon={FileText}
                        title="Incident templates"
                        description="Prefill incident reporting fields"
                        stats={[
                            { label: 'Total', value: templates.length },
                            { label: 'Active', value: activeCount },
                        ]}
                        actions={
                            (auth.can as any)?.incidents?.templatesManage ? (
                                <Link href="/incidents/templates/create">
                                    <Button size="sm">New template</Button>
                                </Link>
                            ) : null
                        }
                    />
                }
            >
                <div className="space-y-2">
                    {templates.map((t) => (
                        <Card key={t.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="font-semibold">
                                                {t.name} {!t.is_active ? <span className="ml-2 text-xs text-muted-foreground">(inactive)</span> : null}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {t.type || '—'} • {t.severity || '—'}
                                            </div>
                                        </div>
                                        <Link href={`/incidents/templates/${t.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            Edit
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!templates.length && <div className="text-sm text-muted-foreground">No templates yet.</div>}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
