import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/react';

type Props = {
    auth: any;
    templates: Array<any>;
};

export default function IncidentTemplateIndex({ auth, templates }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }, { title: 'Templates', href: '/incidents/templates' }]}>
            <Head title="Incident templates" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Incident templates</h1>
                        <div className="mt-1 text-sm text-slate-500">Prefill incident reporting fields</div>
                    </div>

                    {(auth.can as any)?.incidents?.templatesManage && (
                        <Link href="/incidents/templates/create">
                            <Button size="sm">New template</Button>
                        </Link>
                    )}
                </div>

                <div className="space-y-2">
                    {templates.map((t) => (
                        <Card key={t.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="font-semibold">
                                                {t.name} {!t.is_active ? <span className="ml-2 text-xs text-slate-400">(inactive)</span> : null}
                                            </div>
                                            <div className="mt-1 text-xs text-slate-500">
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
                    {!templates.length && <div className="text-sm text-slate-500">No templates yet.</div>}
                </div>
            </div>
        </AppLayout>
    );
}
