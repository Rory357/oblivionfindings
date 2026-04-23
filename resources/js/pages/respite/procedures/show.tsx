import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { Head } from '@inertiajs/react';

type Props = {
    template: any;
};

export default function RespiteProcedureShow({ template }: Props) {
    const steps = Array.isArray(template.steps_json) ? template.steps_json : [];
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Procedures', href: '/respite/procedures' },
            { title: template.name, href: `/respite/procedures/${template.id}` },
        ]}>
            <Head title={`Procedure ${template.name}`} />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">{template.name}</h1>
                    <div className="mt-1 text-sm text-muted-foreground">Version {template.version}</div>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Template</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {steps.length ? (
                            <div className="space-y-3">
                                {steps.map((step: any, idx: number) => (
                                    <div key={step.id ?? idx} className="rounded-md border p-3">
                                        <div className="flex items-center justify-between gap-2">
                                            <div className="font-medium">
                                                {step.title ?? step.id ?? `Step ${idx + 1}`}
                                            </div>
                                            {step.stop_gate && (
                                                <Badge variant="outline">Stop-gate</Badge>
                                            )}
                                        </div>
                                        {step.required_evidence?.length ? (
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Evidence: {step.required_evidence.join(', ')}
                                            </div>
                                        ) : null}
                                        {step.sla_minutes ? (
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                SLA: {step.sla_minutes} minutes
                                            </div>
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="text-sm text-muted-foreground">No steps defined yet.</div>
                        )}
                        <details className="mt-3">
                            <summary className="cursor-pointer text-xs text-muted-foreground">View raw JSON</summary>
                            <pre className="mt-2 text-xs whitespace-pre-wrap">
                                {JSON.stringify(template.steps_json, null, 2)}
                            </pre>
                        </details>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
