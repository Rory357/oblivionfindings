import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import RespiteSubnav from '@/components/respite-subnav';
import { Head, Link } from '@inertiajs/react';

type Props = {
    templates: any;
};

export default function RespiteProceduresIndex({ templates }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Procedures', href: '/respite/procedures' },
        ]}>
            <Head title="Respite Procedures" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Procedure Templates</h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            Define procedure steps for respite workflows.
                        </div>
                    </div>
                    <Link href="/respite/procedures/create">
                        <Button size="sm">New Template</Button>
                    </Link>
                </div>
                <RespiteSubnav />

                <div className="space-y-2">
                    {templates.data.map((t: any) => (
                        <Card key={t.id}>
                            <CardHeader>
                                <CardTitle className="text-base">{t.name} (v{t.version})</CardTitle>
                            </CardHeader>
                            <CardContent className="text-sm text-muted-foreground">
                                <Link href={`/respite/procedures/${t.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                    View
                                </Link>
                            </CardContent>
                        </Card>
                    ))}
                    {!templates.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No procedure templates found.
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
